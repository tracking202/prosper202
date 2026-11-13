<?php

declare(strict_types=1);

namespace Tests\AdPlatforms;

use Api\V3\Controllers\AdCampaignsController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\TooManyRequestsException;
use Api\V3\Exception\UpstreamUnavailableException;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;
use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\AdPlatformUnavailable;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;
use Prosper202\AdPlatforms\OpenAiAds\OpenAiAdsCampaigns;
use Prosper202\AdPlatforms\OpenAiAds\OpenAiAdsConnections;
use Prosper202\AdPlatforms\OpenAiAds\TrackingTemplate;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DestinationStore;
use Tests\Apps\Android\AndroidDatabase;

/**
 * A ChatGPT Ads connection's campaigns against a real server, with OpenAI's
 * Advertiser API stubbed at the transport (tests/fixtures/openai-ads): what
 * a read shows, that every write sends what it says and a dry run sends
 * nothing, the event settings merge, spend by the account's own days, and
 * OpenAI's failures answered as the ad connections answer them.
 *
 * @group integration
 */
final class AdCampaignsIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private databaseSetUp;
    }

    private const KEY = 'sk-ads-live-Z9q7Xc2Vb8Nm4Lk1';

    /** @var list<array{method: string, url: string, route: string, body: mixed}> */
    private array $requests = [];
    /** @var array<string, array<string, mixed>|callable(): array<string, mixed>> answers by "METHOD path", before the model */
    private array $routes = [];
    /** @var array<string, mixed> cmpn_101 as the stand-in holds it: a write changes it, a read answers it */
    private array $campaign = [];

    protected function setUp(): void
    {
        $this->databaseSetUp();
        self::$db->query('TRUNCATE TABLE 202_ad_connections');
        self::fixture('DELETE FROM 202_users_pref WHERE user_id IN (1, 2)');
        self::fixture("INSERT INTO 202_users_pref SET user_id=1, user_account_currency='USD'");
        $this->requests = [];
        $this->campaign = (array) json_decode(self::answer('campaign.json'), true);
        $this->campaign['end_time'] = null;
        $this->routes = [
            'GET ad_account' => ['body' => self::answer('ad_account.json')],
            'GET campaigns' => ['body' => self::answer('campaigns_list.json')],
            'GET ads/ad_301' => ['body' => self::answer('ad.json')],
            'GET ad_account/insights' => ['body' => self::answer('insights_campaigns.json')],
        ];
    }

    private static function answer(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/openai-ads/' . $name);
    }

    private function api(): AdvertiserApi
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.20'], '203.0.113.0/24');
        $transport = function (array $options): array {
            $url = (string) $options[CURLOPT_URL];
            $method = isset($options[CURLOPT_POSTFIELDS]) && !($options[CURLOPT_HTTPGET] ?? false) ? 'POST' : 'GET';
            $route = $method . ' ' . ltrim((string) parse_url(substr($url, strlen(AdvertiserApi::BASE) - 1), PHP_URL_PATH), '/');
            $this->requests[] = ['method' => $method, 'url' => $url, 'route' => $route, 'body' => $method === 'POST' ? json_decode((string) $options[CURLOPT_POSTFIELDS], true) : null];
            $answer = $this->routes[$route] ?? $this->model($route, $this->requests[array_key_last($this->requests)]['body']);
            if (is_callable($answer)) {
                $answer = $answer();
            }

            return $answer + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => '{}'];
        };

        return new AdvertiserApi(new WebhookSender($guard, $transport));
    }

    /**
     * cmpn_101 as OpenAI keeps it: pause and activate set its status, an update takes the fields sent.
     *
     * @return array<string, mixed>
     */
    private function model(string $route, mixed $body): array
    {
        if ($route === 'POST campaigns/cmpn_101/pause' || $route === 'POST campaigns/cmpn_101/activate') {
            $this->campaign['status'] = str_ends_with($route, 'pause') ? 'paused' : 'active';
        } elseif ($route === 'POST campaigns/cmpn_101' && is_array($body)) {
            $this->campaign = array_replace($this->campaign, $body);
        } elseif ($route !== 'GET campaigns/cmpn_101') {
            return ['status' => 404, 'body' => '{"error":{"message":"Not found"}}'];
        }

        return ['body' => (string) json_encode($this->campaign)];
    }

    private function campaigns(): OpenAiAdsCampaigns
    {
        return new OpenAiAdsCampaigns(new AdConnectionStore(new Connection(self::$db)), $this->api());
    }

    private function connect(): int
    {
        $store = new AdConnectionStore(new Connection(self::$db));
        $id = (int) (new OpenAiAdsConnections($store, $this->api(), new DestinationStore(new Connection(self::$db)), str_repeat('a', 64)))
            ->connect(1, self::KEY, null, 1)['connection_id'];
        $this->requests = [];

        return $id;
    }

    /** @return list<array{method: string, url: string, route: string, body: mixed}> */
    private function writes(): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === 'POST'));
    }

    public function testAListShowsStatusBudgetAndServingIssuesAndPages(): void
    {
        $id = $this->connect();
        $page = $this->campaigns()->list(1, $id, 'campaigns', null, 50, null);

        self::assertStringContainsString('campaigns?include[]=serving_issues&limit=50', $this->requests[0]['url']);
        self::assertSame(['cmpn_101', 'cmpn_102'], array_column($page['data'], 'id'));
        self::assertSame(['active', 'paused'], array_column($page['data'], 'status'));
        self::assertSame(['campaign_not_active'], $page['data'][1]['serving_issues']);
        self::assertSame(25000000, $page['data'][0]['budget']['daily_spend_limit_micros']);
        self::assertSame([true, 'cmpn_102'], [$page['has_more'], $page['after']]);

        $this->routes['GET ad_groups'] = ['body' => '{"object":"list","data":[],"first_id":null,"last_id":null,"has_more":false}'];
        self::assertSame([], $this->campaigns()->list(1, $id, 'ad-groups', 'cmpn_101', 10, 'adgrp_9')['data']);
        self::assertStringContainsString('ad_groups?include[]=serving_issues&limit=10&campaign_id=cmpn_101&after=adgrp_9', $this->requests[1]['url']);
        self::assertNull($this->campaigns()->list(2, $id, 'campaigns', null, 10, null), 'another account\'s connection');
    }

    public function testAnAdShowsItsReviewStatusAndReason(): void
    {
        $ad = $this->campaigns()->get(1, $this->connect(), 'ads', 'ad_301');

        self::assertSame(['rejected', 'landing_page_unavailable', ['review_not_approved']], [$ad['review_status'], $ad['review_reason'], $ad['serving_issues']]);
    }

    public function testPauseSendsTheActionAndADryRunSendsNothing(): void
    {
        $id = $this->connect();
        $preview = $this->campaigns()->setStatus(1, $id, 'campaigns', 'cmpn_101', 'pause', true);
        self::assertSame(['from' => 'active', 'to' => 'paused'], $preview['change']['status']);
        self::assertTrue($preview['dry_run']);
        self::assertSame([], $this->writes(), 'a dry run writes nothing');

        $done = $this->campaigns()->setStatus(1, $id, 'campaigns', 'cmpn_101', 'pause', false);
        self::assertSame(['POST campaigns/cmpn_101/pause'], array_column($this->writes(), 'route'));
        self::assertSame('paused', $done['record']['status']);
    }

    public function testTheTemplateDefaultsToTheGetLinksOneAndIsChecked(): void
    {
        $id = $this->connect();
        $this->campaigns()->setTemplate(1, $id, 'campaigns', 'cmpn_101', null, false);

        self::assertSame(['landing_page_configuration' => ['query_string_template' => TrackingTemplate::default()]], $this->writes()[0]['body']);
        self::assertStringContainsString('oppref={oppref}', TrackingTemplate::default());
        foreach (['a b=1', str_repeat('a', 2049), "x=1\ty=2", 'x=1#frag'] as $bad) {
            try {
                $this->campaigns()->setTemplate(1, $id, 'campaigns', 'cmpn_101', $bad, false);
                self::fail('a template OpenAI would refuse was sent: ' . substr($bad, 0, 20));
            } catch (AdConnectionInputException $e) {
                self::assertSame('template', $e->field);
            }
        }
        self::assertCount(1, $this->writes());
    }

    public function testEventSettingsAreAddedToTheOnesTheCampaignHas(): void
    {
        $id = $this->connect();
        $preview = $this->campaigns()->attachEventSettings(1, $id, 'cmpn_101', ['ces_200', 'ces_100'], true);
        self::assertSame(['from' => ['ces_100'], 'to' => ['ces_100', 'ces_200']], $preview['change']['conversion_event_setting_ids']);
        self::assertSame([], $this->writes());

        $this->campaigns()->attachEventSettings(1, $id, 'cmpn_101', ['ces_200'], false);
        self::assertSame([['conversion_event_setting_ids' => ['ces_100', 'ces_200']]], array_column($this->writes(), 'body'), 'read, merge, write: the one it had is kept');

        $same = $this->campaigns()->attachEventSettings(1, $id, 'cmpn_101', ['ces_100'], false);
        self::assertTrue($same['unchanged']);
        self::assertFalse($same['dry_run'], 'nothing to send is not a dry run');
        self::assertCount(1, $this->writes(), 'nothing to add: nothing sent');

        try {
            $this->campaigns()->attachEventSettings(1, $id, 'cmpn_101', [], false);
            self::fail('attached nothing without saying so');
        } catch (AdConnectionInputException $e) {
            self::assertStringContainsString('Setup has made no event settings', $e->getMessage());
        }
        (new AdConnectionStore(new Connection(self::$db)))->mergeSetup(1, $id, ['event_settings' => ['lead_created' => ['id' => 'ces_300', 'name' => 'Leads', 'how' => 'created']]], 2);
        $this->campaigns()->attachEventSettings(1, $id, 'cmpn_101', [], false);
        self::assertSame(['conversion_event_setting_ids' => ['ces_100', 'ces_200', 'ces_300']], $this->writes()[1]['body'], 'the ones setup made, besides what it has');
    }

    public function testIdsThatAreNotOpenAisNeverReachARequestPath(): void
    {
        $id = $this->connect();
        foreach (['../ad_account', 'cmpn_1/archive', 'cmpn_1?x=1', '', 'CMPN_1'] as $bad) {
            try {
                $this->campaigns()->setStatus(1, $id, 'campaigns', $bad, 'pause', false);
                self::fail('sent ' . $bad);
            } catch (AdConnectionInputException $e) {
                self::assertSame('id', $e->field);
            }
        }
        self::assertSame([], $this->requests);
    }

    /**
     * What the API answers for each of OpenAI's failures, read and write alike: an object the account does not have
     * is a 404 and a refused key a 422 naming api_key; OpenAI not reached is a 503 and its limit a 429, both with
     * Retry-After, and an answer that is not OpenAI's documented shape a 502, as the connection's own calls answer.
     */
    public function testOpenAisFailuresAnswerAsTheConnectionsDo(): void
    {
        $id = $this->connect();
        $controller = new AdCampaignsController(self::$db, 1, $this->api());
        $calls = [
            'get' => fn () => $controller->get($id, 'campaigns', 'cmpn_101'),
            'pause preview' => fn () => $controller->setStatus($id, 'campaigns', 'cmpn_101', 'pause', [], true),
            'events' => fn () => $controller->attachEventSettings($id, 'cmpn_101', ['event_setting_ids' => ['ces_200']], false),
        ];
        foreach ([[404, 404, null], [401, 422, null], [400, 409, null], [409, 409, null], [429, 429, 60], [503, 503, 60], [500, 503, 60]] as [$openai, $want, $retry]) {
            $this->routes['GET campaigns/cmpn_101'] = ['status' => $openai, 'body' => '{"error":{"message":"said ' . self::KEY . '"}}'];
            foreach ($calls as $what => $call) {
                try {
                    $call();
                    self::fail($what . ': OpenAI\'s ' . $openai . ' was answered as a success');
                } catch (HttpException $e) {
                    self::assertSame($want, $e->getHttpStatus(), $what . ' after OpenAI\'s ' . $openai);
                    self::assertStringNotContainsString(self::KEY, $e->getMessage());
                    $after = $e instanceof TooManyRequestsException || $e instanceof UpstreamUnavailableException ? $e->retryAfter : null;
                    self::assertSame($retry, $after, $what . ' after OpenAI\'s ' . $openai . ': Retry-After');
                    if ($want === 409) {
                        self::assertInstanceOf(ConflictException::class, $e);
                        self::assertSame(['upstream' => 'openai_ads', 'reason' => 'refused'], $e->getDetails(), $what);
                        self::assertStringContainsString('sending it again unchanged will not change the answer', $e->getMessage(), $what);
                    }
                    if ($want === 422) {
                        self::assertInstanceOf(ValidationException::class, $e);
                        self::assertSame(['api_key'], array_keys($e->getFieldErrors()), $what);
                    }
                }
            }
        }
        self::assertSame([], $this->writes(), 'nothing was written after a failed read');

        $this->routes['GET campaigns/cmpn_101'] = ['body' => self::answer('campaign.json')];
        $this->routes['POST campaigns/cmpn_101/pause'] = ['status' => 503, 'body' => '{"error":{"message":"overloaded"}}'];
        try {
            $controller->setStatus($id, 'campaigns', 'cmpn_101', 'pause', [], false);
            self::fail('a pause OpenAI did not take was answered as done');
        } catch (UpstreamUnavailableException $e) {
            self::assertSame([503, 60], [$e->getHttpStatus(), $e->retryAfter]);
        }
        foreach (['GET campaigns' => fn () => $controller->list($id, 'campaigns', []), 'GET ad_account/insights' => fn () => $controller->spend($id, [])] as $route => $call) {
            $this->routes[$route] = ['body' => '{"object":"list"}'];
            try {
                $call();
                self::fail($route . ': an answer with no data was read as an empty account');
            } catch (UpstreamUnavailableException $e) {
                self::assertSame([502, null], [$e->getHttpStatus(), $e->retryAfter], $route);
            }
        }
    }

    /** A write is done only when OpenAI shows it: an answer that does not show it is checked by reading the object. */
    public function testAWriteIsDoneOnlyWhenOpenAiShowsItDone(): void
    {
        $id = $this->connect();
        $cases = [
            'an empty record' => '{}',
            'a record of another object' => '{"id":"cmpn_999","status":"paused"}',
            'the status it had' => '{"id":"cmpn_101","status":"active"}',
        ];
        foreach ($cases as $what => $body) {
            $this->campaign['status'] = 'active';
            // OpenAI answers that, and the campaign is still active when read back: not done.
            $this->routes['POST campaigns/cmpn_101/pause'] = ['body' => $body];
            try {
                $this->campaigns()->setStatus(1, $id, 'campaigns', 'cmpn_101', 'pause', false);
                self::fail($what . ' was answered as a pause');
            } catch (AdPlatformUnavailable $e) {
                self::assertSame(AdPlatformUnavailable::UNCONFIRMED, $e->reason, $what);
                self::assertStringContainsString('cmpn_101 does not show it at OpenAI now (status active', $e->getMessage(), $what);
                self::assertSame('active', $e->context['record']['status'] ?? null, $what);
            }
        }
        // The same answer, with the campaign paused when read back: the read decides, and it is done.
        $this->routes['POST campaigns/cmpn_101/pause'] = function (): array {
            $this->campaign['status'] = 'paused';

            return ['body' => '{}'];
        };
        $this->campaign['status'] = 'active';
        $done = $this->campaigns()->setStatus(1, $id, 'campaigns', 'cmpn_101', 'pause', false);
        self::assertSame(['paused', 'active'], [$done['record']['status'], $done['previous']['status']]);

        // A template push answered without the template is not done either.
        $this->routes['POST campaigns/cmpn_101'] = ['body' => '{"id":"cmpn_101"}'];
        try {
            $this->campaigns()->setTemplate(1, $id, 'campaigns', 'cmpn_101', 'a={ad_id}', false);
            self::fail('a template push OpenAI did not show was answered as done');
        } catch (AdPlatformUnavailable $e) {
            self::assertSame(AdPlatformUnavailable::UNCONFIRMED, $e->reason);
        }
    }

    /**
     * A write whose answer could be a landed write is never answered "try again": a 2xx OpenAI's JSON is not is read
     * back; when the read fails too it is UNCONFIRMED and says it may have been applied (CLAUDE.md #13).
     */
    public function testAnUnreadableAnswerToAWriteIsReadBackNotRetried(): void
    {
        $id = $this->connect();
        $this->routes['POST campaigns/cmpn_101/pause'] = function (): array {
            $this->campaign['status'] = 'paused';

            return ['body' => 'ok'];
        };
        try {
            self::assertSame('paused', $this->campaigns()->setStatus(1, $id, 'campaigns', 'cmpn_101', 'pause', false)['record']['status'], 'read back: done');
        } catch (AdPlatformUnavailable $e) {
            self::fail('a pause that landed, its answer not JSON, was answered ' . $e->reason . ' instead of read back');
        }

        $this->campaign['status'] = 'active';
        $reads = 0;
        $this->routes['GET campaigns/cmpn_101'] = function () use (&$reads): array {
            // The read before the write answers; the read after it does not.
            return ++$reads === 1 ? ['body' => (string) json_encode($this->campaign)] : ['status' => 503, 'body' => '{"error":{"message":"overloaded"}}'];
        };
        try {
            $this->campaigns()->setStatus(1, $id, 'campaigns', 'cmpn_101', 'pause', false);
            self::fail('an unconfirmed pause was answered');
        } catch (AdPlatformUnavailable $e) {
            self::assertSame(AdPlatformUnavailable::UNCONFIRMED, $e->reason, 'not "unreachable": never a 503 to retry');
            self::assertStringContainsString('it may have been applied', $e->getMessage());
            self::assertStringContainsString('before sending it again', $e->getMessage());
        }

        // No answer at all (OpenAI's 500) is a 503, and says the write may have been applied.
        unset($this->routes['GET campaigns/cmpn_101']);
        $this->campaign['status'] = 'active';
        $this->routes['POST campaigns/cmpn_101/pause'] = ['status' => 500, 'body' => '{"error":{"message":"internal"}}'];
        try {
            $this->campaigns()->setStatus(1, $id, 'campaigns', 'cmpn_101', 'pause', false);
            self::fail('a pause OpenAI failed was answered');
        } catch (AdPlatformUnavailable $e) {
            self::assertSame(AdPlatformUnavailable::UNREACHABLE, $e->reason);
            self::assertStringContainsString('It may have been applied all the same', $e->getMessage());
        }
    }

    /** An archived object is not paused or activated, an ended campaign not activated, and one already there is left. */
    public function testAStatusChangeAsksWhatTheObjectIsFirst(): void
    {
        $id = $this->connect();
        $this->campaign['status'] = 'paused';
        $same = $this->campaigns()->setStatus(1, $id, 'campaigns', 'cmpn_101', 'pause', false);
        self::assertSame([true, 'paused'], [$same['unchanged'], $same['record']['status']]);
        self::assertSame([], $this->writes(), 'already paused: nothing sent');

        $this->campaign['end_time'] = time() - 60;
        foreach (['archived' => ['archived', 'pause'], 'ended' => ['paused', 'activate']] as $what => [$status, $action]) {
            $this->campaign['status'] = $status;
            try {
                $this->campaigns()->setStatus(1, $id, 'campaigns', 'cmpn_101', $action, false);
                self::fail($what . ': ' . $action . ' was sent');
            } catch (AdConnectionInputException $e) {
                self::assertSame('id', $e->field, $what);
            }
        }
        self::assertSame([], $this->writes(), 'nothing sent');
    }

    /** A template push answers the template it replaced, in the preview and after. */
    public function testATemplatePushSaysWhatItReplaced(): void
    {
        $id = $this->connect();
        $preview = $this->campaigns()->setTemplate(1, $id, 'campaigns', 'cmpn_101', 'a={ad_id}', true);
        self::assertSame('utm_source=openai&utm_campaign={campaign_id}', $preview['previous']['query_string_template']);
        $done = $this->campaigns()->setTemplate(1, $id, 'campaigns', 'cmpn_101', 'a={ad_id}', false);
        self::assertSame(['a={ad_id}', 'utm_source=openai&utm_campaign={campaign_id}'], [$done['record']['query_string_template'], $done['previous']['query_string_template']]);
    }

    /** OpenAI replaces the list: one it did not answer is not "none", or the write would remove the campaign's own. */
    public function testEventSettingsAreNeverWrittenOverAListThatWasNotRead(): void
    {
        $id = $this->connect();
        foreach (['missing' => null, 'null' => 'null', 'not a list' => '"ces_100"'] as $what => $value) {
            unset($this->campaign['conversion_event_setting_ids']);
            if ($value !== null) {
                $this->campaign['conversion_event_setting_ids'] = json_decode($value);
            }
            try {
                $this->campaigns()->attachEventSettings(1, $id, 'cmpn_101', ['ces_9'], false);
                self::fail($what . ': the list was written over');
            } catch (AdPlatformUnavailable $e) {
                self::assertSame(AdPlatformUnavailable::UNREADABLE, $e->reason, $what);
            }
        }
        self::assertSame([], $this->writes(), 'nothing sent');

        $this->campaign['conversion_event_setting_ids'] = [];
        $this->campaigns()->attachEventSettings(1, $id, 'cmpn_101', ['ces_9'], false);
        self::assertSame([['conversion_event_setting_ids' => ['ces_9']]], array_column($this->writes(), 'body'), 'an answered [] is none');
    }

    /** A spend figure that is not a number, a row naming no campaign, or a cursor that does not move on is refused. */
    public function testSpendIsNeverCountedFromWhatCannotBeRead(): void
    {
        $id = $this->connect();
        $row = ['campaign_id' => 'cmpn_101', 'campaign_name' => 'Spring launch', 'impressions' => 1200, 'clicks' => 36, 'spend' => 18.42];
        $page = static fn (array $rows, bool $more = false, string $last = 'y'): array => ['body' => (string) json_encode(['object' => 'list', 'data' => $rows, 'last_id' => $last, 'has_more' => $more])];
        $bad = [
            'spend as text' => [$page([['spend' => '18.42'] + $row])],
            'spend null' => [$page([['spend' => null] + $row])],
            'impressions as text' => [$page([['impressions' => '1200'] + $row])],
            'no campaign_id' => [$page([array_diff_key($row, ['campaign_id' => 1])])],
            'one campaign twice' => [$page([$row, $row])],
            'a cursor that repeats' => 'repeat',
            'more without a cursor' => [$page([$row], true, '')],
        ];
        foreach ($bad as $what => $pages) {
            $n = 0;
            $this->routes['GET ad_account/insights'] = function () use (&$pages, &$n, $page, $row): array {
                if ($pages === 'repeat') {
                    // A new campaign on every page, and the same cursor every time.
                    return $page([['campaign_id' => 'cmpn_r' . ++$n] + $row], true, 'p1');
                }

                return count($pages) > 1 ? array_shift($pages) : $pages[0];
            };
            try {
                $this->campaigns()->spend(1, $id, null, null, 1777172400);
                self::fail($what . ' was counted');
            } catch (\RuntimeException $e) {
                self::assertInstanceOf(AdPlatformUnavailable::class, $e, $what . ': ' . $e->getMessage());
                self::assertSame(AdPlatformUnavailable::UNREADABLE, $e->reason, $what);
            }
        }
        $pages = [$page([$row], true, 'p1'), $page([['campaign_id' => 'cmpn_102', 'spend' => 3] + $row], false, 'p2')];
        $this->routes['GET ad_account/insights'] = function () use (&$pages): array {
            return array_shift($pages);
        };
        $spend = $this->campaigns()->spend(1, $id, null, null, 1777172400);
        self::assertSame(21.42, $spend['total']['spend'], 'two pages, each counted once; spend in major units');
    }

    public function testSpendIsYesterdayInTheAccountsZoneByCampaign(): void
    {
        $id = $this->connect();
        // 2026-04-26 03:00 UTC is still the 25th in New York.
        $spend = $this->campaigns()->spend(1, $id, null, null, 1777172400);

        self::assertSame(['2026-04-24', '2026-04-24', 'America/New_York', 'USD'], [$spend['since'], $spend['until'], $spend['timezone'], $spend['currency']]);
        $url = rawurldecode($this->requests[0]['url']);
        self::assertStringContainsString('time_ranges[]={"type":"date_range","since":"2026-04-24","until":"2026-04-24"}', $url);
        self::assertStringContainsString('aggregation_level=campaign', $url);
        self::assertSame(['cmpn_101', 'cmpn_102'], array_column($spend['campaigns'], 'campaign_id'));
        self::assertSame(['impressions' => 2180, 'clicks' => 65, 'spend' => 33.28], $spend['total']);

        foreach ([['2026-04-30', '2026-04-20', 'since'], ['2026-02-30', '2026-03-01', 'since'], ['2026-04-01', null, 'until'], ['2026-04-01', '2026-04-27', 'until'], ['2024-01-01', '2026-04-01', 'since']] as [$since, $until, $field]) {
            try {
                $this->campaigns()->spend(1, $id, $since, $until, 1777172400);
                self::fail("$since..$until was read");
            } catch (AdConnectionInputException $e) {
                self::assertSame($field, $e->field, "$since..$until");
            }
        }
    }
}
