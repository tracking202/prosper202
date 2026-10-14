<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Runs both shipped record_mysql_error() definitions against a real failed
 * query, in every call shape the tree uses, and reads what they log.
 *
 * `$db->query(...) or record_mysql_error($db)` is the shape of dozens of
 * sites on the redirect path (and of the begin_transaction()/commit() guards
 * there). It used to cast the connection to a string — an uncaught Error in
 * place of the error page, with the database's own error never logged.
 *
 * Each run is a child process (the function is `never` and dies); see
 * fixtures/record-mysql-error-runner.php for how the real body is run.
 *
 * The error row was written into whatever transaction the caller had open
 * and discarded with it when the request ended, so the page said "it has
 * been recorded" over nothing; and a failure while recording it was a fatal
 * in place of the page (admin pages) or record_mysql_error() calling itself
 * through _mysqli_query() until memory ran out (click path, tens of
 * thousands of log lines). The scenarios below are those, each run against
 * both definitions.
 *
 * @group integration
 */
final class RecordMysqlErrorIntegrationTest extends TestCase
{
    /** The statement the runner fails, as it asks it. */
    private const FAILING = 'SELECT nothing FROM p202_no_such_table_for_record_mysql_error';

    protected function setUp(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
    }

    /** @return array<string, array{string, string}> */
    public static function definitionsAndShapes(): array
    {
        $cases = [];
        foreach (['functions-tracking202.php', 'connect2.php'] as $file) {
            foreach (['db', 'db_sql', 'sql'] as $shape) {
                $cases["$file ($shape)"] = [$file, $shape];
            }
        }

        return $cases;
    }

    /** @dataProvider definitionsAndShapes */
    public function testTheDatabaseErrorIsLoggedAndTheErrorPageShown(string $file, string $shape): void
    {
        [$stdout, $logged, $all] = self::runScenario($file, $shape, 'page', 1);

        self::assertStringNotContainsString(
            'could not be converted to string',
            $all,
            "a fatal instead of the error page:\n$all"
        );
        self::assertTheErrorPageEndedTheRequest($stdout, $all, true);
        // The database's own words, not a blank: proves the connection that
        // failed is the one read.
        self::assertMatchesRegularExpression(
            "/MySQL error: .*p202_no_such_table_for_record_mysql_error.*doesn't exist/",
            $logged,
            "the real error is logged:\n$all"
        );
        // The page asks the failed connection nothing more, the admin page's
        // footer included: a read there fails the same way and throws, a
        // fatal in place of this page. The first line proves the runner's
        // recording saw the runner's query.
        self::assertStringContainsString('ASKED FIRST: ' . self::FAILING . "\n", $stdout);
        self::assertStringNotContainsString(
            'ASKED BY THE PAGE: ',
            $stdout,
            "the error page queried the database that just failed:\n$all"
        );
        // Its record's own lookups are the repositories', on that connection.
        self::assertStringContainsString('LOOKED UP BY THE RECORD: ', $stdout, $all);
        // What it does do to the connection: end its transaction.
        self::assertStringContainsString(
            "ENDED BY THE PAGE: rollback\nENDED BY THE PAGE: autocommit on\n",
            $stdout,
            $all
        );
        if ($shape === 'db') {
            self::assertStringContainsString('SQL: (statement not given)', $logged);
        } else {
            self::assertStringContainsString('SQL: ' . self::FAILING, $logged);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function definitionsAndOpenTransactions(): array
    {
        $cases = [];
        foreach (['functions-tracking202.php', 'connect2.php'] as $file) {
            foreach (['txn', 'autocommit_off'] as $scenario) {
                $cases["$file ($scenario)"] = [$file, $scenario];
            }
        }

        return $cases;
    }

    /**
     * Called with the caller's transaction open and a write of its own in
     * it: the write is rolled back and the error row is written, and kept.
     *
     * @dataProvider definitionsAndOpenTransactions
     */
    public function testAnOpenTransactionIsRolledBackAndTheErrorRowKept(string $file, string $scenario): void
    {
        $marker = random_int(1_000_000, 16_000_000);
        [$stdout, , $all] = self::runScenario($file, 'db_sql', $scenario, $marker);

        self::assertTheErrorPageEndedTheRequest($stdout, $all, true);
        self::assertStringNotContainsString('RECORD FAILED', $stdout, $all);
        self::assertStringNotContainsString('ASKED BY THE PAGE: ', $stdout, $all);

        $db = self::database();
        $row = $db->query(
            'SELECT e.mysql_error_text, e.mysql_error_sql, i.ip_address, u.site_url_address FROM 202_mysql_errors e'
            . ' LEFT JOIN 202_ips i ON i.ip_id = e.ip_id LEFT JOIN 202_site_urls u ON u.site_url_id = e.site_id'
            . ' WHERE e.user_id = ' . $marker
        );
        self::assertInstanceOf(\mysqli_result::class, $row, $db->error);
        $rows = $row->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows, "the error row is recorded and kept:\n$all");
        self::assertMatchesRegularExpression(
            "/p202_no_such_table_for_record_mysql_error.*doesn't exist/",
            (string) $rows[0]['mysql_error_text']
        );
        self::assertSame(self::FAILING, $rows[0]['mysql_error_sql']);
        // The request's address and URL, resolved by the record's lookups.
        self::assertSame('203.0.113.7', $rows[0]['ip_address'], $all);
        self::assertSame('http://example.test/x', $rows[0]['site_url_address'], $all);

        $probe = $db->query('SELECT COUNT(*) FROM p202_rme_probe WHERE marker = ' . $marker);
        self::assertInstanceOf(\mysqli_result::class, $probe, $db->error);
        self::assertSame(0, (int) $probe->fetch_row()[0], "the caller's own write is not committed with it:\n$all");
        $db->close();
    }

    /**
     * A caller that caught the failure (the rotator writer's, rolled back
     * before the page runs) names it: the row holds it as the error, and
     * what was being done as the statement, where the error text was blank.
     *
     * @dataProvider definitions
     */
    public function testACaughtFailureIsRecordedAsTheError(string $file): void
    {
        $marker = random_int(1_000_000, 16_000_000);
        [$stdout, $logged, $all] = self::runScenario($file, 'caught', 'txn', $marker);

        self::assertTheErrorPageEndedTheRequest($stdout, $all, true);
        $db = self::database();
        $row = $db->query('SELECT mysql_error_text, mysql_error_sql FROM 202_mysql_errors WHERE user_id = ' . $marker);
        self::assertInstanceOf(\mysqli_result::class, $row, $db->error);
        self::assertSame(
            [['mysql_error_text' => 'the failure the caller caught', 'mysql_error_sql' => 'recording the probe']],
            $row->fetch_all(MYSQLI_ASSOC),
            $all
        );
        self::assertStringContainsString(
            'MySQL error: the failure the caller caught | SQL: recording the probe',
            $logged,
            $all
        );
        $db->close();
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function secretsByDefinition(): array
    {
        $cases = [];
        foreach (['functions-tracking202.php', 'connect2.php'] as $file) {
            $cases["$file (the failing statement)"] = [
                $file, 'db_sql_secret',
                self::FAILING . ' WHERE k = ?',
                "/^Table '[^']+\\.p202_no_such_table_for_record_mysql_error' doesn't exist\\z/",
            ];
            $cases["$file (a caught failure)"] = [
                $file, 'caught_secret',
                'UPDATE p202_probe SET k = ? WHERE id = 1',
                "/^MySQL execute failed: Duplicate entry \\? for key 'uk' \\[errno 1062\\]\\z/",
            ];
            // A visitor's value inside the server's words, carrying a tag
            // that names an errno kept as written: the tag read is the last.
            $cases["$file (a caught failure quoting a forged errno tag)"] = [
                $file, 'caught_forged_tag',
                'UPDATE p202_probe SET k = ? WHERE id = 1',
                "/^MySQL execute failed: Duplicate entry \\? for key 'uk' \\[errno 1062\\]\\z/",
            ];
        }

        return $cases;
    }

    /**
     * The page's log line and its row hold the statement and the server's
     * words without the values they quote, as _mysqli_query()'s line does:
     * both had them as sent, and the row is listed to every admin.
     *
     * @dataProvider secretsByDefinition
     */
    public function testTheValuesAFailureQuotesAreNeitherLoggedNorStored(
        string $file,
        string $shape,
        string $statement,
        string $message
    ): void {
        $marker = random_int(1_000_000, 16_000_000);
        [$stdout, $logged, $all] = self::runScenario($file, $shape, 'txn', $marker);

        self::assertTheErrorPageEndedTheRequest($stdout, $all, true);
        $db = self::database();
        $row = $db->query('SELECT mysql_error_text, mysql_error_sql FROM 202_mysql_errors WHERE user_id = ' . $marker);
        self::assertInstanceOf(\mysqli_result::class, $row, $db->error);
        $rows = $row->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows, $all);
        self::assertSame($statement, $rows[0]['mysql_error_sql'], $all);
        self::assertMatchesRegularExpression($message, (string) $rows[0]['mysql_error_text'], $all);
        self::assertStringContainsString(
            'MySQL error: ' . $rows[0]['mysql_error_text'] . ' | SQL: ' . $statement,
            $logged,
            $all
        );
        self::assertStringNotContainsString('s3cr3t', $logged . json_encode($rows), $all);
        $db->close();
    }

    /**
     * An empty caught failure names nothing: the connection's own error is
     * recorded, as when no failure is given.
     *
     * @dataProvider definitions
     */
    public function testAnEmptyCaughtFailureFallsBackToTheConnectionsError(string $file): void
    {
        $marker = random_int(1_000_000, 16_000_000);
        [$stdout, , $all] = self::runScenario($file, 'caught_blank', 'txn', $marker);

        self::assertTheErrorPageEndedTheRequest($stdout, $all, true);
        $db = self::database();
        $row = $db->query('SELECT mysql_error_text FROM 202_mysql_errors WHERE user_id = ' . $marker);
        self::assertInstanceOf(\mysqli_result::class, $row, $db->error);
        self::assertMatchesRegularExpression(
            "/p202_no_such_table_for_record_mysql_error.*doesn't exist/",
            (string) ($row->fetch_row()[0] ?? ''),
            $all
        );
        $db->close();
    }

    /** @return array<string, array{string, string}> */
    public static function definitionsAndFailedRecords(): array
    {
        $cases = [];
        foreach (['functions-tracking202.php', 'connect2.php'] as $file) {
            foreach (['gone', 'lookup_fails'] as $scenario) {
                $cases["$file ($scenario)"] = [$file, $scenario];
            }
        }

        return $cases;
    }

    /**
     * The error row cannot be written — the connection is gone, or the
     * record's own lookup fails — and the page is still shown, saying so,
     * with the failure logged once and no second record_mysql_error() pass.
     *
     * @dataProvider definitionsAndFailedRecords
     */
    public function testARecordThatFailsStillEndsWithTheErrorPage(string $file, string $scenario): void
    {
        [$stdout, $logged, $all] = self::runScenario($file, 'db_sql', $scenario, random_int(1_000_000, 16_000_000));

        self::assertStringNotContainsString('Fatal error', $all);
        self::assertTheErrorPageEndedTheRequest($stdout, $all, false);
        // Which failure: a lost connection's transaction cannot be rolled
        // back; a refused lookup throws from the repository, in both copies.
        $why = $scenario === 'gone'
            ? 'its transaction could not be rolled back: '
            : "Failed to prepare MySQL statement: Unknown column 'ip_id'";
        self::assertStringContainsString('MySQL error not recorded in 202_mysql_errors: ' . $why, $logged, $all);
        // The error itself, once: the lookup's failure is not a second pass
        // through record_mysql_error(), let alone the thousands a recursion writes.
        self::assertSame(1, substr_count($logged, 'MySQL error: '), $all);
    }

    /** @return array<string, array{string}> */
    public static function definitions(): array
    {
        return ['functions-tracking202.php' => ['functions-tracking202.php'], 'connect2.php' => ['connect2.php']];
    }

    /**
     * The error row's INSERT refused: the page says it could not be
     * recorded, and the line saying why is the recorder's own, not the
     * server's words about a statement that carries the failed one.
     *
     * @dataProvider definitions
     */
    public function testARefusedErrorRowIsLoggedWithoutTheServersWords(string $file): void
    {
        $marker = random_int(1_000_000, 16_000_000);
        [$stdout, $logged, $all] = self::runScenario($file, 'db_sql', 'insert_fails', $marker);

        self::assertTheErrorPageEndedTheRequest($stdout, $all, false);
        self::assertStringContainsString(
            'MySQL error not recorded in 202_mysql_errors: the server refused the row',
            $logged,
            $all
        );
        self::assertStringNotContainsString('p202_no_such_table_for_the_error_row', $logged, $all);
    }

    /** @return array{string, string, string} stdout, the error log, and both with stderr */
    private static function runScenario(string $file, string $shape, string $scenario, int $marker): array
    {
        $log = tempnam(sys_get_temp_dir(), 'p202-rme-');
        self::assertIsString($log);
        try {
            $cmd = [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=1', '-d', 'error_log=' . $log,
                '-d', 'memory_limit=128M',
                __DIR__ . '/fixtures/record-mysql-error-runner.php',
                __DIR__ . '/../../202-config/' . $file,
                $shape,
                $scenario,
            ];
            $env = getenv();
            $env['P202_RME_MARKER'] = (string) $marker;
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
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

        $all = "exit $exit\n" . $stdout . $stderr . substr($logged, 0, 4000);
        self::assertSame(0, $exit, "the error page exits cleanly:\n$all");
        // A function the runner did not provide reads as a failure to record,
        // which some scenarios expect: it must not be why.
        self::assertStringNotContainsString('Call to undefined function', $stdout . $stderr . $logged, $all);
        if ($file !== 'connect2.php') {
            // The admin page goes on to its footer, which it draws to the end.
            self::assertStringContainsString('</footer>', $stdout, $all);
            self::assertStringContainsString('</html>', $stdout, $all);
        }

        return [$stdout, $logged, $all];
    }

    private static function assertTheErrorPageEndedTheRequest(string $stdout, string $all, bool $recorded): void
    {
        self::assertStringContainsString('BEFORE', $stdout, "the runner did not reach the call:\n$all");
        self::assertStringNotContainsString('UNREACHED', $stdout, 'record_mysql_error() must end the request');
        // The page as U8 renders it in both definitions: the danger flash,
        // its sentence inside the flash's own body, not merely somewhere;
        // and it says the error was recorded only when it was.
        $said = $recorded ? 'has been recorded' : 'could not be recorded; the server error log has it';
        self::assertMatchesRegularExpression(
            '~<div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i>'
                . '<div class="p202-flash__body">'
                . '<strong>A database error has occurred, and it ' . preg_quote($said, '~') . '\.</strong>~',
            $stdout,
            "the error page is shown:\n$all"
        );
    }

    private static function database(): \mysqli
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertSame(0, $db->connect_errno, (string) $db->connect_error);

        return $db;
    }
}
