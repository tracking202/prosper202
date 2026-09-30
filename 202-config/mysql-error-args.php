<?php

declare(strict_types=1);

if (!function_exists('p202MysqlErrorArgs')) {
    /**
     * Read record_mysql_error()'s call shapes into a connection and a
     * statement text, without ever casting the connection to a string.
     *
     * The function was written for ($sql) and ($db, $sql), and it decided
     * which by whether a second argument was passed. Dozens of call sites pass the
     * connection alone — `$db->query(...) or record_mysql_error($db)`, and
     * `$db->begin_transaction() or record_mysql_error($db)` — which took the
     * ($sql) branch and ran `(string) $db`: mysqli has no __toString(), so the
     * error page became an uncaught Error and the real database error was
     * never logged. A connection is recognised by its type, wherever it is.
     *
     * Both record_mysql_error() definitions (functions-tracking202.php for the
     * admin pages, connect2.php for the redirect path) read their arguments
     * through this, so they cannot drift apart again.
     *
     * @return array{0: ?\mysqli, 1: string} The connection the caller named
     *         (null when it named none, so the caller falls back to its own),
     *         and a printable statement text.
     */
    function p202MysqlErrorArgs(mixed $dbOrSql, mixed $sql = null): array
    {
        if ($dbOrSql instanceof \mysqli) {
            return [$dbOrSql, p202MysqlErrorStatementText($sql)];
        }
        if ($sql === null) {
            return [null, p202MysqlErrorStatementText($dbOrSql)];
        }

        return [null, p202MysqlErrorStatementText($sql)];
    }
}

if (!function_exists('p202MysqlErrorStatementText')) {
    /**
     * A statement for the error log. A caller that passed nothing printable
     * (no statement, or a query result by mistake) is named as such rather
     * than logged as an empty string that reads like a blank query.
     */
    function p202MysqlErrorStatementText(mixed $sql): string
    {
        if (is_string($sql) && $sql !== '') {
            return $sql;
        }
        if (is_int($sql) || is_float($sql)) {
            return (string) $sql;
        }

        return $sql === null || $sql === ''
            ? '(statement not given)'
            : '(statement not given: got ' . get_debug_type($sql) . ')';
    }
}
