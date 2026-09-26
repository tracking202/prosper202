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
 * @group integration
 */
final class RecordMysqlErrorIntegrationTest extends TestCase
{
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
        $log = tempnam(sys_get_temp_dir(), 'p202-rme-');
        self::assertIsString($log);
        try {
            $cmd = [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=1', '-d', 'error_log=' . $log,
                __DIR__ . '/fixtures/record-mysql-error-runner.php',
                __DIR__ . '/../../202-config/' . $file,
                $shape,
            ];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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

        $all = $stdout . $stderr . $logged;
        self::assertStringNotContainsString('could not be converted to string', $all, "a fatal instead of the error page:\n$all");
        self::assertStringContainsString('BEFORE', $stdout, "the runner did not reach the call:\n$all");
        self::assertStringNotContainsString('UNREACHED', $stdout, 'record_mysql_error() must end the request');
        self::assertSame(0, $exit, "the error page exits cleanly:\n$all");
        // The page as U8 renders it in both definitions: the danger flash,
        // its sentence inside the flash's own body, not merely somewhere.
        self::assertMatchesRegularExpression(
            '~<div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i><div class="p202-flash__body"><strong>A database error has occurred, and it has been recorded\.</strong>~',
            $stdout,
            "the error page is shown:\n$all"
        );
        // The database's own words, not a blank: proves the connection that
        // failed is the one read.
        self::assertMatchesRegularExpression(
            "/MySQL error: .*p202_no_such_table_for_record_mysql_error.*doesn't exist/",
            $logged,
            "the real error is logged:\n$all"
        );
        if ($shape === 'db') {
            self::assertStringContainsString('SQL: (statement not given)', $logged);
        } else {
            self::assertStringContainsString('SQL: SELECT nothing FROM p202_no_such_table_for_record_mysql_error', $logged);
        }
    }
}
