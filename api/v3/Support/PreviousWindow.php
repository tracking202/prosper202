<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\ValidationException;

/**
 * The window a report compares itself with (`compare=previous`): the one
 * before it, as the report pages' tiles mean "previous period".
 *
 * - A named period steps back by its own calendar unit in the account's
 *   zone: today is compared with yesterday up to the same time of day,
 *   this month with last month up to the same day and time (the 31st with
 *   the last day of a shorter month), this year likewise; a whole period
 *   (yesterday, last month, last year) with the whole one before it; lastN
 *   (N whole days and today so far) with the N whole days before its start.
 * - time_from and time_to given as dates are whole days: the same number of
 *   days before. Any other pair is the same number of seconds before.
 *
 * Both windows are answered as explicit time_from/time_to computed from one
 * clock, so the two reports cover what the answer says they cover. A window
 * with an open end (alltime, or one bound) has no previous one, and a
 * period with time bounds is two windows, each refused by name.
 */
final class PreviousWindow
{
    /** Periods that end at now: the previous one ends at the same point of its own unit. */
    private const TO_DATE = ['today', 'thismonth', 'thisyear', 'last7', 'last14', 'last30', 'last90'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $params already through ReportFilter::rejectUnknown(), without compare
     * @param callable(): string $timezone
     * @return array{current: array<string, mixed>, previous: array<string, mixed>,
     *     window: array{time_from: int, time_to: int}, previous_window: array{time_from: int, time_to: int}}
     */
    public static function of(array $params, callable $timezone, ?int $now = null): array
    {
        $period = $params['period'] ?? '';
        $from = TimeBound::parse($params, 'time_from', $timezone);
        $to = TimeBound::parse($params, 'time_to', $timezone);
        if ($period !== '' && ($from !== null || $to !== null)) {
            throw new ValidationException('Invalid compare', [
                'compare' => 'Compares one window: give period, or time_from and time_to, not both',
            ]);
        }

        if ($period !== '') {
            if ($period === 'alltime') {
                throw new ValidationException('Invalid compare', [
                    'compare' => 'alltime has no previous period: give a period such as last30,'
                        . ' or time_from and time_to',
                ]);
            }
            $zone = new \DateTimeZone($timezone());
            [$from, $to] = TimeBound::period($period, static fn (): string => $zone->getName(), $now ?? time());
            if ($from === null || $to === null) {
                throw new \LogicException("Period $period has an open end");
            }
            $back = static fn (int $t): int => self::stepBack($t, (string) $period, $zone);
            $prevFrom = $back($from);
            $prevTo = in_array($period, self::TO_DATE, true) ? min($back($to), $from - 1) : $from - 1;
        } else {
            if ($from === null || $to === null) {
                throw new ValidationException('Invalid compare', [
                    'compare' => 'Needs a window with both ends: a period, or time_from and time_to',
                ]);
            }
            $zone = new \DateTimeZone($timezone());
            [$prevFrom, $prevTo] = self::isDate($params['time_from']) && self::isDate($params['time_to'])
                ? self::daysBefore((string) $params['time_from'], (string) $params['time_to'], $zone)
                : [$from - ($to - $from + 1), $from - 1];
        }

        $at = static function (array $params, int $from, int $to): array {
            unset($params['period']);

            return ['time_from' => (string) $from, 'time_to' => (string) $to] + $params;
        };

        return [
            'current' => $at($params, $from, $to),
            'previous' => $at($params, $prevFrom, $prevTo),
            'window' => ['time_from' => $from, 'time_to' => $to],
            'previous_window' => ['time_from' => $prevFrom, 'time_to' => $prevTo],
        ];
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', trim($value)) === 1;
    }

    /**
     * The same number of calendar days ending the day before time_from.
     *
     * @return array{0: int, 1: int}
     */
    private static function daysBefore(string $fromDate, string $toDate, \DateTimeZone $zone): array
    {
        $first = new \DateTimeImmutable(trim($fromDate) . ' 00:00:00', $zone);
        $last = new \DateTimeImmutable(trim($toDate) . ' 00:00:00', $zone);
        $days = (int) $first->diff($last)->days + 1;

        return [$first->modify("-$days days")->getTimestamp(), $first->getTimestamp() - 1];
    }

    /** A moment one unit of the period earlier, on the account's wall clock. */
    private static function stepBack(int $t, string $period, \DateTimeZone $zone): int
    {
        $at = (new \DateTimeImmutable('@' . $t))->setTimezone($zone);
        $stepped = match ($period) {
            'today', 'yesterday' => $at->modify('-1 day'),
            'last7' => $at->modify('-7 days'),
            'last14' => $at->modify('-14 days'),
            'last30' => $at->modify('-30 days'),
            'last90' => $at->modify('-90 days'),
            'thismonth', 'lastmonth' => self::monthsBack($at, 1),
            'thisyear', 'lastyear' => self::monthsBack($at, 12),
            default => throw new \LogicException("No previous window for period $period"),
        };

        return $stepped->getTimestamp();
    }

    /** The same day and time $months earlier, the day cut to the month's last. */
    private static function monthsBack(\DateTimeImmutable $at, int $months): \DateTimeImmutable
    {
        $index = (int) $at->format('Y') * 12 + (int) $at->format('n') - 1 - $months;
        $year = intdiv($index, 12);
        $month = $index % 12 + 1;
        $lastDay = (int) $at->setDate($year, $month, 1)->format('t');

        return $at->setDate($year, $month, min((int) $at->format('j'), $lastDay));
    }
}
