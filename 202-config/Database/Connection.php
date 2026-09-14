<?php

declare(strict_types=1);

namespace Prosper202\Database;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use Prosper202\Database\Exceptions\QueryException;
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
     * @throws QueryException if the prepare fails
     */
    public function prepareWrite(string $sql)
    {
        return $this->prepare($this->write, $sql);
    }

    /**
     * Prepare a statement on the read (replica) connection, falling back to write.
     *
     * @return mysqli_stmt
     * @throws QueryException if the prepare fails
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
     * @throws QueryException if the bind fails
     */
    public function bind(object $stmt, string $types, array $values): void
    {
        if ($types === '' && $values === []) {
            return;
        }

        if (strlen($types) !== count($values)) {
            throw new QueryException(
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
            throw new QueryException('Failed to bind MySQL parameters.');
        }
    }

    /**
     * Execute a prepared statement, throwing on failure.
     *
     * This is the core safety mechanism: every execute() in the codebase
     * must check the return value. By centralising the check here, no
     * repository can accidentally forget.
     *
     * Calls the OOP $stmt->execute() (not procedural mysqli_stmt_execute) so
     * test doubles can intercept it; the procedural function reaches into the
     * native statement handle and fails on fakes/mocks. Param type is relaxed
     * to object for the same reason bind()/prepare() are (see class note);
     * PHPStan enforces mysqli_stmt via the annotation.
     *
     * @param mysqli_stmt $stmt
     * @throws QueryException if execute returns false
     */
    public function execute(object $stmt): void
    {
        // @phpstan-ignore-next-line -- this IS the centralized execute wrapper; self-routing would recurse
        if (!$stmt->execute()) {
            try {
                $error = $stmt->error;
            } catch (\Error) {
                $error = '(unknown)';
            }
            try {
                $errno = (int) $stmt->errno;
            } catch (\Error) {
                $errno = 0;
            }
            unset($this->boundValues[spl_object_id($stmt)]);
            $stmt->close();
            // The errno tag makes error-class detection (deadlock, duplicate
            // key, unknown column) locale-independent — the message text
            // follows the server's lc_messages setting.
            //
            // It is also carried as the exception CODE, which is what a
            // caller deciding whether to retry actually reaches for:
            // 202-config/install.php keys its transient-retry on
            // in_array($e->getCode(), [1205, 1213, 2006, 2013]), and a
            // hand-written execute check that threw with the errno as its
            // code is only replaceable by this one if the code survives.
            // Previously it was 0, so nothing can depend on the old value;
            // isMysqlError() reads the tag and the previous-chain either way.
            throw new QueryException(
                'MySQL execute failed: ' . $error . ($errno > 0 ? ' [errno ' . $errno . ']' : ''),
                $errno
            );
        }
        unset($this->boundValues[spl_object_id($stmt)]);
    }

    /**
     * True when the throwable (or anything in its previous-chain) is a MySQL
     * deadlock (1213) or lock-wait timeout (1205) — the retryable lock
     * errors. Works across both mysqli reporting modes this codebase runs
     * under: STRICT|ERROR paths surface mysqli_sql_exception with the errno
     * as its code; STRICT-only paths surface this class's QueryException,
     * detected via the [errno N] tag with an English-message fallback for
     * exceptions raised before the tag existed.
     */
    public static function isRetryableLockError(Throwable $e): bool
    {
        return self::isMysqlError($e, 1213, 'Deadlock found')
            || self::isMysqlError($e, 1205, 'Lock wait timeout');
    }

    /**
     * True when the throwable (or anything in its previous-chain) is the
     * given MySQL error, detected locale-independently: mysqli_sql_exception
     * carries the errno as its code (STRICT|ERROR reporting paths), this
     * class's QueryException carries the [errno N] tag (STRICT-only
     * paths), and the English message fragment covers exceptions raised
     * before the tag existed.
     */
    public static function isMysqlError(Throwable $e, int $errno, string $englishFragment): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \mysqli_sql_exception && (int) $current->getCode() === $errno) {
                return true;
            }
            $message = $current->getMessage();
            if (str_contains($message, '[errno ' . $errno . ']')
                || ($englishFragment !== '' && str_contains($message, $englishFragment))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A false get_result() after a SUCCESSFUL execute() is a legitimate
     * "nothing to read" only when the statement produced no result set at all
     * — an INSERT/UPDATE/DELETE, where field_count is 0. When the statement
     * DID produce one, false is a transport failure, and reading it as an
     * empty answer is the silent-failure shape CLAUDE.md error pattern #1
     * names by function: "false reads as an empty result set". It is not
     * hypothetical here — safeDeleteModel() reads [] as "no campaigns use
     * this model" and deletes it, and ltv_maintenance reads [] as "no owners
     * to sweep" and leaves churned MRR on the books, both while reporting
     * success. Checking it once, here, is what lets those callers stop
     * hand-rolling the check (and stop getting it wrong).
     *
     * Returning is the "this was legitimately empty" answer; throwing is
     * "I could not find out". How field_count is read, and why it is read
     * that way rather than plainly, is in the comment on the read itself.
     *
     * @param mysqli_stmt $stmt
     * @throws QueryException when a result set existed but could not be read
     */
    private function assertResultSetWasReadable(object $stmt): void
    {
        // isset(), not a bare read, and measured on all four shapes this is
        // called with rather than assumed. On a live statement it is true and
        // the value is right (2 for a two-column SELECT, 0 for an INSERT). On
        // a mysqli_stmt subclass that skipped the real constructor it is false
        // instead of throwing, and on a plain fake object with no such
        // property it is false WITHOUT emitting "Undefined property" — which a
        // bare read does, and which PHPUnit promotes to a test error. Same
        // constraint as ::$affected_rows in executeUpdate(); a double that
        // wants to exercise this path says so through a plain method, since
        // redeclaring the property in a subclass does not take (the internal
        // handler wins). Absent all of that, treat the statement as having
        // produced no result set — the pre-existing behaviour.
        if (method_exists($stmt, 'fieldCountFallback')) {
            $fieldCount = (int) $stmt->fieldCountFallback();
        } else {
            $fieldCount = isset($stmt->field_count) ? (int) $stmt->field_count : 0;
        }
        if ($fieldCount === 0) {
            return;
        }

        try {
            $error = $stmt->error;
        } catch (\Error) {
            $error = '(unknown)';
        }
        try {
            $errno = (int) $stmt->errno;
        } catch (\Error) {
            $errno = 0;
        }
        unset($this->boundValues[spl_object_id($stmt)]);
        // Closed before throwing, the same contract execute() keeps, so a
        // caller's catch never has to guess whether the statement is still open.
        $stmt->close();
        throw new QueryException(
            'MySQL result set could not be read: ' . $error . ($errno > 0 ? ' [errno ' . $errno . ']' : ''),
            $errno
        );
    }

    /**
     * Execute a statement, fetch a single row, and close the statement.
     *
     * Returns null when the query genuinely matched nothing. A result set
     * that could not be read raises QueryException instead — see
     * assertResultSetWasReadable().
     *
     * @param mysqli_stmt $stmt
     * @return array<string, mixed>|null
     * @throws QueryException if the execute fails or the result set is unreadable
     */
    public function fetchOne(object $stmt): ?array
    {
        $this->execute($stmt);
        $result = $stmt->get_result();
        if (!($result instanceof mysqli_result)) {
            $this->assertResultSetWasReadable($stmt);
            $stmt->close();

            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        $stmt->close();

        return $row ?? null;
    }

    /**
     * Execute a statement, fetch all rows, and close the statement.
     *
     * Returns [] when the query genuinely matched nothing. A result set that
     * could not be read raises QueryException instead — see
     * assertResultSetWasReadable().
     *
     * @param mysqli_stmt $stmt
     * @return list<array<string, mixed>>
     * @throws QueryException if the execute fails or the result set is unreadable
     */
    public function fetchAll(object $stmt): array
    {
        $this->execute($stmt);
        $result = $stmt->get_result();
        if (!($result instanceof mysqli_result)) {
            $this->assertResultSetWasReadable($stmt);
            $stmt->close();

            return [];
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();

        return $rows;
    }

    /**
     * Execute an INSERT statement, return the insert ID, and close.
     *
     * Falls back to the connection-level insert_id if the statement
     * does not report one (behaviour varies by mysqli driver version).
     *
     * @param mysqli_stmt $stmt
     * @throws QueryException if execute fails
     */
    public function executeInsert(object $stmt): int
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
     * @param mysqli_stmt $stmt
     * @throws QueryException if execute fails
     */
    public function executeUpdate(object $stmt): int
    {
        $this->execute($stmt);

        try {
            $affected = $stmt->affected_rows;
        } catch (\Error) {
            // Native mysqli_stmt::$affected_rows is a virtual property whose
            // read throws on statements that skipped the real constructor
            // (the test fakes; same constraint as ::$error). Those fakes can
            // expose the count through a plain method instead — callers like
            // the DimensionSync CAS persist branch on it to detect guard
            // misses, so it must be fakeable.
            $affected = method_exists($stmt, 'affectedRowsFallback') ? $stmt->affectedRowsFallback() : 0;
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
            throw new QueryException('Unable to begin transaction: ' . $error);
        }

        try {
            $result = $fn();

            if (!$this->write->commit()) {
                try {
                    $error = $this->write->error;
                } catch (\Error) {
                    $error = '(unknown)';
                }
                try {
                    $errno = (int) $this->write->errno;
                } catch (\Error) {
                    $errno = 0;
                }
                throw new QueryException(
                    'Unable to commit transaction: ' . $error . ($errno > 0 ? ' [errno ' . $errno . ']' : '')
                );
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
     * @throws QueryException if the prepare fails
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
            try {
                $errno = (int) $connection->errno;
            } catch (\Error) {
                $errno = 0;
            }
            // Errno as the exception CODE, for the same reason execute() does
            // it: a caller deciding whether to retry reaches for getCode()
            // (202-config/install.php keys its transient-retry on
            // in_array($e->getCode(), [1205, 1213, 2006, 2013])). A lost
            // connection — 2006/2013 — surfaces at prepare at least as often
            // as at execute, so leaving this at 0 made exactly those failures
            // look permanent. Additive: it was always 0.
            throw new QueryException(
                'Failed to prepare MySQL statement: ' . $error . ($errno > 0 ? ' [errno ' . $errno . ']' : ''),
                $errno
            );
        }

        return $stmt;
    }
}
