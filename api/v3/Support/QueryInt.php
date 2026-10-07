<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\ValidationException;

/**
 * A whole-number query parameter, read strictly.
 *
 * The controllers used to read these as `max(1, min(500, (int)
 * ($params['limit'] ?? 50)))`. The cast turns anything that is not a number
 * into 0 and the clamp then turns 0 into the minimum, so `months=abc`
 * answered one month and `days=0` one day, each reading as the answer to the
 * question asked (CLAUDE.md #4). A value past the ceiling is refused too:
 * `limit=1000` came back as 500 rows that read as all of them. This is the
 * rule GET /reports/* and GET /clicks already applied.
 */
final class QueryInt
{
    private function __construct()
    {
    }

    /**
     * $name from $params as a whole number from $min to $max; absent or ''
     * is $default. Anything else is a 422 naming the parameter and its range.
     *
     * @param array<string, mixed> $params
     * @param string $what what the number counts, for the message ("cohort months")
     */
    public static function param(array $params, string $name, int $default, int $min, int $max, string $what = ''): int
    {
        if ($min < 0 || $max < $min) {
            throw new \LogicException("QueryInt: [$min, $max] is not a range of whole numbers");
        }
        if (!array_key_exists($name, $params) || $params[$name] === '' || $params[$name] === null) {
            return $default;
        }
        $value = $params[$name];
        $text = is_int($value) ? (string) $value : (is_string($value) ? $value : '');
        if (preg_match('/^[0-9]{1,18}$/D', $text) !== 1 || (int) $text < $min || (int) $text > $max) {
            $range = $max === PHP_INT_MAX ? "$min or more" : "$min to $max";
            throw new ValidationException('Invalid ' . $name, [$name => "A whole number, $range" . ($what !== '' ? ": $what" : '')]);
        }

        return (int) $text;
    }
}
