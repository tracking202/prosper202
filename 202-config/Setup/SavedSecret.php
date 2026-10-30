<?php

declare(strict_types=1);

namespace Prosper202\Setup;

/**
 * How Account › API integrations shows a saved secret: that one is saved
 * and, for a long one, its last four characters, never the value. A field
 * for a secret is never filled in, so a submission that leaves it empty
 * keeps the saved one (CLAUDE.md #25).
 */
final class SavedSecret
{
    /** A secret shorter than this shows no ending: four characters would be too much of it. */
    public const SHOWN_ENDING_MIN_LENGTH = 12;

    private function __construct()
    {
    }

    /** "Saved, ends in XXXX", "Saved" for a short one, or "Not saved", from its length and last four characters. */
    public static function hintFrom(int $length, string $end): string
    {
        if ($length <= 0) {
            return 'Not saved';
        }

        return $length >= self::SHOWN_ENDING_MIN_LENGTH ? 'Saved, ends in ' . $end : 'Saved';
    }

    /** The hint for a stored value; a blank one, or one that is not a string, is none. */
    public static function hint(mixed $stored): string
    {
        $value = is_string($stored) ? trim($stored) : '';

        return self::hintFrom(mb_strlen($value, 'UTF-8'), mb_substr($value, -4, null, 'UTF-8'));
    }

    public static function isSaved(mixed $stored): bool
    {
        return is_string($stored) && trim($stored) !== '';
    }

    /** Whether a posted field keeps the saved secret: it was left empty (or is not a single value). */
    public static function keeps(mixed $posted): bool
    {
        return !is_string($posted) || trim($posted) === '';
    }
}
