<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The one place this tree sets a curl option (CurlOptionsAreCheckedTest).
 *
 * curl_setopt() and curl_setopt_array() answer false for a value libcurl
 * will not take (a negative or too large time limit, an unknown proxy
 * type, a size cap below zero), and the request still goes when that
 * answer is not read: without its time limit, its size cap, its route,
 * or with RETURNTRANSFER unset, so the answer is printed into the page.
 * curl_setopt_array() also stops at the refused option, so every option
 * after it is unset too. set() refuses the request instead.
 */
final class CurlOptions
{
    /** @var array<int, string>|null */
    private static ?array $names = null;

    /**
     * Sets each option in order, as curl_setopt_array() does, and throws at
     * the first one curl refuses; the options after it are not set.
     *
     * @param array<int, mixed> $options
     * @throws CurlOptionRefused
     */
    public static function set(\CurlHandle $ch, array $options): void
    {
        foreach ($options as $option => $value) {
            if (curl_setopt($ch, $option, $value)) {
                continue;
            }
            $errno = curl_errno($ch);
            $reason = $errno !== 0 ? (string) curl_strerror($errno) : '';
            throw new CurlOptionRefused($option, 'curl refused an option: ' . self::name($option)
                . ($reason !== '' ? ' (' . $reason . ')' : ''));
        }
    }

    /** An option's constant name, as a message names it. */
    public static function name(int $option): string
    {
        if (self::$names === null) {
            self::$names = [];
            foreach (get_defined_constants(true)['curl'] ?? [] as $name => $value) {
                if (is_int($value) && str_starts_with($name, 'CURLOPT_') && !isset(self::$names[$value])) {
                    self::$names[$value] = $name;
                }
            }
        }

        return self::$names[$option] ?? 'curl option ' . $option;
    }
}
