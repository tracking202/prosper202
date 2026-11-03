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
    private const ADMIN = 2;

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
        self::q('DROP TRIGGER IF EXISTS consent_test_refuse_consent');
        foreach (['202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q("DELETE FROM 202_licence_answers WHERE key_sha256 = SHA2('consent-controls-runner', 256)");
    }

    /**
     * @param array<string, string> $env
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function request(array $env = [], string $page = self::PAGE): array
    {
        $env += ['P202_SU_USER' => (string) self::USER, 'P202_SU_SESSION_DIR' => self::$sessions];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/session-user-runner.php', $page],
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

    private static function role(int $role): void
    {
        self::q('UPDATE 202_user_role SET role_id = ' . $role . ' WHERE user_id = ' . self::USER);
    }

    /** The account page's POST: the profile form with analytics unticked from a ticked box. */
    private static function revoke(string $email): array
    {
        return self::request(['P202_SU_POST' => json_encode([
            'update_profile' => '1', 'token' => 'SESSION', 'user_email' => $email,
            'user_timezone' => 'UTC', 'user_keyword_searched_or_bidded' => 'searched', 'user_referer' => 'browser',
            'user_bid' => '0', 'user_pref_privacy' => 'disabled', 'user_pref_same_ip_fallback' => '0',
            'cloak_referer' => 'origin', 'user_pref_ad_settings' => 'show_all',
            'consent_form' => '1', 'analytics_consent_shown' => '1', 'email_marketing_consent_shown' => '0',
        ], JSON_THROW_ON_ERROR)]);
    }

    private static function analytics(): string
    {
        return (string) self::$db->query('SELECT analytics_consent FROM 202_users_pref WHERE user_id = ' . self::USER)->fetch_row()[0];
    }

    /** What the session queued for the next page. */
    private static function queued(array $request): string
    {
        $file = self::$sessions . '/sess_' . $request['result']['session_id'];

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    private static function refuseConsentWrites(): void
    {
        self::q("CREATE TRIGGER consent_test_refuse_consent BEFORE UPDATE ON 202_users_pref FOR EACH ROW
            BEGIN
              IF NOT (NEW.analytics_consent <=> OLD.analytics_consent) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consent refused by the test';
              END IF;
            END");
    }

    /** @return array<string, array{int}> */
    public static function roles(): array
    {
        return ['personal settings' => [self::ADMIN], 'email only' => [self::CAMPAIGN_MANAGER]];
    }

    /** @dataProvider roles */
    public function testARevocationIsRecordedEvenWhenTheRestOfTheFormIsRefused(int $role): void
    {
        self::role($role);
        $post = self::revoke('not an email');
        self::assertNull($post['result']['uncaught'], $post['output']);
        self::assertStringContainsString('Please enter a valid email address.', $post['output'], 'the form was refused');
        self::assertSame('denied', self::analytics(), 'the revocation stands anyway');
        self::assertStringNotContainsString('could not be saved. Try again', $post['output']);
    }

    /** @dataProvider roles */
    public function testAConsentThatCannotBeSavedIsNeverReportedAsSaved(int $role): void
    {
        self::role($role);
        self::refuseConsentWrites();
        $post = self::revoke('consent-cm@example.com');
        self::assertNull($post['result']['uncaught'], $post['output']);
        self::assertSame('unset', self::analytics(), 'nothing was recorded');
        $queued = self::queued($post);
        self::assertStringContainsString('Data & communication choice could not be', $queued, 'the next page says so');
        self::assertStringNotContainsString('Your settings are saved.', $queued);
    }

    /** @dataProvider roles */
    public function testAConsentThatCannotBeSavedOnARefusedFormIsSaidOnThePage(int $role): void
    {
        self::role($role);
        self::refuseConsentWrites();
        $post = self::revoke('not an email');
        self::assertSame('unset', self::analytics());
        self::assertStringContainsString('Your Data &amp; communication choice could not be saved. Try again.', $post['output']);
    }

    /** The prompt's endpoint records a choice only from the places that ask for one. */
    public function testTheConsentEndpointTakesOnlyItsOwnSources(): void
    {
        $endpoint = '202-account/ajax/messaging/consent.php';
        $post = static fn (string $source): string => json_encode(
            ['flag' => 'analytics', 'state' => 'denied', 'source' => $source, 'token' => 'SESSION'],
            JSON_THROW_ON_ERROR
        );
        foreach (['', str_repeat('é', 40), 'settings ', 'api'] as $source) {
            $refused = self::request(['P202_SU_POST' => $post($source), 'P202_SU_AJAX' => '1'], $endpoint);
            self::assertSame(400, $refused['result']['status'], var_export($source, true) . ': ' . $refused['output']);
            self::assertSame('unset', self::analytics(), 'nothing recorded');
        }
        $saved = self::request(['P202_SU_POST' => $post('eu_prompt'), 'P202_SU_AJAX' => '1'], $endpoint);
        self::assertStringContainsString('{"ok":true}', $saved['output'], 'no status set: 200');
        self::assertSame(['denied', 'eu_prompt'], self::$db->query(
            'SELECT analytics_consent, analytics_consent_source FROM 202_users_pref WHERE user_id = ' . self::USER
        )->fetch_row());
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
