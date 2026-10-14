<?php

declare(strict_types=1);

namespace Prosper202\Repository;

/**
 * A row id read from somewhere that does not promise its type: a result
 * row (an id past PHP_INT_MAX comes back as a string), or a cache (memcache
 * hands back strings, and an entry can hold anything).
 */
final class PositiveId
{
    private function __construct()
    {
    }

    /**
     * The id, or null when $value is not a whole number above zero that an
     * int can hold. The caller decides what null means where it reads.
     */
    public static function of(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            return null;
        }
        $digits = ltrim($value, '0');
        $id = (int) $digits;

        // A value past PHP_INT_MAX casts to PHP_INT_MAX, which is not it.
        return $id > 0 && (string) $id === $digits ? $id : null;
    }
}
