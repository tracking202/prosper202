<?php

declare(strict_types=1);

namespace Prosper202\Database;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use RuntimeException;
use Throwable;

/**
 * Typed wrapper around mysqli providing read/write connection routing,
 * safe parameter binding, checked execute, and closure-based transactions.
 *
 * Consolidates the prepare/bind/execute boilerplate that was duplicated
 * across every Attribution repository implementation.
 */
final class Connection
{
    private readonly mysqli $read;

    /**
     * Keeps bound parameter values alive until the statement is executed.
     *
     * mysqli's bind_param stores references; if the values go out of scope
     * before execute(), the parameters can become NULL/garbled.
     *
     * @var array<int, array<int, mixed>> keyed by spl_object_id($stmt)
     */
    private array $boundValues = [];

    public function __construct(
        private readonly mysqli $write,
        ?mysqli $read = null
    ) {
        $this->read = $read ?? $this->write;
    }

    /**
     * Prepare a statement on the write (primary) connection.
     *
     * Return type is enforced by PHPStan via annotation. PHP runtime type is
     * relaxed to allow test doubles — PHP 8.4 readonly properties on internal
     * classes (mysqli_stmt::$insert_id, $affected_rows, $error) make it
     * impossible to create proper subclass fakes.
     *
     * @return mysqli_stmt
     * @throws RuntimeException if the prepare fails
     */
    public function prepareWrite(string $sql)
    {
        return $this->prepare($this->write, $sql);
    }

    /**
     * Prepare a statement on the read (replica) connection, falling back to write.
     *
     * @return mysqli_stmt
     * @throws RuntimeException if the prepare fails
     */
    public function prepareRead(string $sql)
    {
        return $this->prepare($this->read, $sql);
    }

    /**
     * Bind parameters to a prepared statement with reference safety.
     *
     * mysqli's bind_param requires values by reference. This method
     * handles the reference indirection so callers can pass a plain array.
     *
     * @param mysqli_stmt $stmt
     * @param array<int, mixed> $values positional parameter values
     * @throws RuntimeException if the bind fails
     */
    public function bind(object $stmt, string $types, array $values): void
    {
        if ($types === '' && $values === []) {
            return;
        }

        if (strlen($types) !== count($values)) {
            throw new RuntimeException(
                'bind_param type string length (' . strlen($types) . ') does not match value count (' . count($values) . ').'
            );
        }

        // Re-index to ensure contiguous numeric keys.
        $values = array_values($values);

        // Store values on the Connection so the references survive until execute().
        $stmtId = spl_object_id($stmt);
        $this->boundValues[$stmtId] = $values;

        $refs = [$types];
        foreach ($this->boundValues[$stmtId] as $index => $value) {
            $refs[] = &$this->boundValues[$stmtId][$index];
        }

        if (!call_user_func_array($stmt->bind_param(...), $refs)) {
            unset($this->boundValues[$stmtId]);
            throw new RuntimeException('Failed to bind MySQL parameters.');
        }
    }

    /**
     * Execute a prepared statement, throwing on failure.
     *
     * This is the core safety mechanism: every execute() in the codebase
     * must check the return value. By centralising the check here, no
     * repository can accidentally forget.
     *
     * @throws RuntimeException if execute returns false
     */
    public function execute(mysqli_stmt $stmt): void
    {
        if (!$stmt->execute()) {
            try {
                $error = $stmt->error;
            } catch (\Error) {
                $error = '(unknown)';
            }
            unset($this->boundValues[spl_object_id($stmt)]);
            $stmt->close();
            throw new RuntimeException('MySQL execute failed: ' . $error);
        }
        unset($this->boundValues[spl_object_id($stmt)]);
    }

    /**
     * Execute a statement, fetch a single row, and close the statement.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(mysqli_stmt $stmt): ?array
    {
        $this->execute($stmt);
        $result = $stmt->get_result();
        $row = ($result instanceof mysqli_result) ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();

        return $row ?? null;
    }

    /**
     * Execute a statement, fetch all rows, and close the statement.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(mysqli_stmt $stmt): array
    {
        $this->execute($stmt);
        $result = $stmt->get_result();
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Execute an INSERT statement, return the insert ID, and close.
     *
     * Falls back to the connection-level insert_id if the statement
     * does not report one (behaviour varies by mysqli driver version).
     *
     * @throws RuntimeException if execute fails
     */
    public function executeInsert(mysqli_stmt $stmt): int
    {
        $this->execute($stmt);

        try {
            $insertId = $stmt->insert_id;
        } catch (\Error) {
            $insertId = 0;
        }

        if (!$insertId) {
            try {
                $insertId = $this->write->insert_id;
            } catch (\Error) {
                $insertId = 0;
            }
        }

        $stmt->close();

        return (int) $insertId;
    }

    /**
     * Execute an UPDATE/DELETE statement, return affected rows, and close.
     *
     * @throws RuntimeException if execute fails
     */
    public function executeUpdate(mysqli_stmt $stmt): int
    {
        $this->execute($stmt);

        try {
            $affected = $stmt->affected_rows;
        } catch (\Error) {
            $affected = 0;
        }

        $stmt->close();

        return (int) $affected;
    }

    /**
     * Run a callback inside a transaction with automatic rollback on exception.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws Throwable re-throws whatever the callback threw after rollback
     */
    public function transaction(callable $fn): mixed
    {
        if (!$this->write->begin_transaction()) {
            try {
                $error = $this->write->error;
            } catch (\Error) {
                $error = '(unknown)';
            }
            throw new RuntimeException('Unable to begin transaction: ' . $error);
        }

        try {
            $result = $fn();

            if (!$this->write->commit()) {
                try {
                    $error = $this->write->error;
                } catch (\Error) {
                    $error = '(unknown)';
                }
                throw new RuntimeException('Unable to commit transaction: ' . $error);
            }

            return $result;
        } catch (Throwable $e) {
            $this->write->rollback();
            throw $e;
        }
    }

    /**
     * Expose the write (primary) connection for cases that need raw access.
     *
     * Use sparingly — prefer the typed methods above.
     */
    public function writeConnection(): mysqli
    {
        return $this->write;
    }

    /**
     * Expose the read (replica) connection for cases that need raw access.
     */
    public function readConnection(): mysqli
    {
        return $this->read;
    }

    /**
     * @return mysqli_stmt
     * @throws RuntimeException if the prepare fails
     */
    private function prepare(mysqli $connection, string $sql)
    {
        $stmt = $connection->prepare($sql);
        if ($stmt === false) {
            try {
                $error = $connection->error;
            } catch (\Error) {
                $error = '(unknown)';
            }
            throw new RuntimeException('Failed to prepare MySQL statement: ' . $error);
        }

        return $stmt;
    }
}
