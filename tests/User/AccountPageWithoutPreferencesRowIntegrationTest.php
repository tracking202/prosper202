<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * Personal settings for a user with no 202_users_pref row (J3AW). The install,
 * Account > Users and the API make the row with the user, and the 1.9.x ladder
 * once filled it in, but a user made any other way (by hand, or by a restore
 * that left the table out) has none, and the page joins it with a LEFT JOIN.
 *
 * The page reads the username from 202_users alone (#416), and draws without a
 * warning for both kinds of user. Three things still hung on the row. The
 * licence key, a column of 202_users, was read from the preferences row, so
 * every page sent the user to api-key-required.php to enter the key they had.
 * The two saves that write the row (the profile form and the account currency)
 * UPDATEd a row that was not there: nothing stored, and the page said it was
 * saved; they make the row first now. And Settings read the username through
 * an INNER JOIN on user 1's preferences row, the shape #416 took off this page.
 *
 * Runs the page as the web server does (tests/Auth/fixtures/session-user-runner.php).
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class AccountPageWithoutPreferencesRowIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990893;
    private const PAGE = '202-account/account.php';
    private const ADMIN = 2;
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
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        (new DataSeeder(self::$db))->seed();
        self::$sessions = sys_get_temp_dir() . '/p202-session-noprefs-' . getmypid();
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
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ", user_name = 'noprefs-user',"
            . " user_email = 'noprefs@example.com', user_dash_email = '', user_pass = 'x',"
            . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
            . " p202_customer_api_key = 'noprefs-runner'");
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ADMIN]);
        self::q("INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at)"
            . " VALUES (SHA2('noprefs-runner', 256), 1, UNIX_TIMESTAMP() - 3600)");
        self::assertNull(self::preferences(), 'the user starts with no preferences row');
    }

    private static function cleanUp(): void
    {
        foreach (['202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q("DELETE FROM 202_licence_answers WHERE key_sha256 = SHA2('noprefs-runner', 256)");
    }

    /**
     * @param array<string, string> $env
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function request(array $env = [], string $page = self::PAGE): array
    {
        $env += ['P202_SU_USER' => (string) self::USER, 'P202_SU_SESSION_DIR' => self::$sessions];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1',
                dirname(__DIR__) . '/Auth/fixtures/session-user-runner.php', $page],
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

    /** @param array{output: string, result: array<string, mixed>} $request */
    private static function assertNoPhpDiagnostics(array $request): void
    {
        self::assertNull($request['result']['uncaught'], $request['output']);
        self::assertDoesNotMatchRegularExpression(
            '/(?:PHP )?(?:Warning|Notice|Deprecated|Fatal error):/',
            $request['output'],
            'the page raised a PHP diagnostic'
        );
    }

    /** @return array<string, string|null>|null */
    private static function preferences(): ?array
    {
        $found = self::$db->query('SELECT user_keyword_searched_or_bidded, user_pref_referer_data,'
            . ' user_pref_cloak_referer, user_pref_privacy, user_account_currency'
            . ' FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $found);

        return $found->fetch_assoc();
    }

    /** What the session queued for the next page. */
    private static function queued(array $request): string
    {
        $file = self::$sessions . '/sess_' . $request['result']['session_id'];

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /** @return array<string, array{int}> */
    public static function roles(): array
    {
        return ['personal settings' => [self::ADMIN], 'email only' => [self::CAMPAIGN_MANAGER]];
    }

    /** @dataProvider roles */
    public function testThePageDrawsWithoutAWarning(int $role): void
    {
        self::q('UPDATE 202_user_role SET role_id = ' . $role . ' WHERE user_id = ' . self::USER);
        $page = self::request();
        self::assertFalse($page['result']['denied'], $page['output']);
        self::assertNoPhpDiagnostics($page);
        self::assertStringContainsString('noprefs@example.com', $page['output'], 'the account row was read');
        self::assertNull(self::preferences(), 'a GET writes nothing');
    }

    /** Settings reads the install's settings from user 1's row, which this scratch database has none of. */
    public function testSettingsDrawsWithoutAWarningWhenUserOneHasNoRow(): void
    {
        $userOne = self::$db->query('SELECT COUNT(*) FROM 202_users_pref WHERE user_id = 1');
        self::assertInstanceOf(\mysqli_result::class, $userOne);
        self::assertSame(0, (int) $userOne->fetch_row()[0]);
        $page = self::request([], '202-account/administration.php');
        self::assertFalse($page['result']['denied'], $page['output']);
        self::assertNoPhpDiagnostics($page);
        self::assertStringContainsString('name="auto_database_management"', $page['output'], 'the settings were drawn');
    }

    public function testTheProfileSaveStoresThePreferences(): void
    {
        $post = self::request(['P202_SU_POST' => json_encode([
            'update_profile' => '1', 'token' => 'SESSION', 'user_email' => 'noprefs@example.com',
            'user_timezone' => 'UTC', 'user_keyword_searched_or_bidded' => 'bidded', 'user_referer' => 't202ref',
            'user_bid' => '0', 'user_pref_privacy' => 'all', 'user_pref_same_ip_fallback' => '0',
            'cloak_referer' => 'origin', 'user_pref_ad_settings' => 'show_all',
        ], JSON_THROW_ON_ERROR)]);
        self::assertNoPhpDiagnostics($post);
        $stored = self::preferences();
        self::assertNotNull($stored, 'the save made the row it writes');
        self::assertSame(['bidded', 't202ref', 'origin', 'all'], [
            $stored['user_keyword_searched_or_bidded'], $stored['user_pref_referer_data'],
            $stored['user_pref_cloak_referer'], $stored['user_pref_privacy'],
        ], 'what was posted is what is stored');
        self::assertStringContainsString('Your settings are saved.', self::queued($post));
    }

    public function testTheCurrencySaveStoresTheCurrency(): void
    {
        $post = self::request(['P202_SU_POST' => json_encode([
            'update_account_currency' => '1', 'token' => 'SESSION', 'account_currency' => 'EUR',
        ], JSON_THROW_ON_ERROR)]);
        self::assertNoPhpDiagnostics($post);
        $stored = self::preferences();
        self::assertSame('EUR', $stored['user_account_currency'] ?? null, 'the save made the row it writes');
        self::assertStringContainsString('Account currency saved.', self::queued($post));
    }
}
