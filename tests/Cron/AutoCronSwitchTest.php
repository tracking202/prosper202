<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;
use Prosper202\Cron\AutoCronSwitch;
use Tests\Support\SourceScan;

/**
 * Account › Settings' AutoCron switch: what it reads as On or Off, and when a
 * cron job's recent run leaves AutoCron off.
 */
final class AutoCronSwitchTest extends TestCase
{
    public function testOnlyTheFormsOwnValuesAreAnAnswer(): void
    {
        self::assertTrue(AutoCronSwitch::posted('1'));
        self::assertFalse(AutoCronSwitch::posted('0'));
        // `== true` read every non-empty string but "0" as On.
        foreach (['off', 'false', 'true', 'on', '', ' 1', '01', 1, 0, true, null, ['1']] as $value) {
            self::assertNull(AutoCronSwitch::posted($value), var_export($value, true));
        }
    }

    public function testACronJobsRunInTheLastFiveMinutesLeavesAutoCronOff(): void
    {
        $now = 1_800_000_000;
        self::assertTrue(AutoCronSwitch::ownCronJobRanRecently($now, false, $now));
        self::assertTrue(AutoCronSwitch::ownCronJobRanRecently($now - 300, false, $now), 'five minutes ago');
        self::assertFalse(AutoCronSwitch::ownCronJobRanRecently($now - 301, false, $now));
        self::assertFalse(AutoCronSwitch::ownCronJobRanRecently(null, false, $now), 'no run recorded');
        self::assertTrue(AutoCronSwitch::ownCronJobRanRecently($now + 60, false, $now), 'a clock ahead');
    }

    public function testWithAutoCronOnItsOwnRunsAreNotAReason(): void
    {
        $now = 1_800_000_000;
        self::assertFalse(AutoCronSwitch::ownCronJobRanRecently($now - 30, true, $now));
    }

    /** The page decides through these, from the posted value and row 1. */
    public function testSettingsAsksTheseQuestions(): void
    {
        $page = (string) file_get_contents(SourceScan::repoRoot() . '/202-account/administration.php');
        $start = strpos($page, "if (isset(\$_POST['autocron'])) {");
        self::assertIsInt($start);
        $handler = substr($page, $start, (int) strpos($page, "if (isset(\$_POST['maxmind']))") - $start);
        self::assertSame(1, substr_count($handler, "AutoCronSwitch::posted(\$_POST['autocron'])"));
        self::assertSame(2, substr_count($handler, "\$_POST['autocron']"), 'the isset, and one strict read');
        $decides = 'AutoCronSwitch::ownCronJobRanRecently($lastRun, $alreadyOn, time())';
        self::assertSame(1, substr_count($handler, $decides));
        self::assertStringContainsString('FROM 202_cronjob_logs WHERE id = 1', $handler);
    }
}
