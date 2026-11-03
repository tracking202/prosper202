<?php

declare(strict_types=1);

namespace Tests\Conversion\PostbackAuth;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\PostbackAuth\PostbackAuthGate;
use Prosper202\Conversion\PostbackAuth\PostbackAuthStore;
use Prosper202\Conversion\PostbackAuth\PostbackSignature;
use Prosper202\Database\Connection;

/**
 * Postback authentication on a real database: the store (a token minted
 * once and sealed at rest, rotation with and without a grace period, the
 * requirement), and each conversion endpoint run as the web server runs it
 * (fixtures/postback-endpoint-runner.php), refusing before it writes
 * anything and converting once the request authenticates. Two accounts, so
 * that one account's token is shown to open nothing of the other's.
 *
 * @group integration
 */
final class PostbackAuthIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const A = 990301;
    private const B = 990302;
    private const NOW = 1_800_000_000;
    /** Each account's campaign: [id, public id]. */
    private const CAMPAIGN = [self::A => [9030101, 9030101], self::B => [9030201, 9030201]];
    /** Each account's click. */
    private const CLICK = [self::A => 99030111, self::B => 99030211];
    private const CLICK_TABLES = ['202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_click_latest_visits',
        '202_conversion_logs', '202_dataengine'];

    private static int $tx = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        foreach ([self::A, self::B] as $user) {
            [$id, $public] = self::CAMPAIGN[$user];
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $id, aff_campaign_id_public = $public,"
                . " user_id = $user, aff_network_id = 0, aff_campaign_name = 'pbauth $user',"
                . " aff_campaign_url = 'https://shop.example/?s=[[subid]]', aff_campaign_payout = 5.00,"
                . " aff_campaign_foreign_payout = 5.00, aff_campaign_time = 0, payout_mode = 'accumulate'");
            $click = self::CLICK[$user];
            $columns = "click_id = $click, user_id = $user, aff_campaign_id = $id, landing_page_id = 0, ppc_account_id = 0,"
                . ' click_cpc = 0.10, click_payout = 5.00, click_lead = 0, click_time = ' . (self::NOW - 600);
            self::q("INSERT INTO 202_clicks SET $columns, rotator_id = 0, rule_id = 0");
            self::q("INSERT INTO 202_clicks_spy SET $columns");
            self::q("INSERT INTO 202_clicks_tracking SET click_id = $click, c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0");
            LatestVisit::refresh(new Connection(self::$db), $click);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DELETE FROM 202_postback_auth WHERE user_id IN (' . self::A . ',' . self::B . ')');
        $clicks = implode(',', self::CLICK);
        self::q("DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        self::q("DELETE FROM 202_conversion_logs WHERE click_id IN ($clicks)");
        // The click's cached value too: a lead with no ledger row reads as a conversion from before the ledger.
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("UPDATE $table SET click_lead = 0, click_payout = 5.00 WHERE click_id IN ($clicks)");
        }
    }

    private static function cleanUp(): void
    {
        $clicks = implode(',', self::CLICK);
        self::$db->query("DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        foreach (self::CLICK_TABLES as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        $users = self::A . ',' . self::B;
        foreach (['202_aff_campaigns', '202_postback_auth', '202_revenue_events', '202_dirty_hours'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    private static function store(): PostbackAuthStore
    {
        return new PostbackAuthStore(new Connection(self::$db));
    }

    // ── the store ────────────────────────────────────────────────────────

    public function testATokenIsMintedOnceAndSealedAtRest(): void
    {
        self::assertTrue(self::store()->settings(self::A)->required, 'no row: an account made from 1.9.80 on');
        $first = self::store()->ensureToken(self::A, self::NOW);
        self::assertNotNull($first->token);
        self::assertTrue($first->required, 'the row the mint makes keeps the default');
        self::assertSame($first->token, self::store()->ensureToken(self::A, self::NOW + 5)->token, 'asked again, the same token');

        $raw = self::$db->query('SELECT token_sealed FROM 202_postback_auth WHERE user_id = ' . self::A)->fetch_row()[0];
        self::assertStringStartsWith('v1.', $raw);
        self::assertStringNotContainsString((string) $first->token, $raw);

        // A sealed value copied onto another account does not open there.
        self::q('INSERT INTO 202_postback_auth SET user_id = ' . self::B . ', token_required = 1, updated_at = 0,'
            . ' token_sealed = ' . self::str($raw));
        try {
            self::store()->settings(self::B);
            self::fail('another account\'s sealed token opened');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('does not decrypt', $e->getMessage());
        }
    }

    public function testARotationKeepsTheOldTokenForItsGraceAndNoLonger(): void
    {
        $old = (string) self::store()->ensureToken(self::A, self::NOW)->token;
        $rotated = self::store()->rotate(self::A, 3600, self::NOW);
        self::assertNotSame($old, $rotated->token);
        self::assertSame([$rotated->token, $old], $rotated->validTokens(self::NOW + 3599));
        self::assertSame([$rotated->token], $rotated->validTokens(self::NOW + 3600));

        $again = self::store()->rotate(self::A, 3600, self::NOW + 10);
        self::assertSame([$again->token, $rotated->token], $again->validTokens(self::NOW + 11), 'two work at most');

        $now = self::store()->rotate(self::A, 0, self::NOW + 20);
        self::assertSame([$now->token], $now->validTokens(self::NOW + 20), 'no grace: the old token stops at once');
        self::assertNull($now->previousToken);

        $this->expectException(\InvalidArgumentException::class);
        self::store()->rotate(self::A, PostbackAuthStore::MAX_GRACE_SECONDS + 1, self::NOW);
    }

    public function testTheRequirementIsTheAccountsOwn(): void
    {
        self::store()->ensureToken(self::A, self::NOW);
        self::assertFalse(self::store()->setRequired(self::A, false, self::NOW)->required);
        self::assertTrue(self::store()->settings(self::B)->required, 'B is untouched');
        self::assertNotNull(self::store()->settings(self::A)->token, 'the token stays');
        self::assertTrue(self::store()->setRequired(self::A, true, self::NOW)->required);
        self::assertFalse(self::store()->setRequired(self::B, false, self::NOW)->required, 'a row is made for an account without one');
        self::assertNull(self::store()->settings(self::B)->token);
    }

    public function testOneAccountsTokenOpensNothingOfAnothers(): void
    {
        $a = (string) self::store()->ensureToken(self::A, self::NOW)->token;
        self::store()->ensureToken(self::B, self::NOW);
        $gate = PostbackAuthGate::forConnection(new Connection(self::$db));
        self::assertNull($gate->check(self::A, 'gpb', 'subid=1&p202_token=' . $a, self::NOW));
        self::assertSame('token_wrong', $gate->check(self::B, 'gpb', 'subid=1&p202_token=' . $a, self::NOW)?->reason);
        $signedWithA = PostbackSignature::signedQuery('gpb', ['subid' => '1'], $a, self::NOW);
        self::assertSame('signature_wrong', $gate->check(self::B, 'gpb', $signedWithA, self::NOW)?->reason);
        self::assertSame('no_account', $gate->check(0, 'gpb', 'subid=1', self::NOW)?->reason);
    }

    // ── the endpoints ───────────────────────────────────────────────────

    /** @return list<array{0: string, 1: int}> endpoint, and the account whose click it converts */
    public static function serverEndpoints(): array
    {
        return [['gpb', self::B], ['upx', self::B], ['pb', self::B]];
    }

    /** @dataProvider serverEndpoints */
    public function testAServerEndpointRefusesBeforeItWritesAndConvertsOnceAuthenticated(string $endpoint, int $owner): void
    {
        $other = $owner === self::A ? self::B : self::A;
        $mine = (string) self::store()->ensureToken($owner, time())->token;
        $theirs = (string) self::store()->ensureToken($other, time())->token;

        [$status, $body] = self::hit($endpoint, $owner, []);
        self::assertSame(403, $status, $body);
        self::assertStringContainsString('only authenticated postbacks', $body);
        [$status, $body] = self::hit($endpoint, $owner, ['p202_token' => $theirs]);
        self::assertSame(403, $status, 'another account\'s token: ' . $body);
        self::assertStringContainsString('is not this account', $body);
        self::assertSame(0, self::conversions($owner), 'nothing was written');

        [$status, $body] = self::hit($endpoint, $owner, ['p202_token' => $mine]);
        self::assertContains($status, [200, 202], $body);
        self::assertSame(1, self::conversions($owner), 'the authenticated postback converts');

        $signed = self::signed($endpoint, $owner, $mine, time());
        [$status, $body] = self::runQuery($endpoint, $signed);
        self::assertContains($status, [200, 202], 'signed: ' . $body);
        self::assertSame(2, self::conversions($owner));
        [$status, $body] = self::runQuery($endpoint, self::signed($endpoint, $owner, $mine, time() - PostbackSignature::WINDOW - 60));
        self::assertSame(403, $status, $body);
        self::assertStringContainsString('p202_ts is more than', $body);
        [$status] = self::runQuery($endpoint, self::signed($endpoint === 'gpb' ? 'upx' : 'gpb', $owner, $mine, time(), $endpoint));
        self::assertSame(403, $status, 'signed for another endpoint');

        // Off: nothing presented converts, and a wrong token is still refused.
        self::store()->setRequired($owner, false, time());
        [$status, $body] = self::hit($endpoint, $owner, []);
        self::assertContains($status, [200, 202], $body);
        self::assertSame(3, self::conversions($owner));
        [$status] = self::hit($endpoint, $owner, ['p202_token' => $theirs]);
        self::assertSame(403, $status);
        self::assertSame(3, self::conversions($owner));
    }

    public function testTheCampaignPixelRefusesBeforeItWrites(): void
    {
        $mine = (string) self::store()->ensureToken(self::B, time())->token;
        $theirs = (string) self::store()->ensureToken(self::A, time())->token;
        $cookie = ['tracking202subid' => (string) self::CLICK[self::B]];
        $acip = (string) self::CAMPAIGN[self::B][1];

        [$status] = self::runQuery('px', 'acip=' . $acip, $cookie);
        self::assertSame(403, $status);
        [$status] = self::runQuery('px', 'acip=' . $acip . '&p202_token=' . $theirs, $cookie);
        self::assertSame(403, $status);
        self::assertSame(0, self::conversions(self::B));
        [$status, $body] = self::runQuery('px', 'acip=' . $acip . '&p202_token=' . $mine, $cookie);
        self::assertSame(200, $status, $body);
        self::assertSame(1, self::conversions(self::B));
    }

    public function testTheGlobalPixelRefusesSilentlyBeforeItWrites(): void
    {
        $mine = (string) self::store()->ensureToken(self::B, time())->token;
        $theirs = (string) self::store()->ensureToken(self::A, time())->token;
        $click = (string) self::CLICK[self::B];

        foreach (['', '&p202_token=' . $theirs] as $credential) {
            [$status, $body] = self::runQuery('gpx', 'subid=' . $click . $credential);
            self::assertSame(200, $status, 'the image went out first');
            self::assertStringStartsWith('GIF89a', $body);
        }
        self::assertSame(0, self::conversions(self::B));
        self::runQuery('gpx', 'subid=' . $click . '&p202_token=' . $mine . '&txid=gpx-1');
        self::assertSame(1, self::conversions(self::B));
    }

    public function testAFailedReadIsNeverAPass(): void
    {
        self::store()->ensureToken(self::B, time());
        $key = self::$db->query("SELECT secret_value FROM 202_deployment_secrets WHERE secret_name = 'postback_token'")->fetch_row()[0];
        self::q("DELETE FROM 202_deployment_secrets WHERE secret_name = 'postback_token'");
        try {
            [$status, $body] = self::hit('gpb', self::B, []);
            self::assertSame(500, $status, $body);
            self::assertStringContainsString('Failed to check postback authentication', $body);
            self::assertSame(0, self::conversions(self::B));
        } finally {
            self::q("INSERT INTO 202_deployment_secrets SET secret_name = 'postback_token', secret_value = " . self::str($key)
                . ', created_at = 0');
        }
    }

    /** Files deployed and the upgrade not run: no table yet, and the install works as 1.9.79 did. */
    public function testBeforeTheUpgradeRunsThereIsNothingToAuthenticateAgainst(): void
    {
        $versions = array_column(self::$db->query('SELECT version FROM 202_version')->fetch_all(MYSQLI_ASSOC), 'version');
        self::q('RENAME TABLE 202_postback_auth TO 202_postback_auth_aside');
        try {
            self::q('DELETE FROM 202_version');
            self::q("INSERT INTO 202_version SET version = '1.9.80'");
            [$status] = self::hit('gpb', self::B, []);
            self::assertSame(500, $status, 'on a 1.9.80 schema a missing table is a failure');
            self::assertSame(0, self::conversions(self::B));
            self::q("UPDATE 202_version SET version = '1.9.79'");
            [$status, $body] = self::hit('gpb', self::B, []);
            self::assertContains($status, [200, 202], $body);
            self::assertSame(1, self::conversions(self::B));
        } finally {
            self::q('RENAME TABLE 202_postback_auth_aside TO 202_postback_auth');
            self::q('DELETE FROM 202_version');
            foreach ($versions as $version) {
                self::q('INSERT INTO 202_version SET version = ' . self::str((string) $version));
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private static function conversions(int $owner): int
    {
        return (int) self::$db->query('SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id = ' . self::CLICK[$owner])->fetch_row()[0];
    }

    /** @return array<string, string> what the endpoint needs to name the owner's click, with a fresh transaction id */
    private static function naming(string $endpoint, int $owner): array
    {
        $naming = ['subid' => (string) self::CLICK[$owner], 'txid' => 'pbauth-' . ++self::$tx . '-' . bin2hex(random_bytes(3))];
        if ($endpoint === 'pb') {
            $naming = ['acip' => (string) self::CAMPAIGN[$owner][1]] + $naming;
        }

        return $naming;
    }

    /**
     * @param array<string, string> $extra
     * @return array{0: int, 1: string}
     */
    private static function hit(string $endpoint, int $owner, array $extra): array
    {
        return self::runQuery($endpoint, http_build_query(self::naming($endpoint, $owner) + $extra));
    }

    /** A request naming the owner's click as $endpoint names it, signed for $signedFor. */
    private static function signed(string $signedFor, int $owner, string $token, int $at, ?string $endpoint = null): string
    {
        return PostbackSignature::signedQuery($signedFor, self::naming($endpoint ?? $signedFor, $owner), $token, $at);
    }

    /**
     * @param array<string, string> $cookies
     * @return array{0: int, 1: string}
     */
    private static function runQuery(string $endpoint, string $query, array $cookies = []): array
    {
        $env = getenv() + [];
        $env['P202_TEST_QUERY'] = $query;
        $env['P202_TEST_COOKIES'] = json_encode($cookies === [] ? new \stdClass() : $cookies);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/../../StaticEndpoint/fixtures/postback-endpoint-runner.php', $endpoint],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");
        if (preg_match('/\ASTATUS (\d{3})\n(.*)\z/s', $out, $m) !== 1) {
            self::fail("the runner printed no status:\n$out\n$err");
        }

        return [(int) $m[1], $m[2]];
    }
}
