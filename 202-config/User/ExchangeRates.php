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

    /** The cron job's refresh of the kept rates gives each request this long, and all of them this long. */
    public const REFRESH_REQUEST_SECONDS = 10;
    public const REFRESH_BUDGET_SECONDS = 30;

    private function __construct()
    {
    }

    /**
     * $amount in $from, converted into $to; null when the service gives no
     * positive number.
     */
    public static function convert(string $from, string $to, string $amount, int $timeout = 60): ?string
    {
        $answer = self::foreignPayout($from, $to, $amount, $timeout);
        $value = $answer['exchange_payout'] ?? null;

        return is_numeric($value) && (float) $value > 0 ? (string) $value : null;
    }

    /**
     * The service's form fields for converting $amount from $from into $to.
     *
     * @return array{currency: string, payout_currency: string, payout: string}
     */
    public static function requestFields(string $from, string $to, string $amount): array
    {
        return ['currency' => $from, 'payout_currency' => $to, 'payout' => $amount];
    }

    /**
     * The service's answer for $amount in $from converted into $to:
     * ['exchange_payout' => number], or null. It converts `payout` FROM
     * `currency` INTO `payout_currency` (checked live 2026-11-03: GBP 10 =>
     * USD 13.41, EUR 10 => USD 11.18; ExchangeRatesLiveTest repeats it).
     *
     * $timeout bounds the whole request, in seconds (the cron job's daily
     * refresh of the kept rates asks for less than a page does).
     *
     * @return array<string, mixed>|null
     */
    public static function foreignPayout(string $from, string $to, string $amount, int $timeout = 60): ?array
    {
        $ch = curl_init(self::endpoint());
        if ($ch === false) {
            return null;
        }
        // The request holds the two currencies and the amount and nothing
        // else: no key, no cookie, no install or account. A redirect is not
        // followed, so they go to this host only.
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(self::requestFields($from, $to, $amount)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (!is_string($body) || $status !== 200) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The service's address: ENDPOINT, or its path on the loopback origin
     * P202_EXCHANGE_RATES_ORIGIN names, which is the tests' stand-in. Any
     * other origin is ignored, so the setting cannot send the request
     * anywhere but this machine.
     */
    private static function endpoint(): string
    {
        $origin = getenv('P202_EXCHANGE_RATES_ORIGIN');
        if (!is_string($origin) || $origin === '') {
            return self::ENDPOINT;
        }
        $parts = parse_url($origin);
        $loopback = is_array($parts) && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && !isset($parts['path']) && !isset($parts['query']) && !isset($parts['user']);
        if (!$loopback) {
            error_log('Exchange rates: ignoring P202_EXCHANGE_RATES_ORIGIN; only a loopback origin is accepted');

            return self::ENDPOINT;
        }

        return rtrim($origin, '/') . (string) parse_url(self::ENDPOINT, PHP_URL_PATH);
    }

    /**
     * The cron job's refresh of the rates postback amounts are converted
     * with (Conversion\Ledger\ExchangeRateCache): each pair a campaign needs
     * whose kept rate is missing or a day old is asked for, each request
     * given REFRESH_REQUEST_SECONDS and the run REFRESH_BUDGET_SECONDS, so a
     * service that does not answer costs the cron job that long and no
     * more. A pair with no usable answer keeps the rate it has and is asked
     * for again next hour; all of them are named in one log line.
     *
     * @param (callable(): float)|null $clock seconds, for tests; microtime(true) when null
     * @return array{fetched: int, failed: list<string>, skipped: int} skipped: pairs the budget did not reach
     */
    public static function refreshKept(\Prosper202\Database\Connection $conn, int $now, ?callable $clock = null): array
    {
        $clock ??= static fn (): float => microtime(true);
        $stopAt = $clock() + self::REFRESH_BUDGET_SECONDS;
        $skipped = 0;
        $result = \Prosper202\Conversion\Ledger\ExchangeRateCache::refresh(
            $conn,
            static function (string $from, string $to, string $amount) use ($clock, $stopAt, &$skipped): ?string {
                if ($clock() >= $stopAt) {
                    $skipped++;

                    return null;
                }

                return self::convert($from, $to, $amount, self::REFRESH_REQUEST_SECONDS);
            },
            $now
        );
        // A pair the budget did not reach was not asked for, and did not fail.
        $failed = array_slice($result['failed'], 0, count($result['failed']) - $skipped);
        if ($failed !== []) {
            error_log('Exchange rates: no rate could be had for ' . implode(', ', $failed)
                . '; each keeps the rate it has and is asked for again next hour');
        }

        return ['fetched' => $result['fetched'], 'failed' => $failed, 'skipped' => $skipped];
    }
}
