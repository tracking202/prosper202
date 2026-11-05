<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Notifications\SlackWebhook;

/**
 * 974K: a notice goes to the account's own webhook, or none; never account 1's.
 * The webhooks are documentation addresses: refused before connecting, named in the log.
 *
 * @group integration
 */
final class SlackNoticesGoToTheAccountsOwnWebhookIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const WITH_WEBHOOK = 990091;
    private const WITHOUT = 990092;
    private const BLANK = 990093;
    private const ROLE = 990091;
    private const OWN = 'https://192.0.2.10/services/T0/B0/own-account';
    private const ACCOUNT_ONE = 'https://192.0.2.11/services/T0/B0/account-one';

    private static string $sessions = '';

    /** @var array{exists: bool, webhook: ?string}|null account 1's preferences row as this class found it */
    private static ?array $accountOne = null;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<int> the permission rows this class added */
    private static array $addedPermissions = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        require_once $root . '/202-config/Slack.class.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);

        $one = self::$db->query('SELECT user_slack_incoming_webhook FROM 202_users_pref WHERE user_id = 1');
        $row = $one instanceof \mysqli_result ? $one->fetch_assoc() : null;
        self::$accountOne = [
            'exists' => is_array($row),
            'webhook' => is_array($row) ? $row['user_slack_incoming_webhook'] : null,
        ];
        // tearDownAfterClass() does not run when this throws: put account 1's row back here.
        try {
            if (is_array($row)) {
                self::q('UPDATE 202_users_pref SET user_slack_incoming_webhook = ' . self::str(self::ACCOUNT_ONE)
                    . ' WHERE user_id = 1');
            } else {
                self::row('202_users_pref', ['user_id' => 1, 'user_slack_incoming_webhook' => self::ACCOUNT_ONE]);
            }
            self::cleanUp();
            self::seed();
        } catch (\Throwable $e) {
            self::tearDownAfterClass();
            throw $e;
        }
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-slack-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$accountOne !== null) {
                if (self::$accountOne['exists']) {
                    $was = self::$accountOne['webhook'];
                    self::q('UPDATE 202_users_pref SET user_slack_incoming_webhook = '
                        . ($was === null ? 'NULL' : self::str($was)) . ' WHERE user_id = 1');
                } else {
                    self::q('DELETE FROM 202_users_pref WHERE user_id = 1');
                }
            }
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::WITH_WEBHOOK, self::WITHOUT, self::BLANK] as $user) {
            foreach (['202_user_role', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    private static function seed(): void
    {
        $webhooks = [self::WITH_WEBHOOK => self::OWN, self::WITHOUT => '', self::BLANK => "  \t "];
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'slack test']);
        foreach ($webhooks as $user => $webhook) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user, 'user_slack_incoming_webhook' => $webhook]);
            self::row('202_user_role', ['user_id' => $user, 'role_id' => self::ROLE]);
        }
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id IN ("
            . self::WITH_WEBHOOK . ', ' . self::WITHOUT . ', ' . self::BLANK . ')');
        foreach (['access_to_setup_section', 'remove_tracker'] as $name) {
            $found = self::$db->query(
                "SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'"
            );
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
                self::$addedPermissions[] = $permission;
            }
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        }
    }

    public function testTheHelperReadsTheAccountsOwnWebhook(): void
    {
        $conn = new Connection(self::requireScratch());
        self::assertSame(self::OWN, SlackWebhook::ofAccount($conn, self::WITH_WEBHOOK));
        self::assertNull(SlackWebhook::ofAccount($conn, self::WITHOUT), 'an empty webhook is none');
        self::assertNull(SlackWebhook::ofAccount($conn, self::BLANK), 'a blank webhook is none');
        self::assertNull(SlackWebhook::ofAccount($conn, 990099), 'an account with no preferences row has none');
        self::assertNull(SlackWebhook::ofAccount($conn, 0), 'no account, no webhook (account 1\'s is not a default)');
        self::assertSame(self::ACCOUNT_ONE, SlackWebhook::ofAccount($conn, 1), 'account 1 has its own');

        self::assertInstanceOf(\Slack::class, \Slack::forAccount(self::$db, self::WITH_WEBHOOK));
        self::assertNull(\Slack::forAccount(self::$db, self::WITHOUT));
        self::assertNull(\Slack::forAccount(self::$db, self::BLANK));
    }

    public function testADeletedLinkIsAnnouncedOnTheAccountsOwnWebhook(): void
    {
        $log = $this->deleteTrackerAs(self::WITH_WEBHOOK);
        self::assertStringContainsString('Slack: the "tracking_link_deleted" notice was not sent', $log);
        self::assertStringContainsString('192.0.2.10', $log, 'the notice was for the account\'s own webhook');
        self::assertStringNotContainsString('192.0.2.11', $log, 'and not account 1\'s');
    }

    public function testAnAccountWithNoWebhookSendsNoNotice(): void
    {
        $log = $this->deleteTrackerAs(self::WITHOUT);
        self::assertStringNotContainsString('Slack:', $log, 'no notice, not one to account 1\'s webhook');
        self::assertStringNotContainsString('192.0.2.11', $log);
    }

    /** What the page logged, deleting a link (one there is none of) as $user. */
    private function deleteTrackerAs(int $user): string
    {
        self::requireScratch();
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-slack-page-');
        $env = [
            'P202_TEST_REPORT_USER' => (string) $user,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => '{}',
            'P202_TEST_POST' => (string) json_encode(['token' => 'SESSION', 'tracker_id' => '99009199']),
            'P202_WEBHOOK_ALLOW_NETWORKS' => '',
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=1', '-d', 'error_log=' . $log,
                '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                dirname(__DIR__) . '/Setup/fixtures/page-runner.php', 'tracking202/ajax/delete_tracker.php'],
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
        $code = proc_close($proc);
        $logged = (string) file_get_contents($log);
        @unlink($log);
        self::assertNotSame(2, $code, "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err . $logged, $err . $logged);
        self::assertStringNotContainsString('Invalid token', $out, 'the delete ran');
        self::assertStringNotContainsString('could not be deleted', $out, 'the delete ran');

        return $logged . $err;
    }
}
