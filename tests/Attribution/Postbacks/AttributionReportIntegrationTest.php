<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\AdAttributionKitProtocol;
use Api\V3\Attribution\JwsVerifier;
use Api\V3\Attribution\PostbackReceiver;
use Api\V3\Attribution\PostbackVerifier;
use Api\V3\Attribution\SkadnetworkProtocol;
use Api\V3\Controllers\AttributionAppsController;
use Api\V3\Controllers\AttributionPostbacksController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ResponseSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * The report's SQL semantics only a real server can demonstrate: every
 * metric counts unique postbacks (a replay of one signed postback with a
 * different unsigned field is one install, not two), re-engagements are
 * neither installs nor redownloads, the protocol dimension and filters
 * separate the two frameworks, and the app registration's development
 * opt-in is a live policy that re-flags stored rows when toggled. Rows
 * enter through the real receivers so the whole read path is exercised
 * against what the write path actually stores.
 *
 * Skips automatically unless a test database is configured via env:
 *   P202_TEST_DB_HOST, P202_TEST_DB_PORT, P202_TEST_DB_USER,
 *   P202_TEST_DB_PASS, P202_TEST_DB_NAME
 *
 * @group integration
 */
final class AttributionReportIntegrationTest extends TestCase
{
    use ConnectsToTestDatabase;

    private const OWNER = 7;
    private const SKAN_APP_ID = 525463029;

    /**
     * The key id the extra AdAttributionKit fixtures below carry. It is not
     * one of Apple's, so those postbacks store as UNVERIFIABLE — the
     * verdict is not what they are here to exercise (the read path is), and
     * an unknown key is the one verdict a test can mint without a private
     * key Apple would have to have signed with.
     */
    private const UNKNOWN_KEY_ID = 'integration-test/1';

    public static function setUpBeforeClass(): void
    {
        self::connectTestDatabase();
    }

    public static function tearDownAfterClass(): void
    {
        self::disconnectTestDatabase();
    }

    protected function setUp(): void
    {
        $this->requireEmptyAttributionTables();
    }

    /**
     * Register both apps to the owner (the AdAttributionKit one opted in to
     * development postbacks) and store: two SKAdNetwork copies of one forged
     * postback differing only in conversion value, a second forged
     * SKAdNetwork postback, and Apple's development-signed AdAttributionKit
     * re-engagement example.
     *
     * @return int the AdAttributionKit app's registration id
     */
    private function seed(): int
    {
        self::$db->query(
            'INSERT INTO 202_attribution_apps (user_id, app_id, app_name, accept_development_postbacks, schema_token, created_at, updated_at) VALUES '
            . '(' . self::OWNER . ', ' . self::SKAN_APP_ID . ", 'SKAN app', 0, 'token-skan', 1, 1), "
            . '(' . self::OWNER . ', ' . AdAttributionKitFixtures::EXAMPLE_APP_ID . ", 'AAK app', 1, 'token-aak', 1, 1)"
        );
        $aakRegistrationId = (int) self::$db->query(
            'SELECT attribution_app_id FROM 202_attribution_apps WHERE app_id = ' . AdAttributionKitFixtures::EXAMPLE_APP_ID
        )->fetch_assoc()['attribution_app_id'];

        $skan = new PostbackReceiver(self::$db, new SkadnetworkProtocol(new PostbackVerifier()));
        $this->assertSame(200, $skan->receive($this->skanPostback('tx-1', 5), '203.0.113.9', self::receivedAt(0))['status']);
        // The same signed postback again with a different UNSIGNED field:
        // its own row (the dedupe hash covers the body), one postback.
        $this->assertSame(200, $skan->receive($this->skanPostback('tx-1', 9), '203.0.113.9', self::receivedAt(10))['status']);
        $this->assertSame(200, $skan->receive($this->skanPostback('tx-2', 5), '203.0.113.9', self::receivedAt(20))['status']);

        $aak = new PostbackReceiver(self::$db, new AdAttributionKitProtocol(new JwsVerifier()));
        $received = $aak->receive((string) json_encode(AdAttributionKitFixtures::exampleBody()), '203.0.113.9', self::receivedAt(30));
        $this->assertSame(200, $received['status']);
        $this->assertSame('development', $received['body']['data']['signature']);

        return $aakRegistrationId;
    }

    /**
     * Receipt times for the seeded rows: one hour into the CURRENT UTC day,
     * so every row lands in one day group and inside the window a day
     * report defaults to when the caller gives no time_from. A fixed epoch
     * would fall out of that window as the clock moved past it, and the
     * whole suite would start reporting empty groups on a date nobody
     * chose.
     */
    private static function receivedAt(int $offsetSeconds): int
    {
        return intdiv(time(), 86400) * 86400 + 3600 + $offsetSeconds;
    }

    private function skanPostback(string $transactionId, int $conversionValue, string $adNetworkId = 'integration.skadnetwork'): string
    {
        return (string) json_encode([
            'version' => '4.0',
            'ad-network-id' => $adNetworkId,
            'source-identifier' => '4213',
            'app-id' => self::SKAN_APP_ID,
            'transaction-id' => $transactionId,
            'redownload' => false,
            'fidelity-type' => 1,
            'did-win' => true,
            'conversion-value' => $conversionValue,
            'postback-sequence-index' => 0,
            'attribution-signature' => 'AA==', // forged: stored flagged invalid
        ]);
    }

    private function controller(): AttributionPostbacksController
    {
        return new AttributionPostbacksController(self::$db, self::OWNER);
    }

    /** @return array<string, mixed> */
    private function onlyGroup(array $report): array
    {
        $this->assertCount(1, $report['data']['groups'], json_encode($report['data']['groups']) ?: '');
        return $report['data']['groups'][0];
    }

    public function testAReplayedPostbackCountsOnceAndReengagementsAreNeitherInstallsNorRedownloads(): void
    {
        $this->seed();
        $controller = $this->controller();

        // The forged SKAdNetwork rows are invisible to the default report;
        // the opted-in development re-engagement is trusted.
        $report = $controller->report([]);
        $this->assertSame('verified-only', $report['meta']['trusted'], 'the default report counts verified rows only');
        $trusted = $this->onlyGroup($report);
        // Four stored rows, three unique postbacks (tx-1 twice, tx-2, the
        // AdAttributionKit one) — what meta.notes promises every metric
        // counts, the signature_*_count columns included.
        $this->assertSame(3, $trusted['postbacks']);
        $this->assertSame(0, $trusted['installs']);
        $this->assertSame(0, $trusted['redownloads']);
        $this->assertSame(1, $trusted['reengagements']);
        $this->assertSame(1, $trusted['signature_valid_count']);
        $this->assertSame(2, $trusted['signature_invalid_count'], 'three forged rows, two unique forged postbacks');
        $this->assertSame(0, $trusted['signature_unverified_count']);
        $this->assertSame(1, $trusted['signature_development_count']);
        // The re-engagement's conversion value decodes like any other.
        $this->assertSame(1, $trusted['measurable']);

        // Over the forged class: three rows, two unique postbacks.
        $forgedReport = $controller->report(['signature' => 'invalid']);
        $this->assertSame('as-filtered', $forgedReport['meta']['trusted'], 'an explicit signature filter reports its own class');
        $forged = $this->onlyGroup($forgedReport);
        $this->assertSame(2, $forged['postbacks']);
        $this->assertSame(2, $forged['installs'], 'a replay with a different conversion value is the same postback');
        $this->assertSame(0, $forged['reengagements']);
        $this->assertSame(2, $forged['measurable'], 'the decode counts unique postbacks too');
    }

    public function testTheProtocolDimensionAndFiltersSeparateTheFrameworks(): void
    {
        $this->seed();
        $controller = $this->controller();

        $byProtocol = $controller->report(['group_by' => 'protocol', 'signature' => 'invalid']);
        $groups = array_column($byProtocol['data']['groups'], null, 'protocol');
        $this->assertSame(['skadnetwork'], array_keys($groups), 'no forged AdAttributionKit rows exist');
        $this->assertSame(2, $groups['skadnetwork']['postbacks'], 'three forged rows, two unique postbacks');

        $byProtocol = $controller->report(['group_by' => 'protocol']);
        $groups = array_column($byProtocol['data']['groups'], null, 'protocol');
        $this->assertSame(['skadnetwork', 'adattributionkit'], array_keys($groups));
        $this->assertSame(1, $groups['adattributionkit']['reengagements']);
        $this->assertSame(0, $groups['skadnetwork']['installs'], 'forged rows never count in the default report');

        $byType = $controller->report(['group_by' => 'conversion-type', 'protocol' => 'aak']);
        $this->assertSame('re-engagement', $this->onlyGroup($byType)['conversion_type']);

        $list = $controller->list(['protocol' => 'skan']);
        $this->assertSame(3, $list['pagination']['total']);
        $this->assertSame(['skadnetwork'], array_unique(array_column($list['data'], 'protocol')));

        $list = $controller->list(['conversion_type' => 're-engagement', 'ad_interaction_type' => 'click']);
        $this->assertSame(1, $list['pagination']['total']);
        $this->assertSame('adattributionkit', $list['data'][0]['protocol']);
        $this->assertSame(AdAttributionKitFixtures::EXAMPLE_KEY_ID, $list['data'][0]['key_id']);

        $this->assertSame(1, $controller->list(['signature' => 'development'])['pagination']['total']);
    }

    public function testTogglingTheDevelopmentOptInReflagsTheStoredRows(): void
    {
        $registrationId = $this->seed();
        $postbacks = $this->controller();
        $apps = new AttributionAppsController(self::$db, self::OWNER);

        // Received under the opt-in: trusted.
        $this->assertSame(1, $this->onlyGroup($postbacks->report([]))['reengagements']);

        $apps->update($registrationId, ['accept_development_postbacks' => 0]);
        $off = $this->onlyGroup($postbacks->report([]));
        $this->assertSame(0, $off['reengagements'], 'turning the opt-in off untrusts the stored development rows');
        $this->assertSame(1, $off['signature_unverified_count']);
        $this->assertSame(1, $off['signature_development_count'], 'the verdict itself never changes');

        $apps->update($registrationId, ['accept_development_postbacks' => 1]);
        $this->assertSame(1, $this->onlyGroup($postbacks->report([]))['reengagements'], 'and back on trusts them again');

        // The forged SKAdNetwork rows were never touched by either toggle.
        $this->assertSame(2, $this->onlyGroup($postbacks->report(['signature' => 'invalid']))['postbacks']);
    }

    public function testTheLegacySkadnetworkFilterNamesFindAdAttributionKitRowsToo(): void
    {
        $this->seed();
        // A genuine AdAttributionKit redownload, viewed rather than clicked,
        // stored through the real receiver: its redownload and fidelity_type
        // columns are NULL — only SkadnetworkProtocol writes those — and its
        // conversion_type/ad_interaction_type carry the same meaning.
        $this->assertSame(200, $this->receiveAdAttributionKit(
            ['conversion-type' => 'redownload', 'postback-identifier' => 'AAK-REDOWNLOAD-1'],
            ['ad-interaction-type' => 'view']
        ));
        $raw = self::$db->query(
            "SELECT redownload, fidelity_type FROM 202_attribution_postbacks WHERE transaction_id = 'AAK-REDOWNLOAD-1'"
        )->fetch_assoc();
        $this->assertSame([null, null], [$raw['redownload'], $raw['fidelity_type']], 'the legacy columns are SKAdNetwork-only');

        $controller = $this->controller();

        // redownload and fidelity_type are legacy spellings of the generic
        // dimensions, so each must answer the same question as the generic
        // name — matching the AdAttributionKit row the raw columns cannot.
        $legacy = $controller->list(['redownload' => '1']);
        $this->assertSame(1, $legacy['pagination']['total']);
        $this->assertSame('adattributionkit', $legacy['data'][0]['protocol']);
        $this->assertSame(1, $controller->list(['conversion_type' => 'redownload'])['pagination']['total']);

        $legacy = $controller->list(['fidelity_type' => '0']);
        $this->assertSame(1, $legacy['pagination']['total']);
        $this->assertSame('adattributionkit', $legacy['data'][0]['protocol']);
        $this->assertSame(1, $controller->list(['ad_interaction_type' => 'view'])['pagination']['total']);

        // The SKAdNetwork readings are unchanged: three clicked downloads.
        $this->assertSame(3, $controller->list(['redownload' => '0'])['pagination']['total']);
        $this->assertSame(3, $controller->list(['conversion_type' => 'download'])['pagination']['total']);
        $this->assertSame(4, $controller->list(['fidelity_type' => '1'])['pagination']['total']);
        $this->assertSame(4, $controller->list(['ad_interaction_type' => 'click'])['pagination']['total']);

        // The report reads the same filters through the same builder.
        $this->assertSame(1, $this->onlyGroup($controller->report(['redownload' => '1', 'signature' => 'unverifiable']))['postbacks']);

        // Their validation is unchanged: a boolean and an integer.
        try {
            $controller->list(['redownload' => 'maybe']);
            $this->fail('a non-boolean redownload must be rejected');
        } catch (ValidationException $e) {
            $this->assertSame('Must be a boolean (1/0/true/false)', $e->getFieldErrors()['redownload'] ?? null);
        }
        try {
            $controller->list(['fidelity_type' => 'click']);
            $this->fail('a non-integer fidelity_type must be rejected');
        } catch (ValidationException $e) {
            $this->assertSame('Must be an integer', $e->getFieldErrors()['fidelity_type'] ?? null);
        }
    }

    /**
     * @dataProvider notIntegers
     */
    public function testAMistypedIntegerFilterIsRejectedRatherThanTruncated(string $param, string $value): void
    {
        $controller = $this->controller();

        // is_numeric() accepted all of these and the (int) cast then made
        // them mean something the caller never typed, while the 422 the
        // caller did not get promised an integer.
        try {
            $controller->list([$param => $value]);
            $this->fail("$param=" . var_export($value, true) . ' must be rejected, not truncated');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($param, $e->getFieldErrors());
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function notIntegers(): array
    {
        $cases = [];
        foreach (['app_id', 'campaign_id', 'postback_sequence_index', 'fidelity_type', 'time_from', 'time_to'] as $param) {
            // The last three are digit strings the (int) cast SATURATES:
            // they cleared the digits-only test and were then bound as
            // PHP_INT_MAX / PHP_INT_MIN, so the empty result read as an
            // answer about the number the caller actually typed.
            foreach (['1.9', '1e0', ' 1', '1 ', '9223372036854775808', '99999999999999999999', '-99999999999999999999'] as $value) {
                $cases["$param is " . var_export($value, true)] = [$param, $value];
            }
        }
        return $cases;
    }

    public function testAnIntegerFilterStillAcceptsAnInteger(): void
    {
        $this->seed();
        $controller = $this->controller();
        $this->assertSame(3, $controller->list(['app_id' => (string) self::SKAN_APP_ID])['pagination']['total']);
        $this->assertSame(4, $controller->list(['postback_sequence_index' => '0'])['pagination']['total']);
        $this->assertSame(4, $controller->list(['time_from' => (string) self::receivedAt(0)])['pagination']['total']);
    }

    public function testAStoredJwsComesBackWholeForTheForensicPath(): void
    {
        $this->seed();
        // An AdAttributionKit JWS longer than 4096 characters but within
        // what its receiver accepts. Padding the payload is what a real
        // postback's optional fields do to it.
        $jws = $this->adAttributionKitJws(
            ['postback-identifier' => 'AAK-LONG-JWS-1', 'padding' => str_repeat('p', 3600)]
        );
        $this->assertGreaterThan(4096, strlen($jws));
        $this->assertLessThanOrEqual(AdAttributionKitProtocol::MAX_JWS_LENGTH, strlen($jws));
        $this->assertSame(200, $this->receiveAdAttributionKit([], [], $jws));

        $row = self::$db->query(
            "SELECT postback_id, signature_state FROM 202_attribution_postbacks WHERE transaction_id = 'AAK-LONG-JWS-1'"
        )->fetch_assoc();
        $this->assertSame('unverifiable', $row['signature_state']);

        $served = $this->controller()->get((int) $row['postback_id'])['data']['attribution_signature'];
        $this->assertStringNotContainsString(ResponseSanitizer::TRUNCATION_MARKER, $served);
        $this->assertSame($jws, $served, 'the signature is served for forensics, so it must come back whole');

        // The point of serving it: pasted into /attribution/verify it
        // reproduces the verdict the row was stored with.
        $verified = $this->controller()->verify(['jws-string' => $served])['data'];
        $this->assertSame('unverifiable', $verified['signature']);
        $this->assertSame(self::UNKNOWN_KEY_ID, $verified['key_id']);
        $this->assertSame('AAK-LONG-JWS-1', $verified['payload']['postback-identifier']);
    }

    public function testADayReportReturnsTheSameGroupsAsAnUnboundedOne(): void
    {
        $this->seed();
        $controller = $this->controller();

        // A day report with no time_from TRIES a window bounded to the
        // newest limit + 1 days, because aggregating the tenant's whole
        // retained history to throw all but a page away got slower every
        // month. The bound is an optimisation, never an answer: a postback
        // 300 days old is outside it, and a short page is thrown away and
        // the query re-run unbounded.
        $longAgo = self::receivedAt(0) - 300 * 86400;
        $skan = new PostbackReceiver(self::$db, new SkadnetworkProtocol(new PostbackVerifier()));
        $this->assertSame(200, $skan->receive($this->skanPostback('tx-old', 5), '203.0.113.9', $longAgo)['status']);

        $report = $controller->report([]);
        $this->assertSame(
            [gmdate('Y-m-d', $longAgo), gmdate('Y-m-d', self::receivedAt(0))],
            array_column($report['data']['groups'], 'date'),
            'a populated day older than the internal window is still a group'
        );
        // Result-identical to the query the bound optimises, so there is
        // nothing about a window to disclose to the caller.
        $unbounded = $controller->report(['time_from' => 0]);
        // assertEquals, not assertSame: every group carries an `events`
        // stdClass, and two runs build two distinct objects.
        $this->assertEquals($unbounded['data']['groups'], $report['data']['groups']);
        $this->assertArrayNotHasKey('time_from_defaulted', $report['meta']);
        $this->assertStringNotContainsString('time_from defaulted', $report['meta']['notes']);

        // A caller who bounded the window from ABOVE asked for an older
        // stretch of history and still gets it.
        $older = $controller->report(['time_to' => $longAgo + 3600]);
        $this->assertSame(1, $this->onlyGroup($older)['postbacks'], 'the caller\'s own window still answers');

        // Only day mode is windowed at all: the other modes rank by count,
        // not by time.
        $byProtocol = $controller->report(['group_by' => 'protocol']);
        $this->assertEquals(
            $controller->report(['group_by' => 'protocol', 'time_from' => 0])['data']['groups'],
            $byProtocol['data']['groups']
        );
    }

    /**
     * The sparse tenant is the case the window optimisation got wrong: with
     * fewer populated days than the page holds, every day outside the bound
     * was dropped and the report simply returned less. Six populated days
     * spread over 700 is the shape that reproduced it (it returned two).
     */
    public function testASparseTenantStillGetsEveryPopulatedDay(): void
    {
        // Registers the app whose id these postbacks name, so the receiver
        // claims them to the owner the report reads for. Its own rows all
        // land on the current day, which is the first offset below.
        $this->seed();

        $skan = new PostbackReceiver(self::$db, new SkadnetworkProtocol(new PostbackVerifier()));
        $offsets = [0, 100, 250, 400, 550, 699];
        $expected = [];
        foreach ($offsets as $i => $daysAgo) {
            $at = self::receivedAt(0) - $daysAgo * 86400;
            $this->assertSame(
                200,
                $skan->receive($this->skanPostback('tx-sparse-' . $i, 5), '203.0.113.11', $at)['status']
            );
            $expected[] = gmdate('Y-m-d', $at);
        }
        sort($expected);

        $controller = $this->controller();
        $report = $controller->report([]);
        $dates = array_column($report['data']['groups'], 'date');
        sort($dates);

        $this->assertSame($expected, $dates, 'every populated day is a group, however far back it is');
        $this->assertEquals(
            $controller->report(['time_from' => 0])['data']['groups'],
            $report['data']['groups'],
            'the bounded attempt may only stand when it is provably the whole answer'
        );
    }

    /**
     * The window is subtracted from the caller's own time_to. An anchor near
     * the bottom of the integer range put the result below PHP_INT_MIN, where
     * it stopped being an integer and the filter rejected it — a 422 naming
     * time_from, which the caller never sent, and only in day mode, which
     * disclosed the window's existence and size.
     */
    public function testAnExtremeTimeToDoesNotRejectAFilterTheCallerNeverSent(): void
    {
        $this->seed();
        $controller = $this->controller();

        foreach (['day', 'protocol'] as $groupBy) {
            $report = $controller->report(['group_by' => $groupBy, 'time_to' => (string)PHP_INT_MIN]);
            $this->assertSame(
                [],
                $report['data']['groups'],
                "$groupBy answers an empty window rather than rejecting it"
            );
        }
    }

    public function testPostbacksThatDifferOnlyInWhereASeparatorFallsCountSeparately(): void
    {
        // Neither protocol restricts the characters in an ad-network-id or
        // a transaction-id (both are only non-empty and length-capped), so
        // an identity that joined them with a plain '|' gave these two
        // GENUINELY DIFFERENT postbacks the same identity string: they
        // stored as two rows (the receiver's dedupe hash is
        // length-prefixed) and the report counted them once. A case-only
        // difference did the same thing through the columns'
        // utf8mb4_general_ci collation.
        self::$db->query(
            'INSERT INTO 202_attribution_apps (user_id, app_id, app_name, accept_development_postbacks, schema_token, created_at, updated_at) VALUES '
            . '(' . self::OWNER . ', ' . self::SKAN_APP_ID . ", 'SKAN app', 0, 'token-skan', 1, 1)"
        );
        $skan = new PostbackReceiver(self::$db, new SkadnetworkProtocol(new PostbackVerifier()));
        foreach ([
            ['B7C2', 'acme.skadnetwork|A9F3'],
            ['A9F3|B7C2', 'acme.skadnetwork'],
            ['TX3', 'acme.skadnetwork'],
            ['TX3', 'ACME.skadnetwork'],
        ] as $offset => [$transactionId, $adNetworkId]) {
            $this->assertSame(200, $skan->receive(
                $this->skanPostback($transactionId, 5, $adNetworkId),
                '203.0.113.9',
                self::receivedAt($offset)
            )['status'], "$adNetworkId / $transactionId");
        }
        $this->assertSame(
            4,
            (int) self::$db->query('SELECT COUNT(*) AS c FROM 202_attribution_postbacks')->fetch_assoc()['c'],
            'the store already keeps these apart'
        );

        // All four are forged, so the class report is where they show:
        // four rows, four distinct postbacks, four installs.
        $controller = $this->controller();
        $group = $this->onlyGroup($controller->report(['signature' => 'invalid']));
        $this->assertSame(4, $group['postbacks']);
        $this->assertSame(4, $group['installs']);
        $this->assertSame(4, $group['signature_invalid_count']);
        $this->assertSame(4, $group['measurable'], 'each unique postback decodes through its first-received copy');

        // ...and the breakdown adds up to that total. The ad-network
        // DIMENSION groups on the raw column, so the two spellings of
        // acme.skadnetwork share one display group either way — but under
        // the old identity the two groups reported 1 + 2 = 3 against a day
        // total of 2, a report disagreeing with itself. (Measured by
        // running exactly this fixture against the old constant.)
        $byNetwork = $controller->report(['signature' => 'invalid', 'group_by' => 'ad-network']);
        $this->assertSame(
            4,
            array_sum(array_column($byNetwork['data']['groups'], 'postbacks')),
            'the breakdown must sum to the total the day group reports'
        );
    }

    /**
     * A structurally valid AdAttributionKit JWS carrying an unknown key id
     * (see UNKNOWN_KEY_ID) and a signature that cannot verify, over Apple's
     * documented example payload plus the given overrides.
     *
     * @param array<string, mixed> $payloadOverrides
     */
    private function adAttributionKitJws(array $payloadOverrides): string
    {
        return AdAttributionKitFixtures::jws(
            ['kid' => self::UNKNOWN_KEY_ID, 'alg' => 'ES256'],
            $payloadOverrides + AdAttributionKitFixtures::examplePayload()
        );
    }

    /**
     * Store one more AdAttributionKit postback through the real receiver.
     *
     * @param array<string, mixed> $payloadOverrides
     * @param array<string, mixed> $bodyOverrides
     * @return int the receiver's HTTP status
     */
    private function receiveAdAttributionKit(array $payloadOverrides, array $bodyOverrides = [], ?string $jws = null): int
    {
        $aak = new PostbackReceiver(self::$db, new AdAttributionKitProtocol(new JwsVerifier()));
        $body = AdAttributionKitFixtures::exampleBody(
            $bodyOverrides + ['jws-string' => $jws ?? $this->adAttributionKitJws($payloadOverrides)]
        );
        return $aak->receive((string) json_encode($body), '203.0.113.9', self::receivedAt(40))['status'];
    }
}
