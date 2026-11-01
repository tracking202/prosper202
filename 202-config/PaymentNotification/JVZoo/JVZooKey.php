<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\JVZoo;

use InvalidArgumentException;

/**
 * An account's JVZIPN secret key (JVZoo: My Account › Account Details ›
 * Seller Settings), one per JVZoo account and the same for its Affiliate IPN
 * and its products' JVZIPN, in 202_users_pref.jvzoo_ipn_secret_key. The page
 * and PUT /users/{id}/preferences write it through parse(), and the endpoint
 * and GET /system/integrations read it through stored(), so what is stored
 * is what verifies and `secret_stored` says so.
 */
final class JVZooKey
{
    public const COLUMN = 'jvzoo_ipn_secret_key';

    /** The column is varchar(250); counted in bytes, so nothing is cut. */
    public const MAX_LENGTH = 250;

    private function __construct()
    {
    }

    /**
     * The key a caller sent, trimmed; "" is none. Refused, never by its
     * text, when it cannot be one.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $raw): string
    {
        $key = trim($raw);
        $problem = self::problem($key);
        if ($key !== '' && $problem !== null) {
            throw new InvalidArgumentException('The key ' . $problem);
        }

        return $key;
    }

    /** The stored key as the endpoint signs with it, or null when none is or it cannot be one. */
    public static function stored(?string $column): ?string
    {
        $key = trim((string) $column);

        return $key !== '' && self::problem($key) === null ? $key : null;
    }

    /** Whether a key is stored that is not one (written by hand, cut short): it verifies nothing. */
    public static function unreadable(?string $column): bool
    {
        return trim((string) $column) !== '' && self::stored($column) === null;
    }

    private static function problem(string $key): ?string
    {
        if (strlen($key) > self::MAX_LENGTH) {
            return 'is ' . strlen($key) . ' characters long; at most ' . self::MAX_LENGTH . '.';
        }
        if (!mb_check_encoding($key, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            return 'has a character that is not text (a line break or a control character); paste it as JVZoo'
                . ' shows it under Seller Settings.';
        }

        return null;
    }
}
