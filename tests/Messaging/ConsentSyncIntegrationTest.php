<?php
// tests/Messaging/ConsentSyncIntegrationTest.php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\CoreTables;
use Prosper202\Database\Tables\UserTables;

require_once __DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php';
require_once __DIR__ . '/../../202-config/Messaging/MessagingService.class.php';
require_once __DIR__ . '/../../202-config/Messaging/Analytics.class.php';

/**
 * Captures every wire call instead of talking to my.tracking202.com, so the
 * consent boundary can be asserted on the exact payloads that would have
 * been transmitted.
 */
final class ConsentSyncFakeClient extends MessagingClient
{
    /** @var array<int,array{identity:array,attributes:array,events:array,consent:?array}> */
    public array $trackCalls = [];
    /** @var array<int,array{identity:array,cursor:?string}> */
    public array $pullCalls = [];

    public function pull(array $identity, ?string $cursor): ?array
    {
        $this->pullCalls[] = ['identity' => $identity, 'cursor' => $cursor];
        return ['conversations' => [], 'cursor' => 'cur-1'];
    }

    public function send(array $identity, ?string $conversationExternalId, string $body, string $clientToken): ?array
    {
        return null;
    }

    public function markRead(array $identity, array $externalIds): ?array
    {
        return ['ok' => true];
    }

    public function track(array $identity, array $attributes, array $events, ?array $consent = null): ?array
    {
        $this->trackCalls[] = ['identity' => $identity, 'attributes' => $attributes, 'events' => $events, 'consent' => $consent];
        return ['ok' => true];
    }
}

/**
 * DB-gated behavioral verification of the consent substrate: EU geo
 * persistence, prompt flow, tier'd event writes, denial purge, and the
 * spec §9 sync boundary (only essential rows/no snapshot for non-consented
 * users). These are the behaviors the per-task manual verifications were
 * supposed to cover; they run against a real MySQL using the real schema
 * definitions.
 *
 * Skipped unless a test database is configured:
 *
 *   docker run -d --name p202-test-mysql -e MYSQL_ROOT_PASSWORD=root \
 *     -e MYSQL_DATABASE=p202_test -p 33061:3306 mysql:8
 *
 *   P202_TEST_DB_HOST=127.0.0.1 P202_TEST_DB_PORT=33061 \
 *   P202_TEST_DB_USER=root P202_TEST_DB_PASS=root P202_TEST_DB_NAME=p202_test \
 *   vendor/bin/phpunit -c phpunit.ci.xml tests/Messaging/ConsentSyncIntegrationTest.php
 */
final class ConsentSyncIntegrationTest extends TestCase
{
    private const USER_ID = 4242;

    private static ?mysqli $db = null;
    private static string $skipReason = 'P202_TEST_DB_HOST not set (integration test needs a real MySQL)';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }

        mysqli_report(MYSQLI_REPORT_OFF); // UI-path semantics: failures return false, never throw

        $db = mysqli_init();
        $connected = $db && @$db->real_connect(
            $host,
            getenv('P202_TEST_DB_USER') !== false ? (string) getenv('P202_TEST_DB_USER') : 'root',
            getenv('P202_TEST_DB_PASS') !== false ? (string) getenv('P202_TEST_DB_PASS') : '',
            getenv('P202_TEST_DB_NAME') !== false ? (string) getenv('P202_TEST_DB_NAME') : 'p202_test',
            (int) (getenv('P202_TEST_DB_PORT') !== false ? getenv('P202_TEST_DB_PORT') : 3306)
        );
        if (!$connected) {
            self::$skipReason = 'could not connect to the configured test MySQL';
            return;
        }

        // Mirror the UI path (connect.php runs with strict mode disabled).
        $db->query("SET SESSION sql_mode = ''");

        // Real schema definitions — the same SQL fresh installs run.
        foreach ([
            UserTables::usersPref(),
            CoreTables::messagingEvents(),
            CoreTables::messagingAttributes(),
            CoreTables::messagingSync(),
            CoreTables::messagingConversations(),
            CoreTables::messagingMessages(),
        ] as $definition) {
            if ($db->query('DROP TABLE IF EXISTS `' . $definition->tableName . '`') === false
                || $db->query($definition->createStatement) === false) {
                self::$skipReason = 'failed to create schema: ' . $db->error;
                return;
            }
        }

        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->close();
            self::$db = null;
        }
        unset($GLOBALS['db'], $_SESSION['user_id']);
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped(self::$skipReason);
        }
        $db = self::$db;
        foreach (['202_messaging_events', '202_messaging_attributes', '202_messaging_sync',
                  '202_messaging_messages', '202_messaging_conversations', '202_users_pref'] as $table) {
            $this->assertNotFalse($db->query("DELETE FROM `{$table}`"), "cleanup of {$table} failed: " . $db->error);
        }
        $this->assertNotFalse(
            $db->query('INSERT INTO `202_users_pref` (user_id) VALUES (' . self::USER_ID . ')'),
            'seeding 202_users_pref failed: ' . $db->error
        );
    }

    private function service(array $identity = []): MessagingService
    {
        return new MessagingService(self::$db, self::USER_ID, $identity);
    }

    private function injectFakeClient(MessagingService $service): ConsentSyncFakeClient
    {
        $fake = new ConsentSyncFakeClient();
        $prop = new ReflectionProperty(MessagingService::class, 'client');
        $prop->setAccessible(true);
        $prop->setValue($service, $fake);
        return $fake;
    }

    /** @return array<int,array<string,mixed>> */
    private function eventRows(): array
    {
        $res = self::$db->query(
            'SELECT event_name, tier, delivery_status FROM `202_messaging_events` WHERE user_id = '
            . self::USER_ID . ' ORDER BY id ASC'
        );
        $this->assertNotFalse($res);
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    // ------------------------------------------------------------------
    // Task 6/10 behaviors: EU geo persistence + one-time prompt flow
    // ------------------------------------------------------------------

    public function test_eu_geo_persistence_prompt_and_grant_flow(): void
    {
        $db = self::$db;

        $this->assertTrue(ConsentPolicy::rememberGeo($db, self::USER_ID, true));
        $this->assertFalse(ConsentPolicy::analyticsAllowed($db, self::USER_ID), 'EU + unset must be held');
        $this->assertTrue(ConsentPolicy::needsEuPrompt($db, self::USER_ID));

        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'granted', 'eu_prompt'));
        $this->assertTrue(ConsentPolicy::analyticsAllowed($db, self::USER_ID));
        $this->assertFalse(ConsentPolicy::needsEuPrompt($db, self::USER_ID), 'prompt is one-time');

        $res = $db->query(
            'SELECT analytics_geo_is_eu, eu_consent_prompt_seen, analytics_consent, analytics_consent_source
               FROM `202_users_pref` WHERE user_id = ' . self::USER_ID
        );
        $this->assertNotFalse($res);
        $row = $res->fetch_assoc();
        $this->assertSame(1, (int) $row['analytics_geo_is_eu']);
        $this->assertSame(1, (int) $row['eu_consent_prompt_seen']);
        $this->assertSame('granted', $row['analytics_consent']);
        $this->assertSame('eu_prompt', $row['analytics_consent_source']);
    }

    public function test_eu_decline_denies_and_marks_prompt_seen(): void
    {
        $db = self::$db;
        $this->assertTrue(ConsentPolicy::rememberGeo($db, self::USER_ID, true));
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'eu_prompt'));
        $this->assertFalse(ConsentPolicy::analyticsAllowed($db, self::USER_ID));
        $this->assertFalse(ConsentPolicy::needsEuPrompt($db, self::USER_ID));
    }

    // ------------------------------------------------------------------
    // Task 5/7 behaviors: consent-gated, tier'd event writes
    // ------------------------------------------------------------------

    public function test_analytics_events_are_consent_gated_and_tiered(): void
    {
        $db = self::$db;
        $GLOBALS['db'] = $db;
        $_SESSION['user_id'] = self::USER_ID;

        // Non-EU default: analytics flows and lands with tier='analytics'.
        $this->assertTrue(ConsentPolicy::rememberGeo($db, self::USER_ID, false));
        Analytics::event('feature_used', ['feature' => 'export'], 'analytics');
        $rows = $this->eventRows();
        $this->assertCount(1, $rows);
        $this->assertSame(['feature_used', 'analytics', 'pending'], array_values($rows[0]));

        // Denial: no further analytics writes (and the pending row is purged).
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));
        Analytics::event('feature_used', ['feature' => 'export'], 'analytics');
        $this->assertSame([], $this->eventRows(), 'denied user must produce no analytics rows');

        // Essential still flows for the denied user.
        Analytics::event('login', [], 'essential');
        $rows = $this->eventRows();
        $this->assertCount(1, $rows);
        $this->assertSame(['login', 'essential', 'pending'], array_values($rows[0]));
    }

    // ------------------------------------------------------------------
    // Task 9 behavior + revocation: profile write, then purge on denial
    // ------------------------------------------------------------------

    public function test_denial_purges_snapshot_and_undelivered_analytics_events(): void
    {
        $db = self::$db;
        $service = $this->service();

        $service->updateAttributes(['clicks_30d' => 5, 'offers' => [['name' => 'Keto - US', 'url' => 'https://x.example/o']]]);
        $service->recordEvent('page_viewed', ['path' => '/tracking202/'], 'analytics');
        $service->recordEvent('login', null, 'essential');

        $res = $db->query('SELECT data, dirty FROM `202_messaging_attributes` WHERE user_id = ' . self::USER_ID);
        $this->assertNotFalse($res);
        $snapshot = $res->fetch_assoc();
        $this->assertNotNull($snapshot, 'profile snapshot must be written while consented');
        $this->assertStringContainsString('Keto - US', (string) $snapshot['data']);
        $this->assertCount(2, $this->eventRows());

        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));

        $res = $db->query('SELECT COUNT(*) c FROM `202_messaging_attributes` WHERE user_id = ' . self::USER_ID);
        $this->assertNotFalse($res);
        $this->assertSame(0, (int) $res->fetch_assoc()['c'], 'denial must purge the analytics snapshot');

        $rows = $this->eventRows();
        $this->assertCount(1, $rows, 'denial must purge undelivered analytics events, keep essential');
        $this->assertSame('login', $rows[0]['event_name']);
    }

    // ------------------------------------------------------------------
    // Spec §9 transport boundary: what actually goes over the wire
    // ------------------------------------------------------------------

    public function test_sync_transmits_only_essential_rows_when_analytics_held(): void
    {
        $db = self::$db;

        // EU + unset: analytics held with NO denial purge — the exact case
        // where leftover analytics rows/snapshot exist locally and the
        // transport boundary is the only defense.
        $this->assertTrue(ConsentPolicy::rememberGeo($db, self::USER_ID, true));

        $identity = ['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID,
                     'attributes' => ['clicks_30d' => 9, 'offers' => [['name' => 'Keto - US']]]];
        $service = $this->service($identity);
        $fake = $this->injectFakeClient($service);

        $service->recordEvent('login', null, 'essential');
        $service->recordEvent('page_viewed', ['path' => '/x'], 'analytics');
        $service->updateAttributes(['clicks_30d' => 9]); // dirty analytics snapshot

        $this->assertTrue($service->sync(true));

        $this->assertCount(1, $fake->trackCalls);
        $track = $fake->trackCalls[0];
        $this->assertSame(['login'], array_column($track['events'], 'name'),
            'only essential rows may sync for a non-consented user');
        $this->assertSame('essential', $track['events'][0]['tier'], 'tier must travel in the payload');
        $this->assertSame([], $track['attributes'], 'the analytics snapshot must not sync');
        $this->assertArrayNotHasKey('attributes', $track['identity'],
            'identity must not embed the attribute snapshot for a non-consented user');
        // Spec §6 delta: the consent block still travels for a held user —
        // absence would read as "unset" server-side, but so must "held".
        $this->assertSame('unset', $track['consent']['analytics'] ?? null,
            'the /track consent block must travel even when analytics is held');
        $this->assertSame('unset', $track['identity']['consent']['analytics'] ?? null,
            'identity.consent must travel even when attributes are stripped');
        $this->assertCount(1, $fake->pullCalls);
        $this->assertArrayNotHasKey('attributes', $fake->pullCalls[0]['identity'],
            'pull identity re-transmitted the profile — the revocation leak the review flagged');

        // Local state: essential delivered, analytics kept local and pending.
        $rows = $this->eventRows();
        $this->assertSame([['login', 'essential', 'sent'], ['page_viewed', 'analytics', 'pending']],
            array_map('array_values', $rows));
        $res = $db->query('SELECT dirty FROM `202_messaging_attributes` WHERE user_id = ' . self::USER_ID);
        $this->assertNotFalse($res);
        $this->assertSame(1, (int) $res->fetch_assoc()['dirty'],
            'snapshot stays dirty so a later grant delivers it');
    }

    public function test_sync_transmits_analytics_rows_and_snapshot_when_granted(): void
    {
        $db = self::$db;
        $this->assertTrue(ConsentPolicy::rememberGeo($db, self::USER_ID, true));
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'granted', 'eu_prompt'));

        $identity = ['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID];
        $service = $this->service($identity);
        $fake = $this->injectFakeClient($service);

        $service->recordEvent('login', null, 'essential');
        $service->recordEvent('page_viewed', ['path' => '/x'], 'analytics');
        $service->updateAttributes(['clicks_30d' => 9]);

        $this->assertTrue($service->sync(true));

        $this->assertCount(1, $fake->trackCalls);
        $track = $fake->trackCalls[0];
        $this->assertSame(['login', 'page_viewed'], array_column($track['events'], 'name'));
        $this->assertSame(['essential', 'analytics'], array_column($track['events'], 'tier'));
        $this->assertSame(9, $track['attributes']['clicks_30d'] ?? null);
        $this->assertSame(9, $track['identity']['attributes']['clicks_30d'] ?? null,
            'consented identity carries the snapshot as before');
        $this->assertSame('granted', $track['consent']['analytics'] ?? null,
            'the /track consent block must carry the granted state (spec §6)');
        $this->assertSame('eu_prompt', $track['consent']['analytics_source'] ?? null);

        $rows = $this->eventRows();
        $this->assertSame([['login', 'essential', 'sent'], ['page_viewed', 'analytics', 'sent']],
            array_map('array_values', $rows));
    }
}
