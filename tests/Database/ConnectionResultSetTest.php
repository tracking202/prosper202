<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Tests\Support\FakeMysqliConnection;

/**
 * A failed get_result() is an error, never an empty answer.
 *
 * fetchOne() used to turn a false result into null and fetchAll() into [],
 * so a fetch that failed (lost connection, no mysqlnd) read exactly like a
 * SELECT that matched nothing. The click lookup behind the legacy conversion
 * endpoints then reported "no such click" and dropped the conversion
 * (CLAUDE.md error pattern #1). Both now throw, and "no rows" is what a real
 * server returns for it: an empty result set.
 */
final class ConnectionResultSetTest extends TestCase
{
    public function testFetchOneReturnsNullForAnEmptyResultSet(): void
    {
        $db = new FakeMysqliConnection();
        $conn = new Connection($db);

        $stmt = $conn->prepareRead('SELECT click_id FROM 202_clicks WHERE click_id = ?');
        $conn->bind($stmt, 'i', [1]);

        self::assertNull($conn->fetchOne($stmt));
    }

    public function testFetchOneThrowsWhenTheResultFetchFails(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsResultFails('FROM 202_clicks');
        $conn = new Connection($db);

        $stmt = $conn->prepareRead('SELECT click_id FROM 202_clicks WHERE click_id = ?');
        $conn->bind($stmt, 'i', [1]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('get_result failed');
        $conn->fetchOne($stmt);
    }

    public function testFetchAllReturnsAnEmptyListForAnEmptyResultSet(): void
    {
        $db = new FakeMysqliConnection();
        $conn = new Connection($db);

        $stmt = $conn->prepareRead('SELECT click_id FROM 202_clicks WHERE user_id = ?');
        $conn->bind($stmt, 'i', [1]);

        self::assertSame([], $conn->fetchAll($stmt));
    }

    public function testFetchAllThrowsWhenTheResultFetchFails(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsResultFails('FROM 202_clicks');
        $conn = new Connection($db);

        $stmt = $conn->prepareRead('SELECT click_id FROM 202_clicks WHERE user_id = ?');
        $conn->bind($stmt, 'i', [1]);

        $this->expectException(QueryException::class);
        $conn->fetchAll($stmt);
    }
}
