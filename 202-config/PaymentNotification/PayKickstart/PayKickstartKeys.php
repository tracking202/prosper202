<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\PayKickstart;

use InvalidArgumentException;

/**
 * An account's PayKickstart IPN secret keys: one for each PayKickstart
 * campaign it sells through (PayKickstart gives every campaign its own), in
 * 202_users_pref.paykickstart_ipn_secret_keys, one per line. The page and
 * PUT /users/{id}/preferences both write through parse(), and nothing ever
 * answers with a key: the page shows the last characters of each, GET
 * /users/{id}/preferences whether any is stored, and GET /system/integrations
 * how many.
 */
final class PayKickstartKeys
{
    public const COLUMN = 'paykickstart_ipn_secret_keys';

    public const MAX_KEYS = 20;
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;

    /** Visible ASCII but the comma, which separates keys as whitespace does. */
    private const KEY = '/^[\x21-\x2B\x2D-\x7E]+$/D';

    private function __construct()
    {
    }

    /**
     * The keys a caller sent, separated by commas, spaces or new lines; ""
     * is none. Refused whole, naming the key by its place and never by its
     * text, when one is not a key or there are too many.
     *
     * @return list<string>
     * @throws InvalidArgumentException
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            throw new InvalidArgumentException('The keys could not be read.');
        }
        $keys = [];
        foreach ($parts as $i => $key) {
            $problem = self::problem($key);
            if ($problem !== null) {
                $which = count($parts) === 1 ? 'The key' : 'Key ' . ($i + 1);
                throw new InvalidArgumentException($which . ' ' . $problem);
            }
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        if (count($keys) > self::MAX_KEYS) {
            throw new InvalidArgumentException(
                'At most ' . self::MAX_KEYS . ' keys, one for each PayKickstart campaign; ' . count($keys)
                . ' were given.'
            );
        }

        return $keys;
    }

    /** @param list<string> $keys */
    public static function serialize(array $keys): string
    {
        return implode("\n", $keys);
    }

    /**
     * The stored keys, and how many stored entries are not a key (written
     * by hand, or cut short): those are left out, so they verify nothing.
     *
     * @return array{keys: list<string>, unreadable: int}
     */
    public static function stored(?string $column): array
    {
        $keys = [];
        $unreadable = 0;
        foreach (explode("\n", (string) $column) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (self::problem($entry) !== null) {
                $unreadable++;
                continue;
            }
            if (!in_array($entry, $keys, true)) {
                $keys[] = $entry;
            }
        }

        return ['keys' => $keys, 'unreadable' => $unreadable];
    }

    /**
     * The page's Add: the stored keys and the one (or ones) pasted.
     *
     * @param list<string> $stored
     * @return list<string>
     * @throws InvalidArgumentException
     */
    public static function added(array $stored, string $pasted): array
    {
        $added = self::parse($pasted);
        if ($added === []) {
            throw new InvalidArgumentException('Paste the IPN secret key of a PayKickstart campaign.');
        }
        if (array_diff($added, $stored) === []) {
            throw new InvalidArgumentException('That key is stored already.');
        }

        return self::parse(self::serialize(array_merge($stored, $added)));
    }

    /**
     * The page's Remove: the key at a place (from 1), named by the hint the
     * page showed for it, so a list changed in another tab removes nothing.
     *
     * @param list<string> $stored
     * @return list<string>
     * @throws InvalidArgumentException
     */
    public static function removed(array $stored, string $place, string $hint): array
    {
        $index = preg_match('/^[1-9][0-9]?$/D', $place) === 1 ? (int) $place - 1 : -1;
        if (!isset($stored[$index]) || !hash_equals(self::hint($stored[$index]), $hint)) {
            throw new InvalidArgumentException('The stored keys changed since this page was loaded, so nothing was'
                . ' removed. Look again and remove it from the list below.');
        }
        array_splice($stored, $index, 1);

        return $stored;
    }

    /** How the page tells stored keys apart: their last four characters. */
    public static function hint(string $key): string
    {
        return "\u{2026}" . substr($key, -4);
    }

    private static function problem(string $key): ?string
    {
        if (preg_match(self::KEY, $key) !== 1) {
            return 'has a character that is not a letter, a digit or punctuation; paste it as PayKickstart shows it.';
        }
        $length = strlen($key);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return 'is ' . $length . ' characters long; a PayKickstart IPN secret key is ' . self::MIN_LENGTH
                . ' to ' . self::MAX_LENGTH . '.';
        }

        return null;
    }
}
