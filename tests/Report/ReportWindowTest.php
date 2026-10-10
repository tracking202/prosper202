<?php

declare(strict_types=1);

namespace Tests\Report;

use Api\V3\Support\TimeBound;
use PHPUnit\Framework\TestCase;
use Tracking202\Report\ReportWindow;

/**
 * The report pages' named windows, executed at fixed instants in zones far
 * from the server's and across DST changes, against api/v3 TimeBound — the
 * API's reading of the same names — so the page and GET /reports/* start
 * every period at the same second.
 */
final class ReportWindowTest extends TestCase
{
    /** Zones far apart, a half-hour one, and two with DST on different calendars. */
    private const ZONES = ['UTC', 'America/New_York', 'Asia/Kolkata', 'Pacific/Kiritimati', 'Australia/Lord_Howe', 'America/Sao_Paulo'];

    /** @return list<int> instants: ordinary, and either side of DST changes and month and year ends */
    private static function instants(): array
    {
        return array_map(static fn (string $t): int => (new \DateTimeImmutable($t))->getTimestamp(), [
            '2026-10-07T10:49:00Z',
            '2026-03-09T04:30:00Z', // New York 00:30, the day after spring forward
            '2026-11-02T05:30:00Z', // New York 00:30, the day after fall back
            '2026-01-01T00:10:00Z', // a new year in UTC, still the old one in the Americas
            '2026-03-01T03:00:00Z', // month ends either side of UTC
            '2026-04-05T15:30:00Z', // Lord Howe's half-hour DST change
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function calendarPeriods(): iterable
    {
        foreach (['today', 'yesterday', 'last7', 'last14', 'last30', 'thismonth', 'lastmonth', 'thisyear', 'lastyear'] as $p) {
            yield $p => [$p];
        }
    }

    /** @dataProvider calendarPeriods */
    public function testACalendarPeriodStartsWhereTheApisDoesInEveryZone(string $period): void
    {
        foreach (self::ZONES as $zone) {
            foreach (self::instants() as $now) {
                $page = ReportWindow::preset($period, $zone, $now);
                [$from, $to] = TimeBound::period($period, static fn (): string => $zone, $now);
                $at = "$period in $zone at " . gmdate('Y-m-d H:i', $now) . 'Z';
                self::assertSame($from, $page['from'], "$at: the start");
                if (in_array($period, ['yesterday', 'lastmonth', 'lastyear'], true)) {
                    self::assertSame($to, $page['to'], "$at: a past period ends where the API's does");
                } else {
                    // The API stops at now, the page at the end of today: no
                    // click is later than now, so both count the same clicks.
                    self::assertSame($now, $to);
                    self::assertGreaterThanOrEqual($now, $page['to'], "$at: the end");
                    $endOfToday = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($zone))
                        ->setTime(0, 0, 0)->modify('+1 day')->getTimestamp() - 1;
                    self::assertSame($endOfToday, $page['to'], "$at: the end of today in the zone");
                }
            }
        }
    }

    public function testYesterdayAfterSpringForwardIsTheShortDayNotTheOneBefore(): void
    {
        // New York, 00:30 on 9 March 2026: yesterday is 8 March, 23 hours
        // long. Stepping back 86400 seconds from now lands on 7 March, which
        // is what the pages showed.
        $now = (new \DateTimeImmutable('2026-03-09 00:30:00', new \DateTimeZone('America/New_York')))->getTimestamp();
        $w = ReportWindow::preset('yesterday', 'America/New_York', $now);
        $ny = static fn (int $t): string => (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d H:i:s');
        self::assertSame(['2026-03-08 00:00:00', '2026-03-08 23:59:59'], [$ny($w['from']), $ny($w['to'])]);
        self::assertSame(23 * 3600 - 1, $w['to'] - $w['from']);
    }

    public function testTheSameInstantIsADifferentDayInAnotherZone(): void
    {
        // 02:00 UTC on 7 October: still the 6th in New York, the 7th in Tokyo.
        $now = (new \DateTimeImmutable('2026-10-07T02:00:00Z'))->getTimestamp();
        self::assertSame(
            (new \DateTimeImmutable('2026-10-06 00:00:00', new \DateTimeZone('America/New_York')))->getTimestamp(),
            ReportWindow::preset('today', 'America/New_York', $now)['from']
        );
        self::assertSame(
            (new \DateTimeImmutable('2026-10-07 00:00:00', new \DateTimeZone('Asia/Tokyo')))->getTimestamp(),
            ReportWindow::preset('today', 'Asia/Tokyo', $now)['from']
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function lastDays(): iterable
    {
        yield 'last7' => ['last7', 7];
        yield 'last14' => ['last14', 14];
        yield 'last30' => ['last30', 30];
    }

    /**
     * The pages' lastN: N whole days before today, and today, in the zone,
     * whatever DST did in between.
     *
     * @dataProvider lastDays
     */
    public function testLastNIsWholeDaysOfTheZone(string $preset, int $days): void
    {
        foreach (self::ZONES as $zone) {
            foreach (self::instants() as $now) {
                $w = ReportWindow::preset($preset, $zone, $now);
                $tz = new \DateTimeZone($zone);
                $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
                $first = (new \DateTimeImmutable('@' . $w['from']))->setTimezone($tz);
                self::assertSame('00:00:00', $first->format('H:i:s'), "$preset in $zone starts at a midnight");
                self::assertSame($today->modify("-$days days")->format('Y-m-d'), $first->format('Y-m-d'), "$preset in $zone");
                self::assertSame(ReportWindow::preset('today', $zone, $now)['to'], $w['to']);
            }
        }
    }

    public function testAllTimeStartsAtTheMidnightOfTheDayTheAccountWasCreated(): void
    {
        $registered = (new \DateTimeImmutable('2025-02-03 17:45:00', new \DateTimeZone('Asia/Kolkata')))->getTimestamp();
        $now = (new \DateTimeImmutable('2026-10-07T10:49:00Z'))->getTimestamp();
        self::assertSame(
            (new \DateTimeImmutable('2025-02-03 00:00:00', new \DateTimeZone('Asia/Kolkata')))->getTimestamp(),
            ReportWindow::preset('alltime', 'Asia/Kolkata', $now, $registered)['from']
        );
    }

    public function testANameTheCalendarDoesNotOfferIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReportWindow::preset('last7d', 'UTC', 0);
    }

    public function testEveryPresetTheFilterFormsAcceptIsOne(): void
    {
        self::assertSame(\Tracking202\Report\ReportFilterInput::RANGES, ReportWindow::PRESETS);
    }
}
