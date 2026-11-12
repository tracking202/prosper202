<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

/**
 * A request value a network was meant to fill and did not: the macro itself
 * arrives, as it was pasted into the postback URL.
 *
 * Unfilled is the whole value being one macro, a name between a pair of
 * delimiters: Prosper202's own token ([[subid]]) and the forms Setup ›
 * Postback / Pixel takes for a network's macro ({aff_sub}, {{name}},
 * ${name}, [name], [=SID=], #s2#, %subid1%, <name>), also when the network
 * sent it URL-encoded once more. No click reference has that shape (a click
 * id, a PurLink SubID and its older form are digits, hyphens and hexadecimal
 * digits), so no SubID that names a click is ever read as unfilled. A macro
 * with no delimiters (xxC1xx) cannot be told from a value, and is one.
 */
final class UnfilledPlaceholder
{
    private const NAME = '[A-Za-z][A-Za-z0-9_.\-]{0,63}';

    private const SHAPES = [
        '\[\[' . self::NAME . '\]\]',
        '\{\{' . self::NAME . '\}\}',
        '\$?\{' . self::NAME . '\}',
        '\[=?' . self::NAME . '=?\]',
        '#' . self::NAME . '#',
        '%' . self::NAME . '%',
        '<' . self::NAME . '>',
    ];

    private function __construct()
    {
    }

    /** Whether $value is one unfilled macro and nothing else. */
    public static function isUnfilled(string $value): bool
    {
        $value = trim($value);
        $pattern = '/\A(?:' . implode('|', self::SHAPES) . ')\z/D';
        if (preg_match($pattern, $value) === 1) {
            return true;
        }
        $decoded = rawurldecode($value);

        return $decoded !== $value && preg_match($pattern, trim($decoded)) === 1;
    }

    /**
     * Whether a parameter gives no value: absent, blank, or an unfilled
     * macro. Anything else is a value, whatever it holds (a list is one).
     */
    public static function isMissing(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return is_string($value) && (trim($value) === '' || self::isUnfilled($value));
    }
}
