<?php

declare(strict_types=1);

namespace Tests\Install;

use mysqli;
use mysqli_result;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliResult;

/**
 * p202_setup_config_locked() (202-config/functions-setup-config.php): the
 * predicate that keeps the setup wizard from rewriting the configuration of
 * a running install, executed branch by branch (#169).
 *
 * It is the most security-critical answer the wizard gives, and it answered
 * from inside setup-config.php, where only its "an account exists" branch
 * was ever exercised (by tests/live/prelogin-pages.sh). Every answer it gives
 * when it cannot tell must be "locked" (error pattern #11); the one
 * "unlocked" answer for an existing file is a database that answers and has
 * no account table or no account.
 */
final class SetupConfigLockedTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-setup-config.php';
        // FakeMysqliResult is declared in FakeMysqliConnection's file.
        self::assertTrue(class_exists(\Tests\Support\FakeMysqliConnection::class));
        self::assertTrue(class_exists(FakeMysqliResult::class, false));
    }

    /**
     * A probe connection whose two queries answer as told: a list of rows,
     * or false for a query that fails.
     *
     * @param list<array<string, mixed>>|false $tables
     * @param list<array<string, mixed>>|false $count
     */
    private static function probe(array|false $tables, array|false $count = []): mysqli
    {
        return new class ($tables, $count) extends mysqli {
            /** @var list<string> */
            public array $asked = [];
            public bool $closed = false;

            public function __construct(private array|false $tables, private array|false $count)
            {
            }

            #[\ReturnTypeWillChange]
            public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
            {
                $this->asked[] = $query;
                $rows = str_starts_with($query, 'SHOW TABLES') ? $this->tables : $this->count;
                return $rows === false ? false : new FakeMysqliResult($rows);
            }

            public function close(): true
            {
                $this->closed = true;
                return true;
            }
        };
    }

    private static function connectTo(mysqli|false|null $probe, ?array &$calls = null): \Closure
    {
        $calls = [];
        return static function (string $host, string $user, string $pass, string $name) use ($probe, &$calls) {
            $calls[] = [$host, $user, $pass, $name];
            return $probe;
        };
    }

    public function testNoConfigurationYetIsUnlockedWithoutAsking(): void
    {
        self::assertFalse(p202_setup_config_locked(false, null, null, null, null, self::connectTo(false, $calls)));
        self::assertSame([], $calls, 'nothing is connected to');
    }

    /** @return array<string, array{0: ?string, 1: ?string, 2: ?string}> */
    public static function unreadableSettings(): array
    {
        return [
            'no host' => [null, 'u', 'db'],
            'an empty host' => ['', 'u', 'db'],
            'no user' => ['h', null, 'db'],
            'no database' => ['h', 'u', null],
            'an empty database' => ['h', 'u', ''],
        ];
    }

    /** @dataProvider unreadableSettings */
    public function testSettingsItCannotReadAreLocked(?string $host, ?string $user, ?string $name): void
    {
        self::assertTrue(p202_setup_config_locked(true, $host, $user, 'p', $name, self::connectTo(self::probe([]), $calls)));
        self::assertSame([], $calls, 'and nothing is connected to');
    }

    public function testAConnectionThatFailsIsLocked(): void
    {
        self::assertTrue(p202_setup_config_locked(true, 'h', 'u', 'p', 'db', self::connectTo(false, $calls)));
        self::assertSame([['h', 'u', 'p', 'db']], $calls, 'with the configuration\'s own settings');
        self::assertTrue(p202_setup_config_locked(true, 'h', 'u', null, 'db', self::connectTo(null)), 'a connector that answers nothing');
        self::assertTrue(p202_setup_config_locked(true, 'h', 'u', 'p', 'db', static function (): never {
            throw new \mysqli_sql_exception('refused');
        }), 'a connector that throws');
    }

    public function testAShowTablesThatFailsIsLocked(): void
    {
        $probe = self::probe(false);
        self::assertTrue(p202_setup_config_locked(true, 'h', 'u', 'p', 'db', self::connectTo($probe)));
        self::assertTrue($probe->closed, 'and the probe is closed');
    }

    public function testACountThatFailsIsLocked(): void
    {
        $probe = self::probe([['Tables_in_db' => '202_users']], false);
        self::assertTrue(p202_setup_config_locked(true, 'h', 'u', 'p', 'db', self::connectTo($probe)));
        self::assertCount(2, $probe->asked);
        self::assertTrue($probe->closed);
    }

    public function testACountWithNoRowIsLocked(): void
    {
        self::assertTrue(p202_setup_config_locked(true, 'h', 'u', 'p', 'db', self::connectTo(self::probe([['Tables_in_db' => '202_users']], []))));
        self::assertTrue(p202_setup_config_locked(true, 'h', 'u', 'p', 'db', self::connectTo(self::probe([['Tables_in_db' => '202_users']], [['other' => 1]]))), 'or a row without the count');
    }

    public function testAnInstallWithAnAccountIsLocked(): void
    {
        self::assertTrue(p202_setup_config_locked(true, 'h', 'u', 'p', 'db', self::connectTo(self::probe([['Tables_in_db' => '202_users']], [['cnt' => '1']]))));
    }

    public function testAUsersTableThatExistsButIsEmptyIsUnlocked(): void
    {
        $probe = self::probe([['Tables_in_db' => '202_users']], [['cnt' => '0']]);
        self::assertFalse(p202_setup_config_locked(true, 'h', 'u', 'p', 'db', self::connectTo($probe)),
            'the database answered and holds no account: an install that never finished, which the wizard may redo');
        self::assertSame(["SHOW TABLES LIKE '202\\_users'", 'SELECT COUNT(*) AS cnt FROM 202_users'], $probe->asked, 'it asked exactly this');
        self::assertTrue($probe->closed);
    }

    public function testADatabaseWithNoUsersTableIsUnlocked(): void
    {
        $probe = self::probe([]);
        self::assertFalse(p202_setup_config_locked(true, 'h', 'u', 'p', 'db', self::connectTo($probe)), 'configured, never installed');
        self::assertCount(1, $probe->asked, 'no count is asked of a table that is not there');
        self::assertTrue($probe->closed);
    }

    public function testTheWizardAsksThisFunctionAndHasNoCopyOfItsOwn(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/setup-config.php');
        self::assertStringNotContainsString('function p202_setup_config_locked', $page, 'the page declares no second copy');
        self::assertSame(1, preg_match_all('/\$config_locked = p202_setup_config_locked\(/', $page), 'the page decides with the tested function');
        self::assertStringContainsString("require_once __DIR__ . '/functions-setup-config.php';", $page);
    }
}
