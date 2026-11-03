<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * Personal settings shows the Data & communication choices to every signed-in
 * user and records them, a user without access_to_personal_settings (a
 * Campaign manager) included: analytics is recorded under that user, and the
 * disclosure promises it can be switched off at any time.
 *
 * Runs the page as the web server does (fixtures/session-user-runner.php).
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class ConsentControlsForEveryUserIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990891;
    private const PAGE = '202-account/account.php';
    private const CAMPAIGN_MANAGER = 3;

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

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
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        (new DataSeeder(self::$db))->seed();
        self::$sessions = sys_get_temp_dir() . '/p202-session-consent-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
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

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ", user_name = 'consent-cm',"
            . " user_email = 'consent-cm@example.com', user_dash_email = '', user_pass = 'x',"
            . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
            . " p202_customer_api_key = 'consent-controls-runner'");
        // Signed in from outside the EU/UK: analytics is on by default.
        self::row('202_users_pref', ['user_id' => self::USER, 'analytics_geo_is_eu' => 0]);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::CAMPAIGN_MANAGER]);
        self::q("INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at)"
            . " VALUES (SHA2('consent-controls-runner', 256), 1, UNIX_TIMESTAMP() - 3600)");
    }

    private static function cleanUp(): void
    {
        foreach (['202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q("DELETE FROM 202_licence_answers WHERE key_sha256 = SHA2('consent-controls-runner', 256)");
    }

    /**
     * @param array<string, string> $env
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function request(array $env = []): array
    {
        $env += ['P202_SU_USER' => (string) self::USER, 'P202_SU_SESSION_DIR' => self::$sessions];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/session-user-runner.php', self::PAGE],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + getenv()
        );
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not start the request:\n$stdout$stderr");
        $at = strrpos($stdout, "\n@@RESULT ");
        self::assertNotFalse($at, "the request left no result line:\n$stdout$stderr");

        return [
            'output' => substr($stdout, 0, $at) . $stderr,
            'result' => json_decode(substr($stdout, $at + strlen("\n@@RESULT ")), true, 8, JSON_THROW_ON_ERROR),
        ];
    }

    public function testAUserWithoutPersonalSettingsCanSwitchAnalyticsOff(): void
    {
        $page = self::request();
        self::assertFalse($page['result']['denied'], $page['output']);
        self::assertStringNotContainsString('name="user_timezone"', $page['output'], 'the personal settings stay hidden');
        self::assertMatchesRegularExpression('/name="analytics_consent"[^>]*checked/', $page['output'], 'on by default, and shown');

        $post = self::request(['P202_SU_POST' => json_encode([
            'update_profile' => '1', 'token' => 'SESSION', 'user_email' => 'consent-cm@example.com',
            'consent_form' => '1', 'analytics_consent_shown' => '1', 'email_marketing_consent_shown' => '0',
        ], JSON_THROW_ON_ERROR)]);
        self::assertFalse($post['result']['denied'], $post['output']);
        self::assertNull($post['result']['uncaught'], $post['output']);
        self::assertSame(['denied', 'settings', 'unset'], self::$db->query(
            'SELECT analytics_consent, analytics_consent_source, email_marketing_consent FROM 202_users_pref WHERE user_id = ' . self::USER
        )->fetch_row(), 'the unticked box is a recorded denial; the untouched one records nothing');
    }
}
