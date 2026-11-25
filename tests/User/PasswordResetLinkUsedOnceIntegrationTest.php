<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\DataSeeder;
use Prosper202\User\PasswordChange;
use Prosper202\User\ResetKeyNotUsable;

/**
 * A password-reset link sets one password, however many times it is
 * submitted at once.
 *
 * 202-pass-reset.php reads the user by the link's key, checks the new
 * password, then writes it. The write cleared the key by the user's id alone,
 * so two submits of one link that both passed the read (two browsers, or a
 * double submit from two sessions) both wrote, each answered "Your password
 * has been reset.", and the password that stood was whichever committed last.
 * The write now consumes the key only while it is still the user's and
 * unexpired (PasswordChange::store() with the key), and the second write,
 * waiting on the first one's row lock, sees the key gone and stores nothing.
 *
 * The race runs the real page twice, in processes of their own
 * (tests/Auth/fixtures/session-user-runner.php), with this test holding the
 * user's row so both pass the read and both wait at the write; then it lets
 * go. The rest run PasswordChange::store() here.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class PasswordResetLinkUsedOnceIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990931;
    private const KEY = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const PASSWORDS = ['first-password-1', 'second-password-2'];

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
        self::$sessions = sys_get_temp_dir() . '/p202-reset-once-' . getmypid();
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
            array_map('unlink', glob(self::$sessions . '/*') ?: []);
            rmdir(self::$sessions);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ", user_name = 'ro" . self::USER . "',"
            . " user_email = 'ro" . self::USER . "@example.com', user_dash_email = '', user_pass = 'x',"
            . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '',"
            . " secret_key = '', p202_customer_api_key = 'reset-once-test',"
            . ' user_pass_key = ' . self::str(self::KEY) . ', user_pass_time = ' . time());
        self::row('202_users_pref', ['user_id' => self::USER]);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => 2]);
        self::row('202_auth_keys', [
            'user_id' => self::USER,
            'auth_key' => str_repeat('r', 48),
            'expires' => time() + 86400,
        ]);
    }

    private static function cleanUp(): void
    {
        foreach (['202_api_keys', '202_auth_keys', '202_user_role', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    private static function stored(string $column): ?string
    {
        $result = self::$db->query("SELECT `$column` FROM 202_users WHERE user_id = " . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $value = $result->fetch_row()[0] ?? null;

        return $value === null ? null : (string) $value;
    }

    private static function passwordIs(string $plain): bool
    {
        return \verify_user_pass($plain, (string) self::stored('user_pass'))['valid'];
    }

    private static function keyCount(): int
    {
        $result = self::$db->query('SELECT COUNT(*) FROM 202_auth_keys WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (int) $result->fetch_row()[0];
    }

    /**
     * The reset page's form, posted by a browser that has not signed in, as a
     * process that has started; its output goes to $out.
     *
     * @return resource
     */
    private static function startReset(string $password, string $out)
    {
        $env = [
            'P202_SU_USER' => (string) self::USER,
            'P202_SU_SESSION_DIR' => self::$sessions,
            'P202_SU_SESSION' => 'anonymous',
            'P202_SU_GET' => json_encode(['key' => self::KEY], JSON_THROW_ON_ERROR),
            'P202_SU_POST' => json_encode(
                ['token' => 'SESSION', 'user_pass' => $password, 'verify_user_pass' => $password],
                JSON_THROW_ON_ERROR
            ),
            // A test that never lets go fails here rather than hanging.
            'P202_SU_AFTER_BOOT_SQL' => 'SET SESSION innodb_lock_wait_timeout = 20',
        ];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__) . '/Auth/fixtures/session-user-runner.php',
                '202-pass-reset.php'],
            [1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']],
            $pipes,
            null,
            $env + getenv()
        );
        self::assertIsResource($proc);

        return $proc;
    }

    /** How many connections to this database wait on a row lock. */
    private static function waiting(): int
    {
        $result = self::$db->query('SELECT COUNT(*) FROM information_schema.INNODB_TRX t'
            . ' JOIN information_schema.PROCESSLIST p ON p.ID = t.trx_mysql_thread_id'
            . " WHERE t.trx_state = 'LOCK WAIT' AND p.DB = DATABASE() AND p.ID <> CONNECTION_ID()");
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return (int) $result->fetch_row()[0];
    }

    /**
     * Both submits pass the page's read and wait at the write while this test
     * holds the user's row; once it lets go, one sets its password and the
     * other is told the link was used, and stores nothing.
     */
    public function testTwoSubmitsOfOneLinkAtOnceSetOnePassword(): void
    {
        $holder = mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertInstanceOf(\mysqli::class, $holder);
        $procs = [];
        $outs = [];
        try {
            $holder->begin_transaction();
            $locked = $holder->query('SELECT user_id FROM 202_users WHERE user_id = ' . self::USER . ' FOR UPDATE');
            self::assertInstanceOf(\mysqli_result::class, $locked, $holder->error);
            foreach (self::PASSWORDS as $i => $password) {
                $outs[$i] = self::$sessions . "/page-$i.out";
                $procs[$i] = self::startReset($password, $outs[$i]);
            }
            $deadline = microtime(true) + 20;
            while (self::waiting() < 2) {
                foreach ($procs as $i => $proc) {
                    if (!proc_get_status($proc)['running']) {
                        $holder->rollback();
                        self::fail("Submit $i finished before the row was let go, so it never reached the write:\n"
                            . file_get_contents($outs[$i]) . file_get_contents($outs[$i] . '.err'));
                    }
                }
                if (microtime(true) > $deadline) {
                    self::fail('The two submits never both waited at the write (' . self::waiting() . ' waiting)');
                }
                // The server refreshes INNODB_TRX only once it has gone 0.1s unread.
                usleep(250000);
            }
            self::assertSame(self::KEY, self::stored('user_pass_key'), 'both passed the read with the key standing');
        } finally {
            $holder->commit();
            $holder->close();
            foreach ($procs as $proc) {
                proc_close($proc);
            }
        }

        $reset = [];
        $used = [];
        foreach ($outs as $i => $out) {
            $page = file_get_contents($out) . file_get_contents($out . '.err');
            self::assertStringNotContainsString('"uncaught":{', $page, "submit $i threw:\n$page");
            if (str_contains($page, 'Your password has been reset.')) {
                $reset[] = $i;
            }
            if (str_contains($page, 'This link has already been used to set a password, or it has expired.')) {
                $used[] = $i;
            }
        }
        $pages = implode("\n----\n", array_map(
            static fn (string $o): string => file_get_contents($o) . file_get_contents($o . '.err'),
            $outs
        ));
        self::assertCount(1, $reset, "one submit sets its password:\n$pages");
        self::assertCount(1, $used, "and the other is told the link is used:\n$pages");
        self::assertNotSame($reset, $used);
        self::assertTrue(self::passwordIs(self::PASSWORDS[$reset[0]]), 'the password that stands is the one set');
        self::assertFalse(self::passwordIs(self::PASSWORDS[$used[0]]), 'the refused submit stored nothing');
        self::assertNull(self::stored('user_pass_key'));
        self::assertSame(0, self::keyCount(), 'the reset that stood revoked the remember-me keys');
    }

    /**
     * The write asks the key and its window again: a key that is not the
     * user's, or expired while the page read it, stores nothing and keeps the
     * remember-me keys.
     *
     * @dataProvider unusableKeys
     */
    public function testAKeyThatNoLongerAllowsTheResetStoresNothing(string $key, int $later): void
    {
        $issued = (int) self::stored('user_pass_time');
        $threw = null;
        try {
            PasswordChange::store(
                new Connection(self::$db),
                self::USER,
                \hash_user_pass('another-password-3'),
                $key,
                $issued + $later
            );
        } catch (ResetKeyNotUsable $e) {
            $threw = $e;
        }
        self::assertNotNull($threw, 'the write is refused as a key that no longer allows it');
        self::assertSame('x', self::stored('user_pass'), 'the password stands');
        self::assertSame(self::KEY, self::stored('user_pass_key'), 'the key stands');
        self::assertSame(1, self::keyCount(), 'the remember-me key stands');
    }

    /** @return array<string, array{string, int}> the key, and the seconds since it was issued */
    public static function unusableKeys(): array
    {
        return [
            'another key' => [str_repeat('f', 64), 0],
            'the key, a second past its three days' => [self::KEY, PasswordChange::RESET_KEY_SECONDS + 1],
            'no key' => ['', 0],
        ];
    }

    public function testTheKeyWithinItsWindowSetsThePasswordAndIsUsedUp(): void
    {
        $hash = \hash_user_pass(self::PASSWORDS[0]);
        PasswordChange::store(new Connection(self::$db), self::USER, $hash, self::KEY, time());

        self::assertTrue(self::passwordIs(self::PASSWORDS[0]));
        self::assertNull(self::stored('user_pass_key'));
        self::assertSame('0', self::stored('user_pass_time'));
        self::assertSame(0, self::keyCount());

        $this->expectException(ResetKeyNotUsable::class);
        $hash = \hash_user_pass(self::PASSWORDS[1]);
        PasswordChange::store(new Connection(self::$db), self::USER, $hash, self::KEY, time());
    }
}
