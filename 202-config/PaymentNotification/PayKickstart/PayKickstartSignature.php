<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\PayKickstart;

/**
 * The `hash` PayKickstart signs its product IPN with, as its published PHP
 * validator computes it (docs.paykickstart.com, "IPN Validation Function"):
 * HMAC-SHA1, keyed by the campaign's IPN secret key, over the values of
 * every field but `hash` and `verification_code`, each trimmed, the empty
 * ones and "0" left out (the validator's array_filter()), converted from
 * UTF-8 to ISO-8859-1 with what does not fit dropped (its
 * iconv('UTF-8', 'ISO-8859-1//IGNORE')), ordered by field name as strings
 * and joined with "|".
 *
 * Two readings the validator leaves open, decided here:
 *  - A list value (`licenses` when a sale has several) is left out. The
 *    validator's trim() of an array returned null on the PHP it was
 *    written for, and array_filter() dropped it; on PHP 8 the same line
 *    throws, so it says nothing else. A list is never read by the adapter.
 *  - The conversion is done here, not by iconv(), whose //IGNORE differs
 *    between C libraries: a character above U+00FF and a byte that is not
 *    UTF-8 are dropped, which is what glibc's iconv does with the
 *    validator's arguments.
 *
 * The signature covers the values, not which field each is in: "|" joins
 * values whose names are not part of the string, so a holder of one signed
 * notification can move its values between field names, and add fields
 * whose value is empty or "0", without changing the hash (CLAUDE.md #17).
 * That is PayKickstart's algorithm, and no receiver can close it. The
 * adapter reads each field it uses strictly, so a value moved into a field
 * of another shape is refused, but one moved into a field of its own shape
 * is read: the IPN URL should be https, so that no one between PayKickstart
 * and the install holds a signed notification.
 */
final class PayKickstartSignature
{
    private function __construct()
    {
    }

    /** @param array<array-key, mixed> $fields the form body as PHP parsed it */
    public static function message(array $fields): string
    {
        unset($fields['hash'], $fields['verification_code']);
        $kept = [];
        foreach ($fields as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '' || $value === '0') {
                continue;
            }
            $kept[$name] = self::latin1($value);
        }
        ksort($kept, SORT_STRING);

        return implode('|', $kept);
    }

    /** The lowercase hex HMAC-SHA1 of $message under $secretKey. */
    public static function sign(string $message, string $secretKey): string
    {
        return hash_hmac('sha1', $message, $secretKey);
    }

    /**
     * UTF-8 to ISO-8859-1: a character up to U+00FF becomes its byte, and a
     * character above it, an overlong or surrogate sequence and a byte that
     * starts no valid sequence are dropped.
     */
    public static function latin1(string $utf8): string
    {
        $out = '';
        $length = strlen($utf8);
        $i = 0;
        while ($i < $length) {
            $lead = ord($utf8[$i]);
            if ($lead < 0x80) {
                $out .= $utf8[$i];
                $i++;
                continue;
            }
            if ($lead >= 0xC2 && $lead <= 0xDF) {
                [$size, $codePoint, $least] = [2, $lead & 0x1F, 0x80];
            } elseif ($lead >= 0xE0 && $lead <= 0xEF) {
                [$size, $codePoint, $least] = [3, $lead & 0x0F, 0x800];
            } elseif ($lead >= 0xF0 && $lead <= 0xF4) {
                [$size, $codePoint, $least] = [4, $lead & 0x07, 0x10000];
            } else {
                $i++;
                continue;
            }
            $valid = $i + $size <= $length;
            for ($k = 1; $valid && $k < $size; $k++) {
                $next = ord($utf8[$i + $k]);
                $valid = ($next & 0xC0) === 0x80;
                $codePoint = ($codePoint << 6) | ($next & 0x3F);
            }
            $surrogate = $codePoint >= 0xD800 && $codePoint <= 0xDFFF;
            if (!$valid || $codePoint < $least || $codePoint > 0x10FFFF || $surrogate) {
                $i++;
                continue;
            }
            if ($codePoint <= 0xFF) {
                $out .= chr($codePoint);
            }
            $i += $size;
        }

        return $out;
    }
}
