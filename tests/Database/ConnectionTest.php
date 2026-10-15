<?php

declare(strict_types=1);

namespace Tests\Database;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use RuntimeException;

/**
 * Unit tests for the Connection wrapper.
 *
 * Uses FakeMysqli which extends mysqli (skipping parent ctor) and
 * FakeStmt which wraps a mock to work around PHP 8.3 readonly properties.
 */
final class ConnectionTest extends TestCase
{
    // ── Prepare routing ──────────────────────────────────────────────

    public function testPrepareWriteThrowsOnFailure(): void
    {
        $write = $this->createFakeMysqli(prepareResult: false);
        $conn = new Connection($write);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to prepare MySQL statement');
        $conn->prepareWrite('SELECT 1');
    }

    public function testPrepareReadUsesReadConnectionWhenProvided(): void
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $write = $this->createFakeMysqli(prepareResult: $stmt);
        $read = $this->createFakeMysqli(prepareResult: $stmt);
        $conn = new Connection($write, $read);

        $conn->prepareRead('SELECT 1');

        $this->assertSame(1, $read->prepareCallCount);
        $this->assertSame(0, $write->prepareCallCount);
    }

    public function testPrepareReadFallsBackToWriteWhenNoReadConnection(): void
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $write = $this->createFakeMysqli(prepareResult: $stmt);
        $conn = new Connection($write);

        $conn->prepareRead('SELECT 1');

        $this->assertSame(1, $write->prepareCallCount);
    }

    // ── Execute ──────────────────────────────────────────────────────

    public function testExecuteThrowsOnFailure(): void
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(false);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MySQL execute failed');
        $conn->execute($stmt);
    }

    public function testExecuteSucceeds(): void
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);

        $conn = new Connection($this->createFakeMysqli());

        $conn->execute($stmt);
        $this->assertTrue(true);
    }

    // ── FetchOne ─────────────────────────────────────────────────────

    public function testFetchOneReturnsRowAndClosesStatement(): void
    {
        $expected = ['id' => 1, 'name' => 'test'];
        $result = $this->createMock(mysqli_result::class);
        $result->method('fetch_assoc')->willReturn($expected);
        $result->expects($this->once())->method('free');

        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $row = $conn->fetchOne($stmt);

        $this->assertSame($expected, $row);
    }

    public function testFetchOneReturnsNullForEmptyResult(): void
    {
        $result = $this->createMock(mysqli_result::class);
        $result->method('fetch_assoc')->willReturn(null);

        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $this->assertNull($conn->fetchOne($stmt));
    }

    public function testFetchOneThrowsWhenGetResultReturnsFalse(): void
    {
        // false from get_result() is a fetch failure, not an empty result set
        // (that is a mysqli_result with no rows). This test used to assert
        // null here, which pinned the defect: a failed lookup read exactly
        // like "no such row" (CLAUDE.md error pattern #1).
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn(false);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('get_result failed');
        $conn->fetchOne($stmt);
    }

    public function testFetchOneThrowsWhenTheRowFetchFails(): void
    {
        // fetch_assoc() answers null for no row and false for a fetch that
        // failed; the false used to surface as a TypeError from ?array.
        $result = $this->createMock(mysqli_result::class);
        $result->method('fetch_assoc')->willReturn(false);
        $result->expects($this->once())->method('free');

        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('MySQL fetch failed');
        $conn->fetchOne($stmt);
    }

    // ── FetchAll ─────────────────────────────────────────────────────

    public function testFetchAllReturnsAllRowsAndClosesStatement(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'beta'],
        ];

        $result = $this->createMock(mysqli_result::class);
        $result->method('fetch_assoc')->willReturnOnConsecutiveCalls(
            $rows[0],
            $rows[1],
            null
        );
        $result->expects($this->once())->method('free');

        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $this->assertSame($rows, $conn->fetchAll($stmt));
    }

    public function testFetchAllThrowsWhenARowFetchFailsPartWay(): void
    {
        // A false mid-way used to end the loop, so the rows read before it
        // were answered as all of them.
        $result = $this->createMock(mysqli_result::class);
        $result->method('fetch_assoc')->willReturnOnConsecutiveCalls(['id' => 1], false, null);
        $result->expects($this->once())->method('free');

        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('MySQL fetch failed');
        $conn->fetchAll($stmt);
    }

    public function testFetchAllThrowsWhenGetResultReturnsFalse(): void
    {
        // As for fetchOne: [] is what an empty result set yields; false is an
        // error and must not be read as "no rows".
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn(false);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $this->expectException(QueryException::class);
        $conn->fetchAll($stmt);
    }

    // ── ExecuteInsert ────────────────────────────────────────────────

    public function testExecuteInsertReturnsInsertId(): void
    {
        // In PHP 8.4, insert_id on both stmt and connection are readonly native
        // properties. We test that executeInsert returns an int and closes.
        // The actual insert_id will be 0 (default) without a real DB.
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $id = $conn->executeInsert($stmt);
        $this->assertIsInt($id);
    }

    // ── ExecuteUpdate ────────────────────────────────────────────────

    public function testExecuteUpdateReturnsAffectedRows(): void
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);
        // affected_rows defaults to -1 on mock which is fine for the type test
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        // We can't set affected_rows on the mock, so just verify it doesn't throw
        $count = $conn->executeUpdate($stmt);
        $this->assertIsInt($count);
    }

    // ── Transaction ──────────────────────────────────────────────────

    public function testTransactionCommitsOnSuccess(): void
    {
        $write = $this->createFakeMysqli();
        $conn = new Connection($write);

        $result = $conn->transaction(fn () => 'ok');

        $this->assertSame('ok', $result);
        $this->assertTrue($write->committed);
        $this->assertFalse($write->rolledBack);
    }

    public function testTransactionRollsBackOnException(): void
    {
        $write = $this->createFakeMysqli();
        $conn = new Connection($write);

        try {
            $conn->transaction(function () {
                throw new RuntimeException('boom');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertTrue($write->rolledBack);
        $this->assertFalse($write->committed);
    }

    public function testTransactionThrowsWhenBeginFails(): void
    {
        $write = $this->createFakeMysqli(beginTransactionResult: false);
        $conn = new Connection($write);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to begin transaction');
        $conn->transaction(fn () => 'nope');
    }

    public function testATransactionInsideAnotherIsRefusedBeforeItBegins(): void
    {
        $write = $this->createFakeMysqli();
        $conn = new Connection($write);

        $inners = ['the same wrapper' => $conn, 'another wrapper of the handle' => new Connection($write)];
        foreach ($inners as $how => $inner) {
            $ran = false;
            try {
                $conn->transaction(static function () use ($inner, &$ran): void {
                    $inner->transaction(static function () use (&$ran): void {
                        $ran = true;
                    });
                });
                $this->fail("$how: a transaction began inside another");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('inside another', $e->getMessage(), $how);
            }
            $this->assertFalse($ran, $how);
            $this->assertTrue($write->rolledBack, "$how: the outer rolled back");
            $this->assertFalse($conn->inTransaction(), $how);
            $write->rolledBack = false;
        }
        $this->assertSame(2, $write->begins, 'one begin per outer transaction, none for the refused ones');
    }

    public function testAnotherHandleOpensItsOwnTransaction(): void
    {
        $write = $this->createFakeMysqli();
        $other = $this->createFakeMysqli();

        $result = (new Connection($write))->transaction(
            static fn (): string => (new Connection($other))->transaction(static fn (): string => 'both')
        );

        $this->assertSame('both', $result);
        $this->assertTrue($write->committed);
        $this->assertTrue($other->committed);
    }

    public function testJoinTransactionRunsInTheCallersAndRefusesOutsideOne(): void
    {
        $write = $this->createFakeMysqli();
        $conn = new Connection($write);

        $this->assertSame('joined', $conn->transaction(
            static fn (): string => (new Connection($write))->joinTransaction(static fn (): string => 'joined')
        ));
        $this->assertSame(1, $write->begins, 'joining begins nothing');

        $ran = false;
        try {
            $conn->joinTransaction(static function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('work meant for a transaction ran outside one');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('outside one', $e->getMessage());
        }
        $this->assertFalse($ran);
    }

    public function testEveryWayOutOfATransactionLetsTheNextOneBegin(): void
    {
        $write = $this->createFakeMysqli();
        $conn = new Connection($write);

        $conn->transaction(static fn () => null);
        $this->assertFalse($conn->inTransaction(), 'after a commit');

        try {
            $conn->transaction(static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }
        $this->assertFalse($conn->inTransaction(), 'after a rollback');

        $write->commitResult = false;
        try {
            $conn->transaction(static fn () => null);
            $this->fail('a failed commit read as a commit');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Unable to commit transaction', $e->getMessage());
        }
        $this->assertTrue($write->rolledBack);
        $this->assertFalse($conn->inTransaction(), 'after a failed commit');
        $write->commitResult = true;

        $conn->transaction(static function () use ($conn, $write): void {
            // What the database error page does before it records.
            Connection::transactionsEnded($write);
            $conn->transaction(static fn () => null);
        });
        $this->assertSame(5, $write->begins);
    }

    // ── Connection accessors ─────────────────────────────────────────

    public function testWriteConnectionReturnsWriteInstance(): void
    {
        $write = $this->createFakeMysqli();
        $read = $this->createFakeMysqli();
        $conn = new Connection($write, $read);

        $this->assertSame($write, $conn->writeConnection());
    }

    public function testReadConnectionReturnsReadInstance(): void
    {
        $write = $this->createFakeMysqli();
        $read = $this->createFakeMysqli();
        $conn = new Connection($write, $read);

        $this->assertSame($read, $conn->readConnection());
    }

    public function testReadConnectionFallsBackToWriteWhenNotProvided(): void
    {
        $write = $this->createFakeMysqli();
        $conn = new Connection($write);

        $this->assertSame($write, $conn->readConnection());
    }

    // ── Bind ─────────────────────────────────────────────────────────

    public function testBindWithEmptyTypesIsNoop(): void
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->expects($this->never())->method('bind_param');

        $conn = new Connection($this->createFakeMysqli());
        $conn->bind($stmt, '', []);
        $this->assertTrue(true);
    }

    public function testBindRejectsTypesValuesMismatch(): void
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->expects($this->never())->method('bind_param');

        $conn = new Connection($this->createFakeMysqli());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match value count');
        $conn->bind($stmt, 'ii', [1]);
    }

    public function testBindRejectsEmptyTypesWithValues(): void
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->expects($this->never())->method('bind_param');

        $conn = new Connection($this->createFakeMysqli());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match value count');
        $conn->bind($stmt, '', [1]);
    }

    public function testBindNullableIntAsI(): void
    {
        $stmt = new BindCapturingStmt();

        $conn = new Connection($this->createFakeMysqli());
        $conn->bind($stmt, 'i', [null]);

        $this->assertSame('i', $stmt->capturedTypes);
        $this->assertCount(1, $stmt->capturedValues);
        $this->assertNull($stmt->capturedValues[0]);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function createFakeMysqli(
        mysqli_stmt|false $prepareResult = false,
        bool $beginTransactionResult = true
    ): FakeMysqli {
        return new FakeMysqli($prepareResult, $beginTransactionResult);
    }
}

/**
 * Minimal fake that extends mysqli without opening a real connection.
 *
 * Overrides every method that Connection calls so the native driver
 * is never invoked (which would error on the null internal connection).
 */
class FakeMysqli extends mysqli
{
    public string $error = 'fake connection error';
    public string|int $insert_id = 0;
    public int $prepareCallCount = 0;
    public bool $committed = false;
    public bool $rolledBack = false;
    public int $begins = 0;
    public bool $commitResult = true;
    private bool $inTransaction = false;

    public function __construct(
        private mysqli_stmt|false $prepareResult = false,
        private bool $beginTransactionResult = true
    ) {
        // Skip parent::__construct() — no real connection.
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query): mysqli_stmt|false
    {
        $this->prepareCallCount++;

        return $this->prepareResult;
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        $this->begins++;
        if ($this->beginTransactionResult) {
            $this->inTransaction = true;
        }

        return $this->beginTransactionResult;
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $this->committed = $this->commitResult;
        $this->inTransaction = !$this->commitResult;

        return $this->commitResult;
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        $this->rolledBack = true;
        $this->inTransaction = false;

        return true;
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        return false;
    }

    public function close(): true
    {
        return true;
    }
}

final class BindCapturingStmt extends mysqli_stmt
{
    public string $capturedTypes = '';

    /**
     * @var list<mixed>
     */
    public array $capturedValues = [];

    public function __construct()
    {
        // Skip parent constructor — no real connection.
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->capturedTypes = $types;
        $this->capturedValues = [];
        foreach ($vars as &$var) {
            $this->capturedValues[] = $var;
        }

        return true;
    }
}
