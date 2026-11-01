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
 *   it, the next run would lock a new file beside it);
 * - the analytics delivery, which index.php's hourly tier and
 *   sync-messaging.php both make, is made only under its own lock
 *   (ANALYTICS_DELIVERY_FILE), by both, through
 *   MessagingService::deliverPendingExclusive(); a run that finds it held
 *   delivers nothing;
 * - and no job anywhere keeps its runs apart by touch()ing a file.
 *
 * CronLockTest runs the lock itself in child processes; tests/live/cron-lock.sh
 * runs index.php. OneAnalyticsDeliveryAtATimeIntegrationTest runs
 * sync-messaging.php in child processes, and tests/live/sync-messaging-lock.sh
 * runs it beside index.php.
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

    /** Every file outside tests/ that names a cron lock, and why. */
    private const NAMED_BY = [
        '202-config/Cron/CronLock.php' => 'the lock',
        '202-cronjobs/index.php' => 'takes it, once, for the whole run; names the analytics delivery\'s for its'
            . ' hourly tier',
        '202-cronjobs/health.php' => 'asks whether a run holds it (isHeld(), an instant\'s shared lock)',
        '202-config/Messaging/MessagingService.class.php' => 'takes the analytics delivery\'s for each delivery'
            . ' (deliverPendingExclusive())',
        '202-cronjobs/sync-messaging.php' => 'names the analytics delivery\'s for deliverPendingExclusive()',
    ];

    /** The files that deliver the analytics backlog, which is done only under its lock. */
    private const DELIVERS = [
        '202-config/Messaging/MessagingService.class.php',
        '202-cronjobs/index.php',
        '202-cronjobs/sync-messaging.php',
    ];
    private const DELIVERY_PATH = '\\Prosper202\\Cron\\CronLock::path('
        . '\\Prosper202\\Cron\\CronLock::ANALYTICS_DELIVERY_FILE)';

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
        return self::code(self::INDEX);
    }

    private static function code(string $path): string
    {
        return implode('', self::tokens((string) file_get_contents(SourceScan::repoRoot() . '/' . $path)));
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
     * one that goes stale with age starts a run beside a long one; a run that
     * dies leaves it, and keeps every run out until it is old. The scan looks
     * for the call that made it, touch(), anywhere outside tests/: a presence
     * lock made another way (fopen() with 'x', file_put_contents()) is not
     * seen.
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
        self::assertSame([], array_values(array_unique($found)), 'a job kept apart from its next run by touch()ing'
            . ' a file: take a CronLock (an flock() held for the run) instead');
    }

    public function testTheAnalyticsDeliveryIsMadeOnlyUnderItsLock(): void
    {
        require_once SourceScan::repoRoot() . '/202-config/Messaging/MessagingService.class.php';
        self::assertTrue(
            (new \ReflectionMethod(\MessagingService::class, 'deliverPending'))->isPrivate(),
            'the delivery without its lock is MessagingService\'s own'
        );
        self::assertStringContainsString(
            '{$lock=CronLock::take($lockPath);if($lock===null){returnnull;}'
                . 'self::refreshProfiles($db);'
                . 'returnself::deliverPending($db,$maxBatchesPerUser,$maxConsecutiveFailures);}',
            self::code(self::DELIVERS[0]),
            'deliverPendingExclusive() takes the lock it is handed, delivers nothing while another run holds it,'
                . ' and holds it until the delivery returns'
        );

        $callers = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (str_contains(implode('', self::tokens($source)), 'deliverPending')) {
                $callers[] = $path;
            }
        }
        sort($callers);
        self::assertSame(
            self::DELIVERS,
            $callers,
            'every delivery of the backlog goes through deliverPendingExclusive()'
        );

        $hourly = self::index();
        self::assertSame(
            1,
            substr_count($hourly, 'deliverPendingExclusive('),
            'index.php delivers once, in its hourly tier'
        );
        self::assertStringContainsString(
            '$analytics=MessagingService::deliverPendingExclusive(getDatabaseConnection(),' . self::DELIVERY_PATH . ');'
                . 'if($analytics===null){echo\'Analytics delivery: another run holds the lock; skipped<br>\';}',
            $hourly,
            'index.php\'s hourly tier delivers under the analytics delivery\'s lock, and only says so while another'
                . ' run holds it'
        );

        $own = self::code('202-cronjobs/sync-messaging.php');
        self::assertSame(1, substr_count($own, 'deliverPendingExclusive('), 'sync-messaging.php delivers once');
        self::assertStringContainsString(
            '$lockPath=' . self::DELIVERY_PATH . ';'
                . '$counts=MessagingService::deliverPendingExclusive(DB::getInstance()->getConnection(),$lockPath);'
                . 'if($counts===null){error_log(\'Analytics delivery already running: another run holds \'.$lockPath'
                . '.\'; this run did nothing.\');exit("Analytics delivery already running\n");}',
            $own,
            'sync-messaging.php delivers under the same lock, and a run that finds it held logs why and ends'
        );
        self::assertSame(3, substr_count($own, '$lockPath'), 'the path is assigned, locked and logged, nothing else');
        self::assertSame(3, substr_count($own, 'lockPath'), 'nor named another way ($GLOBALS, compact())');
        foreach (['unlink(', 'extract(', '$$', '$GLOBALS', 'unset('] as $construct) {
            self::assertStringNotContainsString($construct, $own, "sync-messaging.php calls no $construct");
        }
    }

    public function testEveryFileThatNamesTheLockIsListed(): void
    {
        $named = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (preg_match('/\bCronLock\b|cron-run\.lock|analytics-delivery\.lock/', $source) === 1) {
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
                '/\b(?:rm|unlink)\b[^\n]*(?:cron-run|analytics-delivery)\.lock/',
                (string) file_get_contents($script),
                basename($script) . ' removes the cron lock\'s file, which lets a run lock a new one beside a run'
                    . ' that holds it'
            );
        }
    }
}
