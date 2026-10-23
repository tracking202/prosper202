<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;

/**
 * The pixels' same-IP fallback over HTTP, with two accounts, against a
 * running instance: P202_BASE (default http://localhost:8000) with the
 * install's first account's REST key in P202_API_KEY. The suite makes a
 * second account (B) with a key of its own, gives each account a campaign
 * and a direct link, clicks through dl.php and fires gpx.php, upx.php and
 * px.php naming no click, then reads each click's conversions back.
 *
 * What it holds: a pixel credits a guess only for an account with
 * user_pref_same_ip_fallback on, only among the clicks of the account whose
 * campaign the pixel names (gpx.php and upx.php searched the first account's
 * for every account's pixel), never for a visitor the account's privacy
 * setting holds back, and records it as same_ip.
 *
 * Every click comes from this machine. Account A is the first account, the
 * install's privacy floor, so its privacy stays 'disabled' for the run and
 * the hold-back is B's own setting; both accounts' settings are put back.
 *
 * @group integration
 * @group instance
 */
final class SameIpFallbackInstanceTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/120.0 Safari/537.36';

    private static string $base = '';
    private static ?string $setupError = null;
    private static string $run = '';
    /** @var array<string, array{key: string, user: int, campaign: int, acip: string, tracker: string, tag: string}> */
    private static array $accounts = [];
    /** @var array<string, mixed>|null user 1's two settings as found */
    private static ?array $restoreA = null;
    /** @var list<array{string, string}> [key, path] to delete after, newest first */
    private static array $made = [];

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        [$status] = self::request('GET', '/api/v3/system/health', '');
        if ($status === 0) {
            self::$setupError = 'No Prosper202 instance answers at ' . self::$base . ' (set P202_BASE).';
            return;
        }
        $superKey = (string) getenv('P202_API_KEY');
        if ($superKey === '') {
            self::$setupError = "Set P202_API_KEY to the first account's REST key: the suite makes a second account.";
            return;
        }
        $run = self::$run = 'sip' . bin2hex(random_bytes(4));
        try {
            $prefs = self::call($superKey, 'GET', '/users/1/preferences')['data'];
            self::$restoreA = [
                'user_pref_privacy' => $prefs['user_pref_privacy'],
                'user_pref_same_ip_fallback' => (int) $prefs['user_pref_same_ip_fallback'],
            ];
            self::call($superKey, 'PUT', '/users/1/preferences', ['user_pref_privacy' => 'disabled', 'user_pref_same_ip_fallback' => 1]);

            $b = (int) self::call($superKey, 'POST', '/users', [
                'user_name' => "{$run}b", 'user_email' => "{$run}b@example.com", 'user_pass' => "pass-{$run}-b",
            ], 201)['data']['user_id'];
            self::$made[] = [$superKey, "/users/$b"];
            self::call($superKey, 'POST', "/users/$b/roles", ['role_id' => 2]);
            $bKey = (string) self::call($superKey, 'POST', "/users/$b/api-keys", [], 201)['data']['api_key'];
            self::call($superKey, 'PUT', "/users/$b/preferences", ['user_pref_privacy' => 'disabled', 'user_pref_same_ip_fallback' => 1]);

            self::$accounts['A'] = self::setUpAccount($superKey, 1, 'a');
            self::$accounts['B'] = self::setUpAccount($bKey, $b, 'b');
        } catch (\RuntimeException $e) {
            self::$setupError = $e->getMessage();
        }
    }

    /** @return array{key: string, user: int, campaign: int, acip: string, tracker: string, tag: string} */
    private static function setUpAccount(string $key, int $user, string $tag): array
    {
        $run = self::$run;
        $network = (int) self::create($key, 'aff-networks', ['aff_network_name' => "$run $tag network"])['aff_network_id'];
        $campaign = self::create($key, 'campaigns', [
            'aff_campaign_name' => "$run $tag campaign", 'aff_campaign_url' => "https://offer.example/$run$tag?s=[[subid]]",
            'aff_campaign_payout' => '3', 'aff_network_id' => $network,
        ]);
        $source = (int) self::create($key, 'ppc-networks', ['ppc_network_name' => "$run $tag source"])['ppc_network_id'];
        $account = (int) self::create($key, 'ppc-accounts', ['ppc_account_name' => "$run $tag account", 'ppc_network_id' => $source])['ppc_account_id'];
        $tracker = self::create($key, 'trackers', [
            'aff_campaign_id' => (int) $campaign['aff_campaign_id'], 'ppc_account_id' => $account, 'click_cpc' => '0.10',
        ]);

        return [
            'key' => $key, 'user' => $user, 'campaign' => (int) $campaign['aff_campaign_id'],
            'acip' => (string) $campaign['aff_campaign_id_public'], 'tracker' => (string) $tracker['tracker_id_public'],
            'tag' => $tag,
        ];
    }

    public static function tearDownAfterClass(): void
    {
        $superKey = (string) getenv('P202_API_KEY');
        if (self::$restoreA !== null) {
            self::request('PUT', '/api/v3/users/1/preferences', $superKey, self::$restoreA);
        }
        foreach (array_reverse(self::$made) as [$key, $path]) {
            self::request('DELETE', '/api/v3' . $path, $key);
        }
    }

    protected function setUp(): void
    {
        if (self::$setupError !== null) {
            self::fail(self::$setupError);
        }
        foreach (self::$accounts as $account) {
            self::setPrefs($account, ['user_pref_privacy' => 'disabled', 'user_pref_same_ip_fallback' => 1]);
        }
    }

    public function testAClickTheRequestNamesIsMatchedByItsIdOrItsCookie(): void
    {
        $a = self::$accounts['A'];
        self::pixel('gpx.php?subid=%d&amount=1', $click = self::click($a));
        self::assertSame(['click_id'], self::methodsOf($a, $click));
        self::pixel('gpx.php?amount=1', $click = self::click($a), 'tracking202subid=' . $click);
        self::assertSame(['cookie'], self::methodsOf($a, $click));
    }

    public function testAnAccountWithTheFallbackOnHasEachPixelGuessFromTheAddress(): void
    {
        $a = self::$accounts['A'];
        foreach (self::addressOnlyPixels($a) as $pixel) {
            $click = self::click($a);
            self::pixel($pixel, 0);
            self::assertSame(['same_ip'], self::methodsOf($a, $click), "$pixel guessed the latest click from the address");
        }
    }

    public function testAnAccountWithTheFallbackOffHasNoPixelGuess(): void
    {
        $a = self::$accounts['A'];
        self::setPrefs($a, ['user_pref_same_ip_fallback' => 0]);
        foreach (self::addressOnlyPixels($a) as $pixel) {
            $click = self::click($a);
            self::pixel($pixel, 0, null, str_starts_with($pixel, 'upx.php') ? 404 : 200);
            self::assertSame([], self::methodsOf($a, $click), "$pixel guessed nothing: the account has the fallback off");
        }
    }

    /** B's own privacy setting holds its visitor back: no cookie was set, and no address match is made either. */
    public function testAVisitorThePrivacySettingHoldsBackIsNotMatchedByAddress(): void
    {
        $b = self::$accounts['B'];
        self::setPrefs($b, ['user_pref_privacy' => 'all']);
        foreach (self::addressOnlyPixels($b) as $pixel) {
            $click = self::click($b);
            self::pixel($pixel, 0, null, str_starts_with($pixel, 'upx.php') ? 404 : 200);
            self::assertSame([], self::methodsOf($b, $click), "$pixel matched a held-back visitor by address");
        }
    }

    /**
     * B's pixel searches B's clicks: A's click from the same address is
     * later, and is the one the first account's search would have found.
     */
    public function testASecondAccountsPixelGuessesAmongItsOwnClicks(): void
    {
        [$a, $b] = [self::$accounts['A'], self::$accounts['B']];
        foreach (self::addressOnlyPixels($b) as $pixel) {
            $own = self::click($b);
            $first = self::click($a);
            self::pixel($pixel, 0);
            self::assertSame(['same_ip'], self::methodsOf($b, $own), "$pixel credited B's own click");
            self::assertSame([], self::methodsOf($a, $first), "$pixel left the first account's click alone");
        }

        self::setPrefs($b, ['user_pref_same_ip_fallback' => 0]);
        $own = self::click($b);
        $first = self::click($a);
        self::pixel('gpx.php?cid=' . $b['campaign'] . '&amount=1', 0);
        self::assertSame([], self::methodsOf($b, $own), 'B has the fallback off');
        self::assertSame([], self::methodsOf($a, $first), 'and A\'s setting does not reach B\'s pixel');
    }

    /** Two accounts have campaigns: a pixel that names none cannot say whose it is. */
    public function testAPixelThatNamesNoCampaignGuessesNothingWhileTwoAccountsHaveCampaigns(): void
    {
        $a = self::$accounts['A'];
        $click = self::click($a);
        self::pixel('gpx.php?amount=1', 0);
        self::assertSame([], self::methodsOf($a, $click));
    }

    /** @return list<string> */
    private static function addressOnlyPixels(array $account): array
    {
        return [
            'gpx.php?cid=' . $account['campaign'] . '&amount=1',
            'upx.php?cid=' . $account['campaign'] . '&amount=1',
            'px.php?acip=' . $account['acip'],
        ];
    }

    /** @param array<string, mixed> $values */
    private static function setPrefs(array $account, array $values): void
    {
        $superKey = (string) getenv('P202_API_KEY');
        $data = self::call($superKey, 'PUT', '/users/' . $account['user'] . '/preferences', $values)['data'];
        foreach ($values as $column => $value) {
            self::assertSame((string) $value, (string) $data[$column], "user {$account['user']}'s $column");
        }
    }

    /** A click through the account's direct link, as a browser makes it. */
    private static function click(array $account): int
    {
        [$status, $headers, $body] = self::request('GET', '/tracking202/redirect/dl.php?t202id=' . rawurlencode($account['tracker']), '');
        self::assertSame(302, $status, $headers . $body);
        $pattern = '~^Location: https://offer\.example/' . self::$run . $account['tag'] . '\?s=([1-9][0-9]*)\r?$~mi';
        self::assertSame(1, preg_match($pattern, $headers, $m), $headers);

        return (int) $m[1];
    }

    private static function pixel(string $path, int $click, ?string $cookie = null, int $answers = 200): void
    {
        $options = $cookie === null ? [] : [CURLOPT_COOKIE => $cookie];
        [$status, $headers, $body] = self::request('GET', '/tracking202/static/' . sprintf($path, $click), '', null, $options);
        self::assertSame($answers, $status, "$path: $headers$body");
    }

    /** @return list<string> the methods of the click's conversions */
    private static function methodsOf(array $account, int $click): array
    {
        return array_column(self::call($account['key'], 'GET', '/conversions?click_id=' . $click)['data'], 'match_method');
    }

    /** @return array<string, mixed> */
    private static function create(string $key, string $resource, array $body): array
    {
        $data = self::call($key, 'POST', '/' . $resource, $body, 201)['data'];
        $id = $data['aff_network_id'] ?? $data['aff_campaign_id'] ?? $data['ppc_network_id'] ?? $data['ppc_account_id'] ?? $data['tracker_id'] ?? null;
        if ($id !== null) {
            self::$made[] = [$key, "/$resource/$id"];
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private static function call(string $key, string $method, string $path, ?array $body = null, int $expect = 200): array
    {
        [$status, , $response] = self::request($method, '/api/v3' . $path, $key, $body);
        $decoded = json_decode($response, true);
        if ($status !== $expect || !is_array($decoded)) {
            throw new \RuntimeException("$method $path answered $status: " . substr($response, 0, 300));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<int, mixed> $options
     * @return array{0: int, 1: string, 2: string}
     */
    private static function request(string $method, string $path, string $key, ?array $body = null, array $options = []): array
    {
        $ch = curl_init(self::$base . $path);
        $headers = $key === '' ? [] : ['Authorization: Bearer ' . $key, 'Content-Type: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => $headers,
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]) + $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (!is_string($response)) {
            return [0, '', ''];
        }

        return [$status, substr($response, 0, $headerSize), substr($response, $headerSize)];
    }
}
