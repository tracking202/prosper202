<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * A click id read from untrusted input: a cookie, a query parameter, a
 * network's tracking code, a line of an uploaded report.
 */
final class ClickId
{
    private function __construct()
    {
    }

    /**
     * The click id the value names, or null when it is not exactly one.
     *
     * Digits only, no sign, no leading zero, within bigint. `is_numeric()`
     * followed by an int cast accepted "123.9", "1e3" and " 42" and silently
     * turned each into a DIFFERENT click, so a corrupted value could credit a
     * conversion to a real click nobody meant (CLAUDE.md error pattern #18).
     * The round trip pins the value: what is returned prints back as exactly
     * what was given.
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
            return null;
        }
        $id = (int) $value;

        return $id > 0 && (string) $id === $value ? $id : null;
    }
}
