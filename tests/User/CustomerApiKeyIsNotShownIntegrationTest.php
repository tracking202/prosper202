<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * The Prosper202 customer API key, the license key that unlocks a paid
 * install, is never drawn into a page (7QKD): My Account says that one is
 * saved and how it ends, its field is never filled in, an empty save keeps
 * the key, Remove key removes it, and a refused key is not written back; the
 * license prompt says only that a key is saved. A shared screen, an
 * extension or a support screenshot of either page used to hand the key over
 * (My Account put it in the field's value; the prompt printed its first 8 and
 * last 4 characters).
 *
 * Both pages run as the web server runs them (tests/Auth/fixtures/
 * session-user-runner.php). The license service is made unreachable (a proxy
 * that refuses), so a key the page asks about is refused as unchecked and
 * nothing reaches the network. The prompt reads user 1's key, so this class
 * owns user 1.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class CustomerApiKeyIsNotShownIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 1;
    private const ADMIN_ROLE = 2;
    private const SAVED = 'savedkeyAAAA-middle-of-the-key-WXYZ';
    private const POSTED = 'posted-replacement-key-QRST';
    private const HANDED_BACK = 'handback-key-from-dashboard-LMNO';
    private const ACCOUNT = '202-account/account.php';
    private const PROMPT = 'api-key-required.php';

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
        self::$sessions = sys_get_temp_dir() . '/p202-customer-key-' . getmypid();
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
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ", user_name = 'customer-key',"
            . " user_email = 'owner@northwind.example', user_dash_email = '', user_pass = 'x',"
            . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
            . ' p202_customer_api_key = ' . self::str(self::SAVED));
        self::row('202_users_pref', ['user_id' => self::USER]);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ADMIN_ROLE]);
        // The pages behind the sign-in run only for a key with a recent good
        // answer; the prompt is the page a key without one is sent to.
        self::q('INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at)'
            . ' VALUES (SHA2(' . self::str(self::SAVED) . ', 256), 1, UNIX_TIMESTAMP() - 3600)');
    }

    private static function cleanUp(): void
    {
        foreach (['202_auth_keys', '202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q('DELETE FROM 202_licence_answers WHERE key_sha256 IN (SHA2(' . self::str(self::SAVED) . ', 256),'
            . ' SHA2(' . self::str(self::POSTED) . ', 256), SHA2(' . self::str(self::HANDED_BACK) . ', 256))');
    }

    private static function savedKey(): string
    {
        $result = self::$db->query('SELECT p202_customer_api_key FROM 202_users WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) ($result->fetch_row()[0] ?? '');
    }

    /**
     * @param array<string, string>|null $post
     * @param array<string, string> $get
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function request(string $page, ?array $post = null, array $get = [], bool $signedIn = true): array
    {
        $env = [
            'P202_SU_USER' => (string) self::USER,
            'P202_SU_SESSION_DIR' => self::$sessions,
            'P202_SU_GET' => json_encode((object) $get, JSON_THROW_ON_ERROR),
            'HTTPS_PROXY' => 'http://127.0.0.1:9',
            'https_proxy' => 'http://127.0.0.1:9',
            'NO_PROXY' => '',
            'no_proxy' => '',
        ];
        if (!$signedIn) {
            $env['P202_SU_SESSION'] = 'anonymous';
        }
        if ($post !== null) {
            $env['P202_SU_POST'] = json_encode($post + ['token' => 'SESSION'], JSON_THROW_ON_ERROR);
        }
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=/dev/null',
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
        $result = json_decode(substr($stdout, $at + strlen("\n@@RESULT ")), true, 8, JSON_THROW_ON_ERROR);
        self::assertNull($result['uncaught'], $stdout . $stderr);

        return ['output' => substr($stdout, 0, $at) . $stderr, 'result' => $result];
    }

    /** The account page as drawn after a request: a save redirects, so the next GET shows its flash. */
    private static function accountAfter(?array $post, array $get = []): string
    {
        $first = self::request(self::ACCOUNT, $post, $get)['output'];

        return str_contains($first, '</html>') ? $first : self::request(self::ACCOUNT)['output'];
    }

    private static function showsNoPartOf(string $key, string $output, string $why): void
    {
        $parts = [$key, substr($key, 0, 8), substr($key, 8, 12)];
        foreach ($parts as $part) {
            self::assertStringNotContainsString($part, $output, "$why: the page shows '$part' of the key");
        }
    }

    /** The customer-key field as drawn. */
    private static function field(string $html): string
    {
        $found = preg_match('/<input[^>]*type="text"[^>]*name="p202_customer_api_key"[^>]*>/', $html, $m);
        self::assertSame(1, $found, "the page has the customer key's field:\n$html");

        return $m[0];
    }

    public function testMyAccountSaysAKeyIsSavedAndHowItEndsAndNothingMore(): void
    {
        $page = self::request(self::ACCOUNT)['output'];

        self::assertStringContainsString('<span data-saved-secret>Saved, ends in WXYZ</span>', $page);
        self::showsNoPartOf(self::SAVED, $page, 'a plain view');
        self::assertStringContainsString('value=""', self::field($page), 'the field is never filled in');
        self::assertStringContainsString('name="remove_p202_customer_api_key"', $page, 'a saved key can be removed');
    }

    public function testAnEmptySaveKeepsTheSavedKey(): void
    {
        $page = self::accountAfter(['update_p202_customer_api_key' => '1', 'p202_customer_api_key' => '  ']);

        self::assertSame(self::SAVED, self::savedKey());
        self::assertStringContainsString('A saved key is kept until you replace it or press Remove key.', $page);
        self::showsNoPartOf(self::SAVED, $page, 'after an empty save');
    }

    public function testRemoveKeyRemovesIt(): void
    {
        // With no key the pages behind the sign-in send the person to the
        // license prompt, so what is read here is the save itself.
        self::request(self::ACCOUNT, [
            'update_p202_customer_api_key' => '1',
            'p202_customer_api_key' => self::POSTED,
            'remove_p202_customer_api_key' => '1',
        ]);

        self::assertSame('', self::savedKey(), 'Remove key wins over a typed key');
    }

    public function testARefusedKeyIsNotWrittenBack(): void
    {
        $page = self::accountAfter(['update_p202_customer_api_key' => '1', 'p202_customer_api_key' => self::POSTED]);

        self::assertSame(self::SAVED, self::savedKey(), 'a key the service could not check is not saved');
        self::assertStringContainsString('is-invalid', self::field($page), 'the field says why');
        self::showsNoPartOf(self::POSTED, $page, 'a refused key');
        self::showsNoPartOf(self::SAVED, $page, 'beside a refused key');
    }

    public function testTheDashboardHandBackIsCarriedToTheSaveButNotShown(): void
    {
        $page = self::request(self::ACCOUNT, null, ['customers_api_key' => base64_encode(self::HANDED_BACK)])['output'];

        self::assertSame(self::SAVED, self::savedKey(), 'a GET saves nothing');
        self::assertStringContainsString('Ready to save, ends in LMNO', $page);
        self::assertSame(
            1,
            substr_count($page, self::HANDED_BACK),
            'the handed-back key is in the page once, in the hidden field its Save posts'
        );
        self::assertSame(
            1,
            preg_match('/<input type="hidden" name="p202_customer_api_key" value="' . self::HANDED_BACK . '">/', $page)
        );
        self::assertStringContainsString('value=""', self::field($page), 'the visible field stays empty');
        self::showsNoPartOf(self::SAVED, $page, 'beside the hand-back');
    }

    public function testTheLicensePromptSaysOnlyThatAKeyIsSaved(): void
    {
        self::q('DELETE FROM 202_licence_answers WHERE key_sha256 = SHA2(' . self::str(self::SAVED) . ', 256)');

        $page = self::request(self::PROMPT, null, [], false)['output'];

        self::assertStringContainsString('A license key is saved, but it does not validate', $page);
        self::showsNoPartOf(self::SAVED, $page, 'the license prompt');
        self::assertStringNotContainsString('WXYZ', $page, 'not even how it ends: the prompt answers anyone');
    }
}
