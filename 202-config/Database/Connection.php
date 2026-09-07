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
 *
 * Not final: statementError()/statementErrno() are protected so a test can
 * stand in for mysqli_stmt::$error/$errno, which throw on every
 * constructor-skipping fake. Those two readers are the only sanctioned
 * override point; everything else is an implementation detail.
 */
class Connection
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
            $error = $this->statementError($stmt);
            $errno = $this->statementErrno($stmt);
            unset($this->boundValues[spl_object_id($stmt)]);
            $stmt->close();
            // The errno tag makes error-class detection (deadlock, duplicate
            // key, unknown column) locale-independent — the message text
            // follows the server's lc_messages setting.
            throw new QueryException(
                'MySQL execute failed: ' . $error . ($errno > 0 ? ' [errno ' . $errno . ']' : '')
            );
        }
        unset($this->boundValues[spl_object_id($stmt)]);
    }

    /**
     * A false from get_result() after a successful execute() means one of two
     * very different things: the statement produced no result set (an INSERT
     * or UPDATE -- not an error), or the fetch failed (a lost connection, a
     * server that went away mid-query). Reading them both as "no rows" is
     * CLAUDE.md #1's silent-failure tell -- publicIdIsFree() would report a
     * taken id as free, a batch loop would end early and report success. The
     * statement's errno tells them apart.
     *
     * @param mysqli_stmt $stmt
     * @throws QueryException when the fetch failed
     */
    private function assertResultRetrieved(object $stmt, mysqli_result|false $result): void
    {
        if ($result !== false) {
            return;
        }
        $errno = $this->statementErrno($stmt);
        if ($errno === 0) {
            return; // no result set, and MySQL reports no error: a legitimate empty answer
        }
        $error = $this->statementError($stmt);
        $stmt->close();
        throw new QueryException('MySQL get_result failed: ' . $error . ' [errno ' . $errno . ']');
    }

    /**
     * mysqli_stmt::$error, or '(unknown)' where the property cannot be read.
     * Native mysqli_stmt properties throw on a statement whose constructor was
     * skipped (test fakes) and on a closed one; the guard keeps the diagnostic
     * from replacing the failure it was meant to describe. Protected so a test
     * can stand in for the value a fake physically cannot carry.
     *
     * @param mysqli_stmt $stmt
     */
    protected function statementError(object $stmt): string
    {
        try {
            // `??` rather than a bare read: a plain-object test fake without the
            // property must not raise "Undefined property" (a warning PHPUnit
            // promotes to a failure), and on a constructor-skipping native fake
            // isset() answers false instead of throwing.
            return (string) ($stmt->error ?? '(unknown)');
        } catch (\Error) {
            return '(unknown)';
        }
    }

    /**
     * mysqli_stmt::$errno, or 0 where the property cannot be read (see
     * statementError()). 0 is deliberately "no error": a fake that cannot
     * report an errno must not make every fetch look failed.
     *
     * @param mysqli_stmt $stmt
     */
    protected function statementErrno(object $stmt): int
    {
        try {
            return (int) ($stmt->errno ?? 0); // see statementError() on `??`
        } catch (\Error) {
            return 0;
        }
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
     * Execute a statement, fetch a single row, and close the statement.
     *
     * @param mysqli_stmt $stmt
     * @return array<string, mixed>|null
     */
    public function fetchOne(object $stmt): ?array
    {
        $this->execute($stmt);
        $result = $stmt->get_result();
        $this->assertResultRetrieved($stmt, $result);
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
     * @param mysqli_stmt $stmt
     * @return list<array<string, mixed>>
     */
    public function fetchAll(object $stmt): array
    {
        $this->execute($stmt);
        $result = $stmt->get_result();
        $this->assertResultRetrieved($stmt, $result);
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
            throw new QueryException(
                'Failed to prepare MySQL statement: ' . $error . ($errno > 0 ? ' [errno ' . $errno . ']' : '')
            );
        }

        return $stmt;
    }
}
