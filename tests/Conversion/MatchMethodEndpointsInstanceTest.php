<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;

/**
 * How each conversion path found its click, over HTTP against a running
 * instance: P202_BASE (default http://localhost:8000, where the Agent Evals
 * job serves its instance) with the admin's REST key in P202_API_KEY. The
 * suite builds a campaign and a direct link, clicks it through dl.php, and
 * converts each click through a real pixel or postback URL — named by its
 * subid, by a tracking cookie only, or by nothing, so that the endpoint
 * falls back to the latest click from the visitor's address — then reads
 * each conversion's match_method back through GET /conversions and counts
 * them with GET /conversions/match-methods.
 *
 * The endpoints decide the method from their own lookup, which only a
 * request through them exercises; MatchMethodIntegrationTest holds the
 * writers they call to storing what they are handed.
 *
 * The address fallback reads the latest click from the address of the
 * account whose campaign the pixel names (cid on gpx.php and upx.php, acip
 * on px.php), so each such test clicks right before it converts: the click
 * it converts is the latest from this machine's address. The fallback is an
 * account's setting, off for an account made on 1.9.79 (a fresh install's),
 * so the suite turns it on for the run and puts it back
 * (SameIpFallbackInstanceTest holds the setting itself).
 *
 * @group integration
 * @group instance
 */
final class MatchMethodEndpointsInstanceTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/120.0 Safari/537.36';

    private static string $base = '';
    private static string $key = '';
    private static ?string $setupError = null;
    private static string $run = '';
    private static int $campaign = 0;
    private static string $acip = '';
    private static string $tracker = '';
    /** @var array<string, mixed>|null user 1's settings as found */
    private static ?array $restore = null;

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        [$status] = self::request(self::$base . '/api/v3/system/health');
        if ($status === 0) {
            self::$setupError = 'No Prosper202 instance answers at ' . self::$base . ' (set P202_BASE).';
            return;
        }
        self::$key = (string) getenv('P202_API_KEY');
        if (self::$key === '') {
            self::$setupError = "Set P202_API_KEY to the instance admin's REST key: the suite builds what it clicks.";
            return;
        }
        $run = self::$run = 'mm' . bin2hex(random_bytes(4));
        try {
            $prefs = self::get('users/1/preferences')['data'];
            self::$restore = [
                'user_pref_privacy' => $prefs['user_pref_privacy'],
                'user_pref_same_ip_fallback' => (int) $prefs['user_pref_same_ip_fallback'],
            ];
            self::putPrefs(['user_pref_privacy' => 'disabled', 'user_pref_same_ip_fallback' => 1]);
            $network = (int) self::createRow('aff-networks', ['aff_network_name' => "$run network"])['aff_network_id'];
            $campaign = self::createRow('campaigns', [
                'aff_campaign_name' => "$run campaign",
                'aff_campaign_url' => "https://offer.example/$run?s=[[subid]]",
                'aff_campaign_payout' => '3', 'aff_network_id' => $network, 'payout_mode' => 'accumulate',
            ]);
            self::$campaign = (int) $campaign['aff_campaign_id'];
            self::$acip = (string) ($campaign['aff_campaign_id_public'] ?? '');
            $source = (int) self::createRow('ppc-networks', ['ppc_network_name' => "$run source"])['ppc_network_id'];
            $account = (int) self::createRow('ppc-accounts', ['ppc_account_name' => "$run account", 'ppc_network_id' => $source])['ppc_account_id'];
            self::$tracker = (string) (self::createRow('trackers', [
                'aff_campaign_id' => self::$campaign, 'ppc_account_id' => $account, 'click_cpc' => '0.10',
            ])['tracker_id_public'] ?? '');
            if (self::$tracker === '' || self::$acip === '') {
                throw new \RuntimeException('The campaign or the tracker was answered without its public id.');
            }
        } catch (\RuntimeException $e) {
            self::$setupError = $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$restore !== null) {
            self::putPrefs(self::$restore);
        }
    }

    protected function setUp(): void
    {
        if (self::$setupError !== null) {
            self::fail(self::$setupError);
        }
    }

    /** @param array<string, mixed> $values */
    private static function putPrefs(array $values): void
    {
        [$status, , $body] = self::request(self::$base . '/api/v3/users/1/preferences', [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($values, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key, 'Content-Type: application/json'],
        ]);
        if ($status !== 200) {
            throw new \RuntimeException("PUT /users/1/preferences answered $status: " . substr($body, 0, 300));
        }
    }

    public function testThePixelsAndPostbacksSayWhetherTheRequestNamedTheClick(): void
    {
        $bySubid = [
            'gpx' => self::convert('gpx.php?subid=%d&amount=1', self::click()),
            'upx' => self::convert('upx.php?subid=%d&amount=1', self::click()),
            'gpb' => self::convert('gpb.php?subid=%d&amount=1', self::click()),
            'pb' => self::convert('pb.php?acip=' . self::$acip . '&subid=%d&txid=' . self::$run . 'pb', self::click()),
        ];
        foreach ($bySubid as $endpoint => $click) {
            self::assertSame(['click_id'], self::methodsOf($click), "$endpoint.php named the click by its subid");
        }

        $byCookie = [
            'gpx' => self::convert('gpx.php?amount=1', $c = self::click(), 'tracking202subid=' . $c),
            'gpx, the campaign\'s cookie' => self::convert('gpx.php?cid=' . self::$campaign . '&amount=1', $c = self::click(),
                'tracking202subid_a_' . self::$campaign . '=' . $c),
            'upx' => self::convert('upx.php?amount=1', $c = self::click(), 'tracking202subid=' . $c),
            'px' => self::convert('px.php?acip=' . self::$acip, $c = self::click(), 'tracking202subid=' . $c),
        ];
        foreach ($byCookie as $endpoint => $click) {
            self::assertSame(['cookie'], self::methodsOf($click), "$endpoint: only a cookie named the click");
        }
    }

    /** Nothing names a click: each pixel converts the latest click from the address, a guess. */
    public function testAPixelThatIsNamedNoClickGuessesItFromTheAddress(): void
    {
        foreach (['gpx.php?cid=' . self::$campaign . '&amount=1', 'upx.php?cid=' . self::$campaign . '&amount=1', 'px.php?acip=' . self::$acip] as $pixel) {
            $click = self::click();
            self::convert($pixel, 0);
            self::assertSame(['same_ip'], self::methodsOf($click), "$pixel converted the latest click from the address, by a guess");
        }
    }

    public function testTheApiAndTheBreakdownReadTheMethodsBack(): void
    {
        $api = self::click();
        [$status, , $body] = self::request(self::$base . '/api/v3/conversions', [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['click_id' => $api, 'payout' => 2, 'transaction_id' => self::$run . 'api'], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key, 'Content-Type: application/json'],
        ]);
        self::assertSame(201, $status, $body);
        self::assertSame('click_id', json_decode($body, true)['data']['match_method'] ?? null, 'POST /conversions named the click');

        $guessed = self::click();
        self::convert('gpx.php?cid=' . self::$campaign . '&amount=1', 0);
        $listed = self::get('conversions?match_method=same_ip&campaign_id=' . self::$campaign . '&limit=500');
        self::assertContains($guessed, array_map(static fn (array $r): int => (int) $r['click_id'], $listed['data']));
        self::assertSame(['same_ip'], array_values(array_unique(array_column($listed['data'], 'match_method'))));

        $breakdown = self::get('conversions/match-methods?campaign_id=' . self::$campaign);
        $methods = array_column($breakdown['data'], 'match_method');
        foreach (['click_id', 'same_ip'] as $method) {
            self::assertContains($method, $methods, "the campaign's breakdown has a $method row");
        }
        self::assertSame(['click_id', 'cookie', 'visitor_id', 'same_ip', 'unknown'], array_column($breakdown['totals'], 'match_method'));
        foreach ($breakdown['data'] as $row) {
            self::assertSame(self::$campaign, $row['campaign_id']);
        }

        $explained = self::get('clicks/' . $guessed . '/conversions');
        self::assertSame([['same_ip', 'Same IP']], array_map(static fn (array $r): array => [$r['match_method'], $r['match_method_label']], $explained['data']));
    }

    /** A click through the direct link, as a browser makes it; its id is the subid the offer URL carries. */
    private static function click(): int
    {
        [$status, $headers, $body] = self::request(self::$base . '/tracking202/redirect/dl.php?t202id=' . rawurlencode(self::$tracker), [
            CURLOPT_USERAGENT => self::UA,
        ]);
        self::assertSame(302, $status, $headers . $body);
        self::assertSame(1, preg_match('~^Location: https://offer\.example/' . self::$run . '\?s=([1-9][0-9]*)\r?$~mi', $headers, $m), $headers);

        return (int) $m[1];
    }

    /** A pixel or postback, its subid (if its path takes one) the click, with the cookie a browser would send. */
    private static function convert(string $path, int $click, ?string $cookie = null): int
    {
        $options = [CURLOPT_USERAGENT => self::UA];
        if ($cookie !== null) {
            $options[CURLOPT_COOKIE] = $cookie;
        }
        [$status, $headers, $body] = self::request(self::$base . '/tracking202/static/' . sprintf($path, $click), $options);
        self::assertSame(200, $status, "$path: $headers$body");

        return $click;
    }

    /** @return list<string> the methods of the click's conversions */
    private static function methodsOf(int $click): array
    {
        return array_column(self::get('conversions?click_id=' . $click)['data'], 'match_method');
    }

    /** @return array<string, mixed> */
    private static function get(string $path): array
    {
        [$status, , $body] = self::request(self::$base . '/api/v3/' . $path, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key]]);
        self::assertSame(200, $status, "GET /$path: $body");
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, $body);

        return $decoded;
    }

    /**
     * @param array<int, mixed> $options
     * @return array{0: int, 1: string, 2: string}
     */
    private static function request(string $url, array $options = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
        ] + $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (!is_string($response)) {
            return [0, '', ''];
        }

        return [$status, substr($response, 0, $headerSize), substr($response, $headerSize)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> the created row
     */
    private static function createRow(string $resource, array $body): array
    {
        [$status, , $response] = self::request(self::$base . '/api/v3/' . $resource, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key, 'Content-Type: application/json'],
        ]);
        $decoded = json_decode($response, true);
        if ($status !== 201 || !is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new \RuntimeException("POST /$resource answered $status: " . substr($response, 0, 300));
        }

        return $decoded['data'];
    }
}
