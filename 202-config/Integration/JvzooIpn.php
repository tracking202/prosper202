<?php

declare(strict_types=1);

namespace Prosper202\Integration;

/**
 * JVZoo's Instant Payment Notification (JVZIPN v1), as JVZoo's own article
 * "JVZIPN - How to create your own integrated script" defines it.
 *
 * v2 posts are refused: their cverify covers five fields and leaves the
 * amount and the tracking id out, so a copy of one genuine post could be
 * replayed for any click at any amount.
 */
final class JvzooIpn
{
    /** A sale, and a rebill of a recurring product. */
    public const CREDIT = ['SALE', 'BILL'];

    /** A refund, a chargeback, an eCheck chargeback. */
    public const REVERSE = ['RFND', 'CGBK', 'INSF'];

    /**
     * cverify: every other posted value followed by '|', in field-name order,
     * then the secret key; the first 8 hex digits of its SHA-1, upper case.
     * Every field is covered, the click id and the amount included.
     *
     * @param array<array-key, mixed> $post
     */
    public static function verify(array $post, string $secret): bool
    {
        $given = $post['cverify'] ?? null;
        if (!is_string($given) || $given === '' || $secret === '') {
            return false;
        }
        $fields = [];
        foreach ($post as $field => $value) {
            if ($field === 'cverify') {
                continue;
            }
            if (!is_string($value)) {
                return false;
            }
            $fields[] = $field;
        }
        sort($fields);
        $pop = '';
        foreach ($fields as $field) {
            $pop .= $post[$field] . '|';
        }
        $pop .= $secret;
        if (mb_detect_encoding($pop) !== 'UTF-8') {
            $pop = mb_convert_encoding($pop, 'UTF-8', 'UTF-8');
        }

        return hash_equals(strtoupper(substr(sha1($pop), 0, 8)), $given);
    }

    /** Whether the post is a JVZIPN v2 notification (no ctransaction, a paykey). */
    public static function isV2(array $post): bool
    {
        return !isset($post['ctransaction']) && (isset($post['paykey']) || isset($post['transaction_type']));
    }

    /**
     * ctransamount as a decimal string, or null when it is not an amount.
     * JVZoo documents it "in pennies (1000 = $10.00)" and shows "1.00" in its
     * own sample, so a value with a decimal point is read as dollars and a
     * whole number as pennies.
     */
    public static function amount(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if (preg_match('/^\d{1,8}$/D', $raw) === 1) {
            $pennies = (int) $raw;

            return intdiv($pennies, 100) . '.' . str_pad((string) ($pennies % 100), 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^\d{1,6}\.\d{1,2}$/D', $raw) === 1) {
            return $raw;
        }

        return null;
    }
}
