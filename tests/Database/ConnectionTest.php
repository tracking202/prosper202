<?php

declare(strict_types=1);

namespace Tests\Database;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
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

        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);
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

    public function testFetchOneReturnsNullWhenGetResultReturnsFalse(): void
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn(false);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $this->assertNull($conn->fetchOne($stmt));
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
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $this->assertSame($rows, $conn->fetchAll($stmt));
    }

    public function testFetchAllReturnsEmptyArrayWhenGetResultReturnsFalse(): void
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn(false);
        $stmt->expects($this->once())->method('close');

        $conn = new Connection($this->createFakeMysqli());
        $this->assertSame([], $conn->fetchAll($stmt));
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
        if ($this->beginTransactionResult) {
            $this->inTransaction = true;
        }

        return $this->beginTransactionResult;
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $this->committed = true;
        $this->inTransaction = false;

        return true;
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
