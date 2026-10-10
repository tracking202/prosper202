<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * The stored tracking domain is read, or the read throws: a failed read is
 * not "none stored".
 *
 * p202StoredTrackingDomain() answered '' for a query that failed, and ''
 * means no domain: getTrackingDomain() then built every link a page showed
 * on the request's host, and p202TrackingBaseUrl() registered the server's
 * own name with the hosted service (auto cron, the daily email, the DNI
 * host) — on one transient database error, with nothing to say so
 * (CLAUDE.md #11). Measured against a live instance: with the connection
 * killed, the healthy `track.example.com` became the Host header the
 * request sent and `http://internal-name:8134/`.
 *
 * Executed, both copies — functions-tracking202.php's and the connect2.php
 * twin's getTrackingDomain() — each from its own source in a child PHP with
 * the database read stubbed: a stored domain is answered, no row is '', and
 * a query that fails (false, or a strict-mode exception) throws naming the
 * tracking domain.
 */
final class StoredTrackingDomainReadTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, list<string>}>
     */
    public static function definitions(): iterable
    {
        // The DB singleton and _mysqli_query(), as functions-tracking202.php
        // reaches them; $GLOBALS['answer'] says what the query does.
        $stubs = <<<'PHP'
            final class FakeConnection { public string $error = 'MySQL server has gone away';
                public function real_escape_string(string $s): string { return addslashes($s); } }
            final class DB { public static function getInstance(): self { return new self(); }
                public function getConnection(): FakeConnection { return new FakeConnection(); } }
            final class StubResult extends \mysqli_result { public function __construct(private ?array $row) {}
                public function fetch_assoc(): array|null|false { return $this->row; } }
            function _mysqli_query($dbOrSql, $sql = null) {
                return match ($GLOBALS['answer']) {
                    'row' => new StubResult(['user_tracking_domain' => 'track.example.com']),
                    'no row' => new StubResult(null),
                    'false' => false,
                    'throws' => throw new \mysqli_sql_exception('MySQL server has gone away', 2006),
                };
            }
            PHP;
        yield 'functions-tracking202.php p202StoredTrackingDomain()' => [
            '202-config/functions-tracking202.php',
            'p202StoredTrackingDomain',
            $stubs,
            ['false', 'throws'],
        ];
        yield 'connect2.php getTrackingDomain()' => [
            '202-config/connect2.php',
            'getTrackingDomain',
            $stubs,
            // connect2.php sets MYSQLI_REPORT_STRICT alone, so a failed query
            // answers false there; it is that file's owner's to name a
            // strict-mode exception too.
            ['false'],
        ];
    }

    /**
     * @dataProvider definitions
     * @param list<string> $failures
     */
    public function testAFailedReadThrowsAndIsNeverNoneStored(
        string $file,
        string $function,
        string $stubs,
        array $failures
    ): void {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/' . $file);
        // Any parameter list: p202StoredTrackingDomain() takes an optional
        // account, and the call below names none (the session's, or user 1).
        $found = preg_match('/^function ' . $function . '\([^)]*\): string\n\{\n.*?^\}\n/ms', $source, $m);
        self::assertSame(1, $found, "$function() in $file");
        $autoload = SourceScan::repoRoot() . '/vendor/autoload.php';
        $call = var_export($function, true);
        $code = 'require ' . var_export($autoload, true) . '; ' . $stubs . ' ' . $m[0] . <<<PHP
            \$_SERVER = ['SERVER_NAME' => 'internal', 'SERVER_PORT' => '8080', 'HTTP_HOST' => 'proxy.example:9443'];
            \$out = [];
            foreach (['row', 'no row', 'false', 'throws'] as \$answer) {
                \$GLOBALS['answer'] = \$answer;
                try {
                    \$out[\$answer] = 'answered ' . (string) ($call)();
                } catch (\\Throwable \$e) {
                    \$out[\$answer] = get_class(\$e) . ': ' . \$e->getMessage();
                }
            }
            echo json_encode(\$out);
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $seen = json_decode($out, true);
        self::assertIsArray($seen, "$file answered: $out $err");

        self::assertSame('answered track.example.com', $seen['row'], $file);
        $none = $function === 'getTrackingDomain' ? 'answered proxy.example:9443' : 'answered ';
        self::assertSame($none, $seen['no row'], "$file: no row stored is none stored");
        foreach ($failures as $failure) {
            $said = $seen[$failure];
            self::assertStringStartsWith('RuntimeException: ', $said, "$file, a read that fails ($failure): $said");
            self::assertStringContainsString('tracking domain', $said, "$file names what failed");
        }
    }

    /**
     * The one caller that goes on without the domain: every signed-in page's
     * footer pings the cron endpoint, which is optional, and a throw there cut
     * the page off after it had rendered. Executed from source with
     * getTrackingDomain() stubbed: the statement when it answers, a comment
     * when it throws -- never the request's host, never a throw.
     */
    public function testTheCronBeaconIsSkippedNotGuessedWhenTheDomainCannotBeRead(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-config/functions-tracking202.php');
        $found = preg_match('/^function p202CronBeaconStatement\(\): string\n\{\n.*?^\}\n/ms', $source, $m);
        self::assertSame(1, $found);
        $code = <<<'PHP'
            function get_absolute_url() { return '/p202/'; }
            function getTrackingDomain(): string {
                if ($GLOBALS['fail']) {
                    throw new \RuntimeException('Unable to read the tracking domain of account 1');
                }
                return 'track.example.com';
            }
            PHP . $m[0] . <<<'PHP'
            ini_set('error_log', '/dev/null');
            $GLOBALS['fail'] = false;
            $ok = p202CronBeaconStatement();
            $GLOBALS['fail'] = true;
            echo json_encode([$ok, p202CronBeaconStatement()]);
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $seen = json_decode($out, true);
        self::assertIsArray($seen, "answered: $out $err");
        self::assertSame('navigator.sendBeacon("//track.example.com/p202/202-cronjobs/");', $seen[0]);
        self::assertStringStartsWith('/* the cron ping was skipped', $seen[1]);
        self::assertStringNotContainsString('sendBeacon', $seen[1]);
    }
}
