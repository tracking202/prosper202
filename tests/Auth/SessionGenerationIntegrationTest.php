<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * A new password ends every other session of the user at its next request,
 * and keeps the one that made the change on Personal settings.
 *
 * #325 revoked the remember-me keys on a new password, and a browser already
 * signed in stayed signed in: a thief who had used a stolen cookie kept the
 * session it made after the owner changed the password. A session now holds
 * the generation its user was at when it began (AUTH::begin_user_session());
 * a new password, a switch-off and a removal count the user's generation up
 * (SessionGeneration); connect.php reads the row's with the user it already
 * reads and ends a session that holds another.
 *
 * Two sessions stand for two browsers: each request runs as the web server
 * runs it, in a process of its own (fixtures/session-user-runner.php), and a
 * browser's next request resumes the session file the last one left.
 * tests/live/password-ends-sessions.sh drives the same over HTTP.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class SessionGenerationIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990871;
    private const ADMIN = 990872;
    private const PASSWORD = 'generation-old-pw';
    private const NEW_PASSWORD = 'generation-new-pw';

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
        self::$sessions = sys_get_temp_dir() . '/p202-session-generation-' . getmypid();
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
        // USER an Admin, ADMIN a Super user, who may edit Admins.
        foreach ([self::USER => 2, self::ADMIN => 1] as $user => $role) {
            self::q("INSERT INTO 202_users SET user_id = $user, user_name = 'gen$user', user_fname = 'Gen',"
                . " user_lname = 'User', user_email = 'gen$user@example.com', user_dash_email = '',"
                . ' user_pass = ' . self::str(password_hash(self::PASSWORD, PASSWORD_DEFAULT)) . ','
                . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
                . " p202_customer_api_key = 'session-generation'");
            self::row('202_users_pref', ['user_id' => $user]);
            self::row('202_user_role', ['user_id' => $user, 'role_id' => $role]);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::ADMIN] as $user) {
            foreach (['202_api_keys', '202_auth_keys', '202_user_role', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
    }

    private static function generation(int $user): int
    {
        $result = self::$db->query("SELECT user_session_generation FROM 202_users WHERE user_id = $user");
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (int) $result->fetch_row()[0];
    }

    /**
     * One request; what it printed and what it left.
     *
     * @param array<string, string> $env
     * @return array{output: string, result: array<string, mixed>}
     */
    private static function request(string $target, array $env = []): array
    {
        $env += [
            'P202_SU_USER' => (string) self::USER,
            'P202_SU_SESSION_DIR' => self::$sessions,
        ];
        $runner = __DIR__ . '/fixtures/session-user-runner.php';
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

    /**
     * A browser signed in as $user, at the generation given (none: a session
     * from before 1.9.79): its session id.
     */
    private static function browser(int $user = self::USER, ?int $generation = 0): string
    {
        $env = ['P202_SU_USER' => (string) $user];
        if ($generation !== null) {
            $env['P202_SU_GENERATION'] = (string) $generation;
        }
        $signedIn = self::request('call:booted', $env);
        self::assertNull($signedIn['result']['uncaught'], $signedIn['output']);
        self::assertContains('user_id', $signedIn['result']['answer']['identity'], 'the browser is signed in');

        return $signedIn['result']['session_id'];
    }

    /**
     * The browser's next request: whether it is still signed in, and the
     * session id it was left with.
     *
     * @return array{bool, string}
     */
    private static function next(string $session, int $user = self::USER): array
    {
        $next = self::request('call:booted', ['P202_SU_SESSION_ID' => $session, 'P202_SU_USER' => (string) $user]);
        self::assertNull($next['result']['uncaught'], $next['output']);

        return [in_array('user_id', $next['result']['answer']['identity'], true), $next['result']['session_id']];
    }

    /** What Personal settings' password form posts, with the session's token. */
    private static function passwordForm(): string
    {
        return json_encode([
            'change_user_pass' => '1',
            'token' => 'SESSION',
            'user_pass' => self::PASSWORD,
            'new_user_pass' => self::NEW_PASSWORD,
            'retype_new_user_pass' => self::NEW_PASSWORD,
        ], JSON_THROW_ON_ERROR);
    }

    public function testAPasswordChangeEndsTheOtherSessionAndKeepsTheChangingOne(): void
    {
        $changing = self::browser();
        $other = self::browser();

        $change = self::request('202-account/account.php', [
            'P202_SU_SESSION_ID' => $changing,
            'P202_SU_POST' => self::passwordForm(),
        ]);
        $r = $change['result'];
        self::assertNull($r['uncaught'], $change['output']);
        self::assertSame(303, $r['status'], 'the password is changed');
        self::assertSame(1, self::generation(self::USER), 'the user\'s generation is counted up');
        self::assertSame($changing, $r['session_id'], 'the changing browser keeps its session');
        self::assertSame(1, $r['generation'], 'at the new generation');

        [$otherIn, $otherId] = self::next($other);
        self::assertFalse($otherIn, 'the other browser is signed out at its next request');
        self::assertNotSame($other, $otherId, 'with a new session id, as a sign-out');
        [$changingIn, $changingId] = self::next($changing);
        self::assertTrue($changingIn, 'the changing browser is still signed in on its next request');
        self::assertSame($changing, $changingId);
    }

    public function testAResetEndsEverySession(): void
    {
        $one = self::browser();
        $two = self::browser();
        $key = str_repeat('ef', 32);
        self::q('UPDATE 202_users SET user_pass_key = ' . self::str($key) . ', user_pass_time = ' . time()
            . ' WHERE user_id = ' . self::USER);

        $reset = self::request('202-pass-reset.php', [
            'P202_SU_SESSION' => 'anonymous',
            'P202_SU_GET' => json_encode(['key' => $key], JSON_THROW_ON_ERROR),
            'P202_SU_POST' => json_encode(
                ['token' => 'SESSION', 'user_pass' => self::NEW_PASSWORD, 'verify_user_pass' => self::NEW_PASSWORD],
                JSON_THROW_ON_ERROR
            ),
        ]);
        self::assertNull($reset['result']['uncaught'], $reset['output']);
        self::assertStringContainsString('Your password has been reset.', $reset['output']);
        self::assertSame(1, self::generation(self::USER));
        self::assertFalse(self::next($one)[0], 'a browser signed in before the reset is signed out');
        self::assertFalse(self::next($two)[0], 'and so is the other');
    }

    public function testAnAdminChangeThroughTheApiEndsTheUsersSessionsNotTheAdmins(): void
    {
        $users = self::browser();
        $admins = self::browser(self::ADMIN);

        (new \Api\V3\Controllers\UsersController(self::$db))
            ->update(self::USER, ['user_pass' => self::NEW_PASSWORD], self::ADMIN);

        self::assertSame(1, self::generation(self::USER));
        self::assertSame(0, self::generation(self::ADMIN));
        self::assertFalse(self::next($users)[0], 'the user\'s browser is signed out');
        self::assertTrue(self::next($admins, self::ADMIN)[0], 'the admin\'s is not');
    }

    public function testAnAdminChangeOnAccountUsersEndsTheUsersSessionsNotTheAdmins(): void
    {
        $users = self::browser();
        $admins = self::browser(self::ADMIN);

        $edit = self::request('202-account/user-management.php', [
            'P202_SU_SESSION_ID' => $admins,
            'P202_SU_USER' => (string) self::ADMIN,
            'P202_SU_GET' => json_encode(['edit_user_id' => (string) self::USER], JSON_THROW_ON_ERROR),
            'P202_SU_POST' => json_encode([
                'token' => 'SESSION',
                'user_id' => (string) self::USER,
                'user_fname' => 'Gen',
                'user_lname' => 'User',
                'user_email' => 'gen' . self::USER . '@example.com',
                'user_name' => 'gen' . self::USER,
                'user_password' => self::NEW_PASSWORD,
                'user_password2' => self::NEW_PASSWORD,
                'user_role' => '2',
                'user_active' => 'on',
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertNull($edit['result']['uncaught'], $edit['output']);
        self::assertSame(303, $edit['result']['status'], 'the edit is saved');
        self::assertSame(1, self::generation(self::USER));
        self::assertFalse(self::next($users)[0], 'the user\'s browser is signed out');
        self::assertTrue(self::next($admins, self::ADMIN)[0], 'the admin\'s is not');
    }

    /** The same password, stored again under a newer hash as the user signs in, ends nothing. */
    public function testARehashAtSignInEndsNoSession(): void
    {
        $open = self::browser();
        self::q("UPDATE 202_users SET user_pass = MD5(CONCAT('202', MD5(CONCAT('" . self::PASSWORD . "', '202'))))"
            . ' WHERE user_id = ' . self::USER);

        $signIn = self::request('call:authenticate', [
            'P202_SU_SESSION' => 'none',
            'P202_SU_NAME' => 'gen' . self::USER,
            'P202_SU_PASS' => self::PASSWORD,
        ]);
        self::assertNull($signIn['result']['uncaught'], $signIn['output']);
        self::assertTrue($signIn['result']['answer'], 'the legacy password signs in, and is rehashed');
        self::assertSame(0, self::generation(self::USER), 'nothing is counted up');
        self::assertTrue(self::next($open)[0], 'the browser signed in before stays signed in');
    }

    /** A session from before 1.9.79 holds no generation: it is read as 0, and a change ends it. */
    public function testASessionFromBeforeTheUpgradeStaysUntilAChange(): void
    {
        $old = self::browser(self::USER, null);
        self::assertTrue(self::next($old)[0], 'it stays signed in through the upgrade');

        (new \Api\V3\Controllers\UsersController(self::$db))
            ->update(self::USER, ['user_pass' => self::NEW_PASSWORD], self::ADMIN);
        self::assertFalse(self::next($old)[0], 'a new password ends it');
    }

    /**
     * A switch-off counts the generation up too: a session that made no
     * request while the user was off would otherwise go on once they are on
     * again (the standing check sees only the request that comes while off).
     */
    public function testASessionIdleThroughASwitchOffIsEndedWhenTheUserIsOnAgain(): void
    {
        $idle = self::browser();
        $controller = new \Api\V3\Controllers\UsersController(self::$db);
        $controller->update(self::USER, ['user_active' => 0], self::ADMIN);
        $controller->update(self::USER, ['user_active' => 1], self::ADMIN);

        self::assertSame(1, self::generation(self::USER), 'the switch-off counted up; the switch-on did not');
        self::assertFalse(self::next($idle)[0], 'the idle browser is signed out at its next request');
    }

    /**
     * A read that fails is neither answer: the request is the database error
     * page and the session, stale or not, is left as it was for a read that
     * can answer.
     */
    public function testAFailedReadNeitherEndsNorKeepsTheSession(): void
    {
        $stale = self::browser();
        self::q('UPDATE 202_users SET user_session_generation = 4 WHERE user_id = ' . self::USER);
        try {
            self::q('ALTER TABLE 202_users DROP COLUMN user_deleted');
            $failed = self::request('call:booted', ['P202_SU_SESSION_ID' => $stale]);
        } finally {
            self::q("ALTER TABLE 202_users ADD COLUMN user_deleted int(1) NOT NULL DEFAULT '0' AFTER user_active");
        }
        $r = $failed['result'];
        self::assertStringStartsWith(
            'Prosper202\\Database\\Exceptions\\QueryException: Reading whether user ' . self::USER . ' may act failed',
            (string) $r['uncaught'],
            $failed['output']
        );
        self::assertSame(500, $r['status'], 'the database error page');
        self::assertFileExists(self::$sessions . '/sess_' . $stale, 'the session was not ended');

        self::assertFalse(self::next($stale)[0], 'a read that answers ends it');
    }

    /**
     * Sessions that cannot be ended are not a password change: the count
     * fails with the password in one transaction, and nothing is stored.
     */
    public function testAPasswordWhoseSessionsCannotBeEndedIsNotStored(): void
    {
        $stored = static function (): string {
            $result = self::$db->query('SELECT user_pass FROM 202_users WHERE user_id = ' . self::USER);
            self::assertInstanceOf(\mysqli_result::class, $result);

            return (string) $result->fetch_row()[0];
        };
        $before = $stored();
        $writers = [
            'Personal settings and the reset (PasswordChange::store)' => static function (): void {
                \Prosper202\User\PasswordChange::store(
                    new \Prosper202\Database\Connection(self::$db),
                    self::USER,
                    password_hash(self::NEW_PASSWORD, PASSWORD_DEFAULT),
                    false
                );
            },
            'PUT /users/{id}' => static function (): void {
                (new \Api\V3\Controllers\UsersController(self::$db))
                    ->update(self::USER, ['user_pass' => self::NEW_PASSWORD], self::ADMIN);
            },
        ];
        foreach ($writers as $name => $write) {
            $threw = null;
            try {
                self::q('ALTER TABLE 202_users DROP COLUMN user_session_generation');
                $write();
            } catch (\Throwable $e) {
                $threw = $e;
            } finally {
                self::q('ALTER TABLE 202_users ADD COLUMN user_session_generation int(10) unsigned NOT NULL'
                    . " DEFAULT '0'");
            }
            self::assertNotNull($threw, "$name: a password whose sessions were not ended was answered as stored");
            $chain = '';
            for ($e = $threw; $e !== null; $e = $e->getPrevious()) {
                $chain .= $e->getMessage() . "\n";
            }
            self::assertStringContainsString('Ending the sessions of user ' . self::USER . ' failed', $chain, $name);
            self::assertSame($before, $stored(), "$name: the old password stands");
        }
    }

    /**
     * Between deploying 1.9.79's files and running its upgrade, 202_users has
     * no generation: the user is read without it, nothing is compared, and
     * every request still runs.
     */
    public function testBeforeTheUpgradeTheGenerationIsNotAskedAndSessionsRun(): void
    {
        try {
            self::q('ALTER TABLE 202_users DROP COLUMN user_session_generation');
            // A generation no row holds: nothing compares it.
            $session = self::browser(self::USER, 3);
            $before = self::request('call:booted', ['P202_SU_SESSION_ID' => $session]);
        } finally {
            self::q("ALTER TABLE 202_users ADD COLUMN user_session_generation int(10) unsigned NOT NULL DEFAULT '0'");
        }
        self::assertNull($before['result']['uncaught'], $before['output']);
        self::assertContains('user_id', $before['result']['answer']['identity'], 'still signed in');
        self::assertTrue($before['result']['answer']['may_change_settings'], 'with the user\'s roles');
    }
}
