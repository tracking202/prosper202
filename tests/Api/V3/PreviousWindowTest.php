<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\PreviousWindow;
use PHPUnit\Framework\TestCase;

/** The window compare=previous reads, on the account's wall clock. */
final class PreviousWindowTest extends TestCase
{
    private const ZONE = 'America/New_York';

    private static function local(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private static function windows(array $params, string $now): array
    {
        $w = PreviousWindow::of($params, static fn (): string => self::ZONE, self::local($now));
        $show = static fn (int $t): string => (new \DateTimeImmutable('@' . $t))
            ->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d H:i:s');

        return [
            $show($w['window']['time_from']), $show($w['window']['time_to']),
            $show($w['previous_window']['time_from']), $show($w['previous_window']['time_to']),
        ];
    }

    /** @return array<string, array{0: array<string, string>, 1: string, 2: list<string>}> */
    public static function periods(): array
    {
        $seconds = [
            'time_from' => (string) self::local('2026-03-09 10:00:00'),
            'time_to' => (string) self::local('2026-03-09 11:29:59'),
        ];

        return [
            'today, against yesterday to the same time' => [
                ['period' => 'today'], '2026-03-09 14:00:00',
                ['2026-03-09 00:00:00', '2026-03-09 14:00:00', '2026-03-08 00:00:00', '2026-03-08 14:00:00'],
            ],
            'yesterday, a 23-hour day, against the whole day before' => [
                ['period' => 'yesterday'], '2026-03-09 14:00:00',
                ['2026-03-08 00:00:00', '2026-03-08 23:59:59', '2026-03-07 00:00:00', '2026-03-07 23:59:59'],
            ],
            'last7, against the seven whole days before' => [
                ['period' => 'last7'], '2026-03-20 09:15:00',
                ['2026-03-13 00:00:00', '2026-03-20 09:15:00', '2026-03-06 00:00:00', '2026-03-12 23:59:59'],
            ],
            'this month on the 31st, against all of a shorter month' => [
                ['period' => 'thismonth'], '2026-03-31 17:00:00',
                ['2026-03-01 00:00:00', '2026-03-31 17:00:00', '2026-02-01 00:00:00', '2026-02-28 17:00:00'],
            ],
            'this month on the 10th, against last month to the 10th' => [
                ['period' => 'thismonth'], '2026-01-10 08:00:00',
                ['2026-01-01 00:00:00', '2026-01-10 08:00:00', '2025-12-01 00:00:00', '2025-12-10 08:00:00'],
            ],
            'last month, against the whole month before' => [
                ['period' => 'lastmonth'], '2026-03-31 17:00:00',
                ['2026-02-01 00:00:00', '2026-02-28 23:59:59', '2026-01-01 00:00:00', '2026-01-31 23:59:59'],
            ],
            'this year on a leap day, against last year to the 28th' => [
                ['period' => 'thisyear'], '2028-02-29 12:00:00',
                ['2028-01-01 00:00:00', '2028-02-29 12:00:00', '2027-01-01 00:00:00', '2027-02-28 12:00:00'],
            ],
            'last year, against the whole year before' => [
                ['period' => 'lastyear'], '2026-06-01 12:00:00',
                ['2025-01-01 00:00:00', '2025-12-31 23:59:59', '2024-01-01 00:00:00', '2024-12-31 23:59:59'],
            ],
            'dates, as whole days across a clock change' => [
                ['time_from' => '2026-03-07', 'time_to' => '2026-03-11'], '2026-06-01 12:00:00',
                ['2026-03-07 00:00:00', '2026-03-11 23:59:59', '2026-03-02 00:00:00', '2026-03-06 23:59:59'],
            ],
            'seconds, as the same number of seconds' => [
                $seconds, '2026-06-01 12:00:00',
                ['2026-03-09 10:00:00', '2026-03-09 11:29:59', '2026-03-09 08:30:00', '2026-03-09 09:59:59'],
            ],
        ];
    }

    /**
     * @dataProvider periods
     * @param array<string, string> $params
     * @param list<string> $expected
     */
    public function testThePreviousWindowIsTheOneBeforeOnTheAccountsClock(
        array $params,
        string $now,
        array $expected
    ): void {
        self::assertSame($expected, self::windows($params, $now));
    }

    /** A period added to TimeBound without a step back would answer compare=previous with a 500. */
    public function testEveryPeriodButAllTimeHasAWindowBeforeIt(): void
    {
        $now = self::local('2026-03-09 14:00:00');
        foreach (\Api\V3\Support\TimeBound::PERIODS as $period) {
            if ($period === 'alltime') {
                continue;
            }
            $w = PreviousWindow::of(['period' => $period], static fn (): string => self::ZONE, $now);
            self::assertLessThan($w['window']['time_from'], $w['previous_window']['time_to'], $period);
            self::assertLessThan($w['previous_window']['time_to'], $w['previous_window']['time_from'], $period);
        }
    }

    public function testBothWindowsAreAnsweredAsExplicitBoundsAndKeepTheFilters(): void
    {
        $w = PreviousWindow::of(
            ['period' => 'yesterday', 'aff_campaign_id' => '4,5'],
            static fn (): string => self::ZONE,
            self::local('2026-03-09 14:00:00')
        );
        self::assertSame([
            'time_from' => (string) self::local('2026-03-08 00:00:00'),
            'time_to' => (string) self::local('2026-03-08 23:59:59'),
            'aff_campaign_id' => '4,5',
        ], $w['current']);
        self::assertSame([
            'time_from' => (string) self::local('2026-03-07 00:00:00'),
            'time_to' => (string) self::local('2026-03-07 23:59:59'),
            'aff_campaign_id' => '4,5',
        ], $w['previous']);
    }
}
