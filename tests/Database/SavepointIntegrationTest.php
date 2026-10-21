<?php

declare(strict_types=1);

namespace Tests\Database;

use LogicException;
use mysqli;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * Connection::withSavepoint() on a real server, where what a failure does to
 * the transaction is the server's to decide: a failed row is undone alone, a
 * lock wait timeout fails only its statement, and a deadlock rolls the whole
 * transaction back, savepoint and all, after which the handle's statements
 * commit one by one.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS,
 * P202_TEST_DB_NAME); it writes only its own two tables.
 *
 * @group integration
 */
final class SavepointIntegrationTest extends TestCase
{
    private static ?mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        // The API's error mode: a failed statement throws mysqli_sql_exception.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$db = self::connect();
        self::$db->query('CREATE TABLE IF NOT EXISTS p202_savepoint_probe (n INT NOT NULL) ENGINE=InnoDB');
        self::$db->query(
            'CREATE TABLE IF NOT EXISTS p202_savepoint_locks (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB'
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->query('DROP TABLE IF EXISTS p202_savepoint_probe');
            self::$db->query('DROP TABLE IF EXISTS p202_savepoint_locks');
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No scratch database configured (P202_TEST_DB_HOST).');
        }
        self::db()->query('DELETE FROM p202_savepoint_probe');
        self::db()->query('DELETE FROM p202_savepoint_locks');
        self::db()->query('INSERT INTO p202_savepoint_locks VALUES (1, 0), (2, 0)');
    }

    public function testAFailureIsUndoneAloneAndTheRestCommits(): void
    {
        $conn = new Connection(self::db());
        $conn->transaction(static function () use ($conn): void {
            self::q('INSERT INTO p202_savepoint_probe VALUES (1)');
            try {
                $conn->withSavepoint(static function (): void {
                    self::q('INSERT INTO p202_savepoint_probe VALUES (2)');
                    throw new \DomainException('the row is refused after its insert');
                });
            } catch (\DomainException) {
            }
            $conn->withSavepoint(static fn () => self::q('INSERT INTO p202_savepoint_probe VALUES (3)'));
        });

        self::assertSame([1, 3], self::probe());
    }

    public function testALockWaitTimeoutFailsOnlyItsStatementAndIsPassedOnAsItself(): void
    {
        $holder = self::connect();
        self::assertTrue($holder->begin_transaction());
        $holder->query('UPDATE p202_savepoint_locks SET v = 1 WHERE id = 1');
        self::q('SET SESSION innodb_lock_wait_timeout = 1');
        $conn = new Connection(self::db());
        $thrown = null;
        try {
            $conn->transaction(static function () use ($conn, &$thrown): void {
                self::q('INSERT INTO p202_savepoint_probe VALUES (1)');
                try {
                    $conn->withSavepoint(static function (): void {
                        self::q('INSERT INTO p202_savepoint_probe VALUES (2)');
                        self::db()->query('UPDATE p202_savepoint_locks SET v = 2 WHERE id = 1');
                    });
                } catch (\Throwable $e) {
                    $thrown = $e;
                }
            });
        } finally {
            self::q('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            $holder->rollback();
            $holder->close();
        }

        self::assertInstanceOf(\mysqli_sql_exception::class, $thrown);
        self::assertSame(1205, $thrown->getCode(), 'the timeout itself, not the savepoint');
        self::assertTrue(Connection::isRetryableLockError($thrown));
        self::assertSame([1], self::probe(), 'the savepoint undid its insert; the transaction kept the rest');
    }

    /**
     * A real deadlock, this connection its victim: the server rolls the
     * transaction back, the savepoint goes with it, and ROLLBACK TO fails.
     */
    public function testADeadlockEndsTheTransactionAndItMayOnlyRollBack(): void
    {
        $other = self::connect();
        $conn = new Connection(self::db());
        $thrown = null;
        $joined = null;
        try {
            $conn->transaction(static function () use ($conn, $other, &$thrown, &$joined): void {
                self::q('INSERT INTO p202_savepoint_probe VALUES (1)');
                try {
                    $conn->withSavepoint(static function () use ($other): void {
                        self::deadlockAsVictim($other);
                    });
                } catch (\Throwable $e) {
                    $thrown = $e;
                }
                try {
                    $conn->joinTransaction(static fn () => self::q('INSERT INTO p202_savepoint_probe VALUES (3)'));
                } catch (LogicException $e) {
                    $joined = $e;
                }
            });
            self::fail('the transaction a deadlock ended was committed');
        } catch (QueryException $e) {
            self::assertStringContainsString('Transaction not committed', $e->getMessage());
        } finally {
            $other->close();
        }

        self::assertInstanceOf(QueryException::class, $thrown);
        self::assertStringContainsString('Unable to roll back to savepoint', $thrown->getMessage());
        // The savepoint went with the server's rollback: a signalled 1213 leaves it.
        self::assertStringContainsString('[errno 1305]', $thrown->getMessage(), 'the savepoint is gone');
        $previous = $thrown->getPrevious();
        self::assertInstanceOf(\mysqli_sql_exception::class, $previous);
        self::assertSame(1213, $previous->getCode());
        self::assertTrue(Connection::isRetryableLockError($thrown));
        self::assertInstanceOf(LogicException::class, $joined, 'nothing more is added to it');
        self::assertSame([], self::probe(), 'the server rolled the first insert back with the deadlock');
        self::assertFalse($conn->isRollbackOnly());
        self::assertFalse($conn->inTransaction());
    }

    /**
     * Lock row 1 here and row 2 on $other, then cross: $other waits for row
     * 1, and this connection's request for row 2 closes the cycle. $other
     * has written more, so the server picks this one to roll back.
     */
    private static function deadlockAsVictim(mysqli $other): void
    {
        self::q('UPDATE p202_savepoint_locks SET v = 1 WHERE id = 1');
        self::assertTrue($other->begin_transaction());
        for ($i = 0; $i < 50; $i++) {
            $other->query('INSERT INTO p202_savepoint_probe VALUES (100)');
        }
        $other->query('UPDATE p202_savepoint_locks SET v = 2 WHERE id = 2');
        $other->query('UPDATE p202_savepoint_locks SET v = 2 WHERE id = 1', MYSQLI_ASYNC);
        usleep(300000);
        try {
            self::db()->query('UPDATE p202_savepoint_locks SET v = 1 WHERE id = 2');
        } finally {
            $links = $errors = $rejects = [$other];
            mysqli_poll($links, $errors, $rejects, 10);
            $other->reap_async_query();
            $other->rollback();
        }
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

    /** @return list<int> */
    private static function probe(): array
    {
        $result = self::db()->query('SELECT n FROM p202_savepoint_probe ORDER BY n');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_map(static fn (array $r): int => (int) $r[0], $result->fetch_all(MYSQLI_NUM));
    }
}
