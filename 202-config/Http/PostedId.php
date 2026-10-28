<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The id a form or a row's button posts, read exactly, or null for the page
 * to refuse in its own words. `(int)` keeps a string's leading digits, so LTV
 * Settings' Delete removed webhook 12 when it was sent `12abc`: one of the
 * account's own webhooks, and not the one named (CLAUDE.md #4).
 *
 * Digits only, no sign, no leading zero; the round trip refuses a value past
 * PHP_INT_MAX, which `(int)` would read as PHP_INT_MAX.
 */
final class PostedId
{
    public static function read(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
            return null;
        }
        $id = (int) $value;

        return (string) $id === $value ? $id : null;
    }
}
