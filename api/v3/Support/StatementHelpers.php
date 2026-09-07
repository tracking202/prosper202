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
     * Run $fn inside a transaction: checked begin, checked commit, rollback on
     * any throwable. This is the only transaction primitive in api/v3 -- the
     * controllers hand their multi-statement bodies to it as closures rather
     * than opening a transaction themselves, so there is no second place for a
     * commit check or a rollback to be forgotten.
     *
     * On the return-value checks: which mysqli failure mode applies depends on
     * the entry point. api/v3/index.php never includes 202-config/connect.php,
     * so this code runs under PHP's default mysqli_report(ERROR | STRICT) and a
     * failed begin_transaction()/commit() throws mysqli_sql_exception before
     * the `if` is reached. connect.php (the UI and cron paths) downgrades that
     * to STRICT alone, where the same calls return false. The checks are kept
     * so the helper is correct under both modes -- a trait cannot know which
     * bootstrap loaded it -- and so the failure has the same DatabaseException
     * shape as every other helper here.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    protected function transaction(callable $fn): mixed
    {
        // An ignored false here is the worst one: $fn() would run in
        // autocommit, every statement would land individually, and the
        // rollback below would have nothing to undo while the caller is told
        // the operation failed.
        if (!$this->db->begin_transaction()) {
            throw new DatabaseException('Could not start transaction: ' . $this->db->error);
        }
        try {
            $result = $fn();
            if (!$this->db->commit()) {
                // Thrown, not returned: the catch below is what rolls back, so
                // a failed commit leaves nothing half-applied on a connection
                // that may be reused.
                throw new DatabaseException('Transaction commit failed: ' . $this->db->error);
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
