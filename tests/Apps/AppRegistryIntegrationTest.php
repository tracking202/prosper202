<?php

declare(strict_types=1);

namespace Tests\Apps;

use Api\V3\Apps\AppRetention;
use Api\V3\Apps\Apple\PostbackReceiver;
use Api\V3\Controllers\AppRegistrationsController;
use Api\V3\Controllers\AppSkanEncodingsController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;
use Prosper202\User\UserDataPurge;

/**
 * The app registry, its encodings, the user-deletion purge and retention
 * against a real MySQL/MariaDB — the SQL the unit doubles can only capture
 * (plan §4.2, §4.5, §4.6).
 *
 * Skips unless P202_TEST_DB_HOST (and friends) name a scratch database; it
 * installs the full schema there and truncates the tables it uses.
 *
 * @group integration
 */
final class AppRegistryIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;

    private const OWNER = 7;
    private const OTHER = 8;

    private const TABLES = [
        '202_app_registrations', '202_app_postbacks', '202_app_skan_encodings',
        '202_clicks', '202_clicks_visitor', '202_identity_keys', '202_identity_visitors',
        '202_identity_signals', '202_identity_observations', '202_identity_merges',
        '202_attribution_models', '202_attribution_audit', '202_attribution_credits',
        '202_attribution_journeys', '202_attribution_journey_meta', '202_attribution_exports',
        '202_attribution_pending', '202_conversion_logs',
        '202_goals', '202_goal_versions', '202_aff_campaigns',
    ];

    /** A live plain event goal (what an SKAN encoding names, plan §4.5). */
    private static function plainGoal(int $userId, string $event, string $scope = 'account', int $scopeId = 0): int
    {
        return (new \Prosper202\Goals\MysqlGoalRepository(new \Prosper202\Database\Connection(self::$db)))->create(
            $userId,
            \Prosper202\Goals\GoalScope::from($scope),
            $scopeId,
            \Prosper202\Goals\GoalDefinition::parse(['name' => $event, 'trigger' => ['event' => $event]]),
            1
        );
    }

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        // Another suite may have left a campaigns table of its own shape (the
        // upgrade tests build a minimal one); the unlink tests write the
        // installer's, so it is rebuilt from it.
        $db->query('DROP TABLE IF EXISTS 202_aff_campaigns');
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (self::TABLES as $table) {
            self::$db->query('TRUNCATE TABLE ' . $table);
        }
        // 202_users is referenced by a foreign key, so it is emptied row by
        // row rather than truncated.
        self::$db->query('DELETE FROM 202_api_keys WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ')');
        self::$db->query('DELETE FROM 202_user_role WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ')');
        self::$db->query('DELETE FROM 202_users WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ')');
        foreach ([self::OWNER, self::OTHER] as $userId) {
            self::$db->query(
                "INSERT INTO 202_users (user_id, user_name, user_pass, user_email, user_dash_email, user_timezone,
                    user_time_register, install_hash, user_hash, user_deleted)
                 VALUES ($userId, 'user$userId', 'x', 'u$userId@example.test', '', 'UTC', 1, '', '', 0)"
            );
        }
    }

    private function apps(int $userId = self::OWNER): AppRegistrationsController
    {
        return new AppRegistrationsController(self::$db, $userId);
    }

    /** A stored postback row, written directly: the receiver's own path is PostbackReceiverIntegrationTest's. */
    private function postback(int $appId, int $userId, ?int $registrationId, string $state, ?int $trusted, int $receivedAt = 1_800_000_000): int
    {
        static $n = 0;
        $n++;
        $stmt = self::$db->prepare(
            'INSERT INTO 202_app_postbacks (user_id, registration_id, received_at, protocol, ad_network_id, transaction_id,
                app_id, attribution_signature, signature_state, trusted, dedupe_hash, raw_payload, created_at)
             VALUES (?, ?, ?, \'skadnetwork\', \'net\', ?, ?, \'AA==\', ?, ?, ?, \'{}\', ?)'
        );
        $tx = 'tx-' . $n;
        $hash = sha1($tx . microtime());
        $stmt->bind_param('iiisisisi', $userId, $registrationId, $receivedAt, $tx, $appId, $state, $trusted, $hash, $receivedAt);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /** @return array<string, mixed> */
    private function postbackRow(int $postbackId): array
    {
        $row = self::$db->query('SELECT user_id, registration_id, trusted FROM 202_app_postbacks WHERE postback_id = ' . $postbackId)->fetch_assoc();
        $this->assertIsArray($row, "postback $postbackId is gone");
        return [
            'user_id' => (int) $row['user_id'],
            'registration_id' => $row['registration_id'] === null ? null : (int) $row['registration_id'],
            'trusted' => $row['trusted'] === null ? null : (int) $row['trusted'],
        ];
    }

    public function testRegisteringAnIosAppClaimsItsPostbacksAndAppliesTheTestSignalPolicy(): void
    {
        $unclaimedDev = $this->postback(525463029, 0, null, 'development', null);
        $unclaimedValid = $this->postback(525463029, 0, null, 'valid', 1);
        $otherApp = $this->postback(111, 0, null, 'valid', 1);

        $created = $this->apps()->create([
            'store_link' => 'https://apps.apple.com/us/app/summit-run/id525463029',
            'app_name' => 'Summit Run',
            'accept_test_signals' => 1,
        ])['data'];
        $id = (int) $created['registration_id'];
        $this->assertSame('ios', $created['platform']);
        $this->assertSame('525463029', $created['app_key']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $created['app_token']);

        $this->assertSame(['user_id' => self::OWNER, 'registration_id' => $id, 'trusted' => 1], $this->postbackRow($unclaimedDev),
            'claimed, and the accepted test signal is trusted');
        $this->assertSame(['user_id' => self::OWNER, 'registration_id' => $id, 'trusted' => 1], $this->postbackRow($unclaimedValid));
        $this->assertSame(['user_id' => 0, 'registration_id' => null, 'trusted' => 1], $this->postbackRow($otherApp),
            'another app\'s postback is not claimed');

        $this->apps()->update($id, ['accept_test_signals' => 0]);
        $this->assertNull($this->postbackRow($unclaimedDev)['trusted'], 'the policy is live: turning it off untrusts the stored test signals');
        $this->assertSame(1, $this->postbackRow($unclaimedValid)['trusted'], 'and never touches a production-signed row');
    }

    public function testTheSameAppCannotBeRegisteredTwiceButPlatformsAndPackageCaseAreDistinct(): void
    {
        $this->apps()->create(['app_key' => '525463029', 'app_name' => 'A']);
        try {
            $this->apps(self::OTHER)->create(['store_link' => 'id525463029', 'app_name' => 'B']);
            $this->fail('a second registration of the same App Store app was accepted');
        } catch (ConflictException) {
            $this->addToAssertionCount(1);
        }

        // Case matters in an Android application id, so these are two apps;
        // the column's utf8mb4_bin collation is what lets UNIQUE see that.
        $this->apps()->create(['app_key' => 'com.example.app', 'app_name' => 'lower']);
        $this->apps(self::OTHER)->create(['app_key' => 'com.Example.app', 'app_name' => 'mixed']);
        try {
            $this->apps(self::OTHER)->create(['store_link' => 'market://details?id=com.example.app', 'app_name' => 'dup']);
            $this->fail('the same package was registered twice');
        } catch (ConflictException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(
            3,
            (int) self::$db->query('SELECT COUNT(*) AS c FROM 202_app_registrations')->fetch_assoc()['c']
        );
    }

    public function testDeletingARegistrationWithdrawsTrustUnlinksCascadesAndReRegisteringRelinks(): void
    {
        $id = (int) $this->apps()->create(['app_key' => '525463029', 'app_name' => 'A', 'accept_test_signals' => 1])['data']['registration_id'];
        $dev = $this->postback(525463029, 0, null, 'development', null);
        $this->apps()->update($id, ['app_name' => 'A again']); // re-runs the claim
        $this->assertSame(1, $this->postbackRow($dev)['trusted']);
        $appGoal = self::plainGoal(self::OWNER, 'install', 'registration', $id);
        (new AppSkanEncodingsController(self::$db, self::OWNER))->create(['registration_id' => $id, 'fine_value' => 1, 'goal_id' => $appGoal]);

        $this->apps()->delete($id);
        $this->assertNotNull(self::$db->query('SELECT archived_at FROM 202_goals WHERE goal_id = ' . $appGoal)->fetch_assoc()['archived_at'],
            'the app\'s goals are archived with it, their history kept');
        $this->assertSame(['user_id' => self::OWNER, 'registration_id' => null, 'trusted' => null], $this->postbackRow($dev),
            'owner kept, registration unlinked, test-signal trust withdrawn');
        $this->assertSame(0, (int) self::$db->query('SELECT COUNT(*) AS c FROM 202_app_skan_encodings')->fetch_assoc()['c'],
            'the registration\'s encodings went with it');
        $android = (int) $this->apps()->create(['app_key' => 'com.example.app', 'app_name' => 'Android'])['data']['registration_id'];
        $this->assertContains(
            ['resource' => 'campaigns', 'action' => 'unlink (app_registration_id set to NULL)', 'where' => 'app_registration_id = ' . $android],
            $this->apps()->deletePreview($android)['data']['cascade'],
            'the delete preview says the campaigns linked to the app are unlinked'
        );

        $again = (int) $this->apps()->create(['app_key' => '525463029', 'app_name' => 'A', 'accept_test_signals' => 1])['data']['registration_id'];
        $this->assertSame(['user_id' => self::OWNER, 'registration_id' => $again, 'trusted' => 1], $this->postbackRow($dev),
            'the owner\'s own unlinked rows are re-linked by registering the app again');
    }

    public function testAnEncodingMustNameMyOwnIosRegistrationOrBeAccountWide(): void
    {
        $mine = (int) $this->apps()->create(['app_key' => '525463029', 'app_name' => 'iOS'])['data']['registration_id'];
        $android = (int) $this->apps()->create(['app_key' => 'com.example.app', 'app_name' => 'Android'])['data']['registration_id'];
        $theirs = (int) $this->apps(self::OTHER)->create(['app_key' => '990077001', 'app_name' => 'Theirs'])['data']['registration_id'];
        $encodings = new AppSkanEncodingsController(self::$db, self::OWNER);
        $install = self::plainGoal(self::OWNER, 'install');

        $encodings->create(['registration_id' => $mine, 'fine_value' => 1, 'goal_id' => $install]);
        $encodings->create(['registration_id' => 0, 'fine_value' => 1, 'goal_id' => $install]);
        foreach ([$android => 'iOS apps only', $theirs => 'No registration', 999 => 'No registration'] as $id => $because) {
            try {
                $encodings->create(['registration_id' => $id, 'fine_value' => 2, 'goal_id' => $install]);
                $this->fail("registration $id was accepted");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($because, $e->getFieldErrors()['registration_id'] ?? '');
            }
        }
        $this->assertSame(2, (int) self::$db->query('SELECT COUNT(*) AS c FROM 202_app_skan_encodings')->fetch_assoc()['c']);
    }

    public function testDeletingAUserPurgesTheirAppDataAndReleasesTheirPostbacks(): void
    {
        $id = (int) $this->apps()->create(['app_key' => '525463029', 'app_name' => 'A', 'accept_test_signals' => 1])['data']['registration_id'];
        $install = self::plainGoal(self::OWNER, 'install');
        (new AppSkanEncodingsController(self::$db, self::OWNER))->create(['registration_id' => $id, 'fine_value' => 1, 'goal_id' => $install]);
        (new AppSkanEncodingsController(self::$db, self::OWNER))->create(['registration_id' => 0, 'fine_value' => 2, 'goal_id' => $install]);
        $valid = $this->postback(525463029, self::OWNER, $id, 'valid', 1);
        $dev = $this->postback(525463029, self::OWNER, $id, 'development', 1);
        $theirs = (int) $this->apps(self::OTHER)->create(['app_key' => '990077001', 'app_name' => 'Theirs'])['data']['registration_id'];
        $theirPostback = $this->postback(990077001, self::OTHER, $theirs, 'valid', 1);
        self::$db->query("INSERT INTO 202_identity_keys (user_id, hash_key, link_key, created_at) VALUES (" . self::OWNER . ", REPEAT('a', 64), REPEAT('b', 64), 1)");
        self::$db->query("INSERT INTO 202_identity_keys (user_id, hash_key, link_key, created_at) VALUES (" . self::OTHER . ", REPEAT('c', 64), REPEAT('d', 64), 1)");
        self::$db->query('INSERT INTO 202_clicks_visitor (click_id, user_id, visitor_key, click_time) VALUES (1, ' . self::OWNER . ', 1, 1), (2, ' . self::OTHER . ', 2, 1)');
        self::$db->query("INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (" . self::OWNER . ", 'owner-key', 1), (" . self::OTHER . ", 'other-key', 1)");
        $theirGoal = self::plainGoal(self::OTHER, 'install');
        $android = (int) $this->apps()->create(['app_key' => 'com.example.app', 'app_name' => 'Android'])['data']['registration_id'];
        // Written directly: created through the controller it would also
        // create the other user's install goal, which the goal assertions
        // below do not expect.
        self::$db->query("INSERT INTO 202_app_registrations SET user_id = " . self::OTHER . ", platform = 'android', app_key = 'com.other.app',
            app_name = 'Theirs', accept_test_signals = 0, attribution_window_days = 7, trust_client_revenue = 0, app_token = REPEAT('e', 64), created_at = 1, updated_at = 1");
        $theirAndroid = (int) self::$db->insert_id;
        foreach ([[501, self::OWNER, $android], [502, self::OWNER, null], [503, self::OTHER, $theirAndroid]] as [$campaign, $user, $link]) {
            self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $campaign, user_id = $user, aff_network_id = 1, aff_campaign_name = 'c',
                aff_campaign_url = 'http://x', aff_campaign_payout = 1, aff_campaign_time = 1, aff_campaign_foreign_payout = 1,
                app_registration_id = " . ($link ?? 'NULL'));
        }

        (new UserDataPurge(self::$db))->deleteUser(self::OWNER);

        $this->assertSame([['501', null], ['502', null], ['503', (string) $theirAndroid]],
            self::$db->query('SELECT aff_campaign_id, app_registration_id FROM 202_aff_campaigns ORDER BY aff_campaign_id')->fetch_all(),
            'the user\'s campaigns stay, unlinked from the registrations the purge deleted; nobody else\'s link moves');

        $this->assertSame([[(string) $theirGoal]], self::$db->query('SELECT goal_id FROM 202_goals')->fetch_all(),
            'the deleted user\'s goals go (UserDataPurge::GOAL_STATEMENTS); nobody else\'s do');
        $this->assertSame('1', self::$db->query('SELECT COUNT(*) AS c FROM 202_goal_versions')->fetch_assoc()['c']);

        $this->assertSame([['other-key']], self::$db->query('SELECT api_key FROM 202_api_keys WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ')')->fetch_all(),
            'the deleted user\'s API key is revoked with the rest (the API authenticates by key alone); nobody else\'s is');

        $this->assertSame('1', self::$db->query('SELECT user_deleted FROM 202_users WHERE user_id = ' . self::OWNER)->fetch_row()[0]);
        $this->assertSame([], self::$db->query('SELECT registration_id FROM 202_app_registrations WHERE user_id = ' . self::OWNER)->fetch_all());
        $this->assertSame([], self::$db->query('SELECT encoding_id FROM 202_app_skan_encodings WHERE user_id = ' . self::OWNER)->fetch_all());
        $this->assertSame(['user_id' => 0, 'registration_id' => null, 'trusted' => 1], $this->postbackRow($valid),
            'released, not deleted: it is Apple\'s record; the verifier alone vouched for it');
        $this->assertSame(['user_id' => 0, 'registration_id' => null, 'trusted' => null], $this->postbackRow($dev),
            'the policy that trusted this test signal is gone with the registration');
        $this->assertSame([], self::$db->query('SELECT user_id FROM 202_identity_keys WHERE user_id = ' . self::OWNER)->fetch_all());
        $this->assertSame([], self::$db->query('SELECT click_id FROM 202_clicks_visitor WHERE user_id = ' . self::OWNER)->fetch_all());

        // Nobody else's data moved.
        $this->assertSame(['user_id' => self::OTHER, 'registration_id' => $theirs, 'trusted' => 1], $this->postbackRow($theirPostback));
        $this->assertCount(1, self::$db->query('SELECT user_id FROM 202_identity_keys')->fetch_all());
        $this->assertCount(1, self::$db->query('SELECT click_id FROM 202_clicks_visitor')->fetch_all());
        $this->assertSame('0', self::$db->query('SELECT user_deleted FROM 202_users WHERE user_id = ' . self::OTHER)->fetch_row()[0]);

        // The global slot is free: another user can register the app, and
        // claims the released rows (still inside the unclaimed window).
        $newId = (int) $this->apps(self::OTHER)->create(['app_key' => '525463029', 'app_name' => 'Adopted'])['data']['registration_id'];
        $this->assertSame(['user_id' => self::OTHER, 'registration_id' => $newId, 'trusted' => 1], $this->postbackRow($valid));
    }

    public function testAUserDeletionThatFailsPartWayChangesNothing(): void
    {
        $id = (int) $this->apps()->create(['app_key' => '525463029', 'app_name' => 'A'])['data']['registration_id'];
        $postback = $this->postback(525463029, self::OWNER, $id, 'valid', 1);
        self::$db->query("INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (" . self::OWNER . ", 'owner-key', 1)");

        // One statement of the cascade fails — its table is missing — after
        // earlier ones have run inside the transaction.
        self::$db->query('RENAME TABLE 202_app_skan_encodings TO 202_app_skan_encodings_away');
        try {
            (new UserDataPurge(self::$db))->deleteUser(self::OWNER);
            $this->fail('a purge with a failing statement reported success');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('was not deleted', $e->getMessage());
        } finally {
            self::$db->query('RENAME TABLE 202_app_skan_encodings_away TO 202_app_skan_encodings');
        }

        $this->assertSame('0', self::$db->query('SELECT user_deleted FROM 202_users WHERE user_id = ' . self::OWNER)->fetch_row()[0]);
        $this->assertSame(['user_id' => self::OWNER, 'registration_id' => $id, 'trusted' => 1], $this->postbackRow($postback),
            'the release that ran before the failure was rolled back');
        $this->assertCount(1, self::$db->query('SELECT registration_id FROM 202_app_registrations')->fetch_all());
        $this->assertSame([['owner-key']], self::$db->query('SELECT api_key FROM 202_api_keys WHERE user_id = ' . self::OWNER)->fetch_all(),
            'the key revocation, the first statement to run, was rolled back too');
    }

    public function testRetentionPrunesEveryUntrustedClassAndKeepsTrustedClaimedRows(): void
    {
        $id = (int) $this->apps()->create(['app_key' => '525463029', 'app_name' => 'A'])['data']['registration_id'];
        $now = 1_800_000_000;
        $old = $now - 400 * 86400;
        $keep = $this->postback(525463029, self::OWNER, $id, 'valid', 1, $old);
        $unclaimed = $this->postback(111, 0, null, 'valid', 1, $old);
        $refuted = $this->postback(525463029, self::OWNER, $id, 'invalid', 0, $old);
        $unvouched = $this->postback(525463029, self::OWNER, $id, 'unverifiable', null, $old);
        $fresh = $this->postback(111, 0, null, 'invalid', 0, $now - 86400);

        $retention = new AppRetention(self::$db, PostbackReceiver::retentionClasses());
        $this->assertSame(
            ['postbacks/unclaimed' => 1, 'postbacks/refuted' => 1, 'postbacks/unvouched' => 1],
            $retention->backlog($now)
        );
        $retention->prune($now);

        $left = array_map('intval', array_column(self::$db->query('SELECT postback_id FROM 202_app_postbacks ORDER BY postback_id')->fetch_all(MYSQLI_ASSOC), 'postback_id'));
        $this->assertSame([$keep, $fresh], $left);
        $this->assertNotContains($unclaimed, $left);
        $this->assertNotContains($refuted, $left);
        $this->assertNotContains($unvouched, $left);
        $this->assertSame(['postbacks/unclaimed' => 0, 'postbacks/refuted' => 0, 'postbacks/unvouched' => 0], $retention->backlog($now));
    }
}
