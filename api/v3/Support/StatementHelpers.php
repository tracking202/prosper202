<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;

/**
 * Checked mysqli statement helpers shared by every v3 controller.
 *
 * prepare()/bind()/execute() wrap the fallible mysqli calls so a false
 * return can never be silently ignored (CLAUDE.md error pattern 1), and
 * transaction() guarantees rollback on any throwable plus a checked commit.
 *
 * Requires the using class to expose a \mysqli connection as $this->db.
 */
trait StatementHelpers
{
    protected function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }

    protected function bind(\mysqli_stmt $stmt, string $types, mixed ...$values): void
    {
        // @phpstan-ignore-next-line this IS the ref-safe bind wrapper (analog of Connection::bind); no $this->conn exists, cannot self-route
        if (!$stmt->bind_param($types, ...$values)) {
            $stmt->close();
            throw new DatabaseException('Bind failed');
        }
    }

    protected function execute(\mysqli_stmt $stmt, string $message): void
    {
        // @phpstan-ignore-next-line this IS the checked-execute wrapper (analog of Connection::execute); no $this->conn exists, cannot self-route
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException($message);
        }
    }

    /**
     * Open a transaction, or throw.
     *
     * begin_transaction() is fallible like every other mysqli call, and a false
     * return is the worst one to ignore: the body then runs in autocommit,
     * every statement lands individually, and the rollback in the failure path
     * has nothing to roll back. The caller is told the operation failed while
     * half the work is permanently committed -- the exact partial-write hazard
     * transactions are here to prevent.
     *
     * Prefer transaction() where the work fits a closure; this exists for the
     * call sites that need a bare try/catch around multi-statement bodies.
     */
    protected function beginTransaction(): void
    {
        if (!$this->db->begin_transaction()) {
            throw new DatabaseException('Could not start transaction');
        }
    }

    protected function transaction(callable $fn): mixed
    {
        $this->beginTransaction();
        try {
            $result = $fn();
            if (!$this->db->commit()) {
                // Thrown, not returned: the catch below is what rolls back, so
                // a failed commit leaves nothing half-applied on a connection
                // that may be reused.
                throw new DatabaseException('Transaction commit failed');
            }
            return $result;
        } catch (\Throwable $e) {
            // rollback()'s own result is deliberately unchecked: $e is the root
            // cause and must reach the caller. A rollback that also fails has
            // nothing better to report, and replacing $e with it would hide why
            // the work was abandoned.
            $this->db->rollback();
            throw $e;
        }
    }
}
