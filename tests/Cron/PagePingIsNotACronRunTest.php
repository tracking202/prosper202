<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;
use Prosper202\Cron\CronRun;
use Tests\Support\SourceScan;

/**
 * The ping every signed-in page sends to 202-cronjobs/ runs the cron and is
 * not recorded as the cron job's run; a scheduler's run is.
 *
 * Each tier of 202-cronjobs/index.php stamped 202_cronjob_logs, whoever had
 * started the run, so the footer's ping read as a cron job everywhere the
 * time is read: Account › Settings refused AutoCron because "your own cron
 * job ran in the last five minutes" -- the ping that page had sent as it
 * loaded -- on every install, and the "Cron job last ran" tile, health.php
 * and the /system routes reported a cron job on installs that had none.
 *
 * What this holds:
 * - the footer's statement, executed, pings a URL CronRun reads as a page's;
 * - the command line, and every URL a default scheduler fetches (the
 *   installer's cron line, install.sh, both Docker cron services) are
 *   schedulers;
 * - the one write of the time anywhere in the served tree is in
 *   202-cronjobs/index.php, behind exactly CronRun::isScheduled(PHP_SAPI,
 *   $_GET);
 * - every other file that names the table is listed with what it does, so a
 *   new reader or writer is a decision about the page's ping (CLAUDE.md #25).
 *
 * tests/live/autocron-switch.sh is the run end to end.
 */
final class PagePingIsNotACronRunTest extends TestCase
{
    /** Every file that names 202_cronjob_logs (or its TableRegistry constant), and why. */
    private const NAMED_BY = [
        '202-cronjobs/index.php' => 'the one write: a scheduler\'s run, behind CronRun',
        '202-cronjobs/health.php' => 'reads row 1: the cron\'s health is a scheduler\'s',
        '202-account/administration.php' => 'reads row 1: the AutoCron switch\'s five-minute check'
            . ' and the "Cron job last ran" tile',
        'api/v3/Controllers/AdministrationController.php' => 'reads row 1: GET /system/info\'s cron_last_ran_at,'
            . ' the tile\'s twin',
        'api/v3/Controllers/SystemController.php' => 'reads the rows: GET /system/cron\'s recent_logs,'
            . ' which `p202 system cron` reads',
        '202-config/functions-upgrade.php' => 'creates the table, and the frozen 1.9.5 rung reads it (CLAUDE.md #33)',
        '202-config/Database/Tables/CoreTables.php' => 'creates the table',
        '202-config/Database/Schema/TableRegistry.php' => 'the name',
        '202-config/Cron/CronRun.php' => 'names it in its docblock',
    ];

    private const GUARD = 'if(\\Prosper202\\Cron\\CronRun::isScheduled(PHP_SAPI,$_GET)){';

    /** The footer's statement, executed from source with the domain stubbed. */
    private static function footerPingUrl(): string
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-config/functions-tracking202.php');
        self::assertSame(1, preg_match('/^function p202CronBeaconStatement\(\): string\n\{\n.*?^\}\n/ms', $source, $m));
        $code = 'require ' . var_export(SourceScan::repoRoot() . '/vendor/autoload.php', true) . ';'
            . "function get_absolute_url() { return '/p202/'; }"
            . "function getTrackingDomain(): string { return 'track.example.com'; }"
            . $m[0] . 'echo p202CronBeaconStatement();';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $found = preg_match('/^navigator\.sendBeacon\(("[^"]*")\);$/', $out, $call);
        self::assertSame(1, $found, "the statement: $out $err");
        $url = json_decode($call[1], true);
        self::assertIsString($url);

        return $url;
    }

    public function testTheFootersPingIsReadAsAPagesRun(): void
    {
        $url = self::footerPingUrl();
        self::assertSame('//track.example.com/p202/202-cronjobs/', strtok($url, '?'), 'the ping still runs the cron');
        parse_str((string) parse_url('https:' . $url, PHP_URL_QUERY), $query);
        foreach (['apache2handler', 'fpm-fcgi', 'cgi-fcgi', 'cli-server', 'litespeed'] as $sapi) {
            self::assertFalse(CronRun::isScheduled($sapi, $query), "$sapi: $url is a page's ping");
        }
    }

    public function testEveryDefaultSchedulerIsRecorded(): void
    {
        self::assertTrue(CronRun::isScheduled('cli', []), 'a crontab\'s php line');
        self::assertTrue(CronRun::isScheduled('phpdbg', []));
        $seen = 0;
        $schedulers = [
            'install.sh',
            'docker-compose.yaml',
            'docker-compose.coolify.yaml',
            '202-config/functions-install-helpers.php',
        ];
        foreach ($schedulers as $file) {
            $text = (string) file_get_contents(SourceScan::repoRoot() . '/' . $file);
            preg_match_all('~202-cronjobs/(?:index\.php)?(?:\?[^\s"\'\\\\]*)?~', $text, $urls);
            self::assertNotEmpty($urls[0], "$file names the cron's URL");
            foreach ($urls[0] as $url) {
                parse_str((string) parse_url('https://host/' . $url, PHP_URL_QUERY), $query);
                self::assertTrue(CronRun::isScheduled('fpm-fcgi', $query), "$file's $url is a scheduler's run");
                $seen++;
            }
        }
        self::assertGreaterThanOrEqual(4, $seen);
        // AutoCron GETs 202-cronjobs/index.php; older crontabs the bare directory.
        self::assertTrue(CronRun::isScheduled('apache2handler', []));
        // Only the page's own value is the page's.
        self::assertTrue(CronRun::isScheduled('apache2handler', [CronRun::PAGE_PING_PARAM => '0']));
        self::assertTrue(CronRun::isScheduled('apache2handler', [CronRun::PAGE_PING_PARAM => ['1']]));
    }

    public function testEveryFileThatNamesTheCronLogIsListed(): void
    {
        $named = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (str_contains($source, '202_cronjob_logs') || str_contains($source, 'CRONJOB_LOGS')) {
                $named[] = $path;
            }
        }
        $listed = array_keys(self::NAMED_BY);
        sort($named);
        sort($listed);
        self::assertSame($listed, $named, 'a file that reads or writes the cron job\'s last run decides whether'
            . ' a signed-in page\'s ping counts: list it in NAMED_BY with what it does');
    }

    /**
     * Statements that write the table: every string, joined across `.`, read
     * for a REPLACE, INSERT, UPDATE or DELETE of it.
     *
     * @return list<array{0: int, 1: string, 2: int}> [line, statement, index of its first token]
     */
    private static function writes(string $source): array
    {
        $tokens = token_get_all($source);
        $found = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $strings = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], $strings, true)) {
                continue;
            }
            $text = $tokens[$i][1];
            $write = '/\b(?:REPLACE|INSERT)\s+(?:IGNORE\s+)?(?:INTO\s+)?`?202_cronjob_logs'
                . '|\bUPDATE\s+`?202_cronjob_logs|\bDELETE\s+FROM\s+`?202_cronjob_logs/i';
            if (preg_match($write, $text) === 1) {
                $found[] = [$tokens[$i][2], $text, $i];
            }
        }

        return $found;
    }

    public function testTheOneWriteIsAScheduledRunsBehindCronRun(): void
    {
        $writes = [];
        foreach (array_keys(self::NAMED_BY) as $path) {
            foreach (self::writes((string) file_get_contents(SourceScan::repoRoot() . '/' . $path)) as $write) {
                $writes[] = $path . ':' . $write[0];
            }
        }
        $said = 'the cron job\'s last run is written once, by the run that holds the lock: ';
        self::assertCount(1, $writes, $said . implode(', ', $writes));
        self::assertStringStartsWith('202-cronjobs/index.php:', $writes[0]);

        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-cronjobs/index.php');
        $tokens = token_get_all($source);
        [[, , $at]] = self::writes($source);
        // The block the write sits in, and what opens it.
        $depth = 0;
        $open = null;
        for ($i = $at; $i >= 0; $i--) {
            $t = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            $opens = is_array($tokens[$i])
                && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
            if ($t === '}') {
                $depth++;
            } elseif ($t === '{' || $opens) {
                if ($depth === 0) {
                    $open = $i;
                    break;
                }
                $depth--;
            }
        }
        self::assertNotNull($open, 'the write sits in a block');
        $head = '';
        for ($i = $open; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $t = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            $head = $t . $head;
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_IF) {
                break;
            }
            if (strlen($head) > 200) {
                break;
            }
        }
        $said = 'the write is the body of exactly this condition: a page\'s ping records nothing';
        self::assertSame(self::GUARD, $head, $said);
        // The block holds the write and nothing that could leave before it.
        $body = '';
        for ($i = $open + 1; $i < $at; $i++) {
            $body .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }
        $first = preg_replace('/\s+/', '', $body);
        self::assertSame('cronQuery($db,', $first, 'the write is the first statement of the block');
    }
}
