<?php

declare(strict_types=1);

namespace Api\V3\Support;

/** A real calendar day written YYYY-MM-DD, as a DATE column holds one. */
final class CalendarDate
{
    private function __construct()
    {
    }

    public static function isValid(string $value): bool
    {
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $m) !== 1) {
            return false;
        }

        return (int) $m[1] >= 1000 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
