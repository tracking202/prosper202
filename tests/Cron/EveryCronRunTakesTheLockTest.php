<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every run of 202-cronjobs/index.php takes the cron lock (CronLock) before
 * it does anything, keeps it until it ends, and does nothing without it.
 *
 * Every way the cron is run -- a signed-in page's ping, a scheduler's curl or
 * `php` line, the Docker cron services, AutoCron -- requests or runs that one
 * file (PagePingIsNotACronRunTest holds the URLs), so what this holds is that
 * file's top:
 *
 * - the lock is taken once, right after the bootstrap and first in the run,
 *   in the try every run goes through, before any tier and before the record
 *   of a run;
 * - a run that finds it held logs that and exits there, recording nothing;
 * - the variable holding it is never touched again (PHP releases the lock
 *   when the last reference goes), and the run never removes a file;
 * - nothing else includes index.php, so its tiers run nowhere else, and
 *   nothing else names the lock without being listed here: a second taker or
 *   anything that removes the file is a decision (removed while a run holds
 *   it, the next run would lock a new file beside it).
 *
 * CronLockTest runs the lock itself in child processes; tests/live/cron-lock.sh
 * runs index.php.
 */
final class EveryCronRunTakesTheLockTest extends TestCase
{
    private const INDEX = '202-cronjobs/index.php';
    private const BOOTSTRAP = "require_once__DIR__.'/../202-config/connect.php';"
        . "require_once__DIR__.'/../202-config/class-dataengine.php';";
    private const TAKE = '$cronLock=\Prosper202\Cron\CronLock::take(\Prosper202\Cron\CronLock::path());';
    private const BUSY = 'if($cronLock===null){';
    private const TIERS = ['RunSecondsCronjob(', 'RunHourlyCronJob(', 'RunDailyCronjob(', 'AutoOptimizeDatabase(',
        'ClearOldClicks(', '\Prosper202\Cron\CronRun::isScheduled('];

    /** Every file outside tests/ that names the lock, and why. */
    private const NAMED_BY = [
        '202-config/Cron/CronLock.php' => 'the lock',
        '202-cronjobs/index.php' => 'takes it, once, for the whole run',
        '202-cronjobs/health.php' => 'asks whether a run holds it (isHeld(), an instant\'s shared lock)',
    ];

    /**
     * Files that still keep two runs apart by a file's presence, made with
     * touch() and deleted once old, as index.php did, and why each remains.
     */
    private const PRESENCE_LOCKS = [
        '202-cronjobs/sync-messaging.php' => 'the same lock (file_exists(), touch(), stale after 600 s); no default'
            . ' scheduler runs this file, index.php delivers analytics hourly, so it is a backlog item',
    ];

    /** @return list<string> the file's tokens' text, without whitespace or comments */
    private static function tokens(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    private static function index(): string
    {
        return implode('', self::tokens((string) file_get_contents(SourceScan::repoRoot() . '/' . self::INDEX)));
    }

    public function testTheRunTakesTheLockFirstAfterItsBootstrap(): void
    {
        $code = self::index();
        self::assertSame(1, substr_count($code, 'CronLock::take('), 'index.php takes the cron lock, once');
        self::assertStringContainsString(
            'try{' . self::BOOTSTRAP . self::TAKE . self::BUSY,
            $code,
            'the lock is the first thing the run does after its bootstrap, in the try every run goes through,'
                . ' and the next statement asks whether it was held'
        );
        $take = (int) strpos($code, self::TAKE);
        foreach (self::TIERS as $tier) {
            $first = strpos($code, $tier);
            self::assertNotFalse($first, "index.php calls $tier");
            self::assertGreaterThan($take, $first, "$tier runs only after the lock is taken");
        }
    }

    public function testARunThatFindsItHeldDoesNothingAndSaysSo(): void
    {
        $code = self::index();
        $start = (int) strpos($code, self::BUSY) + strlen(self::BUSY);
        $end = strpos($code, '}', $start);
        self::assertNotFalse($end);
        $block = substr($code, $start, $end - $start);
        self::assertSame(
            'error_log(\'Cron job already running: another run holds \'.\Prosper202\Cron\CronLock::path()'
                . '.\'; this run did nothing.\');exit("Cron job already running\n");',
            $block,
            'the held branch logs that another run holds the lock, then ends the run before anything is done'
                . ' or recorded'
        );
    }

    public function testTheLockIsKeptForTheWholeRunAndTheFileNeverRemoved(): void
    {
        $code = self::index();
        self::assertSame(
            2,
            substr_count($code, '$cronLock'),
            'the lock is assigned and tested, never released early, copied or reassigned'
        );
        self::assertSame(2, substr_count($code, 'cronLock'), 'nor named another way ($GLOBALS, compact())');
        foreach (['unlink(', 'extract(', '$$', '$GLOBALS', 'unset('] as $construct) {
            self::assertStringNotContainsString($construct, $code, "index.php calls no $construct");
        }
    }

    public function testNothingElseRunsTheCronsTiers(): void
    {
        $includers = [];
        foreach (SourceScan::phpFiles(true) as $path => $source) {
            if ($path === self::INDEX) {
                continue;
            }
            $tokens = self::tokens($source);
            foreach ($tokens as $i => $text) {
                if (!in_array(strtolower($text), ['include', 'include_once', 'require', 'require_once'], true)) {
                    continue;
                }
                $statement = '';
                for ($j = $i + 1; $j < count($tokens) && $tokens[$j] !== ';'; $j++) {
                    $statement .= $tokens[$j];
                }
                $names = str_contains($statement, 'cronjobs/index.php') || str_contains($statement, "cronjobs/'")
                    || str_contains($statement, "'index.php'");
                if ($names) {
                    $includers[] = "$path: $text $statement";
                }
            }
        }
        self::assertSame([], $includers, 'only index.php runs its tiers, under its lock');
        $indexes = array_map('basename', glob(SourceScan::repoRoot() . '/202-cronjobs/index.*') ?: []);
        self::assertSame(['index.php'], $indexes, 'the directory\'s index, which the page ping requests, is index.php');
    }

    /**
     * A lock that is a file's presence lets two runs both find it absent, and
     * one that goes stale with age starts a run beside a long one. The scan
     * looks for the call that made it, touch(): a presence lock made another
     * way (fopen() with 'x', file_put_contents()) is not seen.
     */
    public function testNoOtherJobIsKeptApartByAFilesPresence(): void
    {
        $skipped = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $notAGlobalCall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $tokens = array_values(array_filter(
                token_get_all($source),
                static fn ($t): bool => !is_array($t) || !in_array($t[0], $skipped, true)
            ));
            foreach ($tokens as $i => $t) {
                $calls = is_array($t) && SourceScan::callableName($t[0], $t[1]) === 'touch'
                    && ($tokens[$i + 1] ?? null) === '(';
                $before = $tokens[$i - 1] ?? null;
                if ($calls && !(is_array($before) && in_array($before[0], $notAGlobalCall, true))) {
                    $found[] = $path;
                }
            }
        }
        $found = array_values(array_unique($found));
        $listed = array_keys(self::PRESENCE_LOCKS);
        sort($found);
        sort($listed);
        self::assertSame($listed, $found, 'a job kept apart from its next run by touch()ing a file: take a CronLock'
            . ' (an flock() held for the run) instead, or list it in PRESENCE_LOCKS with why');
    }

    public function testEveryFileThatNamesTheLockIsListed(): void
    {
        $named = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (preg_match('/\bCronLock\b|cron-run\.lock/', $source) === 1) {
                $named[] = $path;
            }
        }
        $listed = array_keys(self::NAMED_BY);
        sort($named);
        sort($listed);
        self::assertSame(
            $listed,
            $named,
            'a file that takes, reads or removes the cron lock decides whether two runs can overlap: list it in'
                . ' NAMED_BY with what it does'
        );

        // The scripts that used to clear cron.lock: a removal by name only (a path in a variable is not seen).
        foreach (glob(SourceScan::repoRoot() . '/{tests/live,build,scripts}/*.sh', GLOB_BRACE) ?: [] as $script) {
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:rm|unlink)\b[^\n]*cron-run\.lock/',
                (string) file_get_contents($script),
                basename($script) . ' removes the cron lock\'s file, which lets a run lock a new one beside a run'
                    . ' that holds it'
            );
        }
    }
}
