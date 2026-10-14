<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;

/**
 * The schema install goes on, logging it, when its own setup statements are
 * refused: the collation is already 202_ips_v6's own and the table has no
 * character column, and a table the server's mode refuses fails the install
 * where it is created. PartitionInstaller treats its sql_mode SET the same.
 *
 * Run in a child process; see fixtures/schema-installer-runner.php.
 *
 * @group integration
 */
final class SchemaInstallerSetupStatementsIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
    }

    public function testARefusedSetupStatementIsLoggedAndTheInstallGoesOn(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'p202-si-');
        self::assertIsString($log);
        try {
            $proc = proc_open(
                [
                    PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=' . $log, '-d', 'display_errors=stderr',
                    __DIR__ . '/fixtures/schema-installer-runner.php',
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
        self::assertSame("SUCCESS\n", $stdout, "the install failed over a setup statement:\n$all");
        self::assertStringContainsString(
            'SchemaInstaller: clearing sql_mode failed (logged by _mysqli_query()); the install goes on',
            $logged,
            $all
        );
        self::assertStringContainsString(
            'SchemaInstaller: setting the collation of 202_ips_v6 failed (logged by _mysqli_query()); '
                . 'the install goes on',
            $logged,
            $all
        );
    }
}
