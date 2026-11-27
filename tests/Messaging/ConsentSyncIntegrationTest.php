<?php
// tests/Messaging/ConsentSyncIntegrationTest.php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\CoreTables;
use Prosper202\Database\Tables\UserTables;

require_once __DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php';
require_once __DIR__ . '/../../202-config/Messaging/MessagingService.class.php';
require_once __DIR__ . '/../../202-config/Messaging/Analytics.class.php';
require_once __DIR__ . '/../../202-config/functions-auth.php';
require_once __DIR__ . '/../../202-config/functions-tracking202.php';

/**
 * @group integration
 *
 * DB-gated behavioral verification of the consent substrate: opt-in
 * (unset is off everywhere), the one-time question, event writes of both
 * tiers, denial purge, and the spec §9 sync boundary (nothing at all for a
 * user who never opted in; only what was recorded after a yes). They run
 * against a real MySQL using the real schema definitions.
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

        // Real schema definitions — the same SQL fresh installs run. A scratch
        // database another suite installed may hold foreign keys onto 202_users.
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            UserTables::users(),
            UserTables::usersPref(),
            CoreTables::messagingEvents(),
            CoreTables::messagingAttributes(),
            CoreTables::messagingSync(),
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
        foreach (['consent_test_fail_purge_events', 'consent_test_fail_purge_attributes'] as $trigger) {
            $this->assertNotFalse($db->query("DROP TRIGGER IF EXISTS `{$trigger}`"));
        }
        foreach (['202_messaging_events', '202_messaging_attributes', '202_messaging_sync', '202_users_pref', '202_users'] as $table) {
            $this->assertNotFalse($db->query("DELETE FROM `{$table}`"), "cleanup of {$table} failed: " . $db->error);
        }
        $this->assertNotFalse(
            $db->query('INSERT INTO `202_users_pref` (user_id) VALUES (' . self::USER_ID . ')'),
            'seeding 202_users_pref failed: ' . $db->error
        );
        $this->assertNotFalse($db->query(
            "INSERT INTO 202_users (user_id, user_name, user_pass, user_email, user_dash_email, user_time_register, install_hash, user_hash)
             VALUES (" . self::USER_ID . ", 'consent-test', '', '', '', 0, 'h', 'h')"
        ), 'seeding 202_users failed: ' . $db->error);
    }

    protected function tearDown(): void
    {
        if (self::$db !== null) {
            foreach (['consent_test_fail_purge_events', 'consent_test_fail_purge_attributes'] as $trigger) {
                self::$db->query("DROP TRIGGER IF EXISTS `{$trigger}`");
            }
        }
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

    private function grant(): void
    {
        $this->assertTrue(ConsentPolicy::record(self::$db, self::USER_ID, 'analytics', 'granted', 'settings'));
    }

    /** A delivery the portal acknowledged, so it holds this user's block. */
    private function portalKnowsTheUser(): void
    {
        $service = $this->service(['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID]);
        $this->injectFakeClient($service);
        $this->assertSame(MessagingService::SYNC_SENT, $service->sync(true));
        $this->assertFalse($this->consentPending());
    }

    // ------------------------------------------------------------------
    // Opt-in: unset is off everywhere; the one-time question
    // ------------------------------------------------------------------

    public function test_the_question_is_asked_once_and_a_yes_turns_usage_on(): void
    {
        $db = self::$db;
        $this->assertFalse(ConsentPolicy::analyticsAllowed($db, self::USER_ID), 'unset is off');
        $this->assertTrue(ConsentPolicy::takePrompt($db, self::USER_ID));
        $this->assertFalse(ConsentPolicy::takePrompt($db, self::USER_ID), 'asked once, answered or not');

        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'granted', 'prompt'));
        $this->assertTrue(ConsentPolicy::analyticsAllowed($db, self::USER_ID));
        $this->assertFalse(ConsentPolicy::takePrompt($db, self::USER_ID));

        $row = $db->query(
            'SELECT eu_consent_prompt_seen, analytics_consent, analytics_consent_source, analytics_geo_is_eu
               FROM `202_users_pref` WHERE user_id = ' . self::USER_ID
        )->fetch_assoc();
        $this->assertSame(['1', 'granted', 'prompt', null], array_values($row));
    }

    public function test_no_thanks_is_a_no_and_the_question_never_comes_back(): void
    {
        $db = self::$db;
        $this->assertTrue(ConsentPolicy::takePrompt($db, self::USER_ID));
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'prompt'));
        $this->assertFalse(ConsentPolicy::analyticsAllowed($db, self::USER_ID));
        $this->assertFalse(ConsentPolicy::takePrompt($db, self::USER_ID));
    }

    public function test_the_question_is_not_asked_of_a_user_who_already_chose(): void
    {
        $db = self::$db;
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));
        $this->assertTrue($db->query('UPDATE 202_users_pref SET eu_consent_prompt_seen = 0 WHERE user_id = ' . self::USER_ID));
        $this->assertFalse(ConsentPolicy::takePrompt($db, self::USER_ID), 'a choice made is not asked for again');

        // An account with no preferences row is asked once, and gets a row.
        $this->assertTrue($db->query('DELETE FROM 202_users_pref'));
        $this->assertTrue(ConsentPolicy::takePrompt($db, self::USER_ID));
        $this->assertFalse(ConsentPolicy::takePrompt($db, self::USER_ID));
    }

    public function test_unset_is_off_wherever_the_user_signs_in_from(): void
    {
        $db = self::$db;
        foreach (['0', '1', 'NULL'] as $isEu) {
            $this->assertTrue($db->query("UPDATE 202_users_pref SET analytics_geo_is_eu = {$isEu} WHERE user_id = " . self::USER_ID));
            $this->assertFalse(ConsentPolicy::analyticsAllowed($db, self::USER_ID), "analytics_geo_is_eu = {$isEu}");
            $this->assertFalse(ConsentPolicy::deliveryState($db, self::USER_ID)['analytics_allowed']);
        }
        $this->assertTrue($db->query('DELETE FROM 202_users_pref'));
        $this->assertFalse(ConsentPolicy::analyticsAllowed($db, self::USER_ID), 'no row is unset, which is off');
    }

    // ------------------------------------------------------------------
    // Nothing is recorded before the opt-in, whatever the tier
    // ------------------------------------------------------------------

    public function test_nothing_is_recorded_before_the_opt_in_whatever_the_tier(): void
    {
        $db = self::$db;
        $GLOBALS['db'] = $db;
        $_SESSION['user_id'] = self::USER_ID;

        Analytics::event('feature_used', ['feature' => 'export'], 'analytics');
        Analytics::event('login', [], 'essential');
        $this->service()->recordEvent('account_created', null, 'essential');
        $this->service()->updateAttributes(['clicks_30d' => 5]);
        $this->assertSame([], $this->eventRows(), 'no event of either tier before the opt-in');
        $this->assertSame('0', $db->query('SELECT COUNT(*) FROM 202_messaging_attributes')->fetch_row()[0]);

        $this->grant();
        Analytics::event('feature_used', ['feature' => 'export'], 'analytics');
        Analytics::event('login', [], 'essential');
        $this->assertSame([['feature_used', 'analytics', 'pending'], ['login', 'essential', 'pending']],
            array_map('array_values', $this->eventRows()));

        // A no: the unsent rows go, and no tier is recorded after it.
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));
        $this->assertSame([], $this->eventRows(), 'a denial purges unsent events of every tier');
        Analytics::event('login', [], 'essential');
        $this->assertSame([], $this->eventRows(), 'a denied user records nothing');
    }

    public function test_a_sign_in_records_nothing_before_the_opt_in_and_looks_up_no_location(): void
    {
        $db = self::$db;
        $GLOBALS['db'] = $db;
        AUTH::begin_user_session(['user_id' => self::USER_ID, 'user_name' => 'opt-in', 'p202_customer_api_key' => 'k']);
        $this->assertSame([], $this->eventRows());

        $this->grant();
        AUTH::begin_user_session(['user_id' => self::USER_ID, 'user_name' => 'opt-in', 'p202_customer_api_key' => 'k']);
        $this->assertSame([['login', 'essential', 'pending']], array_map('array_values', $this->eventRows()));
        $this->assertNull($db->query('SELECT analytics_geo_is_eu FROM 202_users_pref WHERE user_id = ' . self::USER_ID)->fetch_row()[0]);
    }

    public function test_denial_purges_snapshot_and_undelivered_events(): void
    {
        $db = self::$db;
        $this->grant();
        $service = $this->service();

        $service->updateAttributes(['clicks_30d' => 5, 'offers' => [['name' => 'Keto - US', 'url' => 'https://x.example/o']]]);
        $service->recordEvent('page_viewed', ['path' => '/tracking202/'], 'analytics');
        $service->recordEvent('login', null, 'essential');

        $snapshot = $db->query('SELECT data, dirty FROM `202_messaging_attributes` WHERE user_id = ' . self::USER_ID)->fetch_assoc();
        $this->assertNotNull($snapshot, 'profile snapshot must be written while consented');
        $this->assertStringContainsString('Keto - US', (string) $snapshot['data']);
        $this->assertCount(2, $this->eventRows());

        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));

        $this->assertSame('0', $db->query('SELECT COUNT(*) FROM `202_messaging_attributes` WHERE user_id = ' . self::USER_ID)->fetch_row()[0],
            'denial must purge the snapshot');
        $this->assertSame([], $this->eventRows(), 'denial must purge every undelivered event');
    }

    // ------------------------------------------------------------------
    // Spec §9 transport boundary: what actually goes over the wire
    // ------------------------------------------------------------------

    public function test_nothing_is_sent_for_a_user_who_never_opted_in(): void
    {
        $db = self::$db;
        $identity = ['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID,
                     'attributes' => ['clicks_30d' => 9, 'offers' => [['name' => 'Keto - US']]]];
        $service = $this->service($identity);
        $fake = $this->injectFakeClient($service);

        // Rows an older version wrote unchecked: the transport boundary is the defense.
        foreach (['essential', 'analytics'] as $tier) {
            $this->assertTrue($db->query("INSERT INTO 202_messaging_events (user_id, event_name, client_token, tier) VALUES ("
                . self::USER_ID . ", 'left_{$tier}', 'left-{$tier}', '{$tier}')"));
        }
        $this->assertTrue($db->query("INSERT INTO 202_messaging_attributes (user_id, data, dirty, consent_version) VALUES ("
            . self::USER_ID . ", '{\"clicks_30d\":9}', 1, 99)"));

        $this->assertSame([], MessagingService::usersWithPendingDelivery($db), 'the cron visits nobody who never opted in');
        $this->assertSame(MessagingService::SYNC_IDLE, $service->sync(true));
        $this->assertSame([], $fake->trackCalls, 'no call at all: not even the consent block');

        // A no from a user the portal never heard of is not announced either.
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'email_marketing', 'denied', 'settings'));
        $this->assertSame([], MessagingService::usersWithPendingDelivery($db));
        $this->assertSame(MessagingService::SYNC_IDLE, $service->sync(true));
        $this->assertSame([], $fake->trackCalls);
        $this->assertFalse(ConsentPolicy::deliveryState($db, self::USER_ID)['dirty']);
    }

    public function test_after_a_yes_only_what_was_recorded_after_it_is_sent(): void
    {
        $db = self::$db;
        // Left from before the yes (an older version wrote them unchecked).
        foreach (['essential', 'analytics'] as $tier) {
            $this->assertTrue($db->query("INSERT INTO 202_messaging_events (user_id, event_name, client_token, tier, consent_version) VALUES ("
                . self::USER_ID . ", 'before_{$tier}', 'before-{$tier}', '{$tier}', UNIX_TIMESTAMP() - 60)"));
        }
        $this->assertTrue($db->query("INSERT INTO 202_messaging_attributes (user_id, data, dirty, consent_version) VALUES ("
            . self::USER_ID . ", '{\"before\":1}', 1, UNIX_TIMESTAMP() - 60)"));
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'granted', 'prompt'));

        $service = $this->service(['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID]);
        $fake = $this->injectFakeClient($service);
        $service->recordEvent('login', null, 'essential');
        $service->recordEvent('page_viewed', ['path' => '/x'], 'analytics');

        $this->assertSame(MessagingService::SYNC_SENT, $service->sync(true));
        $this->assertCount(1, $fake->trackCalls);
        $track = $fake->trackCalls[0];
        $this->assertSame(['login', 'page_viewed'], array_column($track['events'], 'name'), 'nothing from before the yes');
        $this->assertSame(['essential', 'analytics'], array_column($track['events'], 'tier'), 'tier travels in the payload');
        $this->assertSame([], $track['attributes'], 'the snapshot from before the yes stays');
        $this->assertSame('granted', $track['consent']['analytics'] ?? null);
        $this->assertSame('prompt', $track['consent']['analytics_source'] ?? null);

        $service->updateAttributes(['clicks_30d' => 9]);
        $this->assertSame(MessagingService::SYNC_SENT, $service->sync(true));
        $this->assertSame(9, $fake->trackCalls[1]['attributes']['clicks_30d'] ?? null);
        $this->assertSame(9, $fake->trackCalls[1]['identity']['attributes']['clicks_30d'] ?? null);
        $this->assertArrayNotHasKey('before', $fake->trackCalls[1]['attributes']);
    }

    public function test_a_yes_to_email_alone_sends_the_consent_block_and_no_usage(): void
    {
        $db = self::$db;
        $this->assertTrue($db->query("INSERT INTO 202_messaging_events (user_id, event_name, client_token, tier) VALUES ("
            . self::USER_ID . ", 'left', 'left-1', 'essential')"));
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'email_marketing', 'granted', 'settings'));
        $service = $this->service(['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID,
                                   'attributes' => ['clicks_30d' => 9]]);
        $fake = $this->injectFakeClient($service);

        $this->assertSame(MessagingService::SYNC_SENT, $service->sync(true));
        $this->assertCount(1, $fake->trackCalls);
        $this->assertSame([], $fake->trackCalls[0]['events']);
        $this->assertArrayNotHasKey('attributes', $fake->trackCalls[0]['identity']);
        $this->assertSame(['unset', 'granted'],
            [$fake->trackCalls[0]['consent']['analytics'], $fake->trackCalls[0]['consent']['email_marketing']]);
    }

    // ------------------------------------------------------------------
    // Consent delivery: a change is pending until the receiver acknowledges it
    // ------------------------------------------------------------------

    public function test_a_revocation_from_an_empty_queue_is_delivered_until_acknowledged(): void
    {
        $db = self::$db;
        $this->grant();
        $this->portalKnowsTheUser();
        $this->assertSame([], $this->eventRows());
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));
        $service = $this->service(['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID]);
        $fake = $this->injectFakeClient($service);

        $fake->answer = 'unreachable';
        $this->assertSame(MessagingService::SYNC_FAILED, $service->sync(true));
        $this->assertTrue($this->consentPending());

        $fake->answer = 'ok';
        $this->assertSame(MessagingService::SYNC_UNACKED, $service->sync(true), 'ok without an acknowledgement');
        $this->assertTrue($this->consentPending());

        $fake->answer = 'stale-ack';
        $this->assertSame(MessagingService::SYNC_UNACKED, $service->sync(true), 'an acknowledgement of another version');
        $this->assertTrue($this->consentPending());

        $fake->answer = 'ack';
        $this->assertSame(MessagingService::SYNC_SENT, $service->sync(true));
        $this->assertFalse($this->consentPending());
        $this->assertSame(MessagingService::SYNC_IDLE, $service->sync(true));

        $this->assertCount(4, $fake->trackCalls);
        foreach ($fake->trackCalls as $call) {
            $this->assertSame('denied', $call['consent']['analytics']);
            $this->assertSame([], $call['events']);
            $this->assertArrayNotHasKey('attributes', $call['identity']);
        }
    }

    public function test_a_no_after_a_yes_whose_acknowledgement_was_lost_is_still_delivered(): void
    {
        $db = self::$db;
        $this->grant();
        $service = $this->service(['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID]);
        $fake = $this->injectFakeClient($service);

        // The yes may have reached the portal, but no answer came back.
        foreach (['unreachable', 'ok'] as $answer) {
            $fake->answer = $answer;
            $this->assertNotSame(MessagingService::SYNC_SENT, $service->sync(true));
        }
        $this->assertSame('granted', $fake->trackCalls[0]['consent']['analytics'] ?? null);
        $this->assertTrue($db->query('SELECT consent_acked_version FROM 202_users_pref WHERE user_id = ' . self::USER_ID)
            ->fetch_row()[0] === '0', 'nothing was acknowledged');

        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));
        $this->assertTrue($this->consentPending(), 'the no goes to a portal that may hold the yes');
        $fake->answer = 'ack';
        $this->assertSame(MessagingService::SYNC_SENT, $service->sync(true));
        $this->assertSame('denied', end($fake->trackCalls)['consent']['analytics'] ?? null);
        $this->assertFalse($this->consentPending());
    }

    public function test_a_change_made_during_a_delivery_stays_pending_after_its_acknowledgement(): void
    {
        $db = self::$db;
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'email_marketing', 'granted', 'settings'));
        $service = $this->service(['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID]);
        $fake = $this->injectFakeClient($service);
        $fake->onTrack = function () use ($db): void {
            $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'email_marketing', 'denied', 'settings'));
        };

        $this->assertSame(MessagingService::SYNC_MORE, $service->sync(true), 'the denial is still to go');
        $this->assertSame('granted', $fake->trackCalls[0]['consent']['email_marketing']);
        $this->assertTrue($this->consentPending());

        $fake->onTrack = null;
        $this->assertSame(MessagingService::SYNC_SENT, $service->sync(true));
        $this->assertSame('denied', $fake->trackCalls[1]['consent']['email_marketing'], 'read again for each delivery');
        $this->assertFalse($this->consentPending());
    }

    // ------------------------------------------------------------------
    // What was captured before a revocation never leaves after a re-grant
    // ------------------------------------------------------------------

    public function test_rows_a_failed_purge_left_are_never_delivered_after_a_regrant(): void
    {
        $db = self::$db;
        $this->grant();
        $service = $this->service(['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID]);
        $service->recordEvent('before_denial', null, 'analytics');
        $service->recordEvent('login', null, 'essential');
        $service->updateAttributes(['before_denial' => 'private']);

        foreach (['consent_test_fail_purge_events' => '202_messaging_events', 'consent_test_fail_purge_attributes' => '202_messaging_attributes'] as $trigger => $table) {
            $this->assertNotFalse($db->query("CREATE TRIGGER `{$trigger}` BEFORE DELETE ON `{$table}` FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'purge refused by the test'"), $db->error);
        }
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'), 'the denial stands');
        $this->assertCount(2, $this->eventRows(), 'the purge failed');
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'granted', 'settings'));

        $after = $this->service(['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID]);
        $after->recordEvent('after_regrant', null, 'analytics');
        $fake = $this->injectFakeClient($after);
        $this->assertSame(MessagingService::SYNC_SENT, $after->sync(true));
        $this->assertSame(['after_regrant'], array_column($fake->trackCalls[0]['events'], 'name'),
            'neither tier from before the denial');
        $this->assertSame([], $fake->trackCalls[0]['attributes'], 'the pre-denial snapshot stays');
        $this->assertArrayNotHasKey('before_denial', $fake->trackCalls[0]['identity']['attributes'] ?? []);

        $this->assertSame([], MessagingService::usersWithPendingDelivery($db),
            'a row no delivery may send does not bring the user back every run');
        $after->updateAttributes(['fresh' => 1]);
        $this->assertSame(['fresh' => 1], json_decode($db->query('SELECT data FROM 202_messaging_attributes WHERE user_id = ' . self::USER_ID)->fetch_row()[0], true),
            'a new snapshot starts empty rather than from the revoked one');
    }

    public function test_a_capture_begun_before_a_denial_writes_nothing_after_a_regrant(): void
    {
        $db = self::$db;
        $this->grant();
        $begun = $this->service();
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'granted', 'settings'));

        $begun->recordEvent('began_before_denial', null, 'analytics');
        $begun->recordEvent('login', null, 'essential');
        $begun->updateAttributes(['began_before_denial' => 'private']);
        $this->assertSame([], $this->eventRows());
        $this->assertSame('0', $db->query('SELECT COUNT(*) FROM 202_messaging_attributes')->fetch_row()[0]);

        $this->service()->recordEvent('after_regrant', null, 'analytics');
        $this->assertSame('after_regrant', $this->eventRows()[0]['event_name'] ?? null);
    }

    public function test_a_second_yes_keeps_what_the_first_one_recorded(): void
    {
        $db = self::$db;
        $this->grant();
        $this->service()->recordEvent('after_first_yes', null, 'analytics');
        $fence = $db->query('SELECT analytics_revoked_version FROM 202_users_pref WHERE user_id = ' . self::USER_ID)->fetch_row()[0];
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'granted', 'settings'));
        $this->assertSame($fence, $db->query('SELECT analytics_revoked_version FROM 202_users_pref WHERE user_id = ' . self::USER_ID)->fetch_row()[0],
            'a yes on a yes does not move the fence');

        $service = $this->service(['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID]);
        $fake = $this->injectFakeClient($service);
        $this->assertSame(MessagingService::SYNC_SENT, $service->sync(true));
        $this->assertSame(['after_first_yes'], array_column($fake->trackCalls[0]['events'], 'name'));
    }

    public function test_the_upgrade_adds_the_consent_versions_without_marking_anything_pending(): void
    {
        $db = self::$db;
        $this->assertTrue($db->query("UPDATE 202_users_pref SET email_marketing_consent = 'denied' WHERE user_id = " . self::USER_ID));
        $this->assertTrue($db->query("INSERT INTO 202_messaging_events (user_id, event_name, client_token, tier) VALUES ("
            . self::USER_ID . ", 'queued', 't1', 'essential')"));
        foreach ([
            'ALTER TABLE 202_users_pref DROP COLUMN consent_version, DROP COLUMN consent_acked_version, DROP COLUMN analytics_revoked_version',
            'ALTER TABLE 202_messaging_events DROP COLUMN consent_version',
            'ALTER TABLE 202_messaging_attributes DROP COLUMN consent_version',
        ] as $sql) {
            $this->assertTrue($db->query($sql), $db->error);
        }
        foreach ([UserTables::usersPref(), CoreTables::messagingEvents(), CoreTables::messagingAttributes()] as $definition) {
            $reconciler = new \Prosper202\Database\SchemaReconciler(static fn (string $sql) => $db->query($sql));
            $this->assertTrue($reconciler->reconcile($definition), $definition->tableName);
            $again = new \Prosper202\Database\SchemaReconciler(static fn (string $sql) => $db->query($sql));
            $this->assertTrue($again->reconcile($definition));
            $this->assertSame([], $again->getApplied(), $definition->tableName . ' converges');
        }
        $this->assertSame(['denied', '0', '0', '0'], $db->query(
            'SELECT email_marketing_consent, consent_version, consent_acked_version, analytics_revoked_version FROM 202_users_pref WHERE user_id = ' . self::USER_ID
        )->fetch_row());
        $this->assertSame([], MessagingService::usersWithPendingDelivery($db), 'nothing goes for a user who never opted in');
        $this->grant();
        $this->assertSame([self::USER_ID], MessagingService::usersWithPendingDelivery($db), 'the yes is pending');
        $service = $this->service(['install_hash' => 'h', 'api_key' => 'k', 'user_id' => self::USER_ID]);
        $fake = $this->injectFakeClient($service);
        $this->assertSame(MessagingService::SYNC_SENT, $service->sync(true));
        $this->assertSame([], $fake->trackCalls[0]['events'], 'the event queued before the yes stays');
        $this->assertSame([], MessagingService::usersWithPendingDelivery($db));
    }

    public function test_a_denial_purges_withheld_rows_like_any_other_unsent_analytics(): void
    {
        $db = self::$db;
        $this->grant();
        $this->portalKnowsTheUser();
        $this->assertTrue($db->query("INSERT INTO 202_messaging_events (user_id, event_name, client_token, tier, delivery_status) VALUES ("
            . self::USER_ID . ", 'before_consent', 'w1', 'analytics', 'withheld')"));
        $this->assertTrue($db->query("INSERT INTO 202_messaging_attributes (user_id, data, withheld) VALUES ("
            . self::USER_ID . ", '{}', 1)"));
        $this->assertSame([], MessagingService::usersWithPendingDelivery($db), 'withheld rows wait for nothing');

        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));
        $this->assertSame([], $this->eventRows());
        $this->assertSame('0', $db->query('SELECT COUNT(*) FROM 202_messaging_attributes')->fetch_row()[0]);
    }

    public function test_a_stored_value_that_is_no_choice_holds_analytics(): void
    {
        $db = self::$db;
        // The UI path's sql_mode is '': an enum value outside the list is stored as ''.
        $this->assertTrue($db->query("UPDATE 202_users_pref SET analytics_consent = 'maybe' WHERE user_id = " . self::USER_ID));
        $this->assertSame('', $db->query('SELECT analytics_consent FROM 202_users_pref WHERE user_id = ' . self::USER_ID)->fetch_row()[0]);

        $state = ConsentPolicy::deliveryState($db, self::USER_ID);
        $this->assertFalse($state['analytics_allowed'], 'held, not read as unset');
        $this->assertSame('unset', $state['consent']['analytics']);
        $this->assertFalse(ConsentPolicy::analyticsAllowed($db, self::USER_ID));
        $this->assertFalse(ConsentPolicy::takePrompt($db, self::USER_ID), 'not unset either, so not asked');
    }

    public function test_a_restored_backup_never_reuses_a_consent_version(): void
    {
        $db = self::$db;
        $before = time();
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'email_marketing', 'granted', 'settings'));
        $first = ConsentPolicy::deliveryState($db, self::USER_ID)['version'];
        $this->assertGreaterThanOrEqual($before, $first, 'at least the Unix time of the change');

        // The database comes back from a backup taken before that change.
        $this->assertTrue($db->query('UPDATE 202_users_pref SET consent_version = 3, consent_acked_version = 3 WHERE user_id = ' . self::USER_ID));
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'email_marketing', 'denied', 'settings'));
        $this->assertGreaterThanOrEqual($first, ConsentPolicy::deliveryState($db, self::USER_ID)['version']);
        $this->assertTrue(ConsentPolicy::record($db, self::USER_ID, 'analytics', 'denied', 'settings'));
        $row = $db->query('SELECT consent_version, analytics_revoked_version FROM 202_users_pref WHERE user_id = ' . self::USER_ID)->fetch_row();
        $this->assertSame($row[0], $row[1], 'the revocation is the version of the denial');
    }

    private function consentPending(): bool
    {
        return MessagingService::usersWithPendingDelivery(self::$db) === [self::USER_ID];
    }
}

/**
 * Captures every wire call instead of talking to my.tracking202.com, so the
 * consent boundary can be asserted on the exact payloads that would have
 * been transmitted.
 */
final class ConsentSyncFakeClient extends MessagingClient
{
    /** @var array<int,array{identity:array,attributes:array,events:array,consent:?array}> */
    public array $trackCalls = [];
    /** 'ack' (the version sent), 'stale-ack', 'ok' (no acknowledgement) or 'unreachable'. */
    public string $answer = 'ack';
    /** @var (callable(): void)|null run while the request is in flight */
    public $onTrack = null;

    public function track(array $identity, array $attributes, array $events, ?array $consent = null): ?array
    {
        $this->trackCalls[] = ['identity' => $identity, 'attributes' => $attributes, 'events' => $events, 'consent' => $consent];
        if ($this->onTrack !== null) {
            ($this->onTrack)();
        }
        return match ($this->answer) {
            'unreachable' => null,
            'ok' => ['ok' => true],
            'stale-ack' => ['ok' => true, 'consent_ack' => (int) ($consent['version'] ?? 0) - 1],
            default => ['ok' => true, 'consent_ack' => $consent['version'] ?? null],
        };
    }
}
