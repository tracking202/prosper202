<?php

declare(strict_types=1);

namespace Tests\AdPlatforms;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserAnswer;
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
 * OpenAI's failures sorted for the API.
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
    /** @var array<string, array<string, mixed>> answers by "METHOD path" */
    private array $routes = [];

    protected function setUp(): void
    {
        $this->databaseSetUp();
        self::$db->query('TRUNCATE TABLE 202_ad_connections');
        self::fixture('DELETE FROM 202_users_pref WHERE user_id IN (1, 2)');
        self::fixture("INSERT INTO 202_users_pref SET user_id=1, user_account_currency='USD'");
        $this->requests = [];
        $campaign = self::answer('campaign.json');
        $this->routes = [
            'GET ad_account' => ['body' => self::answer('ad_account.json')],
            'GET campaigns' => ['body' => self::answer('campaigns_list.json')],
            'GET campaigns/cmpn_101' => ['body' => $campaign],
            'POST campaigns/cmpn_101/pause' => ['body' => str_replace('"status": "active"', '"status": "paused"', $campaign)],
            'POST campaigns/cmpn_101' => ['body' => $campaign],
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
            $answer = $this->routes[$route] ?? ['status' => 404, 'body' => '{"error":{"message":"Not found"}}'];

            return $answer + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => '{}'];
        };

        return new AdvertiserApi(new WebhookSender($guard, $transport));
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
        self::assertCount(1, $this->writes(), 'nothing to add: nothing sent');

        try {
            $this->campaigns()->attachEventSettings(1, $id, 'cmpn_101', [], false);
            self::fail('attached nothing without saying so');
        } catch (AdConnectionInputException $e) {
            self::assertStringContainsString('Setup has made no event settings', $e->getMessage());
        }
        (new AdConnectionStore(new Connection(self::$db)))->mergeSetup(1, $id, ['event_settings' => ['lead_created' => ['id' => 'ces_300', 'name' => 'Leads', 'how' => 'created']]], 2);
        $this->campaigns()->attachEventSettings(1, $id, 'cmpn_101', [], false);
        self::assertSame(['conversion_event_setting_ids' => ['ces_100', 'ces_300']], $this->writes()[1]['body'], 'the ones setup made');
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

    public function testOpenAisFailuresAreSortedForTheApi(): void
    {
        $id = $this->connect();
        foreach ([[404, AdvertiserAnswer::NOT_FOUND], [429, AdvertiserAnswer::RATE_LIMITED], [503, AdvertiserAnswer::UNAVAILABLE], [401, AdvertiserAnswer::KEY_REFUSED]] as [$status, $kind]) {
            $this->routes['GET campaigns/cmpn_101'] = ['status' => $status, 'body' => '{"error":{"message":"said ' . self::KEY . '"}}'];
            try {
                $this->campaigns()->get(1, $id, 'campaigns', 'cmpn_101');
                self::fail('a ' . $status . ' was read as an object');
            } catch (AdConnectionInputException $e) {
                self::assertSame($kind, $e->platform);
                self::assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }
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
