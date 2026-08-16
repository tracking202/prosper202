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

    protected function transaction(callable $fn): mixed
    {
        $this->db->begin_transaction();
        try {
            $result = $fn();
            if (!$this->db->commit()) {
                throw new DatabaseException('Transaction commit failed');
            }
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
