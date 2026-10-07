<?php

declare(strict_types=1);

namespace Tracking202\Report;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The report pages' named windows (today, yesterday, last7 ... alltime) as
 * unix bounds, both inclusive, computed in a named time zone.
 *
 * grab_timeframe() used to compute them from date() and mktime() in the
 * process's default zone, which every page set from the zone the session
 * captured at sign-in, and stepped back across days in 86400-second strides:
 *
 *  - the zone was the session's, not the account's: after the account's
 *    zone changed (Personal Settings in another session, `p202 user
 *    update`), every page went on counting days from the old midnight
 *    until the user signed in again, while GET /reports/* counted from the
 *    new one;
 *  - a day is not 86400 seconds across a DST change, so "yesterday" just
 *    after a spring-forward midnight resolved to the day before yesterday,
 *    and "last 7 days" started a day early.
 *
 * The starts are api/v3 TimeBound::period()'s, calendar arithmetic in the
 * zone (tests/Report/ReportWindowTest holds the two to each other). The ends
 * are the pages' own: the end of the last whole day of a past period, and of
 * today for one that runs to now, where TimeBound stops at the second asked
 * — no click is later than now, so they count the same clicks. lastN is the
 * pages' meaning, N whole days before today plus today, not TimeBound's N
 * times 24 hours to the second.
 */
final class ReportWindow
{
    /** The calendar's presets, in its order. */
    public const PRESETS = ['today', 'yesterday', 'last7', 'last14', 'last30', 'thismonth', 'lastmonth', 'thisyear', 'lastyear', 'alltime'];

    private const ROLLING_DAYS = ['last7' => 7, 'last14' => 14, 'last30' => 30];

    private function __construct()
    {
    }

    /**
     * @param string $zone a time zone PHP knows (the account's)
     * @param int $now the clock
     * @param ?int $registeredAt when the account was created, for alltime
     * @return array{from: int, to: int}
     */
    public static function preset(string $preset, string $zone, int $now, ?int $registeredAt = null): array
    {
        if (!in_array($preset, self::PRESETS, true)) {
            throw new \InvalidArgumentException("ReportWindow: '$preset' is not a date range the calendar offers");
        }
        $tz = new DateTimeZone($zone);
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0, 0);
        $endOf = static fn (DateTimeImmutable $day): int => $day->modify('+1 day')->getTimestamp() - 1;
        $endOfToday = $endOf($today);
        $month = $today->modify('first day of this month');
        $year = $today->setDate((int) $today->format('Y'), 1, 1);

        if (isset(self::ROLLING_DAYS[$preset])) {
            return ['from' => $today->modify('-' . self::ROLLING_DAYS[$preset] . ' days')->getTimestamp(), 'to' => $endOfToday];
        }

        [$from, $to] = match ($preset) {
            'today' => [$today->getTimestamp(), $endOfToday],
            'yesterday' => [$today->modify('-1 day')->getTimestamp(), $today->getTimestamp() - 1],
            'thismonth' => [$month->getTimestamp(), $endOfToday],
            'lastmonth' => [$month->modify('-1 month')->getTimestamp(), $month->getTimestamp() - 1],
            'thisyear' => [$year->getTimestamp(), $endOfToday],
            'lastyear' => [$year->modify('-1 year')->getTimestamp(), $year->getTimestamp() - 1],
            'alltime' => [
                (new DateTimeImmutable('@' . ($registeredAt ?? $now)))->setTimezone($tz)->setTime(0, 0, 0)->getTimestamp(),
                $endOfToday,
            ],
        };

        return ['from' => $from, 'to' => $to];
    }
}
