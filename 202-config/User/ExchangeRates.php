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
     * $payout in $campaignCurrency, as the service converts it to
     * $accountCurrency: ['exchange_payout' => number], or null.
     *
     * @return array<string, mixed>|null
     */
    public static function foreignPayout(string $accountCurrency, string $campaignCurrency, string $payout): ?array
    {
        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'currency' => $accountCurrency,
                'payout_currency' => $campaignCurrency,
                'payout' => $payout,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $status !== 200) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
