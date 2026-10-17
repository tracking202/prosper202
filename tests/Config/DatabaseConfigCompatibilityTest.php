<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

final class DatabaseConfigCompatibilityTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?string, int}> */
    public static function configurations(): iterable
    {
        yield 'retained config without a replica method' => ['legacy', 'primary', null, 0];
        yield 'config with a distinct replica' => ['replica', 'replica', null, 1];
        yield 'primary failure in a retained config' => [
            'legacy-primary-failure', null,
            'cannot connect to the database named in 202-config.php: MySQL Connection Error (2002): '
                . 'primary unavailable',
            0,
        ];
        yield 'primary failure before opening a replica' => [
            'primary-failure', null,
            'cannot connect to the database named in 202-config.php: MySQL Connection Error (2002): '
                . 'primary unavailable',
            0,
        ];
        yield 'declared replica failure is still reported' => [
            'replica-failure', null,
            'cannot connect to the database named in 202-config.php: replica unavailable',
            1,
        ];
    }

    /** @dataProvider configurations */
    public function testTheBootstrapUsesTheConnectionsTheConfigProvides(
        string $shape,
        ?string $readRole,
        ?string $error,
        int $replicaCalls
    ): void {
        $source = (string) file_get_contents(__DIR__ . '/../../202-config/connect.php');
        $start = strpos($source, "try {\n    \$database = DB::getInstance();");
        self::assertIsInt($start);
        $end = strpos($source, "\n// Initialize \$skip_upgrade", $start);
        self::assertIsInt($end);
        $block = substr($source, $start, $end - $start);

        $runner = <<<'CODE'
        $shape = $argv[1];
        $primary = (object) ['connect_error' => str_contains($shape, 'primary-failure') ? 'primary unavailable' : null,
            'connect_errno' => 2002];
        $replica = (object) ['connect_error' => null];
        $replicaCalls = 0;
        class PrimaryOnlyDatabase {
            public function getConnection(): object { return $GLOBALS['primary']; }
        }
        class ReplicaDatabase extends PrimaryOnlyDatabase {
            public function getConnectionro(): object {
                $GLOBALS['replicaCalls']++;
                if ($GLOBALS['shape'] === 'replica-failure') {
                    throw new RuntimeException('replica unavailable');
                }
                return $GLOBALS['replica'];
            }
        }
        class DB {
            public static function getInstance(): object {
                return str_starts_with($GLOBALS['shape'], 'legacy')
                    ? new PrimaryOnlyDatabase() : new ReplicaDatabase();
            }
        }
        function p202_cli_fail(string $message): void { throw new RuntimeException($message); }
        function _die(string $message): void { throw new RuntimeException($message); }
        $readRole = null;
        $error = null;
        try {
        CODE;
        $runner .= "\n" . $block . <<<'CODE'

            $readRole = $dbro === $primary ? 'primary' : ($dbro === $replica ? 'replica' : 'unexpected');
        } catch (Throwable $failure) {
            $error = $failure->getMessage();
        }
        $answer = ['read' => $readRole, 'error' => $error, 'replica_calls' => $replicaCalls];
        echo json_encode($answer, JSON_THROW_ON_ERROR);
        CODE;

        $process = proc_open(
            [PHP_BINARY, '-r', $runner, $shape],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertSame('', $stderr);
        self::assertSame(
            ['read' => $readRole, 'error' => $error, 'replica_calls' => $replicaCalls],
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)
        );
    }
}
