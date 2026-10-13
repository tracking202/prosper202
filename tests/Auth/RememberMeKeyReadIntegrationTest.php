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

    public function testTheCookieCheckSignsNobodyInAndLeavesTheStoredKey(): void
    {
        self::account(self::STORED);

        self::assertSame("ANSWERED false\n", self::runScenario('cookie_check'));
        self::assertSame(self::STORED, self::storedKey());
    }
}
