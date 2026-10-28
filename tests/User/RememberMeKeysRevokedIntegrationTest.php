<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\DataSeeder;
use Prosper202\User\MysqlUserRepository;
use Prosper202\User\UserDataPurge;

/**
 * A remember-me key ends with what ends its sign-in: the sign-out deletes the
 * key of the browser signing out (and no other), and every writer that leaves
 * a user unable to act, or gives them a new password, deletes all of theirs in
 * the same transaction, so the cookie is refused afterwards, also once the
 * user is switched on again. A rehash of the password at sign-in keeps them.
 *
 * The sign-out, Personal settings' password change and the reset page run as
 * the web server runs them, in a process of their own
 * (tests/Auth/fixtures/session-user-runner.php); the other writers run here on
 * the scratch database: Account › Users' save (p202_account_save_user(),
 * lifted from functions-account-ui.php), PUT /users/{id}
 * (UsersController::update()), MysqlUserRepository::update(),
 * PasswordChange::store() and the delete (UserDataPurge::deleteUser()). A
 * revocation that fails rolls the switch-off or the password back, and a
 * sign-out whose revocation fails still ends the session and says so.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class RememberMeKeysRevokedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990891;
    private const OTHER = 990892;

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
        require_once $root . '/tests/Config/fixtures/lift-function.php';
        if (!function_exists('p202_account_save_user')) {
            eval(lift_function($root . '/202-config/functions-account-ui.php', 'p202_account_save_user'));
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        (new DataSeeder(self::$db))->seed();
        self::$sessions = sys_get_temp_dir() . '/p202-remember-me-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->query('RENAME TABLE 202_auth_keys_away TO 202_auth_keys');
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
        foreach ([self::USER, self::OTHER] as $user) {
            self::q("INSERT INTO 202_users SET user_id = $user, user_name = 'rk$user',"
                . " user_email = 'rk$user@example.com', user_dash_email = '', user_pass = 'x',"
                . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
                . ' secret_key = ' . self::str(self::secret($user)) . ','
                . " p202_customer_api_key = 'remember-me-test'");
            self::row('202_users_pref', ['user_id' => $user]);
            self::row('202_user_role', ['user_id' => $user, 'role_id' => 2]);
        }
        // Two remembered browsers of USER, one of OTHER.
        foreach ([[self::USER, 'a'], [self::USER, 'b'], [self::OTHER, 'a']] as [$user, $browser]) {
            self::row('202_auth_keys', [
                'user_id' => $user,
                'auth_key' => self::key($user, $browser),
                'expires' => time() + 86400,
            ]);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $user) {
            foreach (['202_api_keys', '202_auth_keys', '202_user_role', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
    }

    private static function secret(int $user): string
    {
        return substr("rk{$user}" . str_repeat('secret', 8), 0, 48);
    }

    private static function key(int $user, string $browser): string
    {
        return substr("key{$browser}{$user}" . str_repeat('k', 48), 0, 48);
    }

    /** The remember_me cookie of USER's (or $user's) browser, as remember_me_on_auth() signs it. */
    private static function cookie(int $user, string $browser): string
    {
        $named = $user . '-' . self::key($user, $browser);

        return $named . '-' . hash_hmac('sha256', $named, self::secret($user));
    }

    /** @return list<string> the user's stored keys */
    private static function keys(int $user): array
    {
        $result = self::$db->query("SELECT auth_key FROM 202_auth_keys WHERE user_id = $user ORDER BY auth_key");
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'auth_key');
    }

    private static function active(int $user): string
    {
        $result = self::$db->query("SELECT user_active FROM 202_users WHERE user_id = $user");
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) ($result->fetch_row()[0] ?? '');
    }

    /**
     * One request as the web server runs it; what it printed and left.
     *
     * @param array<string, string> $env
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function request(string $target, array $env = []): array
    {
        $env += ['P202_SU_USER' => (string) self::USER, 'P202_SU_SESSION_DIR' => self::$sessions];
        $runner = dirname(__DIR__) . '/Auth/fixtures/session-user-runner.php';
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', $runner, $target],
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

        return ['output' => substr($stdout, 0, $at) . $stderr, 'result' => $result];
    }

    /** Whether a browser holding only this cookie is signed in by it. */
    private static function signsIn(string $cookie): bool
    {
        $call = self::request('call:remember', ['P202_SU_SESSION' => 'none', 'P202_SU_REMEMBER' => $cookie]);
        self::assertNull($call['result']['uncaught'], $call['output']);

        return $call['result']['answer'] === true;
    }

    public function testASignOutRevokesItsOwnKeyAndNoOther(): void
    {
        self::assertTrue(self::signsIn(self::cookie(self::USER, 'a')), 'the cookie signs in before the sign-out');

        $out = self::request('call:sign_out', ['P202_SU_REMEMBER' => self::cookie(self::USER, 'a')]);
        self::assertNull($out['result']['uncaught'], $out['output']);
        $session = self::$sessions . '/sess_' . $out['result']['signed_in_as'];
        self::assertFileDoesNotExist($session, 'the session is gone');
        self::assertFalse($out['result']['cookie_kept'], 'the cookie is cleared');

        $kept = self::key(self::USER, 'b');
        self::assertSame([$kept], self::keys(self::USER), "only the signing-out browser's key goes");
        self::assertSame([self::key(self::OTHER, 'a')], self::keys(self::OTHER));
        self::assertFalse(self::signsIn(self::cookie(self::USER, 'a')), 'a copy of the cookie signs nobody in');
        $other = self::cookie(self::USER, 'b');
        self::assertTrue(self::signsIn($other), "the user's other remembered browser is untouched");
    }

    public function testTheSignOutPageRevokesItsKey(): void
    {
        $page = self::request('202-account/signout.php', ['P202_SU_REMEMBER' => self::cookie(self::USER, 'a')]);
        self::assertNull($page['result']['uncaught'], $page['output']);
        self::assertFileDoesNotExist(self::$sessions . '/sess_' . $page['result']['signed_in_as']);
        self::assertSame([self::key(self::USER, 'b')], self::keys(self::USER));
    }

    public function testASignOutWithNoCookieRevokesNothing(): void
    {
        $out = self::request('call:sign_out');
        self::assertNull($out['result']['uncaught'], $out['output']);
        self::assertFileDoesNotExist(self::$sessions . '/sess_' . $out['result']['signed_in_as']);
        self::assertCount(2, self::keys(self::USER));
    }

    /**
     * A key that could not be deleted is not a sign-out that reads as
     * complete: the session and the cookie still end, and the request is the
     * database error page.
     */
    public function testASignOutWhoseRevocationFailsStillEndsTheSessionAndSaysSo(): void
    {
        try {
            $out = self::request('call:sign_out', [
                'P202_SU_REMEMBER' => self::cookie(self::USER, 'a'),
                'P202_SU_AFTER_BOOT_SQL' => 'RENAME TABLE 202_auth_keys TO 202_auth_keys_away',
            ]);
        } finally {
            self::q('RENAME TABLE 202_auth_keys_away TO 202_auth_keys');
        }
        $r = $out['result'];
        self::assertStringStartsWith(
            'Prosper202\\Database\\Exceptions\\QueryException: Revoking a remember-me key of user '
                . self::USER . ' failed',
            (string) $r['uncaught'],
            $out['output']
        );
        self::assertSame(500, $r['status'], 'the database error page');
        self::assertFileDoesNotExist(self::$sessions . '/sess_' . $r['signed_in_as'], 'the session ended all the same');
        self::assertFalse($r['cookie_kept'], 'and the cookie');
        self::assertCount(2, self::keys(self::USER), 'the key is still stored, and the page said the sign-out failed');
    }

    /**
     * Each writer that switches a user off, and how it switches them on again.
     *
     * @return array<string, array{callable(\mysqli, int, int): void}>
     */
    public static function writers(): array
    {
        $page = static function (\mysqli $db, int $user, int $active): void {
            p202_account_save_user(new Connection($db), $user, ['user_active' => $active], 2);
        };
        $api = static function (\mysqli $db, int $user, int $active): void {
            (new \Api\V3\Controllers\UsersController($db))->update($user, ['user_active' => $active], 1);
        };
        $repository = static function (\mysqli $db, int $user, int $active): void {
            (new MysqlUserRepository(new Connection($db)))->update($user, ['user_active' => $active]);
        };

        return [
            'Account › Users (p202_account_save_user)' => [$page],
            'PUT /users/{id} (UsersController::update)' => [$api],
            'MysqlUserRepository::update' => [$repository],
        ];
    }

    /**
     * @dataProvider writers
     * @param callable(\mysqli, int, int): void $write
     */
    public function testSwitchingAUserOffRevokesEveryKeyOfTheirs(callable $write): void
    {
        $write(self::$db, self::USER, 0);
        self::assertSame('0', self::active(self::USER));
        self::assertSame([], self::keys(self::USER), 'every remembered browser of the user');
        self::assertSame([self::key(self::OTHER, 'a')], self::keys(self::OTHER), 'and no one else\'s');

        $write(self::$db, self::USER, 1);
        self::assertSame('1', self::active(self::USER));
        self::assertFalse(
            self::signsIn(self::cookie(self::USER, 'a')),
            'switched on again, the old cookie signs nobody in'
        );
    }

    /**
     * @dataProvider writers
     * @param callable(\mysqli, int, int): void $write
     */
    public function testAWriteThatLeavesTheUserActiveKeepsTheirKeys(callable $write): void
    {
        $write(self::$db, self::USER, 1);
        self::assertCount(2, self::keys(self::USER));
        self::assertTrue(self::signsIn(self::cookie(self::USER, 'a')));
    }

    /**
     * A revocation that fails is not a switch-off: the write is rolled back
     * and the writer throws.
     *
     * @dataProvider writers
     * @param callable(\mysqli, int, int): void $write
     */
    public function testARevocationThatFailsLeavesTheUserAsTheyWere(callable $write): void
    {
        self::q('RENAME TABLE 202_auth_keys TO 202_auth_keys_away');
        try {
            $write(self::$db, self::USER, 0);
            $threw = null;
        } catch (\Throwable $e) {
            $threw = $e;
        } finally {
            self::q('RENAME TABLE 202_auth_keys_away TO 202_auth_keys');
        }
        self::assertNotNull($threw, 'the writer said nothing of a failed revocation');
        $chain = '';
        for ($e = $threw; $e !== null; $e = $e->getPrevious()) {
            $chain .= get_class($e) . ': ' . $e->getMessage() . "\n";
        }
        self::assertStringContainsString('Revoking the remember-me keys of user ' . self::USER . ' failed', $chain);
        self::assertSame('1', self::active(self::USER), 'the switch-off was rolled back');
        self::assertCount(2, self::keys(self::USER));
    }

    public function testRemovingAUserRevokesEveryKeyOfTheirs(): void
    {
        (new UserDataPurge(self::$db))->deleteUser(self::USER);

        self::assertSame([], self::keys(self::USER));
        self::assertSame([self::key(self::OTHER, 'a')], self::keys(self::OTHER));
        self::q('UPDATE 202_users SET user_deleted = 0 WHERE user_id = ' . self::USER);
        self::assertFalse(self::signsIn(self::cookie(self::USER, 'a')), 'restored, the old cookie signs nobody in');
    }

    private const OLD_PASSWORD = 'old-password-1';
    private const NEW_PASSWORD = 'new-password-2';

    private static function setPassword(int $user, string $stored): void
    {
        self::q('UPDATE 202_users SET user_pass = ' . self::str($stored) . " WHERE user_id = $user");
    }

    private static function stored(int $user, string $column = 'user_pass'): ?string
    {
        $result = self::$db->query("SELECT `$column` FROM 202_users WHERE user_id = $user");
        self::assertInstanceOf(\mysqli_result::class, $result);
        $value = $result->fetch_row()[0] ?? null;

        return $value === null ? null : (string) $value;
    }

    private static function passwordIs(int $user, string $plain): bool
    {
        return \verify_user_pass($plain, (string) self::stored($user))['valid'];
    }

    /** What Personal settings' password form posts, with the session's token. */
    private static function passwordForm(string $current, string $new): string
    {
        return json_encode([
            'change_user_pass' => '1',
            'token' => 'SESSION',
            'user_pass' => $current,
            'new_user_pass' => $new,
            'retype_new_user_pass' => $new,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The user changes their own password in a remembered browser: every key
     * of theirs goes, that browser's too (a stolen cookie is a copy of one),
     * and that browser's session goes on, its cookie cleared.
     */
    public function testChangingYourOwnPasswordRevokesEveryKeyAndTheChangingBrowserStaysSignedIn(): void
    {
        self::setPassword(self::USER, password_hash(self::OLD_PASSWORD, PASSWORD_DEFAULT));

        $page = self::request('202-account/account.php', [
            'P202_SU_REMEMBER' => self::cookie(self::USER, 'a'),
            'P202_SU_POST' => self::passwordForm(self::OLD_PASSWORD, self::NEW_PASSWORD),
        ]);
        $r = $page['result'];
        self::assertNull($r['uncaught'], $page['output']);
        self::assertSame(303, $r['status'], 'the form redirects as saved');
        self::assertTrue(self::passwordIs(self::USER, self::NEW_PASSWORD), 'the new password is stored');

        self::assertFalse($r['denied']);
        self::assertSame($r['signed_in_as'], $r['session_id'], 'the same session');
        self::assertContains('user_id', $r['session_keys'], 'still signed in');
        self::assertFileExists(self::$sessions . '/sess_' . $r['session_id']);
        self::assertFalse($r['cookie_kept'], "the browser's cookie is cleared: its key is gone");

        self::assertSame([], self::keys(self::USER), "every key of the user, the changing browser's included");
        self::assertSame([self::key(self::OTHER, 'a')], self::keys(self::OTHER), "and no one else's");
        self::assertFalse(self::signsIn(self::cookie(self::USER, 'a')), "a copy of the changing browser's cookie");
        self::assertFalse(self::signsIn(self::cookie(self::USER, 'b')), "the user's other remembered browser");
    }

    public function testAPasswordChangeWhoseRevocationFailsIsNotSaved(): void
    {
        $old = password_hash(self::OLD_PASSWORD, PASSWORD_DEFAULT);
        self::setPassword(self::USER, $old);
        try {
            $page = self::request('202-account/account.php', [
                'P202_SU_REMEMBER' => self::cookie(self::USER, 'a'),
                'P202_SU_POST' => self::passwordForm(self::OLD_PASSWORD, self::NEW_PASSWORD),
                'P202_SU_AFTER_BOOT_SQL' => 'RENAME TABLE 202_auth_keys TO 202_auth_keys_away',
            ]);
        } finally {
            self::q('RENAME TABLE 202_auth_keys_away TO 202_auth_keys');
        }
        self::assertNull($page['result']['uncaught'], $page['output']);
        self::assertStringContainsString('Your password could not be changed.', $page['output']);
        self::assertSame($old, self::stored(self::USER), 'the old password stands');
        self::assertCount(2, self::keys(self::USER));
        self::assertTrue($page['result']['cookie_kept']);
    }

    private static function startReset(int $user, string $key): void
    {
        self::q('UPDATE 202_users SET user_pass_key = ' . self::str($key) . ', user_pass_time = ' . time()
            . " WHERE user_id = $user");
    }

    /**
     * The reset page's form, posted by a browser that has not signed in.
     *
     * @param array<string, string> $env
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function reset(string $key, string $password, array $env = []): array
    {
        return self::request('202-pass-reset.php', $env + [
            'P202_SU_SESSION' => 'anonymous',
            'P202_SU_GET' => json_encode(['key' => $key], JSON_THROW_ON_ERROR),
            'P202_SU_POST' => json_encode(
                ['token' => 'SESSION', 'user_pass' => $password, 'verify_user_pass' => $password],
                JSON_THROW_ON_ERROR
            ),
        ]);
    }

    public function testAResetRevokesEveryKeyOfTheUser(): void
    {
        $key = str_repeat('ab', 32);
        self::startReset(self::USER, $key);

        $page = self::reset($key, self::NEW_PASSWORD);
        self::assertNull($page['result']['uncaught'], $page['output']);
        self::assertStringContainsString('Your password has been reset.', $page['output']);
        self::assertTrue(self::passwordIs(self::USER, self::NEW_PASSWORD));
        self::assertNull(self::stored(self::USER, 'user_pass_key'), 'the link is used up');

        self::assertSame([], self::keys(self::USER));
        self::assertSame([self::key(self::OTHER, 'a')], self::keys(self::OTHER));
        self::assertFalse(self::signsIn(self::cookie(self::USER, 'a')));
    }

    public function testAResetWhoseRevocationFailsIsNotSaved(): void
    {
        $key = str_repeat('cd', 32);
        self::startReset(self::USER, $key);
        try {
            $page = self::reset($key, self::NEW_PASSWORD, [
                'P202_SU_AFTER_BOOT_SQL' => 'RENAME TABLE 202_auth_keys TO 202_auth_keys_away',
            ]);
        } finally {
            self::q('RENAME TABLE 202_auth_keys_away TO 202_auth_keys');
        }
        self::assertNull($page['result']['uncaught'], $page['output']);
        self::assertStringContainsString('Could not save your new password', $page['output']);
        self::assertSame('x', self::stored(self::USER), 'the old password stands');
        self::assertSame($key, self::stored(self::USER, 'user_pass_key'), 'and the link still works');
        self::assertCount(2, self::keys(self::USER));
    }

    /**
     * Each writer that sets another user's password (or, through the API,
     * the user's own with the current one).
     *
     * @return array<string, array{callable(\mysqli, int, string): void}>
     */
    public static function passwordWriters(): array
    {
        $page = static function (\mysqli $db, int $user, string $password): void {
            p202_account_save_user(new Connection($db), $user, ['user_pass' => \hash_user_pass($password)], 2);
        };
        $admin = static function (\mysqli $db, int $user, string $password): void {
            (new \Api\V3\Controllers\UsersController($db))->update($user, ['user_pass' => $password], 1);
        };
        $own = static function (\mysqli $db, int $user, string $password): void {
            self::setPassword($user, password_hash(self::OLD_PASSWORD, PASSWORD_DEFAULT));
            (new \Api\V3\Controllers\UsersController($db))
                ->update($user, ['user_pass' => $password, 'current_password' => self::OLD_PASSWORD], $user);
        };
        $repository = static function (\mysqli $db, int $user, string $password): void {
            (new MysqlUserRepository(new Connection($db)))->update($user, ['user_pass' => $password]);
        };
        $store = static function (\mysqli $db, int $user, string $password): void {
            \Prosper202\User\PasswordChange::store(new Connection($db), $user, \hash_user_pass($password), false);
        };

        return [
            'Account › Users (p202_account_save_user)' => [$page],
            "PUT /users/{id}, another user's" => [$admin],
            'PUT /users/{id}, your own' => [$own],
            'MysqlUserRepository::update' => [$repository],
            'PasswordChange::store' => [$store],
        ];
    }

    /**
     * @dataProvider passwordWriters
     * @param callable(\mysqli, int, string): void $write
     */
    public function testSettingAPasswordRevokesEveryKeyOfTheUser(callable $write): void
    {
        $write(self::$db, self::USER, self::NEW_PASSWORD);
        self::assertTrue(self::passwordIs(self::USER, self::NEW_PASSWORD));
        self::assertSame([], self::keys(self::USER));
        self::assertSame([self::key(self::OTHER, 'a')], self::keys(self::OTHER));
        self::assertFalse(self::signsIn(self::cookie(self::USER, 'b')), 'the old cookie signs nobody in');
    }

    /**
     * @dataProvider passwordWriters
     * @param callable(\mysqli, int, string): void $write
     */
    public function testAPasswordWhoseRevocationFailsIsNotStored(callable $write): void
    {
        self::q('RENAME TABLE 202_auth_keys TO 202_auth_keys_away');
        try {
            $write(self::$db, self::USER, self::NEW_PASSWORD);
            $threw = null;
        } catch (\Throwable $e) {
            $threw = $e;
        } finally {
            self::q('RENAME TABLE 202_auth_keys_away TO 202_auth_keys');
        }
        self::assertNotNull($threw, 'the writer said nothing of a failed revocation');
        $chain = '';
        for ($e = $threw; $e !== null; $e = $e->getPrevious()) {
            $chain .= get_class($e) . ': ' . $e->getMessage() . "\n";
        }
        self::assertStringContainsString('Revoking the remember-me keys of user ' . self::USER . ' failed', $chain);
        self::assertFalse(self::passwordIs(self::USER, self::NEW_PASSWORD), 'the new password was rolled back');
        self::assertCount(2, self::keys(self::USER));
    }

    public function testAPasswordForAUserWhoIsNotThereStoresNothingAndRevokesNothing(): void
    {
        try {
            $hash = \hash_user_pass('x-password');
            \Prosper202\User\PasswordChange::store(new Connection(self::$db), 990899, $hash, false);
            $threw = null;
        } catch (\RuntimeException $e) {
            $threw = $e->getMessage();
        }
        self::assertSame('Storing the password of user 990899 changed no row', $threw);
        self::assertCount(2, self::keys(self::USER));
        self::assertCount(1, self::keys(self::OTHER));
    }

    /**
     * The same password, stored again under a newer hash as the user signs
     * in, is not a new password: every remembered browser stays.
     */
    public function testARehashAtSignInKeepsTheKeys(): void
    {
        $legacy = md5('202' . md5(self::OLD_PASSWORD . '202'));
        self::setPassword(self::USER, $legacy);

        $signIn = self::request('call:authenticate', [
            'P202_SU_SESSION' => 'none',
            'P202_SU_NAME' => 'rk' . self::USER,
            'P202_SU_PASS' => self::OLD_PASSWORD,
        ]);
        self::assertNull($signIn['result']['uncaught'], $signIn['output']);
        self::assertTrue($signIn['result']['answer'], 'the legacy password signs in');
        self::assertNotSame($legacy, self::stored(self::USER), 'and is stored again, rehashed');
        self::assertNotNull(password_get_info((string) self::stored(self::USER))['algo']);
        self::assertTrue(self::passwordIs(self::USER, self::OLD_PASSWORD), 'the same password');

        self::assertCount(2, self::keys(self::USER), 'every remembered browser stays');
        self::assertTrue(self::signsIn(self::cookie(self::USER, 'a')));
    }
}
