<?php

declare(strict_types=1);

namespace Tests\User;

use AUTH;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\User\SignInLog;
use Prosper202\User\SignInOutcome;

/**
 * The login log on a real database: each outcome lands as its own value,
 * nothing typed as a password is kept, and the throttle (AUTH::
 * is_rate_limited()) counts failures alone, so a right password whose
 * sign-in could not be completed never locks the user or the address out.
 *
 * Skips unless a scratch test database is configured: P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS,
 * P202_TEST_DB_NAME. It installs the schema there and writes and removes
 * 202_users_log rows under user names starting signin-log-.
 *
 * @group integration
 */
final class SignInLogIntegrationTest extends TestCase
{
    private const USER = 'signin-log-user';
    private const ADDRESS = '198.51.100.71';
    private const OTHER_ADDRESS = '2001:db8::71';

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        require_once dirname(__DIR__, 2) . '/202-config/functions-auth.php';
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null)'
                . ' { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        $db = mysqli_connect(
            $host,
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertInstanceOf(\mysqli::class, $db);
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->query("DELETE FROM 202_users_log WHERE user_name LIKE 'signin-log-%'");
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::assertTrue(self::$db->query("DELETE FROM 202_users_log WHERE user_name LIKE 'signin-log-%'"
            . " OR ip_address IN ('" . self::ADDRESS . "', '" . self::OTHER_ADDRESS . "')"));
    }

    public function testEachOutcomeLandsAsItsOwnValueWithNoPassword(): void
    {
        $ids = [];
        foreach (SignInOutcome::cases() as $outcome) {
            $ids[$outcome->value] = self::log()
                ->append(self::USER, self::ADDRESS, $outcome, ['user' => 'said'], 'a:0:{}');
        }

        foreach ($ids as $value => $id) {
            $row = self::row("SELECT * FROM 202_users_log WHERE login_id = $id");
            self::assertSame((string) $value, $row['login_success']);
            self::assertSame(SignInOutcome::from($value), SignInOutcome::fromStored($row['login_success']));
            self::assertSame(
                [self::USER, '[filtered]', self::ADDRESS, '', serialize(['user' => 'said']), 'a:0:{}'],
                [
                    $row['user_name'], $row['user_pass'], $row['ip_address'],
                    $row['login_session'], $row['login_error'], $row['login_server'],
                ]
            );
            self::assertEqualsWithDelta(time(), (int) $row['login_time'], 5);
        }
    }

    public function testSignInsNotCompletedNeverLockTheUserOut(): void
    {
        for ($i = 0; $i <= AUTH::RATE_LIMIT_MAX_PER_USER; $i++) {
            self::log()->append(self::USER, self::ADDRESS, SignInOutcome::NotCompleted, [], '');
        }
        self::assertFalse(
            AUTH::is_rate_limited(self::$db, self::USER, self::ADDRESS),
            (AUTH::RATE_LIMIT_MAX_PER_USER + 1) . ' sign-ins not completed are not guesses'
        );

        for ($i = 1; $i < AUTH::RATE_LIMIT_MAX_PER_USER; $i++) {
            self::log()->append(self::USER, self::ADDRESS, SignInOutcome::Failed, [], '');
        }
        self::assertFalse(self::limited(self::USER, self::ADDRESS), 'one failure short of the limit');
        self::log()->append(self::USER, self::ADDRESS, SignInOutcome::Failed, [], '');
        self::assertTrue(self::limited(self::USER, self::ADDRESS), 'failures still lock the user out');
    }

    public function testSignInsNotCompletedNeverLockTheAddressOut(): void
    {
        for ($i = 0; $i <= AUTH::RATE_LIMIT_MAX_PER_IP; $i++) {
            self::log()->append('signin-log-ip-' . $i, self::OTHER_ADDRESS, SignInOutcome::NotCompleted, [], '');
            self::log()->append('signin-log-ip-' . $i, self::OTHER_ADDRESS, SignInOutcome::Passed, [], '');
        }
        self::assertFalse(
            AUTH::is_rate_limited(self::$db, 'signin-log-fresh', self::OTHER_ADDRESS),
            'sign-ins not completed and passes from one address are not counted'
        );

        for ($i = 0; $i < AUTH::RATE_LIMIT_MAX_PER_IP; $i++) {
            self::log()->append('signin-log-guess-' . $i, self::OTHER_ADDRESS, SignInOutcome::Failed, [], '');
        }
        self::assertTrue(self::limited('signin-log-fresh', self::OTHER_ADDRESS), 'failures from one address still do');
    }

    private static function limited(string $user, string $address): bool
    {
        self::assertNotNull(self::$db);

        return AUTH::is_rate_limited(self::$db, $user, $address);
    }

    private static function log(): SignInLog
    {
        self::assertNotNull(self::$db);

        return new SignInLog(new Connection(self::$db));
    }

    /** @return array<string, string> */
    private static function row(string $sql): array
    {
        self::assertNotNull(self::$db);
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertIsArray($row);

        return array_map('strval', $row);
    }
}
