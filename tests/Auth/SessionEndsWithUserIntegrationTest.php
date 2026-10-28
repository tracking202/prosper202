<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;

/**
 * A signed-in session ends when its user is switched off, removed, or their
 * row is gone; a user who may act is unaffected; and a failed read of the
 * user is neither answer.
 *
 * The sign-in (AUTH::LOGIN_SELECT), the remember-me cookie and the API keys
 * asked for an active user that was not deleted. A session already signed in
 * was never asked again: AUTH::logged_in() read the session's own fields and
 * refreshed its time on every request, and connect.php's `new User()` checked
 * only that the row existed. A user switched off in Account › Users, or
 * removed, kept every page, AJAX endpoint and form for as long as they kept
 * using the browser. Now connect.php reads the user's standing with the role
 * (UserStanding) and ends such a session before the page runs, and
 * logged_in() asks it of any session it is handed.
 *
 * Each request runs as the web server runs it, in a process of its own
 * (fixtures/session-user-runner.php): a session file written where PHP keeps
 * it, connect.php booted, then the page. Personal settings is the page, and
 * its form to add a REST API key the post: a write a switched-off user must
 * not make, and one that calls nothing outside the database.
 * tests/live/session-ends-with-user.sh drives the same over HTTP.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class SessionEndsWithUserIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990881;
    private const OTHER = 990882;
    private const PASSWORD = 'session-ends-pw';
    private const PAGE = '202-account/account.php';

    /** What a signed-in session holds that a signed-out one does not. */
    private const SIGNED_IN_KEYS = [
        'user_id', 'user_own_id', 'user_name', 'session_fingerprint', 'session_time', 'valid_key', 'account_owner_id',
    ];

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
        self::$sessions = sys_get_temp_dir() . '/p202-session-user-' . getmypid();
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
        foreach ([self::USER, self::OTHER] as $user) {
            self::q("INSERT INTO 202_users SET user_id = $user, user_name = 'su$user',"
                . " user_email = 'su$user@example.com', user_dash_email = '',"
                . ' user_pass = ' . self::str(password_hash(self::PASSWORD, PASSWORD_DEFAULT)) . ','
                . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
                . ' secret_key = ' . self::str(self::secret($user)) . ','
                . " p202_customer_api_key = 'session-user-runner'");
            self::row('202_users_pref', ['user_id' => $user]);
            self::row('202_user_role', ['user_id' => $user, 'role_id' => 2]);
            // A remember-me key on each, so a refusal can be seen to revoke the right one.
            self::row('202_auth_keys', ['user_id' => $user, 'auth_key' => 'key' . $user, 'expires' => time() + 86400]);
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

    private static function standing(int $active, int $deleted): void
    {
        self::q("UPDATE 202_users SET user_active = $active, user_deleted = $deleted WHERE user_id = " . self::USER);
    }

    private static function rows(string $table, int $user): int
    {
        $result = self::$db->query("SELECT COUNT(*) FROM $table WHERE user_id = $user");
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (int) $result->fetch_row()[0];
    }

    /** The remember-me cookie for USER's stored key, as remember_me_on_auth() signs it. */
    private static function cookie(int $user): string
    {
        return $user . '-key' . $user . '-' . hash_hmac('sha256', $user . '-key' . $user, self::secret($user));
    }

    /** The user's remember-me secret: 48 characters, as the column holds them. */
    private static function secret(int $user): string
    {
        return substr("su{$user}" . str_repeat('secret', 8), 0, 48);
    }

    /**
     * One request; what it printed and what it left.
     *
     * @param array<string, string> $env
     * @return array{output: string, result: array<string, mixed>, exit: int}
     */
    private static function request(string $target, array $env = []): array
    {
        $env += [
            'P202_SU_USER' => (string) self::USER,
            'P202_SU_SESSION_DIR' => self::$sessions,
        ];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/session-user-runner.php', $target],
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

        return ['output' => substr($stdout, 0, $at) . $stderr, 'result' => $result, 'exit' => $exit];
    }

    private static function sessionFile(string $id): string
    {
        return self::$sessions . '/sess_' . $id;
    }

    public function testAnActiveUsersPageAndPostRun(): void
    {
        $page = self::request(self::PAGE, ['P202_SU_REMEMBER' => self::cookie(self::USER)]);
        self::assertFalse($page['result']['denied'], $page['output']);
        self::assertStringContainsString('name="add_rest_api_key"', $page['output']);
        self::assertSame($page['result']['signed_in_as'], $page['result']['session_id'], 'the session was kept');
        self::assertContains('user_id', $page['result']['session_keys']);
        self::assertTrue($page['result']['cookie_kept']);
        self::assertSame(1, self::rows('202_auth_keys', self::USER));

        $post = self::request(self::PAGE, ['P202_SU_POST' => '{"add_rest_api_key":"1","token":"SESSION"}']);
        self::assertFalse($post['result']['denied'], $post['output']);
        self::assertNull($post['result']['uncaught'], $post['output']);
        self::assertSame(1, self::rows('202_api_keys', self::USER), 'the active user minted a key');
    }

    /** @return array<string, array{?int, ?int}> user_active, user_deleted; null for no row */
    public static function usersWhoMayNotAct(): array
    {
        return [
            'switched off' => [0, 0],
            'removed' => [1, 1],
            'removed and switched off' => [0, 1],
            'a deleted value no version writes' => [1, 2],
            'an active value no version writes' => [2, 0],
            'the row is gone' => [null, null],
        ];
    }

    /** @dataProvider usersWhoMayNotAct */
    public function testTheSessionOfAUserWhoMayNotActEndsOnTheirNextPage(?int $active, ?int $deleted): void
    {
        if ($active === null) {
            self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::USER);
            self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        } else {
            self::standing($active, (int) $deleted);
        }

        $page = self::request(self::PAGE, ['P202_SU_REMEMBER' => self::cookie(self::USER)]);
        $r = $page['result'];
        self::assertNull($r['uncaught'], $page['output']);
        self::assertTrue($r['denied'], "the access-denied page answered:\n" . $page['output']);
        self::assertStringNotContainsString('add_rest_api_key', $page['output'], 'nothing of the page was drawn');
        self::assertNotSame($r['signed_in_as'], $r['session_id'], 'the browser is given a new session id');
        self::assertFileDoesNotExist(self::sessionFile($r['signed_in_as']), 'the signed-in session is gone');
        self::assertSame(
            [],
            array_values(array_intersect(self::SIGNED_IN_KEYS, $r['session_keys'])),
            'the new session names nobody'
        );
        $token = (string) $r['token'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token, 'a new token, for the sign-in form');
        self::assertNotSame('p202sessionuserrunnertoken000000', $r['token']);
        self::assertFalse($r['cookie_kept'], 'the remember-me cookie is not tried');
        self::assertSame(0, self::rows('202_auth_keys', self::USER), "the user's remember-me keys are revoked");
        self::assertSame(1, self::rows('202_auth_keys', self::OTHER), "another user's are kept");
    }

    /**
     * connect.php ends the session before the page runs, so a page that reads
     * $_SESSION['user_id'] without asking AUTH (the chrome, a scope) finds
     * nobody; and a user who may not act holds no permission.
     *
     * @dataProvider usersWhoMayNotAct
     */
    public function testNoPageFindsTheIdentityOfAUserWhoMayNotAct(?int $active, ?int $deleted): void
    {
        $booted = self::request('call:booted')['result']['answer'];
        self::assertSame(
            [
                'identity' => ['user_id', 'user_own_id', 'user_name', 'session_fingerprint'],
                'may_change_settings' => true,
            ],
            $booted,
            'while the user may act'
        );

        if ($active === null) {
            self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::USER);
            self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        } else {
            self::standing($active, (int) $deleted);
            // A role kept: what connect.php reads is whether the user may act, not whether they have one.
        }
        $booted = self::request('call:booted');
        $nobody = ['identity' => [], 'may_change_settings' => false];
        self::assertSame($nobody, $booted['result']['answer'], $booted['output']);
    }

    /** @dataProvider usersWhoMayNotAct */
    public function testAPostFromAUserWhoMayNotActWritesNothing(?int $active, ?int $deleted): void
    {
        if ($active === null) {
            self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::USER);
            self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        } else {
            self::standing($active, (int) $deleted);
        }

        $post = self::request(self::PAGE, ['P202_SU_POST' => '{"add_rest_api_key":"1","token":"SESSION"}']);
        self::assertNull($post['result']['uncaught'], $post['output']);
        self::assertTrue($post['result']['denied'], $post['output']);
        self::assertSame(0, self::rows('202_api_keys', self::USER), 'no key was minted');
        self::assertFileDoesNotExist(self::sessionFile($post['result']['signed_in_as']));
    }

    /**
     * An endpoint a page's script calls answers 401, which its failure
     * handler shows, for jQuery's request and for fetch() alike.
     *
     * @testWith ["1"]
     *           ["fetch"]
     */
    public function testAnAjaxRequestFromAUserWhoMayNotActIsAnswered401(string $script): void
    {
        self::standing(0, 0);

        $ajax = self::request('tracking202/ajax/account_overview.php', ['P202_SU_AJAX' => $script]);
        self::assertNull($ajax['result']['uncaught'], $ajax['output']);
        self::assertSame(401, $ajax['result']['status']);
        self::assertFalse(
            $ajax['result']['denied'],
            'not the redirect to the sign-in page, which the request would follow and draw'
        );
        self::assertStringContainsString('You are signed out. Sign in again, then reload the page.', $ajax['output']);
        self::assertFileDoesNotExist(self::sessionFile($ajax['result']['signed_in_as']));
    }

    /** begin_user_session() sets both ids to one user; a session where they differ names nobody. */
    public function testASessionWhoseTwoIdsDifferNamesNobody(): void
    {
        $page = self::request(self::PAGE, ['P202_SU_OWN' => (string) self::OTHER]);
        self::assertTrue($page['result']['denied'], $page['output']);
        self::assertFileDoesNotExist(self::sessionFile($page['result']['signed_in_as']));
        $why = 'neither user is the session\'s, so neither loses a key';
        self::assertSame(1, self::rows('202_auth_keys', self::USER), $why);
        self::assertSame(1, self::rows('202_auth_keys', self::OTHER));
    }

    /**
     * A read of the user that fails is neither "may act" (the post would
     * write) nor "may not" (the session would end): the request fails, as
     * any failed read on a page does, and the session is left as it was.
     */
    public function testAFailedReadOfTheUserIsNeitherAnswer(): void
    {
        try {
            self::q('ALTER TABLE 202_users DROP COLUMN user_deleted');
            $post = self::request(self::PAGE, ['P202_SU_POST' => '{"add_rest_api_key":"1","token":"SESSION"}']);
        } finally {
            self::q("ALTER TABLE 202_users ADD COLUMN user_deleted int(1) NOT NULL DEFAULT '0' AFTER user_active");
        }
        $r = $post['result'];
        self::assertStringStartsWith(
            'Prosper202\\Database\\Exceptions\\QueryException: Reading whether user ' . self::USER . ' may act failed',
            (string) $r['uncaught'],
            $post['output']
        );
        self::assertSame(500, $r['status'], 'the database error page');
        self::assertFalse($r['denied'], 'not answered as signed out');
        self::assertSame(0, self::rows('202_api_keys', self::USER), 'nor as signed in');
        self::assertFileExists(self::sessionFile($r['signed_in_as']), 'the session was not ended');
        $kept = (string) file_get_contents(self::sessionFile($r['signed_in_as']));
        self::assertStringContainsString('user_id|i:' . self::USER . ';', $kept);
        self::assertSame(1, self::rows('202_auth_keys', self::USER), 'nor its keys revoked');
    }

    /** The same of logged_in()'s own read, where connect.php read no row for the session's user. */
    public function testAFailedReadInLoggedInItselfIsNeitherAnswer(): void
    {
        try {
            $call = self::request(
                'call:logged_in_unread',
                ['P202_SU_AFTER_BOOT_SQL' => 'ALTER TABLE 202_users DROP COLUMN user_active']
            );
        } finally {
            self::q("ALTER TABLE 202_users ADD COLUMN user_active int(1) NOT NULL DEFAULT '1' AFTER vip_perks_status");
        }
        self::assertStringStartsWith(
            'Prosper202\\Database\\Exceptions\\QueryException: Reading whether user ' . self::USER . ' may act failed',
            (string) $call['result']['uncaught'],
            $call['output']
        );
        self::assertNull($call['result']['answer']);
        self::assertFileExists(self::sessionFile($call['result']['signed_in_as']));
        self::assertSame(1, self::rows('202_auth_keys', self::USER));
    }

    /** logged_in() reads the row itself when connect.php read none for this user, and ends the session. */
    public function testLoggedInAsksTheRowItselfWhenNoneWasRead(): void
    {
        self::assertTrue(self::request('call:logged_in_unread')['result']['answer'], 'an active user');

        $call = self::request(
            'call:logged_in_unread',
            ['P202_SU_AFTER_BOOT_SQL' => 'UPDATE 202_users SET user_active = 0 WHERE user_id = ' . self::USER]
        );
        self::assertFalse($call['result']['answer'], $call['output']);
        self::assertFileDoesNotExist(self::sessionFile($call['result']['signed_in_as']));
        self::assertSame(0, self::rows('202_auth_keys', self::USER));
    }

    /**
     * Who may sign in by password, who by remember-me cookie and whose
     * session stands are one rule: the sign-in asked `user_deleted != 1`,
     * so a value no version writes signed in a user whose session the
     * check then refused, and the reverse would sign a user in for good.
     *
     * @return array<string, array{int, int}>
     */
    public static function standings(): array
    {
        $cases = [];
        foreach ([0, 1, 2] as $active) {
            foreach ([0, 1, 2] as $deleted) {
                $cases["active $active, deleted $deleted"] = [$active, $deleted];
            }
        }

        return $cases;
    }

    /** @dataProvider standings */
    public function testThePasswordTheCookieAndTheSessionAgreeOnWhoMayAct(int $active, int $deleted): void
    {
        self::standing($active, $deleted);
        $mayAct = $active === 1 && $deleted === 0;

        $password = self::request(
            'call:authenticate',
            ['P202_SU_SESSION' => 'none', 'P202_SU_NAME' => 'su' . self::USER, 'P202_SU_PASS' => self::PASSWORD]
        );
        $cookie = self::request(
            'call:remember',
            ['P202_SU_SESSION' => 'none', 'P202_SU_REMEMBER' => self::cookie(self::USER)]
        );
        // Refused by the cookie's own read, before a session is begun: one
        // begun and then ended by logged_in() would revoke the key.
        self::assertSame(1, self::rows('202_auth_keys', self::USER), 'the cookie\'s read asks the rule itself');
        $session = self::request('call:logged_in');

        self::assertSame(
            ['password' => $mayAct, 'cookie' => $mayAct, 'session' => $mayAct],
            [
                'password' => $password['result']['answer'],
                'cookie' => $cookie['result']['answer'],
                'session' => $session['result']['answer'],
            ],
            $password['output'] . $cookie['output'] . $session['output']
        );
    }
}
