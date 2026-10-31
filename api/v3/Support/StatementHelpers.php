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
     * The executed statement's result set. get_result() answers false when it
     * fails, and false read as a result is "no rows": a record reported not
     * found, a list reported empty, a count of 0, for what was a database
     * error (CLAUDE.md #1). This throws instead, and closes the statement.
     */
    protected function resultOf(\mysqli_stmt $stmt, string $message): \mysqli_result
    {
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException($message);
        }
        return $result;
    }

    /**
     * Run $fn inside a transaction: Connection::transaction(), which every
     * transaction goes through. Its checked begin and commit, its rollback
     * on any throwable, and its refusal to begin inside another transaction
     * on the same connection (a nested begin commits the outer one) hold for
     * the controllers as for everything else. A failed begin or commit is a
     * QueryException, which the API answers as a 500 as it does this
     * trait's DatabaseException.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    protected function transaction(callable $fn): mixed
    {
        return (new \Prosper202\Database\Connection($this->db))->transaction($fn);
    }
}
