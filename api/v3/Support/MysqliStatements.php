<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;

/**
 * Checked prepare/bind/execute/result helpers for classes that hold a
 * \mysqli in $this->db but do not extend Api\V3\Controller.
 *
 * One implementation instead of a copy per class: every call site gets the
 * same error contract (DatabaseException, closed statements) and the same
 * get_result() check — a false get_result reads as an empty result set at
 * the call site (error pattern #1's get_result variant), which is why it is
 * centralized here rather than trusted to each caller.
 */
trait MysqliStatements
{
    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed: ' . $this->db->error);
        }
        return $stmt;
    }

    private function bind(\mysqli_stmt $stmt, string $types, mixed ...$values): void
    {
        // @phpstan-ignore-next-line prosper202.directStmtCall — this IS the centralized ref-safe bind wrapper (no Connection instance in scope; routing through $this->conn would self-recurse)
        if (!$stmt->bind_param($types, ...$values)) {
            $stmt->close();
            throw new DatabaseException('Bind failed');
        }
    }

    private function execute(\mysqli_stmt $stmt, string $message = 'Statement execution failed'): void
    {
        // @phpstan-ignore-next-line prosper202.directStmtCall — this IS the centralized checked-execute wrapper (no Connection instance; routing through $this->conn would self-recurse)
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException($message);
        }
    }

    private function result(\mysqli_stmt $stmt): \mysqli_result
    {
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Result retrieval failed');
        }
        return $result;
    }
}
