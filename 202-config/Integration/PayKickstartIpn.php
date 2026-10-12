<?php

declare(strict_types=1);

namespace Prosper202\Integration;

/**
 * PayKickstart's vendor IPN (docs.paykickstart.com, "IPN Validation
 * Function"). The affiliate IPN carries no signature, no event and no
 * transaction id, so it is not accepted here.
 *
 * hash is an HMAC-SHA1, keyed with the campaign's secret key, of every other
 * non-empty value except verification_code, in field-name order, joined
 * with '|'. PayKickstart publishes three samples that agree on ASCII values
 * and differ on the rest: PHP trims each value and hashes it as ISO-8859-1,
 * JavaScript hashes UTF-8, Python drops non-ASCII characters. All three are
 * tried; they differ only in how the signed bytes are spelled, never in
 * which secret signs them.
 */
final class PayKickstartIpn
{
    /** The secret keys stored for an account: one per PayKickstart campaign. */
    public static function secrets(string $stored): array
    {
        return preg_split('/[\s,]+/', trim($stored), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param array<array-key, mixed> $post
     */
    public static function verify(array $post, string $secret): bool
    {
        $given = $post['hash'] ?? null;
        if (!is_string($given) || $given === '' || $secret === '') {
            return false;
        }
        $given = strtolower($given);
        foreach (self::signedStrings($post) as $signed) {
            if (hash_equals(hash_hmac('sha1', $signed, $secret), $given)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The string each published sample signs.
     *
     * @param array<array-key, mixed> $post
     * @return list<string>
     */
    public static function signedStrings(array $post): array
    {
        unset($post['hash'], $post['verification_code']);
        ksort($post, SORT_STRING);

        $php = [];
        $js = [];
        $python = [];
        foreach ($post as $value) {
            if (is_array($value)) {
                // PHP's trim() of an array is no value, so the PHP sample
                // drops it; JavaScript's String() joins it with commas.
                $value = implode(',', array_map(
                    static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                    $value
                ));
                if ($value !== '' && $value !== '0') {
                    $js[] = $value;
                }
                continue;
            }
            $value = (string) $value;
            $trimmed = trim($value);
            if ($trimmed !== '' && $trimmed !== '0') {
                $php[] = self::latin1($trimmed);
            }
            if ($value !== '' && $value !== '0') {
                $js[] = $value;
                $python[] = (string) preg_replace('/[^\x00-\x7F]/', '', $value);
            }
        }

        return array_values(array_unique([implode('|', $php), implode('|', $js), implode('|', $python)]));
    }

    /** UTF-8 to ISO-8859-1, dropping what Latin-1 cannot hold, as iconv's //IGNORE intends. */
    private static function latin1(string $value): string
    {
        $out = '';
        foreach (mb_str_split(mb_scrub($value, 'UTF-8'), 1, 'UTF-8') as $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($code !== false && $code <= 0xFF) {
                $out .= chr($code);
            }
        }

        return $out;
    }

    /** A decimal amount ("9", "47.00"), or null. */
    public static function amount(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);

        return preg_match('/^\d{1,6}(\.\d{1,5})?$/D', $raw) === 1 ? $raw : null;
    }
}
