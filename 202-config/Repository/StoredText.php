<?php

declare(strict_types=1);

namespace Prosper202\Repository;

use InvalidArgumentException;
use RuntimeException;

/**
 * A value as its column keeps it. Looked up uncut, a value longer than its
 * column never finds the row it was stored as, so every lookup cuts first.
 */
final class StoredText
{
    /** A whole UTF-8 sequence, or any other single byte. */
    private const CHARACTER = '(?:[\xC0-\xDF][\x80-\xBF]'
        . '|[\xE0-\xEF][\x80-\xBF]{2}'
        . '|[\xF0-\xF7][\x80-\xBF]{3}'
        . '|[\s\S])';

    /** The start of a UTF-8 sequence that the end of the string cuts short. */
    private const SPLIT_TAIL = '/(?:[\xC0-\xDF]'
        . '|[\xE0-\xEF][\x80-\xBF]?'
        . '|[\xF0-\xF7][\x80-\xBF]{0,2})\z/';

    private function __construct()
    {
    }

    /**
     * Cut to $max bytes, less a UTF-8 character the cut splits.
     */
    public static function bytes(string $value, int $max): string
    {
        if ($max < 1) {
            throw new InvalidArgumentException(
                'A column holds at least one byte, not ' . $max
            );
        }
        if (strlen($value) <= $max) {
            return $value;
        }

        $cut = preg_replace(self::SPLIT_TAIL, '', substr($value, 0, $max));
        if ($cut === null) {
            throw new RuntimeException(
                'Cutting a value to its column failed: ' . preg_last_error_msg()
            );
        }

        return $cut;
    }

    /**
     * Cut to $max characters. A byte that starts no whole UTF-8 sequence
     * counts as one character and is kept.
     */
    public static function chars(string $value, int $max): string
    {
        if ($max < 1) {
            throw new InvalidArgumentException(
                'A column holds at least one character, not ' . $max
            );
        }
        if (strlen($value) <= $max) {
            return $value;
        }

        // No character is longer than four bytes, so the first $max of them
        // lie in the first 4 * $max bytes and are read there as in the whole.
        $head = substr($value, 0, 4 * $max);
        if (preg_match_all('/' . self::CHARACTER . '/', $head, $found) === false) {
            throw new RuntimeException(
                'Cutting a value to its column failed: ' . preg_last_error_msg()
            );
        }

        return implode('', array_slice($found[0], 0, $max));
    }
}
