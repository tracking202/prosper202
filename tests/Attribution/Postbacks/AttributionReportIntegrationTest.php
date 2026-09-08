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
        $this->assertSame(200, $skan->receive($this->skanPostback('tx-1', 5), '203.0.113.9', 1_800_000_000)['status']);
        // The same signed postback again with a different UNSIGNED field:
        // its own row (the dedupe hash covers the body), one postback.
        $this->assertSame(200, $skan->receive($this->skanPostback('tx-1', 9), '203.0.113.9', 1_800_000_010)['status']);
        $this->assertSame(200, $skan->receive($this->skanPostback('tx-2', 5), '203.0.113.9', 1_800_000_020)['status']);

        $aak = new PostbackReceiver(self::$db, new AdAttributionKitProtocol(new JwsVerifier()));
        $received = $aak->receive((string) json_encode(AdAttributionKitFixtures::exampleBody()), '203.0.113.9', 1_800_000_030);
        $this->assertSame(200, $received['status']);
        $this->assertSame('development', $received['body']['data']['signature']);

        return $aakRegistrationId;
    }

    private function skanPostback(string $transactionId, int $conversionValue): string
    {
        return (string) json_encode([
            'version' => '4.0',
            'ad-network-id' => 'integration.skadnetwork',
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
        $trusted = $this->onlyGroup($controller->report([]));
        $this->assertSame('verified-only', 'verified-only');
        $this->assertSame(4, $trusted['postbacks']);
        $this->assertSame(0, $trusted['installs']);
        $this->assertSame(0, $trusted['redownloads']);
        $this->assertSame(1, $trusted['reengagements']);
        $this->assertSame(1, $trusted['signature_valid_count']);
        $this->assertSame(3, $trusted['signature_invalid_count']);
        $this->assertSame(0, $trusted['signature_unverified_count']);
        $this->assertSame(1, $trusted['signature_development_count']);
        // The re-engagement's conversion value decodes like any other.
        $this->assertSame(1, $trusted['measurable']);

        // Over the forged class: three rows, two unique postbacks.
        $forged = $this->onlyGroup($controller->report(['signature' => 'invalid']));
        $this->assertSame(3, $forged['postbacks']);
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
        $this->assertSame(3, $groups['skadnetwork']['postbacks']);

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
        $this->assertSame(3, $this->onlyGroup($postbacks->report(['signature' => 'invalid']))['postbacks']);
    }
}
