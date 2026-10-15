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

if (!function_exists('p202RecordMysqlErrorRow')) {
    /**
     * Run record_mysql_error()'s write of the error row ($write: its lookups
     * and the INSERT into 202_mysql_errors) on the connection that has just
     * failed, so the error page is shown whatever happens there.
     *
     * The caller's transaction (begin_transaction() or autocommit(false)) is
     * rolled back and autocommit put back on first: a row written inside it
     * is discarded with it when the request ends.
     *
     * A failure while recording is logged, never thrown, and answered false
     * so the page does not say the error was recorded. On a lost connection
     * the lookups fail too: an uncaught Error on the admin pages, and on the
     * click path a second record_mysql_error() from its own _mysqli_query(),
     * which recursed until memory ran out. A call made while a row is being
     * recorded throws back to here instead.
     *
     * @param callable(): void $write throws when the row is not written
     * @return bool whether the row was written
     */
    function p202RecordMysqlErrorRow(\mysqli $db, callable $write): bool
    {
        // Never reset: record_mysql_error() ends the request, and a call from
        // the page it goes on to draw throws rather than draw a second one.
        static $recording = false;
        if ($recording) {
            throw new \RuntimeException('a statement run to record it failed as well');
        }
        $recording = true;

        try {
            if (!$db->rollback() || !$db->autocommit(true)) {
                throw new \RuntimeException('its transaction could not be rolled back: ' . $db->error);
            }
            // Connection::transaction() refuses to begin inside another:
            // there is none now, so a lookup may open its own.
            if (class_exists(\Prosper202\Database\Connection::class)) {
                \Prosper202\Database\Connection::transactionsEnded($db);
            }
            $write();
        } catch (\Throwable $e) {
            error_log('MySQL error not recorded in 202_mysql_errors: ' . $e->getMessage());

            return false;
        }

        return true;
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

if (!function_exists('p202RedactedStatement')) {
    /**
     * A statement for a log line or the error list, with the values it quotes
     * taken out: what is left names the table, and some statements carry a
     * secret (the remember-me keys, the licence key) or a visitor's data.
     * Cut to $bytes.
     */
    function p202RedactedStatement(string $sql, int $bytes = 500): string
    {
        // Cut to whole bytes, then off a character the cut split, by pattern
        // rather than mb_strcut(): this runs on every failed query, the
        // error page's included, and asks nothing of an extension.
        $cut = static fn (string $text, int $length): string => (string) preg_replace(
            '/(?:[\xC0-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF7][\x80-\xBF]{0,2})\z/',
            '',
            substr($text, 0, $length)
        );
        // A quote left open (a malformed statement, a value ending in a
        // backslash) is taken out to the end. Where a value ends depends on
        // the session's sql_mode: under NO_BACKSLASH_ESCAPES
        // real_escape_string() leaves `x\` as it is, and `'x\', k='s3cr3t'`
        // read with escapes keeps the second value. This does not ask which
        // mode the statement was escaped for, so it keeps only what a reading
        // with escapes and one without both leave in, up to where they part
        // (after an escaped quote, nothing). Possessive, so a long value is
        // not backtracked through: PCRE gives up past about 20,000 characters.
        $escaped = preg_replace('/\'(?:[^\'\\\\]++|\\\\.?)*+(?:\'|\z)|"(?:[^"\\\\]++|\\\\.?)*+(?:"|\z)/s', '?', $sql);
        $literal = $escaped === null ? null : preg_replace('/\'[^\']*+(?:\'|\z)|"[^"]*+(?:"|\z)/', '?', $sql);
        if ($escaped === null || $literal === null) {
            return '(a statement of ' . strlen($sql) . ' bytes, not shown: ' . preg_last_error_msg() . ')';
        }
        $shape = $escaped === $literal
            ? $escaped
            : $cut($escaped, strspn($escaped ^ $literal, "\0")) . '…';
        $shape = trim((string) preg_replace('/\s+/', ' ', $shape));

        return strlen($shape) > $bytes ? $cut($shape, $bytes - 3) . '…' : $shape;
    }
}

if (!function_exists('p202FailedQueryMessage')) {
    /**
     * The server's message for a failed statement, without the values it quotes.
     *
     * Many messages repeat a value from the statement as sent, its own quotes
     * unescaped: 1062 `Duplicate entry 'O'Brien' for key 'uk'`, 1366 and 1292
     * under a strict sql_mode, 1064's quote of the statement from where parsing
     * stopped. So everything from the first quote to the last is taken out,
     * except in the messages known to quote nothing but names, and a duplicate
     * entry keeps the name of its key.
     */
    function p202FailedQueryMessage(int $errno, string $message): string
    {
        // Unknown or duplicate database, table, column or key, an ambiguous
        // column; a column refused a null, a default, a long or a truncated value;
        // a foreign key. Not 1054 (unknown column): under ANSI_QUOTES a
        // double-quoted value is read as a column, and its message repeats it.
        $namesOnly = [1048, 1049, 1050, 1051, 1052, 1060, 1061, 1091, 1146, 1265, 1364, 1406, 1451, 1452];
        if (in_array($errno, $namesOnly, true)) {
            return $message;
        }
        // A caught failure (Connection's) puts its words before the server's
        // and its errno tag after them; neither quotes anything.
        $duplicate = "/\\A([^'\"]*)Duplicate entry '.*' for key ('[^']*')( \\[errno 1062\\])?\\z/s";
        if ($errno === 1062 && preg_match($duplicate, $message, $m) === 1) {
            return $m[1] . 'Duplicate entry ? for key ' . $m[2] . ($m[3] ?? '');
        }

        // From the first quote to the last, or to the end when there is one.
        $first = strcspn($message, '\'"');
        if ($first === strlen($message)) {
            return $message;
        }
        $last = max((int) strrpos($message, "'"), (int) strrpos($message, '"'));

        return substr($message, 0, $first) . '?' . ($last > $first ? substr($message, $last + 1) : '');
    }
}

if (!function_exists('p202MysqlErrorRecorded')) {
    /**
     * What record_mysql_error() logs and stores of a failure, both
     * definitions alike: the server's message (or the failure the caller
     * caught, whose `[errno N]` it reads) and the statement, without the
     * values they quote.
     *
     * @return array{0: string, 1: string} the message, the statement
     */
    function p202MysqlErrorRecorded(\mysqli $db, string $sql, ?string $error): array
    {
        if ($error !== null && $error !== '') {
            // The tag Connection appends, so the last thing in the message:
            // the server's words before it can quote a visitor's value, and
            // a tag inside that value naming an errno kept as written would
            // keep the value too. No tag there redacts everything quoted.
            $errno = preg_match('/ \[errno (\d+)\]\z/', $error, $m) === 1 ? (int) $m[1] : 0;
            $message = $error;
        } else {
            $errno = mysqli_errno($db);
            $message = mysqli_error($db);
        }

        // The error list's column is a TEXT: 65,535 bytes.
        return [p202FailedQueryMessage($errno, $message), p202RedactedStatement($sql, 65000)];
    }
}
