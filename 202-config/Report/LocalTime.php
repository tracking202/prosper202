<?php

declare(strict_types=1);

namespace Prosper202\Report;

/**
 * A unix-seconds column as wall-clock time in an account's time zone, in
 * SQL, without asking MySQL to know the zone.
 *
 * MySQL converts between zones only with its time-zone tables loaded:
 * CONVERT_TZ() with a named zone is NULL without them, and many installs
 * (Debian's MariaDB among them) ship without. FROM_UNIXTIME() renders in the
 * connection's session zone, which the API never sets and the pages set to
 * the user's offset *today*, rounded to whole hours. Both were wrong for some
 * account: a report bucketed in the server's zone, or in UTC when the
 * conversion came back NULL and a fallback took over, or an hour off for every
 * date on the other side of a daylight-saving change, or half an hour off in
 * India all year.
 *
 * So the offset comes from PHP's zone database instead. getTransitions() lists
 * every change of the zone's UTC offset; the SQL adds the offset in force at
 * the row's moment (a CASE over the changes) and reads the sum as a DATETIME
 * counted from 1970-01-01 00:00:00. That is DATETIME arithmetic, which no
 * zone setting touches, so HOUR(), WEEKDAY() and DATE_FORMAT() of the result
 * are the account's own hour, weekday and date, on every server.
 */
final class LocalTime
{
    /** How far past now the list of offset changes reaches. */
    private const int HORIZON_SECONDS = 2 * 366 * 86400;

    /**
     * The column's wall-clock DATETIME in $timezone.
     *
     * @param string $column a column reference, `alias.column` or `column`
     * @param string $timezone a zone PHP knows (`America/New_York`, `UTC`)
     */
    public static function datetimeSql(string $column, string $timezone, ?int $now = null): string
    {
        return "(CAST('1970-01-01 00:00:00' AS DATETIME) + INTERVAL "
            . self::secondsSql($column, $timezone, $now) . ' SECOND)';
    }

    /**
     * The column's wall-clock time in $timezone as seconds since the local
     * 1970-01-01 00:00:00: the column plus the offset in force at its value.
     */
    public static function secondsSql(string $column, string $timezone, ?int $now = null): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $column) !== 1) {
            throw new \InvalidArgumentException("Not a column reference: $column");
        }
        $offsets = self::offsets($timezone, $now ?? time());
        if (count($offsets) === 1) {
            return "($column + {$offsets[0][1]})";
        }
        $case = 'CASE';
        $previous = $offsets[0][1];
        foreach (array_slice($offsets, 1) as [$from, $offset]) {
            $case .= " WHEN $column < $from THEN $previous";
            $previous = $offset;
        }

        return "($column + $case ELSE $previous END)";
    }

    /**
     * The zone's UTC offsets, each with the second it takes effect, oldest
     * first; the first applies from the beginning of time. Consecutive changes
     * that keep the offset (an abbreviation or a DST flag alone) are merged.
     *
     * @return non-empty-list<array{0: int, 1: int}> [effective from, offset in seconds]
     */
    public static function offsets(string $timezone, int $now): array
    {
        $transitions = (new \DateTimeZone($timezone))->getTransitions(0, $now + self::HORIZON_SECONDS);
        if ($transitions === false || $transitions === []) {
            throw new \RuntimeException("PHP lists no UTC offset for the time zone $timezone");
        }
        $offsets = [];
        foreach ($transitions as $transition) {
            $offset = (int) $transition['offset'];
            if ($offsets !== [] && $offsets[count($offsets) - 1][1] === $offset) {
                continue;
            }
            $offsets[] = [(int) $transition['ts'], $offset];
        }

        return $offsets;
    }
}
