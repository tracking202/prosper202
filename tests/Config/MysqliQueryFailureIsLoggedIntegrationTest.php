<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * The admin pages' _mysqli_query() logs a statement that fails, naming its
 * caller, and still answers false.
 *
 * It answered false and said nothing, and most of its callers read false as
 * "no rows" or never look: a failed write on those pages left no trace. The
 * line carries the statement with its quoted values taken out, because some
 * of these statements carry a secret (the remember-me keys, the licence key).
 *
 * Run in a child process; see fixtures/mysqli-query-runner.php.
 *
 * @group integration
 */
final class MysqliQueryFailureIsLoggedIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
    }

    public function testAFailedStatementIsLoggedWithItsCallerAndWithoutItsValues(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'p202-mq-');
        self::assertIsString($log);
        try {
            $proc = proc_open(
                [
                    PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=' . $log, '-d', 'display_errors=stderr',
                    __DIR__ . '/fixtures/mysqli-query-runner.php',
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($proc);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            $logged = (string) file_get_contents($log);
        } finally {
            @unlink($log);
        }
        $all = "exit $exit\n$stdout$stderr$logged";
        self::assertSame(0, $exit, $all);

        // Still false to the caller, and true for a statement that worked.
        self::assertStringContainsString("secret FALSE\nescaped FALSE\nunclosed FALSE\nsuccess OK\n", $stdout, $all);
        self::assertStringContainsString(
            "caller FALSE\nduplicate FALSE\nrefused FALSE\nansi FALSE\nlong FALSE\nbackslash FALSE\nnbe FALSE\n",
            $stdout,
            $all
        );

        $lines = array_values(array_filter(
            explode("\n", $logged),
            static fn (string $l): bool => str_contains($l, 'MySQL query failed')
        ));
        self::assertCount(10, $lines, "one line for each failure, none for the success:\n$all");

        $runner = realpath(__DIR__ . '/fixtures/mysqli-query-runner.php');
        self::assertMatchesRegularExpression(
            '~MySQL query failed at ' . preg_quote((string) $runner, '~') . ':\d+ \[errno 1146\]: '
                . 'Table \S+p202_no_such_table_for_mysqli_query\S* doesn\'t exist \| '
                . preg_quote('UPDATE p202_no_such_table_for_mysqli_query SET secret_key = ? WHERE user_id = ?', '~')
                . '$~',
            $lines[0],
            $all
        );
        // An escaped quote: read without escapes, the quotes after it pair up
        // shifted, so the line stops where the two readings part.
        self::assertStringEndsWith(
            '| SELECT 1 FROM p202_no_such_table_for_mysqli_query WHERE name = ?…',
            $lines[1],
            $all
        );
        // A quote left open is taken out to the end, and the syntax error's
        // own quote of the statement with it.
        self::assertMatchesRegularExpression(
            '~\[errno 1064\]: You have an error in your SQL syntax; .* near \? at line 1 \| '
                . preg_quote('SELECT 1 FROM p202_no_such_table_for_mysqli_query WHERE name = ?', '~') . '$~',
            $lines[2],
            $all
        );

        // The caller's own line: the one-argument form, through the global connection.
        self::assertSame(1, preg_match('/^line (\d+)$/m', $stdout, $m), $all);
        self::assertStringContainsString(':' . $m[1] . ' [errno 1146]', $lines[3], $all);

        // The server's message repeats the value; the key's name is kept.
        // (MySQL 8 names the key with its table.)
        self::assertMatchesRegularExpression(
            "~\\[errno 1062\\]: Duplicate entry \\? for key '([^']*\\.)?PRIMARY' \\| "
                . preg_quote('INSERT INTO p202_mysqli_query_values (k) VALUES (?…', '~') . '$~',
            $lines[4],
            $all
        );
        self::assertMatchesRegularExpression(
            '~\[errno 1366\]: Incorrect integer value: \? (for column `[^\']*` )?at row 1 \| '
                . preg_quote('INSERT INTO p202_mysqli_query_values (k, n) VALUES (?, ?…', '~') . '$~',
            $lines[5],
            $all
        );
        // The value read as a column name: the message would name it.
        self::assertMatchesRegularExpression(
            '~\[errno 1054\]: Unknown column \? \| '
                . preg_quote('SELECT k FROM p202_mysqli_query_values WHERE k = ?', '~') . '$~',
            $lines[6],
            $all
        );
        // A 200,000-character value is taken out like any other, and a value
        // left open that ends in a backslash with the rest of the statement.
        self::assertStringEndsWith(
            '| UPDATE p202_no_such_table_for_mysqli_query SET v = ? WHERE secret_key = ?',
            $lines[7],
            $all
        );
        self::assertMatchesRegularExpression(
            '~\[errno 1064\]: .* \| '
                . preg_quote('SELECT 1 FROM p202_no_such_table_for_mysqli_query WHERE name = ?', '~') . '$~',
            $lines[8],
            $all
        );
        // A backslash left in place under NO_BACKSLASH_ESCAPES: read with
        // escapes, the value runs on and the next one is kept.
        self::assertStringEndsWith(
            '| SELECT 1 FROM p202_no_such_table_for_mysqli_query WHERE name = ?…',
            $lines[9],
            $all
        );
        self::assertStringNotContainsString('s3cr3t', $logged, "a quoted value reached the log:\n$all");
        self::assertStringNotContainsString('Brien', $logged, "a value's remainder reached the log:\n$all");
    }
}
