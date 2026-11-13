<?php

declare(strict_types=1);

namespace Prosper202\Spend;

/**
 * An amount at the six decimals the money columns keep (DECIMAL(16,6)),
 * rounded half up from its decimal text, never through float arithmetic.
 */
final class SixDecimals
{
    /**
     * A decimal string of at least 0 and below 10^10, as six decimals
     * rounded half up; null when it is not one or does not fit.
     */
    public static function fromText(string $text): ?string
    {
        if (preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,40}))?$/D', $text, $m) !== 1) {
            return null;
        }
        $fraction = str_pad($m[2] ?? '', 7, '0');
        $micros = (int) ($m[1] . substr($fraction, 0, 6)) + ($fraction[6] >= '5' ? 1 : 0);
        if ($micros > 9999999999999999) {
            return null;
        }

        return sprintf('%d.%06d', intdiv($micros, 1000000), $micros % 1000000);
    }

    /**
     * A finite double as plain decimal text: the shortest digits that read
     * back as the same double (what a JSON sender of up to 15 significant
     * digits wrote), whatever serialize_precision says.
     */
    public static function textOf(float $value): string
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('not a finite number');
        }
        for ($digits = 0; $digits < 17; $digits++) {
            $e = sprintf('%.' . $digits . 'e', $value);
            if ((float) $e === $value) {
                break;
            }
        }
        if (preg_match('/^(-?)([0-9])(?:\.([0-9]+))?e([+-][0-9]+)$/D', $e, $m) !== 1) {
            throw new \LogicException('sprintf %e gave ' . $e);
        }
        $mantissa = $m[2] . ($m[3] ?? '');
        $point = 1 + (int) $m[4];
        if ($point <= 0) {
            $whole = '0';
            $fraction = str_repeat('0', -$point) . $mantissa;
        } elseif ($point >= strlen($mantissa)) {
            $whole = $mantissa . str_repeat('0', $point - strlen($mantissa));
            $fraction = '';
        } else {
            $whole = substr($mantissa, 0, $point);
            $fraction = substr($mantissa, $point);
        }
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');

        return $m[1] . $whole . ($fraction === '' ? '' : '.' . $fraction);
    }
}
