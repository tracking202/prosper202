<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * One connection per request: the DB class of 202-config-sample.php opens
 * the primary when it is built and the read connection the first time
 * getConnectionro() is asked, and ConnectionFactory::releaseReadConnection()
 * closes the read connection a retained config (the sample of 1.9.55 to
 * 1.9.79, tests/Config/fixtures/202-config-sample-1.9.79.php.txt) opened in
 * its constructor.
 *
 * The config's own class is run, in a namespace whose `mysqli` records every
 * connection made: a host named down.example fails as a refused connection
 * does (a real connect to a closed port), with the report mode the run sets.
 * DatabaseConnectionCountIntegrationTest counts them on a real server.
 */
final class LazyReadConnectionTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The class and the file-scope tail of a config, runnable in the probe's
     * namespace: everything from `class DB` on.
     */
    private static function classOf(string $config): string
    {
        $at = strpos($config, 'class DB');
        self::assertIsInt($at, 'the config declares class DB');

        return substr($config, $at);
    }

    /**
     * Runs $config's DB class with the given settings, then $then, and
     * answers what $then echoes as JSON, with `opened` (the hosts connected
     * to, in order) and `logged` added.
     *
     * @return array<string, mixed>
     */
    private static function probe(string $config, string $dbhostro, string $then, bool $strict = false): array
    {
        $probe = 'namespace SampleProbe;' . "\n"
            . 'use Exception; use Throwable;' . "\n"
            . 'mysqli_report(' . ($strict ? 'MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT' : 'MYSQLI_REPORT_OFF') . ');' . "\n"
            . 'require ' . var_export(self::root() . '/vendor/autoload.php', true) . ';' . "\n"
            . <<<'CODE'
            final class mysqli extends \mysqli
            {
                /** @var list<string> */
                public static array $opened = [];
                public bool $closedHere = false;

                public function __construct(public string $hostAsked, string $user, string $pass, string $name)
                {
                    self::$opened[] = $hostAsked;
                    if ($hostAsked === 'down.example') {
                        // A refused connection, as the server's absence gives one.
                        @parent::__construct('127.0.0.1', $user, $pass, $name, 9);
                    }
                }

                public function close(): true
                {
                    $this->closedHere = true;

                    return true;
                }
            }
            $logged = [];
            function error_log(string $message): bool
            {
                $GLOBALS['logged'][] = $message;

                return true;
            }
            $dbhost = 'primary.example';
            $dbuser = 'u';
            $dbpass = 'p';
            $dbname = 'n';
            CODE
            . "\n" . '$dbhostro = ' . var_export($dbhostro, true) . ";\n"
            . self::classOf($config) . "\n"
            . '$answer = [];' . "\n" . $then . "\n"
            . 'echo json_encode($answer + ["opened" => mysqli::$opened, "logged" => $logged]);';

        $process = proc_open([PHP_BINARY, '-r', $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertSame('', $stderr);

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function sample(): string
    {
        return (string) file_get_contents(self::root() . '/202-config-sample.php');
    }

    private static function retained(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/202-config-sample-1.9.79.php.txt');
    }

    private const READ = <<<'CODE'
        $read = $database->getConnectionro();
        $again = $database->getConnectionro();
        $answer['read'] = $read === $db ? 'primary' : ($read instanceof mysqli ? $read->hostAsked : 'none');
        $answer['same_again'] = $again === $read;
        CODE;

    public function testTheSampleOpensThePrimaryAlone(): void
    {
        $run = self::probe(self::sample(), 'replica.example', '$answer["db"] = $db instanceof mysqli;');

        self::assertTrue($run['db']);
        self::assertSame(['primary.example'], $run['opened']);
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function readHosts(): iterable
    {
        yield 'the replica setting names the primary' => ['primary.example', 'primary', ['primary.example']];
        yield 'no replica named' => ['', 'primary', ['primary.example']];
        yield 'a replica' => ['replica.example', 'replica.example', ['primary.example', 'replica.example']];
    }

    /**
     * @dataProvider readHosts
     * @param list<string> $opened
     */
    public function testTheReadConnectionIsOpenedWhenAskedFor(string $dbhostro, string $read, array $opened): void
    {
        $run = self::probe(self::sample(), $dbhostro, self::READ);

        self::assertSame($read, $run['read']);
        self::assertTrue($run['same_again'], 'asked again, the same connection');
        self::assertSame($opened, $run['opened'], 'each connection opened once');
        self::assertSame([], $run['logged']);
    }

    /** A replica that refuses is read through the primary, and logged: either way the report mode says. */
    public function testAReplicaThatRefusesIsReadThroughThePrimary(): void
    {
        foreach ([false, true] as $strict) {
            $run = self::probe(self::sample(), 'down.example', self::READ, $strict);

            self::assertSame('primary', $run['read'], $strict ? 'strict' : 'report off');
            self::assertSame(['primary.example', 'down.example'], $run['opened'], 'tried once');
            self::assertCount(1, $run['logged']);
            self::assertStringContainsString('the read replica could not be reached', $run['logged'][0]);
            self::assertStringContainsString('reading from the primary', $run['logged'][0]);
        }
    }

    /** The configs build/ ships for its stacks hold the sample's class. */
    public function testTheShippedConfigsHoldTheSamplesClass(): void
    {
        foreach (['build/test-install-config.php', 'build/staging-config.sample.php'] as $file) {
            self::assertSame(
                rtrim(self::classOf(self::sample())),
                rtrim(self::classOf((string) file_get_contents(self::root() . '/' . $file))),
                $file
            );
        }
    }

    /** A retained config opens both when built; the release closes the read one and leaves the primary. */
    public function testTheReleaseClosesARetainedConfigsReadConnection(): void
    {
        $run = self::probe(self::retained(), 'primary.example', <<<'CODE'
            $held = (new \ReflectionProperty($database, '_connectionro'))->getValue($database);
            $answer['released'] = \Prosper202\Database\ConnectionFactory::releaseReadConnection($database, $db);
            $answer['closed'] = $held->closedHere;
            $answer['primary_open'] = !$db->closedHere;
            $answer['read_now'] = $database->getConnectionro() === $db ? 'primary' : 'other';
            $answer['again'] = \Prosper202\Database\ConnectionFactory::releaseReadConnection($database, $db);
            CODE);

        self::assertSame(['primary.example', 'primary.example'], $run['opened'], 'the retained config opens two');
        self::assertTrue($run['released']);
        self::assertTrue($run['closed']);
        self::assertTrue($run['primary_open']);
        self::assertSame('primary', $run['read_now']);
        self::assertFalse($run['again'], 'nothing left to release');
    }

    /** The sample's class holds no read connection to release, and the release opens none. */
    public function testTheReleaseOpensNothingForTheSample(): void
    {
        $run = self::probe(self::sample(), 'replica.example', <<<'CODE'
            $answer['released'] = \Prosper202\Database\ConnectionFactory::releaseReadConnection($database, $db);
            CODE);

        self::assertFalse($run['released']);
        self::assertSame(['primary.example'], $run['opened']);
    }

    /**
     * The click path releases it as soon as it has the primary: in the
     * bootstrap's connect block, before anything else runs.
     */
    public function testTheClickPathReleasesTheReadConnection(): void
    {
        $bootstrap = (string) file_get_contents(self::root() . '/202-config/connect2.php');

        self::assertMatchesRegularExpression(
            '~try \{\s*\$database = DB::getInstance\(\);\s*\$db = \$database->getConnection\(\);\s*(?://[^\n]*\s*)*'
                . 'if \(\$db instanceof \\\\mysqli\) \{\s*require_once __DIR__ \. \'/Database/ConnectionFactory\.php\';\s*'
                . '\\\\Prosper202\\\\Database\\\\ConnectionFactory::releaseReadConnection\(\$database, \$db\);\s*\}~',
            $bootstrap
        );
    }

    /** A config with no read connection at all (before 1.9.55) is left as it is. */
    public function testTheReleaseLeavesAPrimaryOnlyConfigAlone(): void
    {
        $primaryOnly = new class () {
            public function getConnection(): \mysqli
            {
                return mysqli_init();
            }
        };

        self::assertFalse(\Prosper202\Database\ConnectionFactory::releaseReadConnection($primaryOnly, mysqli_init()));
    }
}
