<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\ValidationException;

/**
 * time_from / time_to as a list or report reads them.
 *
 * They were read with (int), so `2026-10-01` was 2026 — a second in 1970 —
 * and the answer was every row of the account, reading as the ones asked
 * for; a JavaScript millisecond timestamp was a date in the year 57000 and
 * the answer was empty. Accepted now:
 *
 * - unix seconds, as before;
 * - a date, YYYY-MM-DD, in the account's timezone — what the date pickers
 *   on the report pages mean: time_from is the start of that day, time_to
 *   its last second (both bounds are inclusive);
 * - a time with its offset, 2026-10-01T09:30:00Z or …+02:00.
 *
 * Anything else is a 422 naming the field. 0 is no bound, as it always was.
 *
 * `period` is the named window the report pages' range picker offers, and
 * period() is the one place it is computed. A calendar period (today,
 * yesterday, this/last month, this/last year) starts at a midnight in the
 * ACCOUNT's timezone, as the date forms above do: today and yesterday were
 * strtotime('today midnight') in each controller, which is the server's
 * midnight, so a New York account asking at 23:30 its time for "yesterday"
 * got a window that ended four hours into its yesterday.
 */
final class TimeBound
{
    private const FORMATS = "Unix seconds, a date (YYYY-MM-DD, in the account's timezone) or a time with its offset (2026-10-01T09:30:00Z)";

    /**
     * The named periods, in the range picker's order (last90 is the API's
     * own addition). lastN is the N days up to now, to the second.
     */
    public const PERIODS = [
        'today', 'yesterday', 'last7', 'last14', 'last30', 'last90',
        'thismonth', 'lastmonth', 'thisyear', 'lastyear', 'alltime',
    ];

    /** lastN periods: whole days back from now. */
    private const ROLLING_DAYS = ['last7' => 7, 'last14' => 14, 'last30' => 30, 'last90' => 90];

    private function __construct()
    {
    }

    /**
     * A named period's bounds, both inclusive; null is no bound (alltime has
     * neither). A value that is not one of PERIODS is a 422 naming it — a
     * typo like last7d must never fall through to all time.
     *
     * @param callable(): string $timezone the account's timezone; asked only for a calendar period
     * @param ?int $now the clock, for tests; time() when null
     * @param list<string> $allowed the periods this caller accepts (default all of PERIODS)
     * @return array{0: ?int, 1: ?int}
     */
    public static function period(
        mixed $period,
        callable $timezone,
        ?int $now = null,
        array $allowed = self::PERIODS
    ): array {
        if (!is_string($period) || !in_array($period, $allowed, true)) {
            throw new ValidationException('Invalid period', ['period' => 'Valid: ' . implode(', ', $allowed)]);
        }
        $now ??= time();
        if (isset(self::ROLLING_DAYS[$period])) {
            return [$now - self::ROLLING_DAYS[$period] * 86400, $now];
        }
        if ($period === 'alltime') {
            return [null, null];
        }

        $today = (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone($timezone()))
            ->setTime(0, 0, 0);
        $month = $today->modify('first day of this month');
        $year = $today->setDate((int) $today->format('Y'), 1, 1);

        return match ($period) {
            'today'     => [$today->getTimestamp(), $now],
            // Calendar arithmetic, not 86400 seconds: across a DST change
            // yesterday is 23 or 25 hours long.
            'yesterday' => [$today->modify('-1 day')->getTimestamp(), $today->getTimestamp() - 1],
            'thismonth' => [$month->getTimestamp(), $now],
            'lastmonth' => [$month->modify('-1 month')->getTimestamp(), $month->getTimestamp() - 1],
            'thisyear'  => [$year->getTimestamp(), $now],
            'lastyear'  => [$year->modify('-1 year')->getTimestamp(), $year->getTimestamp() - 1],
        };
    }

    /**
     * Both bounds, refused together when they are the wrong way round.
     *
     * @param array<string, mixed> $params
     * @param callable(): string $timezone the account's timezone; asked only when a date is given
     * @return array{0: ?int, 1: ?int}
     */
    public static function window(array $params, callable $timezone): array
    {
        $from = self::parse($params, 'time_from', $timezone);
        $to = self::parse($params, 'time_to', $timezone);
        if ($from !== null && $to !== null && $from > $to) {
            throw new ValidationException('Invalid time range', ['time_from' => 'Must not be after time_to']);
        }

        return [$from, $to];
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(): string $timezone
     */
    public static function parse(array $params, string $field, callable $timezone): ?int
    {
        $value = $params[$field] ?? null;
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new ValidationException('Invalid ' . $field, [$field => self::FORMATS]);
        }
        $text = trim((string) $value);

        if (preg_match('/^[0-9]{1,10}$/D', $text) === 1) {
            return (int) $text;
        }
        if (preg_match('/^[0-9]{13}$/D', $text) === 1) {
            throw new ValidationException('Invalid ' . $field, [$field => "$text looks like milliseconds; send seconds (" . intdiv((int) $text, 1000) . ')']);
        }
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $text, $m) === 1) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new ValidationException('Invalid ' . $field, [$field => "$text is not a date"]);
            }
            $time = $field === 'time_to' ? '23:59:59' : '00:00:00';

            return (new \DateTimeImmutable("$text $time", new \DateTimeZone($timezone())))->getTimestamp();
        }
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $text) === 1) {
            // Seconds are optional; Z is +00:00.
            $full = $text[16] === ':' ? $text : substr_replace($text, ':00', 16, 0);
            $full = str_ends_with($full, 'Z') ? substr($full, 0, -1) . '+00:00' : $full;
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:sP', $full);
            // createFromFormat rolls 2026-02-30 over to March 2: a value that
            // does not format back to itself was not a real time.
            if ($parsed === false || $parsed->format('Y-m-d\\TH:i:sP') !== $full) {
                throw new ValidationException('Invalid ' . $field, [$field => "$text is not a real time"]);
            }

            return $parsed->getTimestamp();
        }

        throw new ValidationException('Invalid ' . $field, [$field => self::FORMATS]);
    }
}
