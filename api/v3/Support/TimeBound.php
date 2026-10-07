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
 */
final class TimeBound
{
    private const FORMATS = "Unix seconds, a date (YYYY-MM-DD, in the account's timezone) or a time with its offset (2026-10-01T09:30:00Z)";

    private function __construct()
    {
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
