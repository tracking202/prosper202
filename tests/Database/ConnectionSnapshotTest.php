<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use RuntimeException;
use Tests\Support\FakeMysqliConnection;

/**
 * What Connection knows of the transaction open on a handle: whether it has
 * run a plain read, which fixed its REPEATABLE READ snapshot, and the locks
 * its takers said it holds (MysqlConversionLedger::lockClick() refuses a
 * click's lock asked for after a plain read, and reads a click it locked
 * first again without one).
 */
final class ConnectionSnapshotTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function statements(): iterable
    {
        yield 'a select of a table' => ['SELECT conv_id FROM 202_conversion_logs WHERE click_id = ?', true];
        yield 'lower case, a join' => ['select c.x from 202_clicks c join 202_y y on y.id = c.id', true];
        yield 'after whitespace, parentheses and comments' => ["\n  ( /* why */ -- because\n SELECT 1 FROM t)", true];
        yield 'a CTE' => ['WITH x AS (SELECT 1 AS a FROM t) SELECT a FROM x', true];
        yield 'information_schema, counted though MariaDB takes no snapshot'
            => ['SELECT COUNT(*) FROM information_schema.INNODB_TRX', true];
        yield 'FOR UPDATE' => ['SELECT click_id FROM 202_clicks WHERE click_id = ? LIMIT 1 FOR UPDATE', false];
        yield 'FOR UPDATE, then a semicolon' => ["SELECT a FROM t WHERE id = 1 FOR UPDATE;\n", false];
        yield 'FOR UPDATE NOWAIT' => ['SELECT a FROM t FOR UPDATE NOWAIT', false];
        yield 'FOR UPDATE SKIP LOCKED' => ['SELECT a FROM t FOR UPDATE SKIP LOCKED', false];
        yield 'FOR SHARE' => ['SELECT a FROM t FOR SHARE', false];
        yield 'LOCK IN SHARE MODE' => ['SELECT a FROM t WHERE id = ? LOCK IN SHARE MODE', false];
        yield 'a FOR UPDATE that is not last'
            => ['SELECT a FROM t WHERE b IN (SELECT b FROM u FOR UPDATE) ORDER BY a', true];
        yield 'no table' => ['SELECT 1', false];
        yield 'a variable' => ['SELECT @@session.sql_mode', false];
        yield 'a function' => ['SELECT LAST_INSERT_ID()', false];
        yield 'an insert' => ['INSERT INTO t (a) VALUES (?)', false];
        yield 'an insert of a select' => ['INSERT INTO t (a) SELECT a FROM u', false];
        yield 'an update with a subquery' => ['UPDATE t SET a = (SELECT MAX(a) FROM u)', false];
        yield 'a delete' => ['DELETE FROM t WHERE id = ?', false];
        yield 'SHOW' => ['SHOW TABLES FROM p202', false];
    }

    /** @dataProvider statements */
    public function testWhatIsAPlainRead(string $sql, bool $plain): void
    {
        self::assertSame($plain, Connection::isPlainRead($sql));
    }

    public function testAPlainReadInATransactionTakesItsSnapshotUntilTheTransactionEnds(): void
    {
        $write = new FakeMysqliConnection();
        $conn = new Connection($write);

        $seen = $conn->transaction(static function () use ($conn): array {
            $seen = [$conn->snapshotTaken()];
            $conn->fetchAll($conn->prepareWrite('SELECT click_id FROM 202_clicks WHERE click_id = 1 FOR UPDATE'));
            $conn->executeUpdate($conn->prepareWrite('UPDATE 202_clicks SET click_lead = 1 WHERE click_id = 1'));
            $conn->fetchOne($conn->prepareWrite('SELECT 1'));
            $seen[] = $conn->snapshotTaken();
            $conn->fetchAll($conn->prepareWrite('SELECT conv_id FROM 202_conversion_logs'));
            $seen[] = $conn->snapshotTaken();
            $seen[] = (new Connection($conn->writeConnection()))->snapshotTaken();

            return $seen;
        });

        self::assertSame(
            [false, false, true, true],
            $seen,
            'only the plain read of a table, and on every wrapper of the handle'
        );
        self::assertFalse($conn->snapshotTaken(), 'not once the transaction ended');
        self::assertFalse($conn->transaction(static fn (): bool => $conn->snapshotTaken()), 'nor in the next one');
    }

    public function testAReadOutsideTheTransactionTakesNoSnapshotOfIt(): void
    {
        $write = new FakeMysqliConnection();
        $replica = new Connection($write, new FakeMysqliConnection());
        $conn = new Connection($write);

        $conn->fetchAll($conn->prepareWrite('SELECT conv_id FROM 202_conversion_logs'));
        $seen = $conn->transaction(static function () use ($replica, $conn): array {
            $replica->fetchAll($replica->prepareRead('SELECT conv_id FROM 202_conversion_logs'));
            $seen = [$conn->snapshotTaken()];
            // With no replica, a read is on the write handle.
            $conn->fetchAll($conn->prepareRead('SELECT conv_id FROM 202_conversion_logs'));
            $seen[] = $conn->snapshotTaken();

            return $seen;
        });

        self::assertSame([false, true], $seen);
    }

    public function testAReadThatFailsHasStillTakenTheSnapshot(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsExecuteReturns('FROM 202_conversion_logs', false);
        $conn = new Connection($write);

        $seen = $conn->transaction(static function () use ($conn): bool {
            try {
                $conn->fetchAll($conn->prepareWrite('SELECT conv_id FROM 202_conversion_logs'));
            } catch (RuntimeException) {
            }

            return $conn->snapshotTaken();
        });

        self::assertTrue($seen);
    }

    public function testALockIsHeldUntilItsTransactionEnds(): void
    {
        $write = new FakeMysqliConnection();
        $conn = new Connection($write);

        $conn->holdLock('click:1');
        self::assertFalse($conn->holdsLock('click:1'), 'outside a transaction a lock ends with its statement');

        $held = $conn->transaction(static function () use ($conn): array {
            $conn->holdLock('click:1');
            $other = new Connection($conn->writeConnection());

            return [$conn->holdsLock('click:1'), $other->holdsLock('click:1'), $conn->holdsLock('click:2')];
        });
        self::assertSame([true, true, false], $held);
        self::assertFalse($conn->holdsLock('click:1'), 'after the commit');

        try {
            $conn->transaction(static function () use ($conn): void {
                $conn->holdLock('click:1');
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }
        self::assertFalse($conn->transaction(static fn (): bool => $conn->holdsLock('click:1')), 'after a rollback');

        $ended = $conn->transaction(static function () use ($conn, $write): array {
            $conn->holdLock('click:1');
            $conn->fetchAll($conn->prepareWrite('SELECT conv_id FROM 202_conversion_logs'));
            // What the database error page does before it records.
            Connection::transactionsEnded($write);

            return [$conn->holdsLock('click:1'), $conn->snapshotTaken()];
        });
        self::assertSame([false, false], $ended);
    }
}
