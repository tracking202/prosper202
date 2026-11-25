<?php

declare(strict_types=1);

namespace Tests\User;

use Api\V3\Controllers\UsersController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * A new email for your own account takes your current password, on
 * PUT /api/v3/users/{id} and on Personal settings, as a new password does:
 * the lost-password page mails its reset to the stored email, so a key or a
 * session that is not the password must not be able to point it elsewhere.
 * The email as stored, sent back, needs none, and an admin changing another
 * user's email is not asked for one.
 *
 * Runs the page as the web server does (tests/Auth/fixtures/session-user-runner.php).
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class OwnEmailNeedsCurrentPasswordIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990741;
    private const ADMIN = 990742;
    private const PASSWORD = 'own-email-pw-1';
    private const EMAIL = 'owner@northwind.example';
    private const NEW_EMAIL = 'elsewhere@example.net';
    private const PAGE = '202-account/account.php';
    private const LICENCE = 'own-email-runner';

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
        self::$sessions = sys_get_temp_dir() . '/p202-session-own-email-' . getmypid();
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
        // USER an Admin (Personal settings in full), ADMIN a Super user, who may edit Admins.
        $users = [self::USER => [2, self::EMAIL], self::ADMIN => [1, 'super@northwind.example']];
        foreach ($users as $user => [$role, $email]) {
            self::q("INSERT INTO 202_users SET user_id = $user, user_name = 'email$user', user_fname = 'Own',"
                . " user_lname = 'Email', user_email = " . self::str($email) . ", user_dash_email = '',"
                . ' user_pass = ' . self::str(\hash_user_pass(self::PASSWORD)) . ','
                . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
                . ' p202_customer_api_key = ' . self::str(self::LICENCE));
            self::row('202_users_pref', ['user_id' => $user, 'analytics_geo_is_eu' => 0]);
            self::row('202_user_role', ['user_id' => $user, 'role_id' => $role]);
        }
        self::q('INSERT INTO 202_licence_answers (key_sha256, valid_yes, valid_at)'
            . ' VALUES (SHA2(' . self::str(self::LICENCE) . ', 256), 1, UNIX_TIMESTAMP() - 3600)');
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::ADMIN] as $user) {
            foreach (['202_api_keys', '202_auth_keys', '202_user_role', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        self::q('DELETE FROM 202_licence_answers WHERE key_sha256 = SHA2(' . self::str(self::LICENCE) . ', 256)');
    }

    private static function email(int $user = self::USER): string
    {
        $result = self::$db->query("SELECT user_email FROM 202_users WHERE user_id = $user");
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) $result->fetch_row()[0];
    }

    /** @param array<string, mixed> $payload */
    private static function refusal(array $payload, int $actor = self::USER): ValidationException
    {
        try {
            (new UsersController(self::$db))->update(self::USER, $payload, $actor);
        } catch (ValidationException $refused) {
            return $refused;
        }
        self::fail('the update was not refused: ' . json_encode($payload));
    }

    public function testYourOwnNewEmailWithoutTheCurrentPasswordIsRefusedNamingIt(): void
    {
        $refused = self::refusal(['user_email' => self::NEW_EMAIL]);

        self::assertSame(422, $refused->getCode());
        self::assertSame('Changing your own email needs your current password', $refused->getMessage());
        self::assertArrayHasKey('current_password', $refused->getFieldErrors());
        self::assertSame(self::EMAIL, self::email(), 'nothing is written');
    }

    public function testYourOwnNewEmailWithAWrongPasswordIsRefused(): void
    {
        $refused = self::refusal(['user_email' => self::NEW_EMAIL, 'current_password' => 'not-the-password']);

        self::assertSame(422, $refused->getCode());
        self::assertSame(['current_password' => 'Does not match'], $refused->getFieldErrors());
        self::assertSame(self::EMAIL, self::email());
    }

    public function testANewPasswordBesideTheNewEmailIsNotAnAnswer(): void
    {
        $refused = self::refusal(['user_email' => self::NEW_EMAIL, 'user_pass' => 'brand-new-pw-1']);

        self::assertArrayHasKey('current_password', $refused->getFieldErrors());
        self::assertSame(self::EMAIL, self::email());
    }

    public function testYourOwnNewEmailWithTheCurrentPasswordIsSaved(): void
    {
        $updated = (new UsersController(self::$db))
            ->update(self::USER, ['user_email' => self::NEW_EMAIL, 'current_password' => self::PASSWORD], self::USER);

        self::assertSame(self::NEW_EMAIL, $updated['data']['user_email']);
        self::assertSame(self::NEW_EMAIL, self::email());
    }

    public function testTheStoredEmailSentBackNeedsNoPassword(): void
    {
        $controller = new UsersController(self::$db);
        $body = $controller->get(self::USER)['data'];
        unset($body['roles']);
        $body['user_fname'] = 'Renamed';

        $updated = $controller->update(self::USER, $body, self::USER);

        self::assertSame('Renamed', $updated['data']['user_fname']);
        self::assertSame(self::EMAIL, self::email());
    }

    public function testAnAdminChangingAnotherUsersEmailIsNotAskedForAPassword(): void
    {
        $updated = (new UsersController(self::$db))->update(self::USER, ['user_email' => self::NEW_EMAIL], self::ADMIN);

        self::assertSame(self::NEW_EMAIL, $updated['data']['user_email']);
    }

    /**
     * @param array<string, string> $post the profile form's fields, over the ones every save sends
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function page(array $post, ?string $session = null): array
    {
        $env = [
            'P202_SU_USER' => (string) self::USER,
            'P202_SU_SESSION_DIR' => self::$sessions,
            'P202_SU_POST' => json_encode(isset($post['change_user_pass']) ? $post : $post + [
                'update_profile' => '1', 'token' => 'SESSION', 'user_email' => self::EMAIL,
                'user_timezone' => 'UTC', 'user_keyword_searched_or_bidded' => 'searched', 'user_referer' => 'browser',
                'user_bid' => '0', 'user_pref_privacy' => 'disabled', 'user_pref_same_ip_fallback' => '0',
                'cloak_referer' => 'origin', 'user_pref_ad_settings' => 'show_all',
            ], JSON_THROW_ON_ERROR),
        ];
        if ($session !== null) {
            $env['P202_SU_SESSION_ID'] = $session;
        }
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr',
                dirname(__DIR__) . '/Auth/fixtures/session-user-runner.php', self::PAGE,
            ],
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

    /** The current-password field the profile form draws, with the sentence under it. */
    private static function passwordField(string $html): string
    {
        $found = preg_match(
            '/<input[^>]*name="email_current_password"[^>]*>(?:\s*<div class="form-text">[^<]*<\/div>)?'
                . '\s*(?:<div class="invalid-feedback[^"]*">[^<]*<\/div>)?/',
            $html,
            $m
        );
        self::assertSame(1, $found, 'the profile form has the current-password field');

        return $m[0];
    }

    /** Personal settings in full (an Admin) and the email-only form (role 4, no Personal settings). */
    public static function pageUsers(): array
    {
        return ['personal settings' => [2], 'email only' => [4]];
    }

    /** @dataProvider pageUsers */
    public function testThePageRefusesYourOwnNewEmailWithoutTheRightPassword(int $role): void
    {
        self::q('UPDATE 202_user_role SET role_id = ' . $role . ' WHERE user_id = ' . self::USER);

        $missing = self::page(['user_email' => self::NEW_EMAIL])['output'];
        self::assertSame(self::EMAIL, self::email(), 'nothing is written without the password');
        $field = self::passwordField($missing);
        self::assertStringContainsString('Enter your current password to change your email.', $field);
        self::assertStringContainsString('is-invalid', self::passwordField($missing));
        self::assertStringContainsString('value="' . self::NEW_EMAIL . '"', $missing, 'the typed email is kept');

        $wrong = self::page(['user_email' => self::NEW_EMAIL, 'email_current_password' => 'wrong-pw'])['output'];
        self::assertSame(self::EMAIL, self::email(), 'nothing is written with a wrong password');
        self::assertStringContainsString('Your current password was typed incorrectly.', self::passwordField($wrong));

        $saved = self::page(['user_email' => self::NEW_EMAIL, 'email_current_password' => self::PASSWORD]);
        self::assertSame(303, $saved['result']['status'], $saved['output']);
        self::assertSame(self::NEW_EMAIL, self::email(), 'the right password saves it');
    }

    /** @dataProvider pageUsers */
    public function testThePageSavesTheEmailUnchangedWithoutAPassword(int $role): void
    {
        self::q('UPDATE 202_user_role SET role_id = ' . $role . ' WHERE user_id = ' . self::USER);

        $saved = self::page([]);

        self::assertSame(303, $saved['result']['status'], $saved['output']);
        self::assertSame(self::EMAIL, self::email());
    }

    /**
     * Wrong guesses on the email form count toward the limit the password
     * form keeps, and the session ends at it: the email form is not a way to
     * keep guessing.
     */
    public function testWrongGuessesOnEitherFormShareOneLimit(): void
    {
        $first = self::page(['user_email' => self::NEW_EMAIL, 'email_current_password' => 'guess-1']);
        $session = (string) $first['result']['session_id'];
        self::assertContains('user_id', $first['result']['session_keys']);
        self::page(['user_email' => self::NEW_EMAIL, 'email_current_password' => 'guess-2'], $session);
        $password = ['change_user_pass' => '1', 'token' => 'SESSION', 'new_user_pass' => 'brand-new-pw-1',
            'retype_new_user_pass' => 'brand-new-pw-1'];
        for ($guess = 3; $guess < \AUTH::MAX_PASSWORD_REAUTH_FAILS; $guess++) {
            $still = self::page($password + ['user_pass' => "guess-$guess"], $session);
            self::assertContains('user_id', $still['result']['session_keys'], "still signed in after guess $guess");
        }

        $last = self::page(['user_email' => self::NEW_EMAIL, 'email_current_password' => 'guess-last'], $session);

        self::assertNotContains('user_id', $last['result']['session_keys'], 'the session ends at the limit');
        self::assertSame(self::EMAIL, self::email());
    }
}
