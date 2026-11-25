<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * A click id read from untrusted input: a cookie, a query parameter, a
 * network's tracking code, a line of an uploaded report; and where a click id
 * came from.
 */
final class ClickId
{
    /**
     * The first id the hosted edge hands out. Every id from here up is the
     * edge's (its allocators from here, their fallback from 90,000,000,000),
     * so a click's origin is read from its id alone, and the install's own
     * counter never issues one (MysqlClickRepository::allocateClickId()).
     * Eleven digits, thirteen as a public id, well under 2^53.
     */
    public const int EDGE_FIRST = 10_000_000_000;

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

    /**
     * Whether the click id is one the edge handed out. The one place the
     * boundary is tested (ClickIdBoundaryIsOneFunctionTest).
     */
    public static function isEdge(int $clickId): bool
    {
        return $clickId >= self::EDGE_FIRST;
    }
}
