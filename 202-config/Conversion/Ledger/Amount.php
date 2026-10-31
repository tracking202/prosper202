<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use InvalidArgumentException;

/**
 * Exact money arithmetic for the ledger, in integer units of 0.00001.
 *
 * Conversion and click amounts are `decimal(11,5)`. Summing them as floats
 * drifts (0.1 + 0.2 is not 0.3), and a click value derived from its rows
 * has to equal what a report re-adding the same rows gets, so the ledger
 * never adds floats: every amount is parsed once into an integer count of
 * the column's smallest unit and formatted back only when written.
 */
final class Amount
{
    public const SCALE = 5;
    private const FACTOR = 100000;

    private function __construct()
    {
    }

    /**
     * Parse a decimal amount into units.
     *
     * Accepts what MySQL returns for a decimal column and what a caller
     * passes as a payout: an int, a float, or a plain decimal string with an
     * optional leading minus. More than five decimal places is rounded half
     * away from zero, the way MySQL stores an over-precise value into
     * decimal(11,5). Anything else (an exponent, a thousands separator, an
     * empty string, a non-finite float) is refused: an amount that cannot be
     * read must never become 0 (CLAUDE.md error pattern #4).
     */
    public static function toUnits(int|float|string $value): int
    {
        if (is_int($value)) {
            return $value * self::FACTOR;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('amount is not a finite number');
            }
            // Round through the decimal string PHP would print, so 0.1 reads
            // as exactly 10000 units rather than 9999.999... truncated.
            $value = number_format($value, self::SCALE + 3, '.', '');
        }

        $text = trim($value);
        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/D', $text, $m) !== 1) {
            throw new InvalidArgumentException('amount "' . $value . '" is not a decimal number');
        }
        $negative = $m[1] === '-';
        $whole = ltrim($m[2], '0');
        $fraction = $m[3] ?? '';
        if (strlen($whole) > 13) {
            throw new InvalidArgumentException('amount "' . $value . '" is too large');
        }

        $roundUp = false;
        if (strlen($fraction) > self::SCALE) {
            $roundUp = (int) $fraction[self::SCALE] >= 5;
            $fraction = substr($fraction, 0, self::SCALE);
        }
        $fraction = str_pad($fraction, self::SCALE, '0');

        $units = (int) ($whole === '' ? '0' : $whole) * self::FACTOR + (int) $fraction;
        if ($roundUp) {
            $units++;
        }

        return $negative ? -$units : $units;
    }

    /** Format units as the decimal string a decimal(11,5) column stores. */
    public static function fromUnits(int $units): string
    {
        $negative = $units < 0;
        $abs = abs($units);
        $text = intdiv($abs, self::FACTOR) . '.' . str_pad((string) ($abs % self::FACTOR), self::SCALE, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '') . $text;
    }
}
