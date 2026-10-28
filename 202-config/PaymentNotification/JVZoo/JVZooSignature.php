<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\JVZoo;

/**
 * The `cverify` JVZoo signs its JVZIPN (v1) notification with, as its
 * published validator computes it (support.jvzoo.com, "JVZIPN - How to
 * create your own integrated script"): the values of every posted field but
 * `cverify`, ordered by field name, each followed by "|", the account's
 * JVZIPN secret key after them, SHA-1, the first 8 hex digits in upper case.
 * Values are taken as posted: not trimmed, and an empty one is still a "|".
 *
 * Two readings the validator leaves open, decided here:
 *  - Its sort() orders the names as PHP compares them; for names that are
 *    not numbers, which every JVZIPN field is, that is byte order, so the
 *    names are sorted as strings.
 *  - It converts the string to UTF-8 when mbstring does not detect it as
 *    UTF-8. A string that is UTF-8 (ASCII included) is hashed as it is; one
 *    that is not has no one reading (mbstring's substitution differs between
 *    PHP versions), so it is not signed here at all and the adapter refuses
 *    the notification.
 *
 * Eight hex digits are 32 bits: JVZoo's algorithm, which no receiver can
 * lengthen. The values are joined with no field names, so the adapter takes
 * only the fields JVZIPN v1 documents, each by its own name (JVZooAdapter).
 */
final class JVZooSignature
{
    private function __construct()
    {
    }

    /**
     * What is hashed before the key: each value but cverify's, in field-name
     * order, followed by "|"; null when a value is not text or not UTF-8.
     *
     * @param array<array-key, mixed> $fields the form body as PHP parsed it
     */
    public static function message(array $fields): ?string
    {
        unset($fields['cverify']);
        $values = [];
        foreach ($fields as $name => $value) {
            if (!is_string($value)) {
                return null;
            }
            $values[(string) $name] = $value;
        }
        ksort($values, SORT_STRING);
        $message = '';
        foreach ($values as $value) {
            $message .= $value . '|';
        }

        return mb_check_encoding($message, 'UTF-8') ? $message : null;
    }

    /** The cverify of $message under $secretKey: 8 upper-case hex digits. */
    public static function sign(string $message, string $secretKey): string
    {
        return strtoupper(substr(sha1($message . $secretKey), 0, 8));
    }
}
