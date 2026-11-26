<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\UserTables;

/**
 * The remember-me secret key is never replaced, or handed out unstored, on
 * a statement that failed.
 *
 * AUTH::get_user_secret_key() read a failed SELECT as "this account has no
 * key", minted one and stored it over the real one, so one failed read
 * signed every remembered browser of the account out; and when storing a
 * new key failed it returned the key anyway, so the cookie signed with it
 * was refused on every later visit. Both throw now, and the cookie check
 * answers "not signed in" without touching the stored key.
 *
 * The real functions-auth.php and functions.php's _mysqli_query() run in a
 * child process (fixtures/remember-me-key-runner.php), with the one
 * statement each case is about made to fail.
 *
 * The cookie check clears a cookie the database refused, so a key deleted
 * elsewhere (a switch-off, a new password) is read once per browser rather
 * than on every signed-out request until the cookie expires; and keeps one
 * whose read failed, so an outage signs nobody out.
 *
 * @group integration
 */
final class RememberMeKeyReadIntegrationTest extends TestCase
{
    private const USER = 994101;
    private const STORED = 'storedkeystoredkeystoredkeystoredkeystoredkey123';

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = new \mysqli(
            $host,
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        if ($db->connect_errno !== 0) {
            return;
        }
        foreach ([UserTables::users(), UserTables::authKeys()] as $table) {
            self::assertNotFalse($db->query($table->createStatement), $db->error);
        }
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->query('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        self::$db?->query('DELETE FROM 202_auth_keys WHERE user_id = ' . self::USER);
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
    }

    private static function account(?string $secret): void
    {
        $db = self::$db;
        self::assertNotNull($db);
        self::assertNotFalse($db->query('DELETE FROM 202_users WHERE user_id = ' . self::USER), $db->error);
        self::assertNotFalse($db->query(sprintf(
            "INSERT INTO 202_users SET user_id = %d, user_name = 'rmk%d', user_pass = 'x',"
            . " user_email = 'rmk@example.com', user_dash_email = '', user_time_register = 0, install_hash = '',"
            . " user_hash = '', secret_key = %s",
            self::USER,
            self::USER,
            $secret === null ? 'NULL' : "'" . $db->real_escape_string($secret) . "'"
        )), $db->error);
    }

    private static function storedKey(): ?string
    {
        $result = self::$db?->query('SELECT secret_key FROM 202_users WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $result);

        return $result->fetch_assoc()['secret_key'] ?? null;
    }

    private static function runScenario(string $scenario): string
    {
        return self::runScenarioWithLog($scenario)[0];
    }

    /** @return array{string, string} stdout, and stderr (where error_log() writes) */
    private static function runScenarioWithLog(string $scenario): array
    {
        $env = getenv();
        $env['P202_RMK_USER'] = (string) self::USER;
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
                __DIR__ . '/fixtures/remember-me-key-runner.php', $scenario,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), $stdout . $stderr);

        return [$stdout, $stderr];
    }

    public function testTheStoredKeyIsReadWhenNothingFails(): void
    {
        self::account(self::STORED);

        self::assertSame('KEY ' . self::STORED . "\n", self::runScenario('none'));
    }

    public function testAFailedReadThrowsAndLeavesTheStoredKey(): void
    {
        self::account(self::STORED);

        $out = self::runScenario('read_fails');

        self::assertStringStartsWith('THREW Reading the remember-me key of user ' . self::USER . ' failed', $out);
        // Logged by the caller as it stands: MySQL's words, unredacted, are
        // for _mysqli_query()'s own line to carry.
        self::assertStringNotContainsString('p202_no_such_table_for_remember_me', $out);
        self::assertSame(self::STORED, self::storedKey(), 'a failed read replaced the stored key');
    }

    /** A row that cannot be fetched is a failed read, not an account that is not there. */
    public function testARowThatCannotBeFetchedThrows(): void
    {
        self::account(self::STORED);

        $out = self::runScenario('fetch_fails');

        self::assertSame(
            'THREW Reading the remember-me key of user ' . self::USER . " failed: its row could not be fetched\n",
            $out
        );
        self::assertSame(self::STORED, self::storedKey());
    }

    public function testAKeyThatWasNotStoredIsNotHandedOut(): void
    {
        self::account(null);

        $out = self::runScenario('write_fails');

        self::assertStringStartsWith('THREW Storing the remember-me key of user ' . self::USER . ' failed', $out);
        self::assertStringNotContainsString('p202_no_such_table_for_remember_me', $out);
        self::assertNull(self::storedKey());
    }

    public function testAUserWithNoAccountGetsNoKey(): void
    {
        $db = self::$db;
        self::assertNotNull($db);
        self::assertNotFalse($db->query('DELETE FROM 202_users WHERE user_id = ' . self::USER), $db->error);

        $out = self::runScenario('none');

        // The UPDATE that stores a new key matches no row and still answers
        // true: the key it minted was handed out stored nowhere.
        self::assertSame("NO ACCOUNT\n", $out);
        self::assertNull(self::storedKey());
    }

    public function testACookieNamingNoAccountIsRefusedWithoutALogLine(): void
    {
        $db = self::$db;
        self::assertNotNull($db);
        self::assertNotFalse($db->query('DELETE FROM 202_users WHERE user_id = ' . self::USER), $db->error);

        [$out, $logged] = self::runScenarioWithLog('cookie_no_account');

        // Anyone can send it: a line per request would be theirs to write.
        self::assertSame("ANSWERED false\n", $out);
        self::assertSame('', $logged, 'nothing is written for a cookie anyone can send');
    }

    /**
     * A cookie is signed only with a key already stored, so for an account
     * with none it cannot verify; the check minted and stored one anyway,
     * before verifying anything, for whoever sent the cookie.
     */
    public function testACookieForAnAccountWithNoKeyMintsNone(): void
    {
        self::account(null);

        [$out, $logged] = self::runScenarioWithLog('cookie_no_key');

        self::assertSame("ANSWERED false\n", $out);
        self::assertSame('', $logged);
        self::assertNull(self::storedKey(), 'the cookie check stored a key for an account that had none');
    }

    public function testTheCookieCheckSignsNobodyInAndLeavesTheStoredKey(): void
    {
        self::account(self::STORED);

        self::assertSame("ANSWERED false\n", self::runScenario('cookie_check'));
        self::assertSame(self::STORED, self::storedKey());
    }

    private const AUTH_KEY = 'authkeyauthkeyauthkeyauthkeyauthkeyauthkeyauthk';

    /** A stored remember-me key for the user, expiring $expiresIn seconds from now; none for null. */
    private static function storedAuthKey(?int $expiresIn): void
    {
        $db = self::$db;
        self::assertNotNull($db);
        self::assertNotFalse($db->query('DELETE FROM 202_auth_keys WHERE user_id = ' . self::USER), $db->error);
        if ($expiresIn !== null) {
            self::assertNotFalse($db->query(sprintf(
                "INSERT INTO 202_auth_keys SET user_id = %d, auth_key = '%s', expires = UNIX_TIMESTAMP() + %d",
                self::USER,
                self::AUTH_KEY,
                $expiresIn
            )), $db->error);
        }
    }

    /** The cookie remember_me_on_auth() sets for AUTH_KEY, signed with $secret. */
    private static function signedCookie(string $secret = self::STORED, string $authKey = self::AUTH_KEY): string
    {
        $named = self::USER . '-' . $authKey;

        return $named . '-' . hash_hmac('sha256', $named, $secret);
    }

    /** @return array{string, string} stdout, and stderr */
    private static function runCookie(string $cookie, string $fail = 'none'): array
    {
        putenv('P202_RMK_COOKIE=' . $cookie);
        putenv('P202_RMK_FAIL=' . $fail);
        try {
            return self::runScenarioWithLog('cookie');
        } finally {
            putenv('P202_RMK_COOKIE');
            putenv('P202_RMK_FAIL');
        }
    }

    public function testACookieTheDatabaseAcceptsSignsInAndIsKept(): void
    {
        self::account(self::STORED);
        self::storedAuthKey(3600);

        [$out, $logged] = self::runCookie(self::signedCookie());

        self::assertSame("ANSWERED true\nCOOKIE kept\n", $out, $logged);
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?int, 2: string|callable(): string, 3?: bool}>
     *   the account's secret key (null: no key), the stored key's expiry
     *   (null: none stored), the cookie, and whether the user is switched off
     */
    public static function refusedCookies(): iterable
    {
        yield 'malformed' => [self::STORED, 3600, 'not-a-cookie-at-all'];
        yield 'a part empty' => [self::STORED, 3600, self::USER . '--x'];
        yield 'no account' => [null, null, 'noaccount'];
        yield 'no key on the account' => [null, 3600, 'signed'];
        yield 'a signature that does not verify' => [self::STORED, 3600, 'forged'];
        yield 'the key expired' => [self::STORED, -60, 'signed'];
        yield 'the key deleted' => [self::STORED, null, 'signed'];
        yield 'its user switched off' => [self::STORED, 3600, 'signed', true];
    }

    /**
     * @dataProvider refusedCookies
     */
    public function testACookieTheDatabaseRefusedIsCleared(
        ?string $secret,
        ?int $expiresIn,
        string $cookie,
        bool $switchedOff = false
    ): void {
        $db = self::$db;
        self::assertNotNull($db);
        if ($cookie === 'noaccount') {
            self::assertNotFalse($db->query('DELETE FROM 202_users WHERE user_id = ' . self::USER), $db->error);
            $cookie = self::signedCookie();
        } else {
            self::account($secret);
            $cookie = match ($cookie) {
                'signed' => self::signedCookie(),
                'forged' => self::signedCookie('notthestoredkeynotthestoredkeynotthestoredkey12'),
                default => $cookie,
            };
        }
        self::storedAuthKey($expiresIn);
        if ($switchedOff) {
            $switched = $db->query('UPDATE 202_users SET user_active = 0 WHERE user_id = ' . self::USER);
            self::assertNotFalse($switched, $db->error);
        }

        [$out, $logged] = self::runCookie($cookie);

        self::assertSame("ANSWERED false\nCOOKIE cleared\n", $out, $logged);
        // Anyone can send one: a line per request would be theirs to write.
        self::assertSame('', $logged);
        self::assertSame($secret, self::storedKey(), 'refusing a cookie changed the stored secret key');
    }

    /** @return iterable<string, array{string}> */
    public static function failedReads(): iterable
    {
        yield 'the secret key read fails' => ['secret_read'];
        yield 'the remember-me key read fails' => ['key_read'];
        yield 'the remember-me key row cannot be fetched' => ['key_fetch'];
    }

    /**
     * @dataProvider failedReads
     */
    public function testACookieWhoseReadFailedIsKept(string $fail): void
    {
        self::account(self::STORED);
        self::storedAuthKey(3600);

        [$out, $logged] = self::runCookie(self::signedCookie(), $fail);

        self::assertSame("ANSWERED false\nCOOKIE kept\n", $out, $logged);
        self::assertNotSame('', $logged, 'a failed read was not logged');
        self::assertSame(self::STORED, self::storedKey());
    }
}
