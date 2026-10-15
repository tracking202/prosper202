<?php

declare(strict_types=1);

namespace Prosper202\Database;

use LogicException;
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
 * It is the one place a transaction begins or ends
 * (TransactionsBeginOnlyInConnectionTest): mysqli's begin_transaction()
 * inside an open transaction commits it, so a transaction opened anywhere
 * else is one transaction() cannot refuse to nest inside.
 */
final class Connection
{
    /**
     * The connections a transaction() is open on. One handle is wrapped by
     * many Connections, so it is kept per handle, not per wrapper. The value
     * is true for an open transaction, or why the handle may still hold
     * writes nobody will commit or roll back (a rollback that failed).
     *
     * @var \WeakMap<mysqli, true|string>|null
     */
    private static ?\WeakMap $open = null;

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
        if (!$stmt->execute()) {
            $error = self::text(static fn (): string => $stmt->error, '(unknown)');
            $errno = self::number(static fn (): int => $stmt->errno);
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
        $result = $this->resultSet($stmt);
        $row = $result->fetch_assoc();
        if ($row === false) {
            $this->fetchFailed($stmt, $result);
        }
        $result->free();
        $stmt->close();

        return $row;
    }

    /**
     * fetch_assoc() answers null for "no more rows" and false for a fetch
     * that failed, which must not read as the end of the rows.
     *
     * @param mysqli_stmt $stmt
     * @throws QueryException always
     */
    private function fetchFailed(object $stmt, mysqli_result $result): never
    {
        $error = self::text(static fn (): string => $stmt->error, '');
        $result->free();
        $stmt->close();
        throw new QueryException('MySQL fetch failed: ' . ($error !== '' ? $error : '(unknown)'));
    }

    /**
     * The result set of an executed read, or a QueryException.
     *
     * A SELECT that matched nothing is a mysqli_result with no rows; `false`
     * from get_result() means the fetch itself failed (no mysqlnd, a lost
     * connection, an out-of-memory result). Reading that false as "no rows"
     * turned a database failure into a silent empty answer — a click lookup
     * reported "no such click" and the conversion was dropped (CLAUDE.md
     * error pattern #1: a false that is indistinguishable from a legitimate
     * empty answer). It is an error, so it throws.
     *
     * @param mysqli_stmt $stmt
     * @throws QueryException
     */
    private function resultSet(object $stmt): mysqli_result
    {
        $result = $stmt->get_result();
        if (!$result instanceof mysqli_result) {
            $error = self::text(static fn (): string => $stmt->error, '(unknown)');
            $stmt->close();
            throw new QueryException('MySQL get_result failed: ' . ($error !== '' ? $error : '(no result set)'));
        }
        return $result;
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
        $result = $this->resultSet($stmt);
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            if ($row === false) {
                $this->fetchFailed($stmt, $result);
            }
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

        $insertId = self::number(static fn (): int|string => $stmt->insert_id);
        if ($insertId === 0) {
            $write = $this->write;
            $insertId = self::number(static fn (): int|string => $write->insert_id);
        }

        $stmt->close();

        return $insertId;
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

        // Native mysqli_stmt::$affected_rows is a virtual property whose read
        // throws on statements that skipped the real constructor (the test
        // fakes). Those fakes can expose the count through a plain method
        // instead — callers like the DimensionSync CAS persist branch on it
        // to detect guard misses, so it must be fakeable.
        $fallback = method_exists($stmt, 'affectedRowsFallback') ? $stmt->affectedRowsFallback() : 0;
        $affected = self::number(static fn (): int|string => $stmt->affected_rows, is_int($fallback) ? $fallback : 0);

        $stmt->close();

        return $affected;
    }

    /**
     * Run $fn in a transaction of its own: begin, commit, and roll back on
     * any throwable (which is passed on). A value returned from here has
     * been committed.
     *
     * Refused inside another transaction on the same connection, before
     * anything is written: mysqli's begin_transaction() there commits the
     * outer one (CLAUDE.md #13), whose rollback then undoes nothing it had
     * done so far. Work that belongs to the caller's transaction runs
     * through joinTransaction() instead.
     *
     * A rollback that fails leaves the transaction's writes pending on the
     * handle, so that is thrown (with the callback's throwable as previous)
     * and the handle stays marked with why: a later transaction() is refused
     * rather than beginning, which would commit them, and joinTransaction()
     * rather than adding to them.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws LogicException inside another transaction on this connection
     * @throws Throwable re-throws whatever the callback threw after rollback
     * @throws QueryException when the rollback itself fails
     */
    public function transaction(callable $fn): mixed
    {
        $this->refuseAfterFailedRollback();
        if ($this->inTransaction()) {
            throw new LogicException(
                'A transaction was asked for inside another on the same connection; beginning it would'
                    . ' commit the outer one. Run work that belongs to the caller\'s transaction with'
                    . ' Connection::joinTransaction().'
            );
        }
        if (!$this->write->begin_transaction()) {
            throw $this->boundaryFailed('Unable to begin transaction');
        }
        $open = self::$open ??= new \WeakMap();
        $open[$this->write] = true;
        $rolledBack = true;

        try {
            $result = $fn();
            if (!$this->write->commit()) {
                throw $this->boundaryFailed('Unable to commit transaction');
            }

            return $result;
        } catch (Throwable $e) {
            try {
                $rolledBack = $this->write->rollback();
            } catch (Throwable) {
                $rolledBack = false;
            }
            if (!$rolledBack) {
                $failed = $this->boundaryFailed('Unable to roll back transaction after "' . $e->getMessage() . '"');
                $open[$this->write] = $failed->getMessage();
                throw new QueryException($failed->getMessage(), $failed->getCode(), $e);
            }
            throw $e;
        } finally {
            if ($rolledBack) {
                unset($open[$this->write]);
            }
        }
    }

    /**
     * Run $fn inside the transaction the caller holds on this connection:
     * no begin and no commit of its own, so its writes commit or roll back
     * with the caller's. Refused when there is none, where each of its
     * statements would commit on its own.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws LogicException outside a transaction on this connection
     */
    public function joinTransaction(callable $fn): mixed
    {
        $this->refuseAfterFailedRollback();
        if (!$this->inTransaction()) {
            throw new LogicException(
                'Work meant for the caller\'s transaction was run outside one; each of its statements would'
                    . ' commit on its own. Run it inside Connection::transaction().'
            );
        }

        return $fn();
    }

    /** Whether a transaction() is open on this connection's write handle. */
    public function inTransaction(): bool
    {
        return self::$open !== null && isset(self::$open[$this->write]);
    }

    /**
     * Neither begin a transaction nor join one on a handle whose rollback
     * failed: its writes may still be pending, and a begin would commit them.
     */
    private function refuseAfterFailedRollback(): void
    {
        $why = self::$open[$this->write] ?? true;
        if (is_string($why)) {
            throw new LogicException(
                'A rollback on this connection failed (' . $why . '), so its writes may still be pending;'
                    . ' no transaction is begun or joined on it until the error page ends them.'
            );
        }
    }

    /**
     * The transaction open on $connection was ended without transaction():
     * the database error page rolls back its caller's before it records, and
     * its own lookups then run outside any.
     */
    public static function transactionsEnded(mysqli $connection): void
    {
        if (self::$open !== null) {
            unset(self::$open[$connection]);
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
            $error = self::text(static fn (): string => $connection->error, '(unknown)');
            $errno = self::number(static fn (): int => $connection->errno);
            throw new QueryException(
                'Failed to prepare MySQL statement: ' . $error . ($errno > 0 ? ' [errno ' . $errno . ']' : '')
            );
        }

        return $stmt;
    }

    /** A failed begin or commit, with the server's errno as its tag and its code. */
    private function boundaryFailed(string $what): QueryException
    {
        $write = $this->write;
        $error = self::text(static fn (): string => $write->error, '(unknown)');
        $errno = self::number(static fn (): int => $write->errno);

        return new QueryException($what . ': ' . $error . ($errno > 0 ? ' [errno ' . $errno . ']' : ''), $errno);
    }

    /**
     * A handle's error text. Reading it throws \Error on a closed handle and
     * on a test double that skipped the native constructor.
     *
     * @param \Closure(): string $read
     */
    private static function text(\Closure $read, string $otherwise): string
    {
        try {
            return $read();
        } catch (\Error) {
            return $otherwise;
        }
    }

    /**
     * A handle's errno, insert id or row count, read as text() reads.
     *
     * @param \Closure(): (int|string) $read
     */
    private static function number(\Closure $read, int $otherwise = 0): int
    {
        try {
            return (int) $read();
        } catch (\Error) {
            return $otherwise;
        }
    }
}
