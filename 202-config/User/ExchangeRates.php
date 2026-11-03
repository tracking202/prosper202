<?php

declare(strict_types=1);

namespace Prosper202\User;

/**
 * The payout-conversion service Personal settings re-prices campaigns with
 * (getForeignPayout() in functions-tracking202.php, the copy account.php
 * loads), for callers that do not load that file: the same request, with a
 * bounded connect time, and null rather than a decoded warning when the
 * service cannot be reached — CurrencyChange::plan() refuses the whole
 * change on anything but a positive number.
 */
final class ExchangeRates
{
    private const ENDPOINT = 'https://my.tracking202.com/api/v2/get-foreign-payout';

    private function __construct()
    {
    }

    /**
     * $amount in $from, converted into $to; null when the service gives no
     * positive number.
     */
    public static function convert(string $from, string $to, string $amount): ?string
    {
        $answer = self::foreignPayout($from, $to, $amount);
        $value = $answer['exchange_payout'] ?? null;

        return is_numeric($value) && (float) $value > 0 ? (string) $value : null;
    }

    /**
     * The service's answer for $amount in $from converted into $to:
     * ['exchange_payout' => number], or null. It converts `payout` FROM
     * `currency` INTO `payout_currency` (checked live 2026-11-03: GBP 10 =>
     * USD 13.41, EUR 10 => USD 11.18; ExchangeRatesLiveTest repeats it).
     *
     * @return array<string, mixed>|null
     */
    public static function foreignPayout(string $from, string $to, string $amount): ?array
    {
        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'currency' => $from,
                'payout_currency' => $to,
                'payout' => $amount,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (!is_string($body) || $status !== 200) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
