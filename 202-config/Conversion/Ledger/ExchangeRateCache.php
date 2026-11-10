<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Api\V3\Controllers\UsersController;
use Prosper202\Database\Connection;

/**
 * The exchange rates a postback's or pixel's amount is converted with
 * (202_exchange_rates).
 *
 * A campaign in another currency than its account's is paid in that
 * currency, so an amount its network sends is in it too. The conversion
 * path converts it with the rate kept here and never asks the rate service:
 * a postback must not wait on, or fail with, another host. The cron job
 * refreshes the pairs the campaigns need once a day (refresh()).
 *
 * A rate older than MAX_AGE is no rate: the amount is then recorded as
 * sent, in its own currency (MysqlConversionRepository says so on the row).
 */
final class ExchangeRateCache
{
    /** A rate is asked for again once it is this old. */
    public const REFRESH_AFTER = 86400;

    /** A rate older than this is not used: a week of failed refreshes. */
    public const MAX_AGE = 7 * 86400;

    /**
     * The amount the service is asked to convert. It answers to two
     * decimals, so the rate is read from a large amount: at 100,000 the
     * weakest pair of the supported currencies still gives five digits.
     */
    public const REFERENCE = '100000.00';

    private function __construct()
    {
    }

    /**
     * One $from in $to, or null when no usable rate is kept. Read on the
     * write connection: the caller is inside a conversion's transaction.
     *
     * @return numeric-string|null
     */
    public static function rate(Connection $conn, string $from, string $to, int $now): ?string
    {
        $stmt = $conn->prepareWrite(
            'SELECT rate, fetched_at FROM 202_exchange_rates WHERE from_currency = ? AND to_currency = ? LIMIT 1'
        );
        $conn->bind($stmt, 'ss', [$from, $to]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }
        $rate = (string) $row['rate'];
        if (!is_numeric($rate) || (float) $rate <= 0) {
            throw new LedgerIntegrityException('the exchange rate kept for ' . $from . ' to ' . $to . ' reads as "' . $rate . '"');
        }

        return (int) $row['fetched_at'] >= $now - self::MAX_AGE ? $rate : null;
    }

    /**
     * $units of $from in $to at $rate, in units, rounded half away from zero.
     *
     * @param numeric-string $rate
     */
    public static function convert(int $units, string $rate): int
    {
        return (int) round($units * (float) $rate);
    }

    /**
     * The rate that turned $statedUnits into $recordedUnits, applied to
     * $units: a refund of a converted sale is converted as its sale was, so
     * taking back all that was stated takes back all that was recorded.
     */
    public static function convertAs(int $units, int $statedUnits, int $recordedUnits): int
    {
        if ($statedUnits === 0) {
            throw new LedgerIntegrityException('a converted amount states 0, which gives no rate');
        }

        return (int) round($units * (abs($recordedUnits) / abs($statedUnits)));
    }

    /**
     * Ask the rate service for every pair a campaign needs whose kept rate
     * is missing or a day old, at most $limit a run. A pair the service does
     * not answer keeps the rate it has, and is asked again next run.
     *
     * @param callable(string $from, string $to, string $amount): ?string $convert
     *        $amount in $from, in $to; null when no rate could be had
     * @return array{fetched: int, failed: list<string>}
     */
    public static function refresh(Connection $conn, callable $convert, int $now, int $limit = 10): array
    {
        $fetched = 0;
        $failed = [];
        foreach (self::duePairs($conn, $now) as [$from, $to]) {
            if ($fetched + count($failed) >= $limit) {
                break;
            }
            $answer = $convert($from, $to, self::REFERENCE);
            $rate = is_string($answer) && is_numeric($answer) ? (float) $answer / (float) self::REFERENCE : 0.0;
            if ($rate <= 0.0 || !is_finite($rate)) {
                $failed[] = $from . '>' . $to;
                continue;
            }
            $stmt = $conn->prepareWrite(
                'INSERT INTO 202_exchange_rates (from_currency, to_currency, rate, fetched_at) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE rate = VALUES(rate), fetched_at = VALUES(fetched_at)'
            );
            $conn->bind($stmt, 'sssi', [$from, $to, number_format($rate, 10, '.', ''), $now]);
            $conn->executeUpdate($stmt);
            $fetched++;
        }

        return ['fetched' => $fetched, 'failed' => $failed];
    }

    /**
     * refresh() for a caller that has just changed which pairs are needed
     * (a campaign saved in another currency, the account's currency changed)
     * and whose own work is done: a failure is logged, and the cron job
     * fetches the pair within the hour.
     *
     * @param callable(string $from, string $to, string $amount): ?string $convert
     */
    public static function refreshQuietly(Connection $conn, callable $convert, int $limit = 5): void
    {
        try {
            $result = self::refresh($conn, $convert, time(), $limit);
            if ($result['failed'] !== []) {
                error_log('p202 exchange rates: no rate could be had for ' . implode(', ', $result['failed'])
                    . '; the cron job asks again within the hour.');
            }
        } catch (\Throwable $e) {
            error_log('p202 exchange rates: the kept rates were not refreshed: ' . $e->getMessage());
        }
    }

    /**
     * The (campaign currency, account currency) pairs of campaigns paid in
     * another currency than their account's, less those with a rate fetched
     * within REFRESH_AFTER.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function duePairs(Connection $conn, int $now): array
    {
        $stmt = $conn->prepareWrite(
            'SELECT DISTINCT c.aff_campaign_currency AS from_currency, p.user_account_currency AS to_currency
             FROM 202_aff_campaigns AS c
             JOIN 202_users_pref AS p ON p.user_id = c.user_id
             WHERE c.aff_campaign_deleted = 0 AND c.aff_campaign_foreign_payout <> 0'
        );
        $pairs = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $from = RowCurrency::read($row['from_currency'] ?? null);
            $to = UsersController::normalizeCurrency($row['to_currency'] ?? null);
            if ($from !== RowCurrency::UNKNOWN && $from !== $to) {
                $pairs[$from . '>' . $to] = [$from, $to];
            }
        }
        if ($pairs === []) {
            return [];
        }

        $fresh = $conn->prepareWrite('SELECT from_currency, to_currency FROM 202_exchange_rates WHERE fetched_at > ?');
        $conn->bind($fresh, 'i', [$now - self::REFRESH_AFTER]);
        foreach ($conn->fetchAll($fresh) as $row) {
            unset($pairs[(string) $row['from_currency'] . '>' . (string) $row['to_currency']]);
        }

        return array_values($pairs);
    }
}
