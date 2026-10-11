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
 * event kept for the next try.
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
        foreach ([UserTables::users(), CoreTables::messagingSync(), CoreTables::messagingEvents(), CoreTables::messagingAttributes()] as $definition) {
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
