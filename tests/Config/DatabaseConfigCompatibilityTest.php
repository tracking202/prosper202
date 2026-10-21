<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * An installer-written 202-config.php is kept across upgrades, so its DB
 * class can have getConnection() alone. Every read of the replica goes
 * through ConnectionFactory::readConnectionOf(), and each place that reads
 * it is run here against a config with no replica method and one with.
 */
final class DatabaseConfigCompatibilityTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

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
        $source = (string) file_get_contents(self::root() . '/202-config/connect.php');
        $start = strpos($source, "try {\n    \$database = DB::getInstance();");
        self::assertIsInt($start);
        $end = strpos($source, "\n// Initialize \$skip_upgrade", $start);
        self::assertIsInt($end);
        $block = substr($source, $start, $end - $start);

        $runner = 'require ' . var_export(self::root() . '/202-config/Database/ConnectionFactory.php', true) . ";\n";
        $runner .= <<<'CODE'
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

    /** @return array{string, string} stdout and stderr of $code run as a PHP process of its own */
    private static function runPhp(string $code): array
    {
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$stdout, $stderr];
    }

    /** The two shapes of config a DB class can have, as the runners below declare them. */
    private const CONFIGS = <<<'CODE'
        class PrimaryOnlyDatabase {
            public function __construct(public object $primary, public object $replica) {}
            public function getConnection(): object { return $this->primary; }
        }
        class ReplicaDatabase extends PrimaryOnlyDatabase {
            public function getConnectionro(): object { return $this->replica; }
        }
        CODE;

    /** @return iterable<string, array{string, string}> */
    public static function shapes(): iterable
    {
        yield 'retained config without a replica method' => ['PrimaryOnlyDatabase', 'primary'];
        yield 'config with a replica' => ['ReplicaDatabase', 'replica'];
    }

    /** @dataProvider shapes */
    public function testTheApiConnectionReadsFromWhatTheConfigProvides(string $class, string $role): void
    {
        $code = 'require ' . var_export(self::root() . '/vendor/autoload.php', true) . ';' . self::CONFIGS . <<<CODE
            \$primary = mysqli_init();
            \$replica = mysqli_init();
            class DB {
                public static function getInstance(): object
                {
                    return new {$class}(\$GLOBALS['primary'], \$GLOBALS['replica']);
                }
            }
            \$read = \\Prosper202\\Database\\ConnectionFactory::create()->readConnection();
            echo \$read === \$primary ? 'primary' : (\$read === \$replica ? 'replica' : 'unexpected');
            CODE;
        [$stdout, $stderr] = self::runPhp($code);
        self::assertSame($role, $stdout, $stderr);
    }

    /** @dataProvider shapes */
    public function testTheSpyQueryReadsFromWhatTheConfigProvides(string $class, string $role): void
    {
        $code = 'require ' . var_export(self::root() . '/vendor/autoload.php', true) . ';'
            . 'require ' . var_export(self::root() . '/202-config/functions-tracking202.php', true) . ';'
            . self::CONFIGS . <<<CODE
            // The first thing query() asks of its connection names the connection.
            \$named = static fn (string \$role): object => new class (\$role) {
                public function __construct(private string \$role) {}
                public function real_escape_string(string \$s): string { throw new RuntimeException(\$this->role); }
            };
            \$primary = \$named('primary');
            \$replica = \$named('replica');
            class DB {
                public static function getInstance(): object
                {
                    return new {$class}(\$GLOBALS['primary'], \$GLOBALS['replica']);
                }
            }
            \$_SESSION['user_id'] = 7;
            try {
                query('SELECT 1', null, null, null, null, null, null, null, null, true);
                echo 'no read';
            } catch (RuntimeException \$read) {
                echo \$read->getMessage();
            }
            CODE;
        [$stdout, $stderr] = self::runPhp($code);
        self::assertSame($role, $stdout, $stderr);
    }

    /**
     * No served file calls getConnectionro() or names it except the helper
     * and the config files that declare it. A name built at run time
     * ('getConnection' . 'ro') is not seen.
     */
    public function testOnlyTheHelperAsksForTheReplica(): void
    {
        $root = self::root();
        $files = \Tests\Support\SourceScan::phpFiles();
        self::assertGreaterThan(500, count($files), 'the tree was walked');

        $found = [];
        foreach ($files as $path => $source) {
            if (stripos($source, 'getconnectionro') === false) {
                continue;
            }
            $tokens = token_get_all($source);
            $declares = false;
            $uses = 0;
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || stripos($token[1], 'getconnectionro') === false) {
                    continue;
                }
                if ($token[0] === T_STRING) {
                    $before = $i - 1;
                    while (is_array($tokens[$before]) && $tokens[$before][0] === T_WHITESPACE) {
                        $before--;
                    }
                    if (is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION) {
                        $declares = true;
                        continue;
                    }
                    $uses++;
                } elseif (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $uses++;
                }
            }
            if ($uses > 0 && !$declares) {
                $found[$path] = $uses;
            }
        }

        // The helper's own method_exists() and call, and nothing else in its file.
        self::assertSame(['202-config/Database/ConnectionFactory.php' => 2], $found);
        $factory = (string) file_get_contents($root . '/202-config/Database/ConnectionFactory.php');
        self::assertSame(1, preg_match(
            '/public static function readConnectionOf\(object \$database\): mixed\s*\{(.*?)\n    \}/s',
            $factory,
            $m
        ));
        self::assertSame(2, substr_count(strtolower($m[1]), 'getconnectionro'));
    }
}
