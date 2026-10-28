<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;
use Prosper202\Ltv\LtvMaintenance;

/**
 * Where the LTV maintenance runs. Before 1.9.79 only
 * 202-cronjobs/ltv_maintenance.php ran it and no default deployment ran that,
 * so a subscription that stopped renewing stayed active for good
 * (EveryCronJobIsScheduledByDefaultTest holds that index.php reaches the
 * maintenance at all). What this holds:
 *
 * - the hourly tier sweeps subscriptions, under the sweep's own budget, after
 *   the tier's other jobs and before it records the hour;
 * - the daily tier runs the other jobs under theirs, before it records the
 *   day; the minutely tier runs neither;
 * - between them every job runs, the sweep only hourly (executed against the
 *   class's lists);
 * - RunLtvMaintenance() hands the cron's connection, the tier's deadline and
 *   its jobs to the locked run, and a failure stays inside it.
 */
final class LtvMaintenanceRunsHourlyAndDailyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';
    private const HELPER = 'RunLtvMaintenance(';
    private const HOURLY = '[\Prosper202\Ltv\LtvMaintenance::SWEEP],\Prosper202\Ltv\LtvMaintenance::HOURLY_SECONDS';
    private const DAILY = '\Prosper202\Ltv\LtvMaintenance::DAILY_JOBS,\Prosper202\Ltv\LtvMaintenance::DAILY_SECONDS';
    private const CALL = '\Prosper202\Ltv\LtvMaintenance::runExclusive(';
    private const ARGS = 'new\Prosper202\Database\Connection(getDatabaseConnection()),time()+$seconds,$jobs';
    private const RECORD = 'cronQuery($db,$insert_sql,';

    /** @return list<array{0: int, 1: string}> significant tokens */
    private static function tokens(string $src): array
    {
        $out = [];
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $out[] = [$t[0], $t[1]];
                }
            } else {
                $out[] = [0, $t];
            }
        }

        return $out;
    }

    /**
     * Each function's body as one string of its tokens, by name.
     *
     * @return array<string, string>
     */
    private static function functionBodies(string $src): array
    {
        $t = self::tokens($src);
        $bodies = [];
        foreach ($t as $i => [$id]) {
            if ($id !== T_FUNCTION || ($t[$i + 1][0] ?? null) !== T_STRING) {
                continue;
            }
            $j = $i;
            while ($t[$j][1] !== '{') {
                $j++;
            }
            $level = 0;
            $body = '';
            for ($k = $j; $k < count($t); $k++) {
                $text = $t[$k][1];
                if ($text === '{' || $t[$k][0] === T_CURLY_OPEN || $t[$k][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $level++;
                } elseif ($text === '}') {
                    $level--;
                }
                $body .= $text;
                if ($level === 0) {
                    break;
                }
            }
            $bodies[$t[$i + 1][1]] = $body;
        }

        return $bodies;
    }

    /**
     * The argument text of every call in $code that starts with $call, with
     * where it starts.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function calls(string $code, string $call): array
    {
        $out = [];
        $offset = 0;
        while (($at = strpos($code, $call, $offset)) !== false) {
            // A name that only ends with the helper's (MyRunLtvMaintenance(,
            // ->RunLtvMaintenance() is another call.
            $before = $at > 0 ? $code[$at - 1] : '';
            $offset = $at + strlen($call);
            if ($call[0] !== '\\' && (ctype_alnum($before) || in_array($before, ['_', '>', ':', '\\'], true))) {
                continue;
            }
            $level = 1;
            for ($i = $offset; $i < strlen($code); $i++) {
                if ($code[$i] === '(') {
                    $level++;
                } elseif ($code[$i] === ')' && --$level === 0) {
                    $out[] = [$at, substr($code, $offset, $i - $offset)];
                    break;
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function index(): array
    {
        $bodies = self::functionBodies((string) file_get_contents(self::ROOT . '202-cronjobs/index.php'));
        foreach (['RunSecondsCronjob', 'RunHourlyCronJob', 'RunDailyCronjob', 'RunLtvMaintenance'] as $fn) {
            self::assertArrayHasKey($fn, $bodies, "202-cronjobs/index.php defines $fn()");
        }

        return $bodies;
    }

    public function testTheHourlyTierSweepsAndTheDailyTierRunsTheRest(): void
    {
        $bodies = self::index();

        $hourly = self::calls($bodies['RunHourlyCronJob'], self::HELPER);
        self::assertCount(1, $hourly, 'RunHourlyCronJob() runs the LTV maintenance once');
        self::assertSame(self::HOURLY, $hourly[0][1], 'the hourly tier sweeps, under the sweep\'s budget');
        foreach (['PushDirtyLpoDimensions(', 'PruneAppRetention(', 'DeliverPendingAnalytics('] as $job) {
            $at = strpos($bodies['RunHourlyCronJob'], $job);
            self::assertIsInt($at, "the hourly tier still runs $job; update this list if it moved");
            self::assertLessThan($hourly[0][0], $at, "$job runs before the sweep, which has a budget and they do not");
        }
        $record = strpos($bodies['RunHourlyCronJob'], self::RECORD);
        self::assertIsInt($record, 'the hourly tier records its run');
        self::assertLessThan($record, $hourly[0][0], 'the sweep runs before the hour is recorded');

        $daily = self::calls($bodies['RunDailyCronjob'], self::HELPER);
        self::assertCount(1, $daily, 'RunDailyCronjob() runs the LTV maintenance once');
        self::assertSame(self::DAILY, $daily[0][1], 'the daily tier runs the other jobs, under their budget');
        $record = strpos($bodies['RunDailyCronjob'], self::RECORD);
        self::assertIsInt($record, 'the daily tier records its run');
        self::assertLessThan($record, $daily[0][0], 'the daily jobs run before the day is recorded');

        self::assertSame([], self::calls($bodies['RunSecondsCronjob'], self::HELPER), 'not every minute');
        self::assertStringNotContainsString('LtvMaintenance', $bodies['RunSecondsCronjob'], 'not every minute');
    }

    public function testBetweenTheTiersEveryJobRunsAndTheSweepOnlyHourly(): void
    {
        self::assertNotContains(LtvMaintenance::SWEEP, LtvMaintenance::DAILY_JOBS);
        $scheduled = array_merge([LtvMaintenance::SWEEP], LtvMaintenance::DAILY_JOBS);
        sort($scheduled);
        $all = LtvMaintenance::JOBS;
        sort($all);
        self::assertSame($all, $scheduled, 'a job in neither tier runs on no default deployment');
    }

    public function testTheHelperRunsTheLockedMaintenanceWithTheTiersDeadline(): void
    {
        $body = self::index()['RunLtvMaintenance'];
        $calls = self::calls($body, self::CALL);
        self::assertCount(1, $calls, 'RunLtvMaintenance() runs the maintenance once, under its lock');
        self::assertSame(self::ARGS, $calls[0][1], 'the cron\'s connection, the tier\'s budget and its jobs');
        self::assertStringStartsWith('{try{', $body, 'the run sits in a try');
        self::assertStringContainsString(
            '}catch(\Throwable$e){error_log(',
            $body,
            'its failure is logged, not thrown into the tier'
        );
    }

    public function testTheCallReaderReadsCallsNotNames(): void
    {
        $src = '{a();MyRunLtvMaintenance(1);$o->RunLtvMaintenance(2);X::RunLtvMaintenance(3);'
            . 'RunLtvMaintenance(f(4),5);}';
        self::assertSame([[77, 'f(4),5']], self::calls($src, self::HELPER));
    }
}
