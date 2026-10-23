<?php

declare(strict_types=1);

namespace Tests\Database;

use Api\V3\Support\StatementHelpers;
use LogicException;
use mysqli;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Database\SchemaInstaller;
use Prosper202\User\InstallAccount;
use Prosper202\User\UserDataPurge;
use Tracking202\Report\ReportPrefsStore;

/**
 * Transactions on a real server: the server commits an open transaction
 * when another begins on the same connection, so Connection::transaction()
 * refuses to begin inside one, and the callers moved onto it from their own
 * begin_transaction() still commit and roll back as they did.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS,
 * P202_TEST_DB_NAME); it installs the schema there and writes only its own
 * table and the rows of user ids no install has.
 *
 * @group integration
 */
final class TransactionNestingIntegrationTest extends TestCase
{
    private const USER = 990001;
    private const OTHER_USER = 990002;

    private static ?mysqli $db = null;

    /** Whether this class added role 1, to take it away after. */
    private static bool $addedRole = false;

    public static function setUpBeforeClass(): void
    {
        // The page controller sits outside the PSR-4 roots.
        require_once dirname(__DIR__, 2) . '/tracking202/Setup/MobileAppsController.php';
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        // connect.php's mode: a failed call returns false rather than throwing.
        mysqli_report(MYSQLI_REPORT_STRICT);
        self::$db = self::connect();
        self::q("SET SESSION sql_mode = ''");
        (new SchemaInstaller(self::$db))->install();
        self::q('CREATE TABLE IF NOT EXISTS p202_nesting_probe (n INT NOT NULL) ENGINE=InnoDB');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->query('DROP TABLE IF EXISTS p202_nesting_probe');
            self::clearUsers();
            if (self::$addedRole) {
                self::$db->query('DELETE FROM 202_roles WHERE role_id = 1');
            }
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No scratch database configured (P202_TEST_DB_HOST).');
        }
        self::q("SET SESSION sql_mode = ''");
        self::q('DELETE FROM p202_nesting_probe');
        self::clearUsers();
    }

    /** What the guard exists for, measured: a second begin commits the first. */
    public function testASecondBeginOnTheConnectionCommitsTheFirst(): void
    {
        $db = self::db();
        self::assertTrue($db->begin_transaction());
        self::q('INSERT INTO p202_nesting_probe SET n = 1');
        self::assertTrue($db->begin_transaction());
        self::assertTrue($db->rollback());

        self::assertSame([1], self::probe(), 'the second begin committed the first transaction\'s write');
    }

    public function testATransactionInsideAnotherIsRefusedAndTheOuterRollsBackWhole(): void
    {
        $conn = new Connection(self::db());
        $inners = ['the same Connection' => $conn, 'another over the same handle' => new Connection(self::db())];
        foreach ($inners as $how => $inner) {
            self::q('DELETE FROM p202_nesting_probe');
            $refused = null;
            try {
                $conn->transaction(static function () use ($inner, &$refused): void {
                    self::q('INSERT INTO p202_nesting_probe SET n = 1');
                    try {
                        $inner->transaction(static fn () => self::q('INSERT INTO p202_nesting_probe SET n = 2'));
                    } catch (LogicException $e) {
                        $refused = $e;
                    }
                    throw new \DomainException('the outer fails after it');
                });
            } catch (\DomainException) {
            }

            self::assertSame([], self::probe(), "$how: the outer's write survived its rollback");
            self::assertInstanceOf(LogicException::class, $refused, $how);
            self::assertStringContainsString('inside another', $refused->getMessage(), $how);
            self::assertFalse($conn->inTransaction(), $how);
        }
    }

    public function testARefusalLeavesTheOuterToCommitWhatItWrote(): void
    {
        $conn = new Connection(self::db());
        $conn->transaction(static function () use ($conn): void {
            self::q('INSERT INTO p202_nesting_probe SET n = 1');
            try {
                $conn->transaction(static fn () => self::q('INSERT INTO p202_nesting_probe SET n = 2'));
                self::fail('a transaction began inside another');
            } catch (LogicException) {
            }
            self::q('INSERT INTO p202_nesting_probe SET n = 3');
        });

        self::assertSame([1, 3], self::probe(), 'the refused work never ran');
        $conn->transaction(static fn () => self::q('INSERT INTO p202_nesting_probe SET n = 4'));
        self::assertSame([1, 3, 4], self::probe(), 'the next transaction begins as usual');
    }

    public function testJoinedWorkCommitsAndRollsBackWithTheCallers(): void
    {
        $conn = new Connection(self::db());
        $joined = new Connection(self::db());
        $conn->transaction(static function () use ($joined): void {
            self::q('INSERT INTO p202_nesting_probe SET n = 1');
            $joined->joinTransaction(static fn () => self::q('INSERT INTO p202_nesting_probe SET n = 2'));
        });
        self::assertSame([1, 2], self::probe());

        try {
            $conn->transaction(static function () use ($joined): void {
                $joined->joinTransaction(static fn () => self::q('INSERT INTO p202_nesting_probe SET n = 3'));
                throw new \DomainException('the caller fails after it');
            });
        } catch (\DomainException) {
        }
        self::assertSame([1, 2], self::probe(), 'the joined write rolled back with the caller');
    }

    public function testJoiningWhenNoTransactionIsOpenIsRefusedBeforeItWrites(): void
    {
        try {
            (new Connection(self::db()))->joinTransaction(
                static fn () => self::q('INSERT INTO p202_nesting_probe SET n = 1')
            );
            self::fail('work meant for a transaction ran in autocommit');
        } catch (LogicException $e) {
            self::assertStringContainsString('outside one', $e->getMessage());
        }
        self::assertSame([], self::probe());
    }

    public function testAnotherConnectionToTheServerIsNotTheSameTransaction(): void
    {
        $other = self::connect();
        try {
            (new Connection(self::db()))->transaction(static function () use ($other): void {
                self::q('INSERT INTO p202_nesting_probe SET n = 1');
                (new Connection($other))->transaction(static function () use ($other): void {
                    self::assertTrue($other->query('INSERT INTO p202_nesting_probe SET n = 2'));
                });
            });
        } finally {
            $other->close();
        }

        self::assertSame([1, 2], self::probe());
    }

    public function testTheControllersTransactionIsConnections(): void
    {
        $controller = new class (self::db()) {
            use StatementHelpers;

            public function __construct(private readonly mysqli $db)
            {
            }

            public function run(callable $fn): mixed
            {
                return $this->transaction($fn);
            }
        };
        $conn = new Connection(self::db());

        $controller->run(static fn () => self::q('INSERT INTO p202_nesting_probe SET n = 1'));
        try {
            $controller->run(static function (): void {
                self::q('INSERT INTO p202_nesting_probe SET n = 2');
                throw new \DomainException('rolled back');
            });
        } catch (\DomainException) {
        }
        self::assertSame([1], self::probe(), 'it commits and rolls back');

        foreach ([[$controller, $conn], [$conn, $controller]] as [$outer, $inner]) {
            try {
                $run = static fn (object $o, callable $fn): mixed
                    => $o instanceof Connection ? $o->transaction($fn) : $o->run($fn);
                $run($outer, static function () use ($run, $inner): void {
                    self::q('INSERT INTO p202_nesting_probe SET n = 3');
                    $run($inner, static fn () => self::q('INSERT INTO p202_nesting_probe SET n = 4'));
                });
                self::fail('one began inside the other');
            } catch (LogicException) {
            }
        }
        self::assertSame([1], self::probe());
    }

    public function testReportFiltersAreSavedWholeOrNotAtAll(): void
    {
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER);
        $store = new ReportPrefsStore(self::db());

        self::assertSame([], $store->save(self::USER, ['country_id' => '7', 'region_id' => '9'], null));
        self::assertSame(['7', '9'], self::prefs());

        // A TINYINT is clamped under this sql_mode: 300 comes back 255.
        $refused = $store->save(self::USER, ['country_id' => '300', 'region_id' => '11'], null);
        self::assertSame(['country_id'], array_keys($refused));
        self::assertSame(['7', '9'], self::prefs(), 'the region that fitted rolled back with the country that did not');

        $conn = new Connection(self::db());
        try {
            $conn->transaction(static function () use ($store): void {
                self::q('INSERT INTO p202_nesting_probe SET n = 1');
                $store->save(self::USER, ['country_id' => '12'], null);
            });
            self::fail('the filters were saved inside another transaction');
        } catch (LogicException) {
        }
        self::assertSame(['7', '9'], self::prefs());
        self::assertSame([], self::probe());
    }

    public function testAUserIsDeletedWholeAndNeverInsideAnotherTransaction(): void
    {
        foreach ([self::USER, self::OTHER_USER] as $id) {
            self::user($id);
            self::q("INSERT INTO 202_api_keys SET user_id = $id, api_key = 'nesting-key-$id', created_at = 0");
        }

        (new UserDataPurge(self::db()))->deleteUser(self::USER);
        self::assertSame('1', self::value('SELECT user_deleted FROM 202_users WHERE user_id = ' . self::USER));
        $keys = 'SELECT COUNT(*) FROM 202_api_keys WHERE user_id = ';
        self::assertSame('0', self::value($keys . self::USER), 'its keys went with it');

        $refused = null;
        try {
            (new Connection(self::db()))->transaction(static function (): void {
                self::q('INSERT INTO p202_nesting_probe SET n = 1');
                (new UserDataPurge(self::db()))->deleteUser(self::OTHER_USER);
            });
        } catch (\RuntimeException $e) {
            $refused = $e;
        }
        $deleted = 'SELECT user_deleted FROM 202_users WHERE user_id = ';
        self::assertSame('0', self::value($deleted . self::OTHER_USER), 'deleted inside another transaction');
        self::assertSame('1', self::value($keys . self::OTHER_USER));
        self::assertSame([], self::probe(), 'the caller\'s write was committed');
        self::assertInstanceOf(\RuntimeException::class, $refused);
        self::assertInstanceOf(LogicException::class, $refused->getPrevious());
        self::assertStringContainsString('was not deleted', $refused->getMessage());
    }

    public function testTheInstallersAccountIsCreatedWholeOrNotAtAll(): void
    {
        // The admin role the installer's seed writes; the account's role row names it.
        if (self::value('SELECT COUNT(*) FROM 202_roles WHERE role_id = 1') === '0') {
            self::q("INSERT INTO 202_roles SET role_id = 1, role_name = 'Super user'");
            self::$addedRole = true;
        }
        $conn = new Connection(self::db());
        $id = InstallAccount::create($conn, self::account('p5d-install'));
        self::assertGreaterThan(0, $id);
        self::assertSame(['1', '1', '1', '1'], self::accountRows($id), 'its user, preferences, role and model rows');
        self::assertSame('1', self::value("SELECT COUNT(*) FROM 202_charts WHERE user_id = $id"));

        self::assertSame($id, InstallAccount::create($conn, self::account('p5d-install')), 'a second install finds it');
        self::assertSame('1', self::value("SELECT COUNT(*) FROM 202_charts WHERE user_id = $id"), 'and adds no chart');

        self::q("CREATE TRIGGER p202_test_refuse_chart BEFORE INSERT ON 202_charts FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'chart refused by the test'");
        try {
            InstallAccount::create($conn, self::account('p5d-install-2'));
            self::fail('an account was created without its chart');
        } catch (QueryException $e) {
            self::assertStringContainsString('chart refused by the test', $e->getMessage());
            self::assertFalse(InstallAccount::isTransient($e));
        } finally {
            self::q('DROP TRIGGER IF EXISTS p202_test_refuse_chart');
        }
        $named = static fn (string $name): ?string
            => self::value("SELECT user_id FROM 202_users WHERE user_name = '$name'");
        self::assertNull($named('p5d-install-2'), 'the user rolled back with it');

        try {
            $conn->transaction(
                static fn () => InstallAccount::create(new Connection(self::db()), self::account('p5d-install-3'))
            );
            self::fail('an account was created inside another transaction');
        } catch (LogicException) {
        }
        self::assertNull($named('p5d-install-3'));
    }

    public function testAnInstallFailureTheServerMayNotRepeatIsOfferedARetry(): void
    {
        $failed = static fn (int $errno): QueryException
            => new QueryException("MySQL execute failed: x [errno $errno]");
        self::assertTrue(InstallAccount::isTransient($failed(1213)));
        self::assertTrue(InstallAccount::isTransient(new \mysqli_sql_exception('gone', 2006)));
        self::assertTrue(InstallAccount::isTransient(new \RuntimeException('Schema installation failed', 1205)));
        self::assertFalse(InstallAccount::isTransient($failed(1062)));
    }

    public function testTheMobileAppsRuleWriteCommitsAndRollsBack(): void
    {
        $class = \Tracking202\Setup\MobileAppsController::class;
        $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($class, 'db'))->setValue($controller, self::db());
        $inTransaction = new \ReflectionMethod($class, 'inTransaction');

        $inTransaction->invoke($controller, static fn () => self::q('INSERT INTO p202_nesting_probe SET n = 1'));
        try {
            $inTransaction->invoke($controller, static function (): void {
                self::q('INSERT INTO p202_nesting_probe SET n = 2');
                throw new \DomainException('the rule was refused');
            });
        } catch (\DomainException) {
        }
        self::assertSame([1], self::probe());
    }

    private static function connect(): mysqli
    {
        $db = mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) getenv('P202_TEST_DB_NAME'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertInstanceOf(mysqli::class, $db);

        return $db;
    }

    private static function db(): mysqli
    {
        self::assertNotNull(self::$db);

        return self::$db;
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::db()->query($sql), self::db()->error . ' in ' . $sql);
    }

    private static function value(string $sql): ?string
    {
        $result = self::db()->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::db()->error . ' in ' . $sql);
        $row = $result->fetch_row();

        return $row === null || $row[0] === null ? null : (string) $row[0];
    }

    /** @return list<int> */
    private static function probe(): array
    {
        $result = self::db()->query('SELECT n FROM p202_nesting_probe ORDER BY n');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_map(static fn (array $r): int => (int) $r[0], $result->fetch_all(MYSQLI_NUM));
    }

    /** @return list<string|null> the country and region filters stored */
    private static function prefs(): array
    {
        $result = self::db()->query(
            'SELECT user_pref_country_id, user_pref_region_id FROM 202_users_pref WHERE user_id = ' . self::USER
        );
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();
        self::assertIsArray($row);

        return array_map(static fn ($v): ?string => $v === null ? null : (string) $v, $row);
    }

    private static function user(int $id): void
    {
        self::q("INSERT INTO 202_users SET user_id = $id, user_name = 'nesting$id', user_pass = 'x',"
            . " user_email = 'n$id@example.com', user_dash_email = 'n$id@example.com', user_time_register = 0,"
            . " install_hash = 'h', user_hash = 'h'");
    }

    /**
     * @return array{email: string, name: string, pass_hash: string, timezone: string, registered: int,
     *               install_hash: string, user_hash: string, customer_key: string}
     */
    private static function account(string $name): array
    {
        return [
            'email' => $name . '@example.com', 'name' => $name, 'pass_hash' => 'x', 'timezone' => 'UTC',
            'registered' => 0, 'install_hash' => 'h', 'user_hash' => 'h', 'customer_key' => 'k',
        ];
    }

    /** @return list<string|null> how many user, preferences, role and default model rows the user has */
    private static function accountRows(int $id): array
    {
        return array_map(static fn (string $sql): ?string => self::value($sql), [
            "SELECT COUNT(*) FROM 202_users WHERE user_id = $id",
            "SELECT COUNT(*) FROM 202_users_pref WHERE user_id = $id",
            "SELECT COUNT(*) FROM 202_user_role WHERE user_id = $id AND role_id = 1",
            "SELECT COUNT(*) FROM 202_attribution_models WHERE user_id = $id AND is_default = 1",
        ]);
    }

    private static function clearUsers(): void
    {
        $installed = self::db()->query("SELECT user_id FROM 202_users WHERE user_name LIKE 'p5d-install%'");
        foreach ($installed instanceof \mysqli_result ? $installed->fetch_all() : [] as [$id]) {
            $tables = ['202_user_role', '202_users_pref', '202_attribution_models', '202_charts', '202_users'];
            foreach ($tables as $table) {
                self::db()->query("DELETE FROM $table WHERE user_id = " . (int) $id);
            }
        }
        foreach ([self::USER, self::OTHER_USER] as $id) {
            self::db()->query("DELETE FROM 202_users WHERE user_id = $id");
            self::db()->query("DELETE FROM 202_users_pref WHERE user_id = $id");
            self::db()->query("DELETE FROM 202_api_keys WHERE user_id = $id");
        }
    }
}
