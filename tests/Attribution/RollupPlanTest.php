<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\AttributionRollup;
use Prosper202\Report\RollupDirty;

/**
 * The pure parts of the report rollup: the range arithmetic a plan is made
 * of, the dimension table it stores, the name lookup it reads names through,
 * and the hot-path freshness rule. RollupMatchesFullComputationTest is the
 * differential test against a database.
 */
final class RollupPlanTest extends TestCase
{
    public function testEveryReportDimensionHasOneStoredCode(): void
    {
        self::assertSame(AttributionReports::dimensions(), array_keys(AttributionRollup::DIMENSION_CODES));
        $codes = array_values(AttributionRollup::DIMENSION_CODES);
        self::assertSame(count($codes), count(array_unique($codes)), 'codes are distinct');
        self::assertNotContains(0, $codes, '0 is the totals part\'s dimension');
    }

    public function testNamesAreLookedUpInTheTableTheirJoinNames(): void
    {
        foreach (AttributionReports::dimensions() as $dim) {
            if ($dim === 'day') {
                continue;
            }
            [$table, $id, $name] = AttributionReports::nameLookup($dim);
            [, $nameSql, $joins] = AttributionReports::dimensionSql($dim, 'c.click_time');
            self::assertSame("dn.$name", $nameSql, $dim);
            self::assertStringContainsString("LEFT JOIN $table dn ON dn.$id = ", $joins, $dim);
        }
    }

    /**
     * The hours the day dimension cannot take from the rollup, against the
     * definition, hour by hour: an hour is on two of the account's dates
     * when its first and last second fall on different dates, or under
     * different offsets (a jump in the wall clock). Zones whose midnights
     * are not on the hour, half-hour DST, changes at 00:01 local
     * (Moncton until 2006), and zones that are always on the hour.
     */
    public function testTheHoursOnTwoDatesAreTheOnesWhoseEndsDisagree(): void
    {
        $zones = ['UTC', 'GMT', '+05:30', 'Asia/Kolkata', 'Asia/Kathmandu', 'America/St_Johns', 'America/New_York',
            'Australia/Lord_Howe', 'Pacific/Chatham', 'America/Moncton', 'Pacific/Kiritimati', 'Europe/London'];
        $now = 1_791_331_200;
        $windows = [['2005-01-01', '2006-12-31'], ['2025-01-01', '2026-10-07']];
        $found = 0;
        foreach ($zones as $zone) {
            $tz = new \DateTimeZone($zone);
            foreach ($windows as [$a, $b]) {
                $first = intdiv((new \DateTimeImmutable($a . 'T00:00:00Z'))->getTimestamp(), 3600);
                $last = intdiv((new \DateTimeImmutable($b . 'T23:59:59Z'))->getTimestamp(), 3600);
                $expected = [];
                for ($h = $first; $h <= $last; $h++) {
                    $start = (new \DateTimeImmutable('@' . ($h * 3600)))->setTimezone($tz);
                    $end = (new \DateTimeImmutable('@' . ($h * 3600 + 3599)))->setTimezone($tz);
                    if ($start->format('Y-m-d') !== $end->format('Y-m-d') || $start->getOffset() !== $end->getOffset()) {
                        $expected[] = $h;
                    }
                }
                self::assertSame($expected, AttributionRollup::hoursAcrossLocalDates($zone, $first, $last, $now), "$zone, $a to $b");
                $found += count($expected);
            }
        }
        self::assertGreaterThan(2000, $found, 'the zones off the hour put hours on two dates every day');
        self::assertSame([], AttributionRollup::hoursAcrossLocalDates('Asia/Kolkata', 10, 9), 'an empty range has none');
    }

    public function testTheKeyWithoutItsNameJoinKeepsEveryOtherJoin(): void
    {
        [$key, $joins] = AttributionRollup::keySql('device', 'cr.conv_time');
        self::assertSame('dm.device_type', $key);
        self::assertStringContainsString('LEFT JOIN 202_clicks_advance ca', $joins);
        self::assertStringContainsString('LEFT JOIN 202_device_models dm', $joins);
        self::assertStringNotContainsString(' dn ', $joins);
        self::assertSame(['0', ''], AttributionRollup::keySql('day', 'cr.conv_time'), 'the day is the hour, grouped by its local date when read');
    }

    /** @return array<string, array{0: list<array{0: int, 1: int}>, 1: list<array{0: int, 1: int}>, 2: list<array{0: int, 1: int}>}> */
    public static function subtractions(): array
    {
        return [
            'nothing removed' => [[[1, 10]], [], [[1, 10]]],
            'middle' => [[[1, 10]], [[4, 5]], [[1, 3], [6, 10]]],
            'edges' => [[[1, 10]], [[1, 1], [10, 10]], [[2, 9]]],
            'everything' => [[[1, 10]], [[0, 20]], []],
            'overlapping and unsorted' => [[[1, 10]], [[7, 8], [2, 3], [3, 4]], [[1, 1], [5, 6], [9, 10]]],
            'outside' => [[[5, 6]], [[1, 2], [8, 9]], [[5, 6]]],
            'adjacent results merge' => [[[1, 3], [4, 6]], [], [[1, 6]]],
            'seconds of a range minus whole hours' => [[[3_599, 10_801]], [[3_600, 7_199], [7_200, 10_799]], [[3_599, 3_599], [10_800, 10_801]]],
        ];
    }

    /**
     * @dataProvider subtractions
     * @param list<array{0: int, 1: int}> $ranges
     * @param list<array{0: int, 1: int}> $minus
     * @param list<array{0: int, 1: int}> $expected
     */
    public function testSubtractIsExactOnClosedIntegerRanges(array $ranges, array $minus, array $expected): void
    {
        self::assertSame($expected, AttributionReports::subtract($ranges, $minus));
    }

    public function testSubtractAgreesWithABruteForceSetDifference(): void
    {
        mt_srand(99);
        for ($case = 0; $case < 500; $case++) {
            $ranges = [];
            $minus = [];
            for ($i = mt_rand(1, 3); $i > 0; $i--) {
                $a = mt_rand(0, 60);
                $ranges[] = [$a, $a + mt_rand(0, 20)];
            }
            for ($i = mt_rand(0, 5); $i > 0; $i--) {
                $a = mt_rand(0, 80);
                $minus[] = [$a, $a + mt_rand(0, 10)];
            }
            $want = [];
            foreach ($ranges as [$a, $b]) {
                for ($x = $a; $x <= $b; $x++) {
                    $want[$x] = true;
                }
            }
            foreach ($minus as [$a, $b]) {
                for ($x = $a; $x <= $b; $x++) {
                    unset($want[$x]);
                }
            }
            $got = [];
            foreach (AttributionReports::subtract($ranges, $minus) as [$a, $b]) {
                self::assertLessThanOrEqual($b, $a);
                for ($x = $a; $x <= $b; $x++) {
                    self::assertArrayNotHasKey($x, $got, 'no point twice');
                    $got[$x] = true;
                }
            }
            ksort($want);
            ksort($got);
            self::assertSame(array_keys($want), array_keys($got), json_encode([$ranges, $minus]));
        }
    }

    public function testAHotPathRewriteMarksAnythingItCannotShowIsYoung(): void
    {
        $now = 1_800_000_000;
        self::assertFalse(RollupDirty::hotPathRewriteNeedsMark($now - 60, $now), 'a click a minute old');
        self::assertFalse(RollupDirty::hotPathRewriteNeedsMark((string) ($now - RollupDirty::HOT_PATH_SECONDS), $now), 'exactly the horizon');
        self::assertTrue(RollupDirty::hotPathRewriteNeedsMark($now - RollupDirty::HOT_PATH_SECONDS - 1, $now));
        foreach ([null, '', 'abc', '12.5', -5, false, []] as $unreadable) {
            self::assertTrue(RollupDirty::hotPathRewriteNeedsMark($unreadable, $now), var_export($unreadable, true) . ' is marked');
        }
        self::assertLessThan(AttributionRollup::SEAL_SECONDS, RollupDirty::HOT_PATH_SECONDS, 'a skipped mark always leaves time before the hour is summed');
    }
}
