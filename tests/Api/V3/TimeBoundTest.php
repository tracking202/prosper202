<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Exception\ValidationException;
use Api\V3\Support\TimeBound;
use PHPUnit\Framework\TestCase;

/**
 * time_from/time_to were read with (int): `2026-10-01` was 2026 (a second in
 * 1970) and the list answered with every row, as if it were the ones asked
 * for. These are the values TimeBound reads and the ones it refuses.
 */
final class TimeBoundTest extends TestCase
{
    private static function newYork(): string
    {
        return 'America/New_York';
    }

    /** @return iterable<string, array{0: string, 1: mixed, 2: ?int}> */
    public static function accepted(): iterable
    {
        yield 'unix seconds' => ['time_from', '1759276800', 1759276800];
        yield 'unix seconds as an integer' => ['time_from', 1759276800, 1759276800];
        yield 'zero is no bound' => ['time_to', '0', null];
        yield 'absent' => ['time_to', '', null];
        yield 'a date starts at midnight in the account timezone' => ['time_from', '2026-10-01', 1790827200];
        yield 'a date ends at its last second' => ['time_to', '2026-10-01', 1790913599];
        yield 'the day the clocks go forward ends at 23:59:59 local' => ['time_to', '2026-03-08', 1773028799];
        yield 'a UTC time without seconds' => ['time_from', '2026-10-01T09:30Z', 1790847000];
        yield 'a time with an offset' => ['time_from', '2026-10-01T09:30:00+02:00', 1790839800];
    }

    /** @dataProvider accepted */
    public function testReads(string $field, mixed $value, ?int $expected): void
    {
        self::assertSame($expected, TimeBound::parse([$field => $value], $field, self::newYork(...)));
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function refused(): iterable
    {
        yield 'a date that does not exist' => ['2026-02-30', 'is not a date'];
        yield 'a time that does not exist' => ['2026-02-28T25:00Z', 'is not a real time'];
        yield 'a time on a date that does not exist' => ['2026-02-30T09:00Z', 'is not a real time'];
        yield 'milliseconds' => ['1759276800000', 'send seconds (1759276800)'];
        yield 'negative' => ['-5', 'Unix seconds'];
        yield 'scientific notation' => ['1.5e9', 'Unix seconds'];
        yield 'a time with no offset' => ['2026-10-01 09:30', 'Unix seconds'];
        yield 'true' => [true, 'Unix seconds'];
        yield 'a list' => [['1'], 'Unix seconds'];
    }

    /** @dataProvider refused */
    public function testRefuses(mixed $value, string $says): void
    {
        try {
            TimeBound::parse(['time_from' => $value], 'time_from', self::newYork(...));
            self::fail('read ' . json_encode($value));
        } catch (ValidationException $e) {
            self::assertStringContainsString($says, $e->getFieldErrors()['time_from'] ?? '');
        }
    }

    public function testABackwardsRangeIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        TimeBound::window(['time_from' => '2026-10-02', 'time_to' => '2026-10-01'], self::newYork(...));
    }

    public function testTheTimezoneIsAskedForOnlyWhenADateNeedsIt(): void
    {
        $asked = false;
        $tz = static function () use (&$asked): string {
            $asked = true;

            return 'UTC';
        };
        self::assertSame([5, 10], TimeBound::window(['time_from' => '5', 'time_to' => '10'], $tz));
        self::assertFalse($asked, 'a query per request for nothing');
    }

    // ─── named periods ──────────────────────────────────────────────

    /** 2026-10-07 03:25:00 UTC: 23:25 on the 6th in New York, 03:25 on the 7th in UTC. */
    private const NOW = 1791343500;

    /**
     * New York wall times, inclusive.
     *
     * @return iterable<string, array{0: string, 1: ?string, 2: ?string}>
     */
    public static function periods(): iterable
    {
        yield 'today starts at the account\'s midnight' => ['today', '2026-10-06 00:00:00', '2026-10-06 23:25:00'];
        yield 'yesterday is the account\'s whole day before'
            => ['yesterday', '2026-10-05 00:00:00', '2026-10-05 23:59:59'];
        yield 'last7 is 7 days to the second' => ['last7', '2026-09-29 23:25:00', '2026-10-06 23:25:00'];
        yield 'last14' => ['last14', '2026-09-22 23:25:00', '2026-10-06 23:25:00'];
        yield 'last30' => ['last30', '2026-09-06 23:25:00', '2026-10-06 23:25:00'];
        yield 'last90' => ['last90', '2026-07-08 23:25:00', '2026-10-06 23:25:00'];
        yield 'thismonth runs from the 1st to now' => ['thismonth', '2026-10-01 00:00:00', '2026-10-06 23:25:00'];
        yield 'lastmonth is the whole previous month' => ['lastmonth', '2026-09-01 00:00:00', '2026-09-30 23:59:59'];
        yield 'thisyear runs from January 1 to now' => ['thisyear', '2026-01-01 00:00:00', '2026-10-06 23:25:00'];
        yield 'lastyear is the whole previous year' => ['lastyear', '2025-01-01 00:00:00', '2025-12-31 23:59:59'];
        yield 'alltime has no bounds' => ['alltime', null, null];
    }

    /** @dataProvider periods */
    public function testAPeriodIsComputedInTheAccountsTimezone(string $period, ?string $from, ?string $to): void
    {
        $at = static fn (?string $wall): ?int => $wall === null ? null
            : (new \DateTimeImmutable($wall, new \DateTimeZone('America/New_York')))->getTimestamp();
        self::assertSame([$at($from), $at($to)], TimeBound::period($period, self::newYork(...), self::NOW));
    }

    public function testEveryPeriodIsListedAndComputed(): void
    {
        $listed = [];
        foreach (self::periods() as [$period]) {
            $listed[] = $period;
        }
        self::assertSame(TimeBound::PERIODS, $listed);
    }

    /**
     * The before/after of moving today and yesterday off the server's clock:
     * the controllers computed them with strtotime('today midnight'), the
     * server's midnight. At 23:25 in New York the server (UTC) is already on
     * the next day, so "today" was the account's last 35 minutes of
     * tomorrow's date and its real today was "yesterday".
     */
    public function testTodayAndYesterdayAreNotTheServersDays(): void
    {
        $serverToday = (new \DateTimeImmutable('@' . self::NOW))->setTime(0, 0)->getTimestamp(); // UTC midnight
        [$from] = TimeBound::period('today', self::newYork(...), self::NOW);
        self::assertNotSame($serverToday, $from);
        self::assertSame(
            $serverToday - 86400 + 4 * 3600,
            $from,
            'New York\'s midnight of the 6th is 04:00 UTC on the 6th'
        );

        [$from, $to] = TimeBound::period('yesterday', self::newYork(...), self::NOW);
        self::assertSame(86400 - 1, $to - $from);
    }

    public function testYesterdayAcrossTheClockChangeIsItsRealLength(): void
    {
        // 2026-11-01 is the US fall-back day: 25 hours long.
        $now = (new \DateTimeImmutable('2026-11-02 12:00:00', new \DateTimeZone('America/New_York')))->getTimestamp();
        [$from, $to] = TimeBound::period('yesterday', self::newYork(...), $now);
        self::assertSame(25 * 3600, $to - $from + 1);
        // And lastmonth on March 31 is February, whole.
        $now = (new \DateTimeImmutable('2026-03-31 12:00:00', new \DateTimeZone('America/New_York')))->getTimestamp();
        [$from, $to] = TimeBound::period('lastmonth', self::newYork(...), $now);
        $wall = static fn (int $at): string => (new \DateTimeImmutable('@' . $at))
            ->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d H:i:s');
        self::assertSame('2026-02-01 00:00:00 / 2026-02-28 23:59:59', $wall($from) . ' / ' . $wall($to));
    }

    public function testARollingPeriodNeverAsksForTheTimezone(): void
    {
        $tz = static function (): string {
            throw new \LogicException('asked');
        };
        foreach (['last7', 'last14', 'last30', 'last90', 'alltime'] as $period) {
            TimeBound::period($period, $tz, self::NOW);
        }
        $this->addToAssertionCount(1);
    }

    public function testAnUnknownPeriodNamesTheValidOnes(): void
    {
        foreach (['last7d', 'Today', '', 7, ['today']] as $bad) {
            try {
                TimeBound::period($bad, self::newYork(...), self::NOW);
                self::fail('accepted ' . var_export($bad, true));
            } catch (ValidationException $e) {
                self::assertSame('Valid: ' . implode(', ', TimeBound::PERIODS), $e->getFieldErrors()['period']);
            }
        }
        // A caller may narrow the set; the message names its own list.
        try {
            TimeBound::period('thisyear', self::newYork(...), self::NOW, ['today', 'last7']);
            self::fail('a period outside the caller\'s set was accepted');
        } catch (ValidationException $e) {
            self::assertSame('Valid: today, last7', $e->getFieldErrors()['period']);
        }
    }
}
