<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;
use Prosper202\License\KeyReplacementProof;

/**
 * The license-key page (api-key-required.php) run as the web server runs it
 * (fixtures/session-user-runner.php): a saved key is replaced only by a
 * session whose user may change Settings or with such a user's password, the
 * license service is not asked before that, and no part of the saved key is
 * shown. While no key is saved, a visitor with the page's token may still
 * save one, as the install does.
 *
 * Whether the page asked the license service is read from what it answers:
 * the service is unreachable here (a proxy that refuses), so an ask answers
 * P202_ACCOUNT_KEY_UNCHECKED, and a refusal before the ask answers its own
 * sentence. Nothing here reaches the network.
 *
 * The page reads and writes user 1's key, so this class owns user 1.
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class LicenceKeyPageIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 1;
    private const ADMIN = 990893;
    private const VIEWER = 990894;
    private const PASSWORD = 'licence-key-page-pw';
    private const SAVED = 'savedkeyAAAA-middle-of-the-key-ZZZZ';
    private const POSTED = 'posted-replacement-key';
    private const PAGE = 'api-key-required.php';
    /** The sentence an ask answers when the service cannot be reached. */
    private const ASKED = 'The key could not be checked with Tracking202 just now';

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
        require_once $root . '/202-config/functions-auth.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        (new DataSeeder(self::$db))->seed();
        self::$sessions = sys_get_temp_dir() . '/p202-licence-key-page-' . getmypid();
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
        $roles = [self::OWNER => 1, self::ADMIN => 2, self::VIEWER => 5];
        foreach ($roles as $user => $role) {
            self::q("INSERT INTO 202_users SET user_id = $user, user_name = 'lk$user',"
                . " user_email = 'lk$user@example.com', user_dash_email = '',"
                . ' user_pass = ' . self::str(password_hash(self::PASSWORD, PASSWORD_DEFAULT)) . ','
                . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
                . ' p202_customer_api_key = ' . self::str($user === self::OWNER ? self::SAVED : ''));
            self::row('202_users_pref', ['user_id' => $user]);
            self::row('202_user_role', ['user_id' => $user, 'role_id' => $role]);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::OWNER, self::ADMIN, self::VIEWER] as $user) {
            foreach (['202_auth_keys', '202_user_role', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
            self::q("DELETE FROM 202_users_log WHERE user_name = 'lk$user'");
        }
    }

    private static function savedKey(): string
    {
        $result = self::$db->query('SELECT p202_customer_api_key FROM 202_users WHERE user_id = ' . self::OWNER);
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) ($result->fetch_row()[0] ?? '');
    }

    private static function failures(string $userName): int
    {
        $result = self::$db->query('SELECT COUNT(*) FROM 202_users_log WHERE login_success = 0 AND user_name = '
            . self::str($userName));
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (int) $result->fetch_row()[0];
    }

    /**
     * One request to the page, by a visitor who has not signed in unless
     * $signedInAs names a user.
     *
     * @param array<string, string>|null $post
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function request(?array $post, ?int $signedInAs = null): array
    {
        $env = [
            'P202_SU_USER' => (string) ($signedInAs ?? self::OWNER),
            'P202_SU_SESSION_DIR' => self::$sessions,
            'HTTPS_PROXY' => 'http://127.0.0.1:9',
            'https_proxy' => 'http://127.0.0.1:9',
            'NO_PROXY' => '',
            'no_proxy' => '',
        ];
        if ($signedInAs === null) {
            $env['P202_SU_SESSION'] = 'anonymous';
        }
        if ($post !== null) {
            $env['P202_SU_POST'] = json_encode($post + ['token' => 'SESSION'], JSON_THROW_ON_ERROR);
        }
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=/dev/null',
                __DIR__ . '/fixtures/session-user-runner.php', self::PAGE],
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
        $exit = proc_close($proc);
        self::assertNotSame(2, $exit, "the runner could not start the request:\n$stdout$stderr");
        $at = strrpos($stdout, "\n@@RESULT ");
        self::assertNotFalse($at, "the request left no result line:\n$stdout$stderr");
        $result = json_decode(substr($stdout, $at + strlen("\n@@RESULT ")), true, 8, JSON_THROW_ON_ERROR);
        self::assertNull($result['uncaught'], $stdout . $stderr);
        self::assertStringContainsString('</html>', $stdout, "the page was drawn:\n$stdout$stderr");

        return ['output' => substr($stdout, 0, $at), 'result' => $result];
    }

    private static function refusedWith(string $sentence, string $output, string $why): void
    {
        self::assertStringContainsString(
            '<div class="p202-flash__body">' . htmlspecialchars($sentence, ENT_QUOTES, 'UTF-8'),
            $output,
            $why
        );
        self::assertStringNotContainsString(self::ASKED, $output, 'the license service was not asked');
        self::assertSame(self::SAVED, self::savedKey(), 'the saved key is unchanged');
    }

    private static function showsNoPartOfTheKey(string $output): void
    {
        foreach (['savedkey', 'AAAA', 'ZZZZ', 'middle-of'] as $part) {
            self::assertStringNotContainsString($part, $output, "the page shows '$part' of the saved key");
        }
    }

    public function testWithNoKeySavedAVisitorsKeyIsChecked(): void
    {
        self::q("UPDATE 202_users SET p202_customer_api_key = '' WHERE user_id = " . self::OWNER);

        $page = self::request(['api_key' => self::POSTED]);
        self::assertStringContainsString(self::ASKED, $page['output'], 'the install flow asks the license service');
        self::assertStringNotContainsString(KeyReplacementProof::NEEDS_PROOF, $page['output']);
        self::assertStringNotContainsString('name="owner_user_pass"', $page['output'], 'no password is asked for');
    }

    public function testASavedKeyIsNotReplacedByAVisitor(): void
    {
        $form = self::request(null);
        self::assertStringContainsString('A license key is saved, but it does not validate', $form['output']);
        self::assertStringContainsString('name="owner_user_pass"', $form['output'], 'the form asks for a password');
        self::showsNoPartOfTheKey($form['output']);

        $page = self::request(['api_key' => self::POSTED]);
        self::refusedWith(KeyReplacementProof::NEEDS_PROOF, $page['output'], 'a key posted with no proof is refused');
        self::showsNoPartOfTheKey($page['output']);
    }

    public function testTheOwnersPasswordLetsTheKeyBeChecked(): void
    {
        $page = self::request([
            'api_key' => self::POSTED,
            'owner_user_name' => 'lk' . self::OWNER,
            'owner_user_pass' => self::PASSWORD,
        ]);
        self::assertStringContainsString(self::ASKED, $page['output'], 'the owner shown, the service is asked');
        self::assertSame(0, self::failures('lk' . self::OWNER));
    }

    public function testAWrongPasswordIsRefusedAndCounted(): void
    {
        $page = self::request([
            'api_key' => self::POSTED,
            'owner_user_name' => 'lk' . self::OWNER,
            'owner_user_pass' => 'not-the-password',
        ]);
        self::refusedWith(KeyReplacementProof::WRONG_PASSWORD, $page['output'], 'a wrong password is refused');
        self::assertSame(1, self::failures('lk' . self::OWNER), 'and counted where the sign-in throttle counts');
        self::assertStringContainsString('value="lk' . self::OWNER . '"', $page['output'], 'the username is kept');
    }

    public function testAThrottledUsernameIsRefusedEvenWithItsPassword(): void
    {
        for ($i = 0; $i < \AUTH::RATE_LIMIT_MAX_PER_USER; $i++) {
            self::row('202_users_log', [
                'user_name' => 'lk' . self::OWNER, 'user_pass' => '[filtered]', 'ip_address' => '198.51.100.' . $i,
                'login_time' => time(), 'login_success' => 0, 'login_error' => '', 'login_server' => '',
                'login_session' => '',
            ]);
        }
        $page = self::request([
            'api_key' => self::POSTED,
            'owner_user_name' => 'lk' . self::OWNER,
            'owner_user_pass' => self::PASSWORD,
        ]);
        self::refusedWith(KeyReplacementProof::THROTTLED, $page['output'], 'the sign-in throttle holds here too');
    }

    public function testThePasswordOfAUserWhoMayNotChangeSettingsIsRefused(): void
    {
        $page = self::request([
            'api_key' => self::POSTED,
            'owner_user_name' => 'lk' . self::VIEWER,
            'owner_user_pass' => self::PASSWORD,
        ]);
        self::refusedWith(KeyReplacementProof::NOT_PERMITTED, $page['output'], 'a viewer cannot replace the key');
    }

    public function testASignedInAdminReplacesItWithoutAPassword(): void
    {
        $form = self::request(null, self::ADMIN);
        self::assertStringNotContainsString('name="owner_user_pass"', $form['output'], 'no password is asked for');
        self::showsNoPartOfTheKey($form['output']);

        $page = self::request(['api_key' => self::POSTED], self::ADMIN);
        self::assertStringContainsString(self::ASKED, $page['output'], 'the admin\'s key is checked');
    }

    public function testASignedInViewerIsAskedForProof(): void
    {
        $page = self::request(['api_key' => self::POSTED], self::VIEWER);
        self::refusedWith(KeyReplacementProof::NEEDS_PROOF, $page['output'], 'a viewer\'s session is not proof');
    }
}
