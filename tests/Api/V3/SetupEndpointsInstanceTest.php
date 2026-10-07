<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * The Setup endpoints over HTTP, against a running instance: P202_BASE with
 * the Super user's REST key in P202_API_KEY (the key the installer mints, as
 * the Agent Evals job has it).
 *
 * What a person does with the Setup pages, done through the API and then
 * used the way a visitor and a network use it:
 *
 * - the landing-page code is fetched and its loader's record.php and its
 *   outbound go.php link are requested: the click is recorded on the
 *   landing page and leaves for the offer (click_out);
 * - the postback URL is fetched with a click's id as its sub id and called,
 *   and the click's conversion is read back;
 * - a custom variable is created and the tracker link carries it; a pixel is
 *   created and the conversion's postback answer renders it.
 *
 * And the gates the pages have: a Campaign viewer (no
 * access_to_setup_section) is refused every route, a Campaign manager (who
 * has it) is served but only its own records, and a read-scoped key cannot
 * write. Every refusal is asserted by status and by the guard's sentence.
 *
 * Builds what it needs through the API; the two users it creates are removed
 * after.
 *
 * @group integration
 * @group instance
 */
final class SetupEndpointsInstanceTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    private static string $base = '';
    private static string $key = '';
    private static ?string $setupError = null;
    private static string $run = '';

    /** @var array<string, int> */
    private static array $ids = [];
    /** @var array<string, string> */
    private static array $roleKeys = [];
    /** @var list<int> */
    private static array $users = [];

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        self::$key = (string) getenv('P202_API_KEY');
        if (self::$key === '') {
            self::$setupError = "Set P202_API_KEY to the Super user's REST key (the installer's).";
            return;
        }
        [$status] = self::call('', 'GET', '/system/health');
        if ($status === 0) {
            self::$setupError = 'No Prosper202 instance answers at ' . self::$base . ' (set P202_BASE).';
            return;
        }
        self::$run = 'set' . bin2hex(random_bytes(4));
        $run = self::$run;
        try {
            self::$ids['category'] = self::create('aff-networks', ['aff_network_name' => "$run cat"], 'aff_network_id');
            self::$ids['campaign'] = self::create('campaigns', [
                'aff_campaign_name' => "$run offer", 'aff_campaign_url' => "https://offer.example/$run?s=[[subid]]",
                'aff_campaign_payout' => '3.00', 'aff_network_id' => self::$ids['category'],
            ], 'aff_campaign_id');
            self::$ids['source'] = self::create('ppc-networks', ['ppc_network_name' => "$run source"], 'ppc_network_id');
            self::$ids['account'] = self::create('ppc-accounts', ['ppc_account_name' => "$run account", 'ppc_network_id' => self::$ids['source']], 'ppc_account_id');
            self::$ids['simple'] = self::create('landing-pages', [
                'landing_page_url' => "https://lp.example/$run", 'aff_campaign_id' => self::$ids['campaign'],
                'landing_page_nickname' => "$run simple", 'landing_page_type' => 0,
            ], 'landing_page_id');
            self::$ids['advanced'] = self::create('landing-pages', [
                'landing_page_url' => "https://lp.example/$run-adv", 'aff_campaign_id' => self::$ids['campaign'],
                'landing_page_nickname' => "$run adv", 'landing_page_type' => 1,
            ], 'landing_page_id');
            self::$ids['rotator'] = self::create('rotators', ['name' => "$run split", 'default_url' => "https://offer.example/$run-rotated"], 'id');
            [$status, $body] = self::call(self::$key, 'POST', '/trackers', [
                'aff_campaign_id' => self::$ids['campaign'], 'landing_page_id' => self::$ids['simple'], 'ppc_account_id' => self::$ids['account'],
            ]);
            self::$ids['tracker'] = (int) ($body['data']['tracker_id'] ?? 0);
            self::$ids['tracker_public'] = (int) ($body['data']['tracker_id_public'] ?? 0);
            if ($status !== 201 || self::$ids['tracker_public'] <= 0) {
                throw new \RuntimeException("POST /trackers answered $status: " . json_encode($body));
            }
            [$status, $body] = self::call(self::$key, 'POST', '/trackers', [
                'aff_campaign_id' => self::$ids['campaign'], 'landing_page_id' => self::$ids['advanced'], 'ppc_account_id' => self::$ids['account'],
            ]);
            self::$ids['adv_tracker_public'] = (int) ($body['data']['tracker_id_public'] ?? 0);
            if ($status !== 201 || self::$ids['adv_tracker_public'] <= 0) {
                throw new \RuntimeException("POST /trackers (advanced page) answered $status: " . json_encode($body));
            }

            foreach ([5 => 'viewer', 3 => 'manager', 2 => 'admin'] as $role => $name) {
                [$status, $body] = self::call(self::$key, 'POST', '/users', [
                    'user_name' => "$run$name", 'user_email' => "$run$name@example.com", 'user_pass' => 'pass-' . $run . '-1',
                ]);
                $userId = (int) ($body['data']['user_id'] ?? 0);
                if ($status !== 201 || $userId <= 1) {
                    throw new \RuntimeException("Creating the $name user answered $status: " . json_encode($body));
                }
                self::$users[] = $userId;
                [$status, $body] = self::call(self::$key, 'POST', '/users/' . $userId . '/roles', ['role_id' => $role]);
                if ($status !== 200) {
                    throw new \RuntimeException("Granting role $role answered $status: " . json_encode($body));
                }
                [$status, $body] = self::call(self::$key, 'POST', '/users/' . $userId . '/api-keys', []);
                self::$roleKeys[$name] = (string) ($body['data']['api_key'] ?? '');
                if ($status !== 201 || self::$roleKeys[$name] === '') {
                    throw new \RuntimeException("Minting the $name key answered $status: " . json_encode($body));
                }
            }
        } catch (\RuntimeException $e) {
            self::$setupError = $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$users as $userId) {
            self::call(self::$key, 'DELETE', '/users/' . $userId);
        }
    }

    protected function setUp(): void
    {
        if (self::$setupError !== null) {
            self::fail(self::$setupError);
        }
    }

    public function testTheLandingPageCodeTracksAVisitorThroughToTheOffer(): void
    {
        [$status, $body] = self::call(self::$key, 'GET', '/landing-pages/' . self::$ids['simple'] . '/code');
        $this->assertSame(200, $status, json_encode($body));
        $code = $body['data'];
        $this->assertSame('simple', $code['landing_page_type']);
        $this->assertMatchesRegularExpression('#^//[^/]+/#', $code['outbound_link'], 'scheme-relative, as the page writes it');
        $this->assertSame(1, preg_match('#load\("(//[^"]+landing\.php\?lpip=(\d+)&t202id=)"#', $code['loader'], $m), 'the loader loads landing.php for this page');
        $lpip = $m[2];
        $this->assertSame((string) $code['landing_page_id_public'], $lpip);

        // The visitor arrives from the tracker link; the loader runs, and the
        // script it serves calls record.php, which records the click.
        [$status] = self::fetch('http:' . $m[1] . self::$ids['tracker_public']);
        $this->assertSame(200, $status, 'landing.php serves the loader');
        $keyword = self::$run . 'kw';
        [$status, , $cookies] = self::fetch(self::$base . "/tracking202/static/record.php?lpip=$lpip&t202id=" . self::$ids['tracker_public'] . "&t202kw=$keyword&referer=" . rawurlencode("https://lp.example/" . self::$run));
        $this->assertSame(200, $status, 'record.php records the visit');
        $click = $this->newestClick();
        $this->assertSame(self::$ids['simple'], (int) $click['landing_page_id']);
        $this->assertSame($keyword, $click['keyword']);
        $this->assertSame(0, (int) $click['click_out']);
        $subidCookie = 'tracking202subid_a_' . self::$ids['campaign'];
        $this->assertSame((string) $click['click_id'], $cookies[$subidCookie] ?? null, 'record.php hands the browser the click it recorded');

        // The visitor follows the code's outbound link, carrying that cookie
        // as a browser would.
        [$status, $headers] = self::fetch('http:' . $code['outbound_link'], [$subidCookie => (string) $click['click_id']]);
        $this->assertSame(302, $status, 'go.php sends the visitor on');
        $this->assertStringStartsWith('https://offer.example/' . self::$run . '?s=', $headers['location'] ?? '', 'to the campaign\'s offer');
        $this->assertSame(1, (int) $this->click((int) $click['click_id'])['click_out'], 'and records that the click left for it');
    }

    public function testAnAdvancedPagesOffersLeadToTheirOwnDestinations(): void
    {
        [$status, $body] = self::call(self::$key, 'GET', '/landing-pages/' . self::$ids['advanced'] . '/code?offers=' . rawurlencode('rotator:' . self::$ids['rotator'] . ',campaign:' . self::$ids['campaign']));
        $this->assertSame(200, $status, json_encode($body));
        [$rotator, $campaign] = $body['data']['offers'];
        $this->assertSame(['rotator', 'campaign'], [$rotator['type'], $campaign['type']]);

        // The visitor's click on the advanced page, recorded by its loader.
        $lpip = (string) $body['data']['landing_page_id_public'];
        [$status, , $cookies] = self::fetch(self::$base . "/tracking202/static/record.php?lpip=$lpip&t202id=" . self::$ids['adv_tracker_public'] . '&t202kw=' . self::$run . 'adv');
        $this->assertSame(200, $status);
        $click = (string) ($cookies['tracking202subid'] ?? '');
        $this->assertMatchesRegularExpression('/^\d+$/', $click, 'record.php hands the browser the click it recorded');
        $this->assertSame(self::$ids['advanced'], (int) $this->click((int) $click)['landing_page_id']);

        // Each offer's outbound link sends that visitor to its own destination:
        // off.php for the campaign, offrtr.php for the redirector. A browser
        // that kept the click cookie, and one that did not (third-party
        // cookies blocked: off.php finds the click by IP) — the second
        // answered 500 until off.php's mktime() got integers.
        [$status, $headers] = self::fetch('http:' . $campaign['outbound_link'], ['tracking202subid' => $click]);
        $this->assertSame(302, $status, 'go.php?acip= sends the visitor on');
        $this->assertStringStartsWith('https://offer.example/' . self::$run . '?s=', $headers['location'] ?? '');
        [$status, $headers] = self::fetch('http:' . $campaign['outbound_link']);
        $this->assertSame(302, $status, 'go.php?acip= sends a visitor without the cookie on');
        $this->assertStringStartsWith('https://offer.example/' . self::$run . '?s=', $headers['location'] ?? '');
        [$status, $headers] = self::fetch('http:' . $rotator['outbound_link'], ['tracking202subid' => $click]);
        $this->assertSame(302, $status, 'go.php?rpi= sends the visitor on');
        $this->assertStringStartsWith('https://offer.example/' . self::$run . '-rotated', $headers['location'] ?? '');

        [$status, $body] = self::call(self::$key, 'GET', '/landing-pages/' . self::$ids['advanced'] . '/code');
        $this->assertSame(422, $status);
        $this->assertSame('Please select an affiliate campaign or rotator', $body['message'], 'the page\'s own sentence');
    }

    public function testThePostbackUrlRecordsTheConversion(): void
    {
        // A click to convert, through the tracker's landing page.
        self::fetch(self::$base . '/tracking202/static/record.php?lpip=' . $this->lpip() . '&t202id=' . self::$ids['tracker_public'] . '&t202kw=' . self::$run . 'pb');
        $click = (int) $this->newestClick()['click_id'];

        // A pixel on the account, made through the API: the postback's answer
        // renders it.
        [$status, $pixel] = self::call(self::$key, 'POST', '/ppc-accounts/' . self::$ids['account'] . '/pixels', ['pixel_type_id' => 1, 'pixel_code' => 'https://ads.example/' . self::$run . '.gif?c=[[subid]]']);
        $this->assertSame(201, $status, json_encode($pixel));

        [$status, $body] = self::call(self::$key, 'GET', '/conversions/postback-code?amount=4.25&subid=' . $click);
        $this->assertSame(200, $status, json_encode($body));
        $url = $body['data']['simple']['postback_url'];
        $this->assertStringEndsWith('/tracking202/static/gpb.php?amount=4.25&subid=' . $click, $url);
        [$status, , , $answer] = self::fetch($url);
        $this->assertContains($status, [200, 202], $answer);
        $this->assertStringContainsString("<img src='https://ads.example/" . self::$run . ".gif?c=$click'", $answer, 'the account\'s API-made pixel fired');

        [$status, $conversions] = self::call(self::$key, 'GET', '/clicks/' . $click . '/conversions');
        $this->assertSame(200, $status, json_encode($conversions));
        $this->assertCount(1, $conversions['data']);
        $this->assertSame('4.25000', $conversions['data'][0]['amount']);
        $this->assertSame('postback', $conversions['data'][0]['source']);

        [$status] = self::call(self::$key, 'DELETE', '/ppc-accounts/' . self::$ids['account'] . '/pixels/' . $pixel['data']['pixel_id']);
        $this->assertSame(204, $status);
    }

    public function testAVariableReachesTheTrackingLinkAndLeavesIt(): void
    {
        $source = self::$ids['source'];
        [$status, $created] = self::call(self::$key, 'POST', "/ppc-networks/$source/variables", ['name' => 'Ad', 'parameter' => 'adid', 'placeholder' => '{ad_id}']);
        $this->assertSame(201, $status, json_encode($created));
        $id = $created['data']['ppc_variable_id'];
        $this->assertStringContainsString('&adid={ad_id}&', $this->trackerParams());

        [$status, $updated] = self::call(self::$key, 'PUT', "/ppc-networks/$source/variables/$id", ['placeholder' => '{{ad.id}}']);
        $this->assertSame(200, $status, json_encode($updated));
        $this->assertStringContainsString('&adid={{ad.id}}&', $this->trackerParams());

        [$status, $preview] = self::call(self::$key, 'DELETE', "/ppc-networks/$source/variables/$id?dry_run=1");
        $this->assertSame(200, $status, json_encode($preview));
        $this->assertStringContainsString('adid', $this->trackerParams(), 'a dry run removes nothing');
        [$status] = self::call(self::$key, 'DELETE', "/ppc-networks/$source/variables/$id");
        $this->assertSame(204, $status);
        $this->assertStringNotContainsString('adid', $this->trackerParams());

        // Staged, then applied through the real route.
        [$status, $staged] = self::call(self::$key, 'POST', "/ppc-networks/$source/variables?staged=1", ['name' => 'Kw', 'parameter' => 'kw', 'placeholder' => '{keyword}']);
        $this->assertSame(202, $status, json_encode($staged));
        $this->assertStringNotContainsString('kw={keyword}', $this->trackerParams(), 'a proposal changes nothing');
        [$status, $applied] = self::call(self::$key, 'POST', '/staged-changes/' . $staged['data']['change_id'] . '/apply');
        $this->assertSame(200, $status, json_encode($applied));
        $this->assertStringContainsString('&kw={keyword}&', $this->trackerParams());
    }

    public function testTheSetupSectionPermissionGatesEveryRoute(): void
    {
        $source = self::$ids['source'];
        $account = self::$ids['account'];
        // The variables dialog opens only for remove_traffic_source as well
        // (ppc_accounts.php), and a refusal names the narrower permission.
        $variables = [
            ['GET', "/ppc-networks/$source/variables", null],
            ['POST', "/ppc-networks/$source/variables", ['name' => 'x', 'parameter' => 'x', 'placeholder' => 'x']],
            ['PUT', "/ppc-networks/$source/variables/1", ['name' => 'x']],
            ['DELETE', "/ppc-networks/$source/variables/1?dry_run=1", null],
            ['DELETE', "/ppc-networks/$source/variables/1", null],
            ['POST', "/ppc-networks/$source/variables?staged=1", ['name' => 'x', 'parameter' => 'x', 'placeholder' => 'x']],
        ];
        $section = [
            ['GET', '/landing-pages/' . self::$ids['simple'] . '/code', null],
            ['GET', '/conversions/postback-code', null],
            ['GET', "/ppc-accounts/$account/pixels", null],
            ['POST', "/ppc-accounts/$account/pixels", ['pixel_type_id' => 1, 'pixel_code' => 'https://x.example/']],
            ['PUT', "/ppc-accounts/$account/pixels/1", ['pixel_code' => 'https://x.example/']],
            ['DELETE', "/ppc-accounts/$account/pixels/1?dry_run=1", null],
            ['DELETE', "/ppc-accounts/$account/pixels/1", null],
            ['POST', "/ppc-accounts/$account/pixels?staged=1", ['pixel_type_id' => 1, 'pixel_code' => 'https://x.example/']],
        ];
        $refusals = [];
        foreach ($variables as $route) {
            $refusals[] = ['viewer', 'remove_traffic_source', $route];
            $refusals[] = ['manager', 'remove_traffic_source', $route];
        }
        foreach ($section as $route) {
            $refusals[] = ['viewer', 'access_to_setup_section', $route];
        }
        foreach ($refusals as [$role, $permission, [$method, $path, $body]]) {
            [$status, $answer] = self::call(self::$roleKeys[$role], $method, $path, $body);
            $this->assertSame(403, $status, "$role: $method $path " . json_encode($answer));
            $this->assertStringContainsString("'$permission' permission", (string) ($answer['message'] ?? ''), "$role: $method $path");
        }

        // A Campaign manager has the Setup section, for its own account.
        [$status, $answer] = self::call(self::$roleKeys['manager'], 'GET', '/conversions/postback-code?subid=%7Baff_sub%7D');
        $this->assertSame(200, $status, json_encode($answer));
        [$status, $answer] = self::call(self::$roleKeys['manager'], 'GET', '/landing-pages/' . self::$ids['simple'] . '/code');
        $this->assertSame(404, $status, 'another account\'s landing page: ' . json_encode($answer));
        $this->assertSame('Landing page ' . self::$ids['simple'] . ' not found', $answer['message']);
        [$status, $answer] = self::call(self::$roleKeys['manager'], 'GET', "/ppc-accounts/$account/pixels");
        $this->assertSame(404, $status, json_encode($answer));
        $this->assertSame("Traffic source account $account not found", $answer['message']);
        // An Admin has both, for its own account.
        [$status, $answer] = self::call(self::$roleKeys['admin'], 'POST', "/ppc-networks/$source/variables", ['name' => 'x', 'parameter' => 'x', 'placeholder' => 'x']);
        $this->assertSame(404, $status, json_encode($answer));
        $this->assertSame("Traffic source $source not found", $answer['message']);
        [$status, $answer] = self::call(self::$roleKeys['admin'], 'GET', "/ppc-networks/$source/variables");
        $this->assertSame(404, $status, json_encode($answer));
    }

    public function testAReadScopedKeyReadsTheCodeAndCannotWrite(): void
    {
        [, $body] = self::call(self::$key, 'GET', '/users');
        $owner = (int) ($body['data'][0]['user_id'] ?? 0);
        [$status, $body] = self::call(self::$key, 'POST', '/users/' . $owner . '/api-keys', ['scope' => 'read']);
        $this->assertSame(201, $status, json_encode($body));
        $readKey = (string) $body['data']['api_key'];
        try {
            [$status, $answer] = self::call($readKey, 'GET', '/landing-pages/' . self::$ids['simple'] . '/code');
            $this->assertSame(200, $status, json_encode($answer));
            [$status, $answer] = self::call($readKey, 'POST', '/ppc-networks/' . self::$ids['source'] . '/variables', ['name' => 'x', 'parameter' => 'x', 'placeholder' => 'x']);
            $this->assertSame(403, $status, json_encode($answer));
            $this->assertStringContainsString("requires 'ppc-networks:write'", (string) $answer['message']);
            [$status, $answer] = self::call($readKey, 'POST', '/ppc-accounts/' . self::$ids['account'] . '/pixels', ['pixel_type_id' => 1, 'pixel_code' => 'https://x.example/']);
            $this->assertSame(403, $status, json_encode($answer));
            $this->assertStringContainsString("requires 'ppc-accounts:write'", (string) $answer['message']);
        } finally {
            self::call(self::$key, 'DELETE', '/users/' . $owner . '/api-keys/' . $readKey);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function lpip(): string
    {
        [, $body] = self::call(self::$key, 'GET', '/landing-pages/' . self::$ids['simple']);
        return (string) $body['data']['landing_page_id_public'];
    }

    private function trackerParams(): string
    {
        [$status, $body] = self::call(self::$key, 'GET', '/trackers/' . self::$ids['tracker'] . '/url');
        $this->assertSame(200, $status, json_encode($body));
        return $body['data']['tracking_params'] . '&';
    }

    /** @return array<string, mixed> the newest click on the run's landing page */
    private function newestClick(): array
    {
        [$status, $body] = self::call(self::$key, 'GET', '/clicks?limit=500&landing_page_id=' . self::$ids['simple']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertNotEmpty($body['data'], 'a click was recorded on the landing page');
        $ids = array_map('intval', array_column($body['data'], 'click_id'));
        return $this->click(max($ids));
    }

    /** @return array<string, mixed> */
    private function click(int $id): array
    {
        [$status, $body] = self::call(self::$key, 'GET', '/clicks/' . $id);
        $this->assertSame(200, $status, json_encode($body));
        return $body['data'];
    }

    /**
     * A visitor's request: no redirect followed, the given cookies sent.
     *
     * @param array<string, string> $cookies
     * @return array{0: int, 1: array<string, string>, 2: array<string, string>, 3: string} status, headers (lower-cased), cookies set, body
     */
    private static function fetch(string $url, array $cookies = []): array
    {
        $headers = [];
        $set = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$set): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = array_map('trim', explode(':', $line, 2));
                    $headers[strtolower($name)] = $value;
                    if (strtolower($name) === 'set-cookie' && preg_match('/^([^=;]+)=([^;]*)/', $value, $c) === 1) {
                        $set[$c[1]] = urldecode($c[2]);
                    }
                }
                return strlen($line);
            },
        ]);
        if ($cookies !== []) {
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', array_map(static fn (string $k, string $v): string => "$k=$v", array_keys($cookies), $cookies)));
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $headers, $set, is_string($body) ? $body : ''];
    }

    /** @param array<string, mixed> $body */
    private static function create(string $resource, array $body, string $idField): int
    {
        [$status, $answer] = self::call(self::$key, 'POST', '/' . $resource, $body);
        $id = (int) ($answer['data'][$idField] ?? 0);
        if ($status !== 201 || $id <= 0) {
            throw new \RuntimeException("POST /$resource answered $status: " . json_encode($answer));
        }
        return $id;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function call(string $key, string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        $headers = ['Content-Type: application/json'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response)) {
            return [0, []];
        }
        $decoded = $response === '' ? [] : json_decode($response, true);

        return [$status, is_array($decoded) ? $decoded : ['raw' => $response]];
    }
}
