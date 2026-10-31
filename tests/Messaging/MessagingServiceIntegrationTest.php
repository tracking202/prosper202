<?php

declare(strict_types=1);

namespace Tests\Messaging;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Database\Tables\CoreTables;
use Prosper202\Database\Tables\UserTables;

/**
 * MessagingService on a server: the identity both the analytics delivery and
 * the chat pass are built from (identityFor()), and sync()'s outcomes against
 * a stub portal on loopback (LocalHttpRecorder).
 *
 * identityFor() answers the user's own key, else the first active user's on
 * the same install (sub-users), else ''; nothing for a deactivated or deleted
 * user; and throws when the user cannot be read, because '' would hide the
 * chat box as "this install has no key" (CLAUDE.md #11).
 *
 * sync() is idle with nothing pending (and leaves the throttle alone),
 * delivers what is pending, holds back within MESSAGING_SYNC_THROTTLE and says
 * for how long, and reports a portal it cannot reach as failed, with the
 * event kept for the next try. A backlog goes out in batches, and "more" says
 * rows are still pending. deliverPendingExclusive(), the hourly cron's run,
 * visits only active users with something pending and stops after failures in
 * a row (OneAnalyticsDeliveryAtATimeIntegrationTest holds its lock).
 *
 * Each test runs in its own process: MESSAGING_API_URL is a constant.
 *
 * @group integration
 */
final class MessagingServiceIntegrationTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheIdentityOfEachKindOfUser(): void
    {
        $db = $this->connect();
        $this->seedUsers($db);

        $owner = \MessagingService::identityFor($db, 1);
        $this->assertSame([
            'install_hash' => 'ih_install',
            'api_key' => 'key-of-the-owner',
            'user_id' => 1,
            'user_email' => 'owner@acme.test',
            'user_name' => 'Olive Owner',
            'registered_at' => 1700000000,
        ], $owner);

        $sub = \MessagingService::identityFor($db, 2);
        $this->assertSame('key-of-the-owner', $sub['api_key'] ?? null, 'a sub-user without a key takes the install owner\'s');
        $this->assertSame('sam', $sub['user_name'] ?? null, 'no first or last name: the sign-in name');

        $this->assertNull(\MessagingService::identityFor($db, 3), 'a deactivated user has no identity');
        $this->assertNull(\MessagingService::identityFor($db, 4), 'a deleted user has no identity');
        $this->assertNull(\MessagingService::identityFor($db, 99), 'no such user');

        $alone = \MessagingService::identityFor($db, 5);
        $this->assertSame('', $alone['api_key'] ?? null, 'an install with no key anywhere: empty, never another install\'s');
        $this->assertSame(100, mb_strlen((string) ($alone['user_name'] ?? '')), 'a name is cut to the pass\'s 100 characters');

        // 50 emoji and a surname: 102 UTF-16 code units, which the portal
        // would send as no name at all. Cut at a character, to 100 units.
        $emoji = \MessagingService::identityFor($db, 8)['user_name'] ?? '';
        $this->assertSame(str_repeat("\u{1F600}", 50), $emoji);
        $this->assertSame(200, strlen((string) mb_convert_encoding($emoji, 'UTF-16LE', 'UTF-8')));

        // The only active key on install 6's hash is a deactivated user's.
        $this->assertSame('', \MessagingService::identityFor($db, 6)['api_key'] ?? null, 'a deactivated user\'s key is not borrowed');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAnUnreadableUserIsAnErrorNotAnEmptyKey(): void
    {
        $db = $this->connect();
        $this->assertTrue($db->query('RENAME TABLE 202_users TO 202_users_unreadable_probe'));
        try {
            \MessagingService::identityFor($db, 1);
            $this->fail('a user that cannot be read answered an identity');
        } catch (QueryException $e) {
            $this->assertStringContainsString('202_users', $e->getMessage());
        } finally {
            $this->assertTrue($db->query('RENAME TABLE 202_users_unreadable_probe TO 202_users'));
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSyncDeliversHoldsBackAndSaysWhen(): void
    {
        $portal = LocalHttpRecorder::start();
        try {
            define('MESSAGING_API_URL', $portal->url() . '/api/v3/messaging');
            define('MESSAGING_SYNC_THROTTLE', 20);
            $db = $this->connect();
            $this->seedUsers($db);
            $identity = \MessagingService::identityFor($db, 1);
            $this->assertIsArray($identity);
            $service = new \MessagingService($db, 1, $identity);

            $this->assertSame(\MessagingService::SYNC_IDLE, $service->sync());
            $this->assertSame(0, $service->secondsUntilSyncDue(), 'an idle sync leaves the throttle alone');
            $this->assertSame([], $portal->requests());

            $service->recordEvent('created_tracker', ['tracker_id' => 42]);
            $this->assertSame(\MessagingService::SYNC_SENT, $service->sync());
            $sent = json_decode($portal->requests()[0]['body'] ?? 'null', true);
            $this->assertSame('/api/v3/messaging/track', $portal->requests()[0]['target'] ?? null);
            $this->assertSame('created_tracker', $sent['events'][0]['name'] ?? null);
            $this->assertSame(['tracker_id' => 42], $sent['events'][0]['metadata'] ?? null);
            $this->assertSame('key-of-the-owner', $sent['identity']['api_key'] ?? null);
            $this->assertSame([['sent']], $db->query('SELECT delivery_status FROM 202_messaging_events')->fetch_all());

            $service->recordEvent('created_campaign');
            $this->assertSame(\MessagingService::SYNC_THROTTLED, $service->sync());
            $wait = $service->secondsUntilSyncDue();
            $this->assertGreaterThan(0, $wait);
            $this->assertLessThanOrEqual(20, $wait);
            $this->assertCount(1, $portal->requests(), 'held back: nothing more was sent');

            $service->updateAttributes(['plan' => 'pro']);
            $this->assertSame(\MessagingService::SYNC_SENT, $service->sync(true), 'the cron forces past the throttle');
            $sent = json_decode($portal->requests()[1]['body'] ?? 'null', true);
            $this->assertSame(['created_campaign'], array_column($sent['events'] ?? [], 'name'));
            $this->assertSame(['plan' => 'pro'], $sent['attributes'] ?? null);
            $this->assertSame(\MessagingService::SYNC_IDLE, $service->sync(true), 'delivered rows are not sent again');
        } finally {
            $portal->stop();
        }
    }

    /**
     * Attributes saved by another request while a delivery is in flight are
     * still pending afterwards: the delivery clears only the version it sent.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAttributesSavedDuringADeliveryAreSentNextTime(): void
    {
        define('MESSAGING_API_URL', 'http://127.0.0.1:9/api/v3/messaging');
        $db = $this->connect();
        $this->seedUsers($db);
        $identity = (array) \MessagingService::identityFor($db, 1);
        $service = new \MessagingService($db, 1, $identity);

        $sent = [];
        $portal = new class (static function (array $attributes) use (&$sent, $db, $identity): void {
            $sent[] = $attributes;
            if (count($sent) === 1) {
                (new \MessagingService($db, 1, $identity))->updateAttributes(['plan' => 'enterprise']);
            }
        }) extends \MessagingClient {
            public function __construct(private \Closure $onTrack)
            {
            }

            public function track(array $identity, array $attributes, array $events, ?array $consent = null): ?array
            {
                ($this->onTrack)($attributes);
                return ['ok' => true];
            }
        };
        (new \ReflectionProperty(\MessagingService::class, 'client'))->setValue($service, $portal);

        $service->updateAttributes(['plan' => 'pro']);
        $this->assertSame(\MessagingService::SYNC_MORE, $service->sync(), 'delivered, and newer attributes are waiting');
        $this->assertSame([['plan' => 'pro']], $sent);
        $this->assertSame(
            [['1']],
            $db->query('SELECT dirty FROM 202_messaging_attributes WHERE user_id = 1')->fetch_all(),
            'the newer attributes are still pending'
        );

        $this->assertSame(\MessagingService::SYNC_SENT, $service->sync(true));
        $this->assertSame(['plan' => 'enterprise'], $sent[1] ?? null);
        $this->assertSame([['0']], $db->query('SELECT dirty FROM 202_messaging_attributes WHERE user_id = 1')->fetch_all());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAPortalThatCannotBeReachedIsAFailureAndTheEventWaits(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $closed = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        define('MESSAGING_API_URL', 'http://' . $closed . '/api/v3/messaging');
        $log = tempnam(sys_get_temp_dir(), 'p202-messaging-sync-');
        $this->assertIsString($log);
        ini_set('error_log', $log);
        $db = $this->connect();
        $this->seedUsers($db);
        $service = new \MessagingService($db, 1, (array) \MessagingService::identityFor($db, 1));

        $service->recordEvent('created_tracker');
        $this->assertSame(\MessagingService::SYNC_FAILED, $service->sync());
        $this->assertSame(
            [['pending', '1']],
            $db->query('SELECT delivery_status, sync_attempts FROM 202_messaging_events')->fetch_all(),
            'kept for the next sync, one attempt counted'
        );
        $this->assertSame(
            [['1', 'track failed']],
            $db->query('SELECT error_count, last_error FROM 202_messaging_sync WHERE user_id = 1')->fetch_all()
        );
        $this->assertStringContainsString('failed to reach track', (string) file_get_contents($log));
        unlink($log);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testABacklogGoesOutInBatchesAndMoreSaysSomethingIsLeft(): void
    {
        $portal = LocalHttpRecorder::start();
        try {
            define('MESSAGING_API_URL', $portal->url() . '/api/v3/messaging');
            $db = $this->connect();
            $this->seedUsers($db);
            $service = new \MessagingService($db, 1, (array) \MessagingService::identityFor($db, 1));
            for ($i = 1; $i <= 250; $i++) {
                $service->recordEvent('event_' . $i);
            }
            $pending = static fn (): int => (int) $db->query("SELECT COUNT(*) FROM 202_messaging_events WHERE delivery_status = 'pending'")->fetch_row()[0];

            $this->assertSame(\MessagingService::SYNC_MORE, $service->sync(true), 'one batch went out and 150 are left');
            $this->assertSame(150, $pending());
            $this->assertSame(\MessagingService::SYNC_MORE, $service->sync(true, 1), 'still more after the second batch');
            $this->assertSame(\MessagingService::SYNC_SENT, $service->sync(true, 10), 'the last 50 go out and nothing is left');
            $this->assertSame(0, $pending());
            $this->assertSame(\MessagingService::SYNC_IDLE, $service->sync(true, 10));

            $batches = array_map(static fn (array $r): array => array_column(json_decode($r['body'], true)['events'] ?? [], 'name'), $portal->requests());
            $this->assertSame([100, 100, 50], array_map('count', $batches), 'one /track call per batch');
            $this->assertSame('event_1', $batches[0][0], 'oldest first');
            $this->assertSame('event_250', $batches[2][49]);
            $this->assertCount(250, array_unique(array_merge(...$batches)), 'nothing sent twice');
        } finally {
            $portal->stop();
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheCronVisitsOnlyActiveUsersWithSomethingPending(): void
    {
        $portal = LocalHttpRecorder::start();
        try {
            define('MESSAGING_API_URL', $portal->url() . '/api/v3/messaging');
            $db = $this->connect();
            $this->seedUsers($db);
            (new \MessagingService($db, 1, []))->recordEvent('created_tracker');
            (new \MessagingService($db, 6, []))->updateAttributes(['plan' => 'pro']);
            (new \MessagingService($db, 3, []))->recordEvent('deactivated_user_event');
            (new \MessagingService($db, 4, []))->recordEvent('deleted_user_event');
            // User 2 has only a delivered event; user 8 one out of attempts.
            $this->assertTrue($db->query("INSERT INTO 202_messaging_events (user_id, event_name, client_token, delivery_status) VALUES (2, 'old', 't2', 'sent')"));
            $this->assertTrue($db->query("INSERT INTO 202_messaging_events (user_id, event_name, client_token, delivery_status, sync_attempts) VALUES (8, 'poison', 't8', 'pending', 5)"));

            $this->assertSame([1, 6], \MessagingService::usersWithPendingDelivery($db));
            $this->assertSame(
                ['sent' => 2, 'more' => 0, 'idle' => 0, 'failed' => 0, 'stopped' => 0],
                \MessagingService::deliverPendingExclusive($db, $this->lockPath())
            );
            $who = array_map(static fn (array $r): int => (int) (json_decode($r['body'], true)['identity']['user_id'] ?? 0), $portal->requests());
            $this->assertSame([1, 6], $who);
            $this->assertSame([], \MessagingService::usersWithPendingDelivery($db), 'nothing deliverable is left');
            $this->assertSame(
                [['3', 'pending'], ['4', 'pending']],
                $db->query("SELECT user_id, delivery_status FROM 202_messaging_events WHERE user_id IN (3, 4) ORDER BY user_id")->fetch_all(),
                'a deactivated or deleted user\'s rows are not sent'
            );
        } finally {
            $portal->stop();
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheCronStopsAfterFailuresInARow(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $closed = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        define('MESSAGING_API_URL', 'http://' . $closed . '/api/v3/messaging');
        $log = tempnam(sys_get_temp_dir(), 'p202-messaging-cron-');
        $this->assertIsString($log);
        ini_set('error_log', $log);
        $db = $this->connect();
        $this->seedUsers($db);
        foreach ([1, 2, 5, 6, 8] as $userId) {
            (new \MessagingService($db, $userId, []))->recordEvent('waiting');
        }

        $this->assertSame(
            ['sent' => 0, 'more' => 0, 'idle' => 0, 'failed' => 3, 'stopped' => 2],
            \MessagingService::deliverPendingExclusive($db, $this->lockPath(), 10, 3)
        );
        $this->assertSame(
            [['1', '1'], ['2', '1'], ['5', '1'], ['6', '0'], ['8', '0']],
            $db->query('SELECT user_id, sync_attempts FROM 202_messaging_events ORDER BY user_id')->fetch_all(),
            'the users not tried keep every attempt'
        );
        $this->assertStringContainsString('3 deliveries failed in a row; 2 user(s) wait for the next run', (string) file_get_contents($log));
        unlink($log);
    }

    /** The delivery's lock, in this test's own place: each test runs in a process of its own. */
    private function lockPath(): string
    {
        return sys_get_temp_dir() . '/p202-analytics-delivery-' . getmypid() . '.lock';
    }

    protected function tearDown(): void
    {
        if (is_file($this->lockPath())) {
            unlink($this->lockPath());
        }
    }

    private function connect(): \mysqli
    {
        $name = getenv('P202_TEST_DB_NAME') ?: '';
        if ($name === '' || (getenv('P202_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('Set P202_TEST_DB_HOST and P202_TEST_DB_NAME to a scratch database.');
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            $name,
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        if ($db->connect_errno) {
            self::markTestSkipped('The scratch database could not be reached: ' . $db->connect_error);
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        require_once __DIR__ . '/../../202-config/Messaging/MessagingService.class.php';

        // A scratch database another suite installed carries foreign keys
        // onto 202_users; they do not concern these tables' rows.
        $this->assertTrue($db->query('SET FOREIGN_KEY_CHECKS = 0'));
        foreach ([UserTables::users(), UserTables::usersPref(), CoreTables::messagingSync(), CoreTables::messagingEvents(), CoreTables::messagingAttributes()] as $definition) {
            $this->assertTrue($db->query('DROP TABLE IF EXISTS `' . $definition->tableName . '`'));
            $this->assertTrue($db->query($definition->createStatement));
        }
        $this->assertTrue($db->query('SET FOREIGN_KEY_CHECKS = 1'));

        return $db;
    }

    private function seedUsers(\mysqli $db): void
    {
        $long = str_repeat('n', 50);
        foreach ([
            // id, first, last, sign-in, email, install hash, key, active, deleted
            [1, 'Olive', 'Owner', 'olive', 'owner@acme.test', 'ih_install', 'key-of-the-owner', 1, 0],
            [2, '', null, 'sam', 'sam@acme.test', 'ih_install', null, 1, 0],
            [3, 'Dee', 'Activated', 'dee', 'dee@acme.test', 'ih_install', 'key-of-dee', 0, 0],
            [4, 'Del', 'Eted', 'del', 'del@acme.test', 'ih_install', 'key-of-del', 1, 1],
            [5, $long, $long, 'alone', 'alone@other.test', 'ih_alone', '', 1, 0],
            [6, 'Six', '', 'six', 'six@third.test', 'ih_third', null, 1, 0],
            [7, 'Off', '', 'off', 'off@third.test', 'ih_third', 'key-of-a-deactivated-user', 0, 0],
            [8, str_repeat("\u{1F600}", 50), 'Lee', 'smile', 'smile@acme.test', 'ih_install', null, 1, 0],
        ] as [$id, $first, $last, $login, $email, $hash, $key, $active, $deleted]) {
            $stmt = $db->prepare(
                'INSERT INTO 202_users (user_id, user_fname, user_lname, user_name, user_pass, user_email, user_dash_email,
                    user_time_register, install_hash, user_hash, user_active, user_deleted, p202_customer_api_key)
                 VALUES (?, ?, ?, ?, \'x\', ?, \'\', 1700000000, ?, \'uh\', ?, ?, ?)'
            );
            $this->assertNotFalse($stmt);
            $stmt->bind_param('isssssiis', $id, $first, $last, $login, $email, $hash, $active, $deleted, $key);
            $this->assertTrue($stmt->execute());
            $stmt->close();
        }
    }
}
