<?php

declare(strict_types=1);

namespace Tests\Conversion\PostbackAuth;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\PostbackAuth\PostbackAuthGate;
use Prosper202\Conversion\PostbackAuth\PostbackAuthMode;
use Prosper202\Conversion\PostbackAuth\PostbackAuthSettings;
use Prosper202\Conversion\PostbackAuth\PostbackCredential;
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

    public function testTheCredentialsAreMintedOnceAndSealedAtRest(): void
    {
        self::assertSame(PostbackAuthMode::TOKEN, self::store()->settings(self::A)->mode, 'no row: an account made from 1.9.80 on');
        $first = self::store()->ensureCredentials(self::A, self::NOW);
        self::assertNotNull($first->token);
        self::assertNotNull($first->secret);
        self::assertNotSame($first->token, $first->secret);
        self::assertSame(PostbackAuthMode::TOKEN, $first->mode, 'the row the mint makes keeps the default');
        $again = self::store()->ensureCredentials(self::A, self::NOW + 5);
        self::assertSame([$first->token, $first->secret], [$again->token, $again->secret], 'asked again, the same ones');

        [$token, $secret] = self::$db->query('SELECT token_sealed, secret_sealed FROM 202_postback_auth WHERE user_id = ' . self::A)->fetch_row();
        foreach ([$token, $secret] as $raw) {
            self::assertStringStartsWith('v1.', $raw);
            self::assertStringNotContainsString((string) $first->token, $raw);
            self::assertStringNotContainsString((string) $first->secret, $raw);
        }

        // A sealed value copied onto another account, or into the other credential's column, does not open.
        foreach ([[self::B, 'token_sealed', $token], [self::A, 'secret_sealed', $token]] as [$user, $column, $raw]) {
            self::q("DELETE FROM 202_postback_auth WHERE user_id = " . self::B);
            if ($user === self::B) {
                self::q('INSERT INTO 202_postback_auth SET user_id = ' . self::B . ", auth_mode = 'token', updated_at = 0, $column = " . self::str($raw));
            } else {
                self::q("UPDATE 202_postback_auth SET $column = " . self::str($raw) . ' WHERE user_id = ' . self::A);
            }
            try {
                self::store()->settings($user);
                self::fail("a sealed value moved into $column of account $user opened");
            } catch (\PHPUnit\Framework\AssertionFailedError $f) {
                throw $f;
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('does not decrypt', $e->getMessage());
            }
        }
    }

    public function testARotationKeepsTheOldOneForItsGraceAndNoLonger(): void
    {
        foreach ([PostbackCredential::TOKEN, PostbackCredential::SIGNING_SECRET] as $credential) {
            $valid = static fn (PostbackAuthSettings $s, int $at): array => $credential === PostbackCredential::TOKEN
                ? $s->validTokens($at) : $s->validSecrets($at);
            $current = static fn (PostbackAuthSettings $s): ?string => $credential === PostbackCredential::TOKEN ? $s->token : $s->secret;
            $start = self::store()->ensureCredentials(self::A, self::NOW);
            $old = (string) $current($start);
            $other = $credential === PostbackCredential::TOKEN ? $start->secret : $start->token;
            $rotated = self::store()->rotate(self::A, $credential, 3600, self::NOW);
            self::assertNotSame($old, $current($rotated));
            self::assertSame($other, $credential === PostbackCredential::TOKEN ? $rotated->secret : $rotated->token,
                $credential->value . ': the other credential is untouched');
            self::assertSame([$current($rotated), $old], $valid($rotated, self::NOW + 3599));
            self::assertSame([$current($rotated)], $valid($rotated, self::NOW + 3600));

            $again = self::store()->rotate(self::A, $credential, 3600, self::NOW + 10);
            self::assertSame([$current($again), $current($rotated)], $valid($again, self::NOW + 11), 'two work at most');

            $now = self::store()->rotate(self::A, $credential, 0, self::NOW + 20);
            self::assertSame([$current($now)], $valid($now, self::NOW + 20), 'no grace: the old one stops at once');
        }

        $this->expectException(\InvalidArgumentException::class);
        self::store()->rotate(self::A, PostbackCredential::TOKEN, PostbackAuthStore::MAX_GRACE_SECONDS + 1, self::NOW);
    }

    public function testTheModeIsTheAccountsOwn(): void
    {
        self::store()->ensureCredentials(self::A, self::NOW);
        foreach (PostbackAuthMode::cases() as $mode) {
            self::assertSame($mode, self::store()->setMode(self::A, $mode, self::NOW)->mode);
            self::assertSame(PostbackAuthMode::TOKEN, self::store()->settings(self::B)->mode, 'B is untouched');
        }
        self::assertNotNull(self::store()->settings(self::A)->token, 'the credentials stay');
        self::assertSame(PostbackAuthMode::OFF, self::store()->setMode(self::B, PostbackAuthMode::OFF, self::NOW)->mode, 'a row is made for an account without one');
        self::assertNotNull(self::store()->settings(self::B)->token, 'with the credentials, in the same transaction');
        self::assertSame(PostbackAuthMode::OFF, self::store()->mode(self::B));

        self::q("UPDATE 202_postback_auth SET auth_mode = 'maybe' WHERE user_id = " . self::A);
        $this->expectException(\UnexpectedValueException::class);
        self::store()->settings(self::A);
    }

    public function testOneAccountsCredentialsOpenNothingOfAnothers(): void
    {
        $a = self::store()->ensureCredentials(self::A, self::NOW);
        self::store()->ensureCredentials(self::B, self::NOW);
        $gate = PostbackAuthGate::forConnection(new Connection(self::$db));
        self::assertNull($gate->check(self::A, 'gpb', 'subid=1&p202_token=' . $a->token, self::NOW));
        self::assertSame('token_wrong', $gate->check(self::B, 'gpb', 'subid=1&p202_token=' . $a->token, self::NOW)?->reason);
        $signedWithA = PostbackSignature::signedQuery('gpb', ['subid' => '1'], (string) $a->secret, self::NOW);
        self::assertNull($gate->check(self::A, 'gpb', $signedWithA, self::NOW));
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
        $own = self::store()->ensureCredentials($owner, time());
        $mine = (string) $own->token;
        $secret = (string) $own->secret;
        $theirs = (string) self::store()->ensureCredentials($other, time())->token;

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

        $signed = self::signed($endpoint, $owner, $secret, time());
        [$status, $body] = self::runQuery($endpoint, $signed);
        self::assertContains($status, [200, 202], 'signed: ' . $body);
        self::assertSame(2, self::conversions($owner));
        [$status, $body] = self::runQuery($endpoint, self::signed($endpoint, $owner, $secret, time() - PostbackSignature::WINDOW - 60));
        self::assertSame(403, $status, $body);
        self::assertStringContainsString('p202_ts is more than', $body);
        [$status] = self::runQuery($endpoint, self::signed($endpoint === 'gpb' ? 'upx' : 'gpb', $owner, $secret, time(), $endpoint));
        self::assertSame(403, $status, 'signed for another endpoint');
        [$status, $body] = self::runQuery($endpoint, self::signed($endpoint, $owner, $mine, time()));
        self::assertSame(403, $status, 'keyed with the URL token, not the signing secret: ' . $body);
        self::assertStringContainsString('signing secret', $body);
        self::assertSame(2, self::conversions($owner));

        // Signed only: the token alone is refused, a signature converts.
        self::store()->setMode($owner, PostbackAuthMode::SIGNED, time());
        [$status, $body] = self::hit($endpoint, $owner, ['p202_token' => $mine]);
        self::assertSame(403, $status, 'the token alone, signed only: ' . $body);
        self::assertStringContainsString('only signed postbacks', $body);
        [$status, $body] = self::hit($endpoint, $owner, []);
        self::assertSame(403, $status, $body);
        self::assertSame(2, self::conversions($owner));
        [$status, $body] = self::runQuery($endpoint, self::signed($endpoint, $owner, $secret, time()));
        self::assertContains($status, [200, 202], 'signed, signed only: ' . $body);
        self::assertSame(3, self::conversions($owner));

        // Off: nothing presented converts, and a wrong token is still refused.
        self::store()->setMode($owner, PostbackAuthMode::OFF, time());
        [$status, $body] = self::hit($endpoint, $owner, []);
        self::assertContains($status, [200, 202], $body);
        self::assertSame(4, self::conversions($owner));
        [$status] = self::hit($endpoint, $owner, ['p202_token' => $theirs]);
        self::assertSame(403, $status);
        self::assertSame(4, self::conversions($owner));
    }

    public function testTheCampaignPixelRefusesBeforeItWrites(): void
    {
        $mine = (string) self::store()->ensureCredentials(self::B, time())->token;
        $theirs = (string) self::store()->ensureCredentials(self::A, time())->token;
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

        // Signed only: a pixel cannot sign, and the refusal says so.
        self::store()->setMode(self::B, PostbackAuthMode::SIGNED, time());
        [$status, $body] = self::runQuery('px', 'acip=' . $acip . '&p202_token=' . $mine . '&txid=px-2', $cookie);
        self::assertSame(403, $status, $body);
        self::assertStringContainsString('cannot sign, so px.php is refused', $body);
        self::assertSame(1, self::conversions(self::B));
    }

    public function testTheGlobalPixelRefusesSilentlyBeforeItWrites(): void
    {
        $mine = (string) self::store()->ensureCredentials(self::B, time())->token;
        $theirs = (string) self::store()->ensureCredentials(self::A, time())->token;
        $click = (string) self::CLICK[self::B];

        foreach (['', '&p202_token=' . $theirs] as $credential) {
            [$status, $body] = self::runQuery('gpx', 'subid=' . $click . $credential);
            self::assertSame(200, $status, 'the image went out first');
            self::assertStringStartsWith('GIF89a', $body);
        }
        self::assertSame(0, self::conversions(self::B));
        self::runQuery('gpx', 'subid=' . $click . '&p202_token=' . $mine . '&txid=gpx-1');
        self::assertSame(1, self::conversions(self::B));

        self::store()->setMode(self::B, PostbackAuthMode::SIGNED, time());
        [$status, $body] = self::runQuery('gpx', 'subid=' . $click . '&p202_token=' . $mine . '&txid=gpx-2');
        self::assertStringStartsWith('GIF89a', $body);
        self::assertSame(1, self::conversions(self::B), 'signed only: the pixel\'s token alone converts nothing');
    }

    public function testAMissingKeyFailsClosedUntilARotationMakesNewCredentials(): void
    {
        $old = self::store()->ensureCredentials(self::B, time());
        self::store()->ensureCredentials(self::A, time());
        self::store()->setMode(self::A, PostbackAuthMode::OFF, time());
        $key = self::key();
        self::q("DELETE FROM 202_deployment_secrets WHERE secret_name = 'postback_token'");
        try {
            // Token mode: the old token is refused with 500, never a pass; nothing presented is a 403, decided by the mode alone.
            [$status, $body] = self::hit('gpb', self::B, ['p202_token' => (string) $old->token]);
            self::assertSame(500, $status, $body);
            self::assertStringContainsString('Failed to check postback authentication', $body);
            [$status, $body] = self::hit('gpb', self::B, []);
            self::assertSame(403, $status, $body);
            self::assertSame(0, self::conversions(self::B));
            // Off mode keeps accepting a postback without credentials.
            [$status, $body] = self::hit('gpb', self::A, []);
            self::assertContains($status, [200, 202], 'off, key missing: ' . $body);
            self::assertSame(1, self::conversions(self::A));
            self::assertSame(PostbackAuthMode::TOKEN, self::store()->mode(self::B), 'the mode reads without the key');

            $rotated = self::store()->rotate(self::B, PostbackCredential::TOKEN, 3600, time());
            self::assertSame([PostbackCredential::TOKEN, PostbackCredential::SIGNING_SECRET], $rotated->replacedUnreadable);
            self::assertTrue($rotated->keyRecreated);
            self::assertStringContainsString('stopped working', (string) $rotated->recoveryNotice());
            self::assertNotSame($old->token, $rotated->token);
            self::assertNotSame($old->secret, $rotated->secret);
            self::assertNull($rotated->previousTokenValidUntil(time()), 'an old token that cannot be read is not kept');
            self::assertNotSame($key, self::key(), 'a new key');

            [$status, $body] = self::hit('gpb', self::B, ['p202_token' => (string) $rotated->token]);
            self::assertContains($status, [200, 202], 'the new token: ' . $body);
            self::assertSame(1, self::conversions(self::B));
            [$status] = self::hit('gpb', self::B, ['p202_token' => (string) $old->token]);
            self::assertSame(403, $status, 'the old token stopped working');
            self::assertSame(1, self::conversions(self::B));
            [$status, $body] = self::hit('gpb', self::A, []);
            self::assertContains($status, [200, 202], 'off, after the new key: ' . $body);
            self::assertSame(2, self::conversions(self::A));

            // A's values were sealed under the lost key: its next read replaces them, and says so.
            $a = self::store()->ensureCredentials(self::A, time());
            self::assertSame([PostbackCredential::TOKEN, PostbackCredential::SIGNING_SECRET], $a->replacedUnreadable);
            self::assertFalse($a->keyRecreated, 'the key was made again once, by B\'s rotation');
            self::assertSame([], self::store()->ensureCredentials(self::A, time())->replacedUnreadable, 'and only once');
        } finally {
            self::q("DELETE FROM 202_deployment_secrets WHERE secret_name = 'postback_token'");
            self::q("INSERT INTO 202_deployment_secrets SET secret_name = 'postback_token', secret_value = " . self::str($key)
                . ', created_at = 0');
        }
    }

    public function testAValueThatDoesNotOpenFailsClosedUntilItIsReplaced(): void
    {
        $old = self::store()->ensureCredentials(self::B, time());
        $sealedB = self::$db->query('SELECT token_sealed FROM 202_postback_auth WHERE user_id = ' . self::B)->fetch_row()[0];
        self::store()->ensureCredentials(self::A, time());
        // A's own sealed token, copied onto B: well formed, and it does not open as B's.
        $foreign = self::$db->query('SELECT token_sealed FROM 202_postback_auth WHERE user_id = ' . self::A)->fetch_row()[0];
        self::assertNotSame($sealedB, $foreign);
        self::q('UPDATE 202_postback_auth SET token_sealed = ' . self::str((string) $foreign) . ' WHERE user_id = ' . self::B);

        [$status, $body] = self::hit('gpb', self::B, ['p202_token' => (string) $old->token]);
        self::assertSame(500, $status, $body);
        $signed = self::signed('gpb', self::B, (string) $old->secret, time());
        [$status, $body] = self::runQuery('gpb', $signed);
        self::assertContains($status, [200, 202], 'a signature needs only the secret, which still opens: ' . $body);
        self::assertSame(1, self::conversions(self::B));

        $ensured = self::store()->ensureCredentials(self::B, time());
        self::assertSame([PostbackCredential::TOKEN], $ensured->replacedUnreadable);
        self::assertFalse($ensured->keyRecreated);
        self::assertSame($old->secret, $ensured->secret, 'the secret that opens is kept');
        self::assertNotSame($old->token, $ensured->token);
        [$status, $body] = self::hit('gpb', self::B, ['p202_token' => (string) $ensured->token]);
        self::assertContains($status, [200, 202], $body);
        self::assertSame(2, self::conversions(self::B));
    }

    public function testAModeThatCannotBeSavedWithItsCredentialsIsNotSaved(): void
    {
        self::store()->setMode(self::B, PostbackAuthMode::OFF, time());
        $key = self::key();
        self::q("UPDATE 202_deployment_secrets SET secret_value = 'not hex' WHERE secret_name = 'postback_token'");
        self::q('UPDATE 202_postback_auth SET token_sealed = NULL WHERE user_id = ' . self::B);
        try {
            $refused = null;
            try {
                self::store()->setMode(self::B, PostbackAuthMode::SIGNED, time());
            } catch (\RuntimeException $e) {
                $refused = $e->getMessage();
            }
            self::assertStringContainsString('not 64 lower-case hex', (string) $refused, 'a mode was saved without the credential it needs');
            self::assertSame(PostbackAuthMode::OFF, self::store()->mode(self::B), 'the mode is as it was');
        } finally {
            self::q('UPDATE 202_deployment_secrets SET secret_value = ' . self::str($key) . " WHERE secret_name = 'postback_token'");
        }
        $saved = self::store()->setMode(self::B, PostbackAuthMode::SIGNED, time());
        self::assertSame(PostbackAuthMode::SIGNED, $saved->mode);
        self::assertNotNull($saved->token, 'the missing token was made with it');
    }

    /**
     * pb.php authenticates the campaign's account, and the click comes from
     * subid: account A's campaign and token, naming B's click, convert
     * nothing of B's and say no more than an unknown click would.
     */
    public function testOneAccountsCampaignAndTokenConvertNothingOfAnothersClick(): void
    {
        $a = (string) self::store()->ensureCredentials(self::A, time())->token;
        self::store()->ensureCredentials(self::B, time());
        $acip = (string) self::CAMPAIGN[self::A][1];
        $theirs = (string) self::CLICK[self::B];
        $unknown = self::runQuery('pb', http_build_query(['acip' => $acip, 'subid' => '99030999', 'p202_token' => $a, 'txid' => 'x-0']));
        self::assertSame(404, $unknown[0], $unknown[1]);
        foreach ([[], ['status' => 'reversed'], ['event' => 'purchase']] as $extra) {
            $query = http_build_query(['acip' => $acip, 'subid' => $theirs, 'p202_token' => $a, 'txid' => 'x-' . ++self::$tx] + $extra);
            [$status, $body] = self::runQuery('pb', $query);
            self::assertSame(404, $status, json_encode($extra) . ': ' . $body);
            self::assertSame($unknown[1], $body, 'the answer an unknown click gets, nothing of B\'s');
        }
        self::assertSame(0, self::conversions(self::B));

        // A row from before the campaign check that ties B's click to A's campaign changes nothing.
        $click = self::CLICK[self::B];
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("UPDATE $table SET aff_campaign_id = " . self::CAMPAIGN[self::A][0] . " WHERE click_id = $click");
        }
        LatestVisit::refresh(new Connection(self::$db), $click);
        try {
            [$status, $body] = self::runQuery('pb', http_build_query(['acip' => $acip, 'subid' => $theirs, 'p202_token' => $a, 'txid' => 'x-legacy']));
            self::assertSame(404, $status, $body);
            self::assertSame($unknown[1], $body);
            self::assertSame(0, self::conversions(self::B));
        } finally {
            foreach (['202_clicks', '202_clicks_spy'] as $table) {
                self::q("UPDATE $table SET aff_campaign_id = " . self::CAMPAIGN[self::B][0] . " WHERE click_id = $click");
            }
            LatestVisit::refresh(new Connection(self::$db), $click);
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

    private static function key(): string
    {
        return (string) self::$db->query("SELECT secret_value FROM 202_deployment_secrets WHERE secret_name = 'postback_token'")->fetch_row()[0];
    }

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

    /** A request naming the owner's click as $endpoint names it, signed for $signedFor with $key. */
    private static function signed(string $signedFor, int $owner, string $key, int $at, ?string $endpoint = null): string
    {
        return PostbackSignature::signedQuery($signedFor, self::naming($endpoint ?? $signedFor, $owner), $key, $at);
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
