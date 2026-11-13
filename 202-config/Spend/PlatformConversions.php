<?php

declare(strict_types=1);

namespace Prosper202\Spend;

use Prosper202\Database\Connection;
use Prosper202\Report\LocalTime;

/**
 * The conversions an ad platform reports per ad per day
 * (202_ad_platform_conversions), beside Prosper202's own conversions of the
 * clicks that carried each ad, for reconciliation. A day is a date in the ad
 * account's zone, as the platform reports it, and Prosper202's side is
 * counted in that same zone, by click time (the platform's default basis is
 * the ad interaction's date).
 */
final class PlatformConversions
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(private readonly Connection $conn, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Store the platform's rows for an ad account: each replaces the row of
     * its ad and day. A row that cannot be read exactly is left out and
     * named in the answer; the rest are written in one transaction.
     *
     * @param list<array<string, mixed>> $rows entity_id, date, conversions,
     *     click_through_conversions, view_through_conversions,
     *     order_created_attributed_sales, order_created_attributed_sales_currency
     * @return array{written: int, refused: list<string>}
     */
    public function write(int $userId, string $platform, string $accountRef, string $timezone, array $rows): array
    {
        $good = [];
        $refused = [];
        foreach ($rows as $i => $row) {
            $why = self::refusal($row);
            if ($why !== null) {
                $refused[] = "row $i: $why";
                continue;
            }
            $good[(string) $row['entity_id'] . "\0" . (string) $row['date']] = $row;
        }
        ksort($good, SORT_STRING);
        $now = ($this->clock)();
        $written = $good === [] ? 0 : $this->conn->transaction(function () use ($good, $userId, $platform, $accountRef, $timezone, $now): int {
            foreach ($good as $row) {
                $stmt = $this->conn->prepareWrite(
                    'INSERT INTO 202_ad_platform_conversions (user_id, platform, account_ref, ad_ref, day, timezone, conversions,'
                    . ' click_through, view_through, sales, sales_currency, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE timezone = VALUES(timezone), conversions = VALUES(conversions),'
                    . ' click_through = VALUES(click_through), view_through = VALUES(view_through), sales = VALUES(sales),'
                    . ' sales_currency = VALUES(sales_currency), updated_at = VALUES(updated_at)'
                );
                $sales = $row['order_created_attributed_sales'] ?? null;
                $this->conn->bind($stmt, 'isssssiiissi', [
                    $userId, $platform, $accountRef, (string) $row['entity_id'], (string) $row['date'], $timezone,
                    (int) $row['conversions'],
                    isset($row['click_through_conversions']) ? (int) $row['click_through_conversions'] : null,
                    isset($row['view_through_conversions']) ? (int) $row['view_through_conversions'] : null,
                    $sales === null ? null : self::salesAmount($sales),
                    $sales === null ? null : (string) ($row['order_created_attributed_sales_currency'] ?? ''),
                    $now,
                ]);
                $this->conn->executeUpdate($stmt);
            }

            return count($good);
        });

        return ['written' => $written, 'refused' => $refused];
    }

    /**
     * Why a platform row is not stored exactly, or null.
     *
     * @param mixed $row
     */
    public static function refusal(mixed $row): ?string
    {
        if (!is_array($row)) {
            return 'not an object';
        }
        $ad = $row['entity_id'] ?? null;
        if (!is_string($ad) || $ad === '' || mb_strlen($ad) > SpendRow::REF_WIDTH || preg_match('/^\s|\s$|[\x00-\x1F\x7F]/u', $ad) === 1) {
            return 'entity_id is not an ad id';
        }
        $day = $row['date'] ?? null;
        if (!is_string($day) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "ad $ad: date is not a day (YYYY-MM-DD)";
        }
        foreach (['conversions' => true, 'click_through_conversions' => false, 'view_through_conversions' => false] as $field => $required) {
            $value = $row[$field] ?? null;
            if ($value === null && !$required) {
                continue;
            }
            if (!is_int($value) || $value < 0 || $value > 4294967295) {
                return "ad $ad on $day: $field is not a whole number of at least 0";
            }
        }
        $sales = $row['order_created_attributed_sales'] ?? null;
        if ($sales !== null) {
            if (self::salesAmount($sales) === null) {
                return "ad $ad on $day: order_created_attributed_sales is not an amount of at least 0 below 10000000000";
            }
            $currency = $row['order_created_attributed_sales_currency'] ?? null;
            if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                return "ad $ad on $day: order_created_attributed_sales_currency is not a currency code";
            }
        }

        return null;
    }

    /**
     * OpenAI's sales, an unrounded decimal string, at the column's six
     * decimals (DECIMAL(16,6)), rounded half up from the text; null when it
     * is not an amount or does not fit.
     */
    public static function salesAmount(mixed $sales): ?string
    {
        return is_string($sales) ? SixDecimals::fromText($sales) : null;
    }

    /**
     * The account's platform conversions per ad and day in [$fromDay,
     * $toDay] (dates, inclusive), newest first, each with Prosper202's
     * conversions of the clicks carrying the ad on that day in the row's
     * zone (`prosper202_conversions`). Two platform ads that differ only in
     * case or trailing spaces are one ad to the click tie and share its count.
     *
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function read(int $userId, string $fromDay, string $toDay, ?string $platform, int $limit): array
    {
        $sql = 'SELECT platform, account_ref, ad_ref, day, timezone, conversions, click_through, view_through, sales, sales_currency, updated_at'
            . ' FROM 202_ad_platform_conversions WHERE user_id = ? AND day >= ? AND day <= ?'
            . ($platform === null ? '' : ' AND platform = ?')
            . ' ORDER BY day DESC, platform, account_ref, ad_ref LIMIT ?';
        $stmt = $this->conn->prepareRead($sql);
        $binds = [$userId, $fromDay, $toDay, ...($platform === null ? [] : [$platform]), $limit + 1];
        $this->conn->bind($stmt, 'iss' . ($platform === null ? '' : 's') . 'i', $binds);
        $rows = $this->conn->fetchAll($stmt);
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $ours = $this->ours($userId, $rows);
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['platform'] . "\0" . LedgerCost::tieKey((string) $row['ad_ref']) . "\0" . (string) $row['day'] . "\0" . (string) $row['timezone'];
            $out[] = [
                'day' => (string) $row['day'],
                'timezone' => (string) $row['timezone'],
                'platform' => (string) $row['platform'],
                'account_ref' => (string) $row['account_ref'],
                'ad_ref' => (string) $row['ad_ref'],
                'platform_conversions' => (int) $row['conversions'],
                'click_through' => $row['click_through'] === null ? null : (int) $row['click_through'],
                'view_through' => $row['view_through'] === null ? null : (int) $row['view_through'],
                'sales' => $row['sales'] === null ? null : (float) $row['sales'],
                'sales_currency' => $row['sales_currency'] === null ? null : (string) $row['sales_currency'],
                'prosper202_conversions' => $ours[$key] ?? 0,
                'updated_at' => (int) $row['updated_at'],
            ];
        }

        return ['rows' => $out, 'truncated' => $truncated];
    }

    /**
     * Prosper202's conversions of the clicks carrying each row's ad, per day
     * in each row's zone, matched to clicks as the spend of the row's
     * platform is (LedgerCost::sets()): platform\0ad\0day\0zone => leads.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function ours(int $userId, array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) $row['platform'] . "\0" . (string) $row['timezone']][] = $row;
        }
        $out = [];
        foreach ($groups as $group => $zoneRows) {
            [$platform, $zone] = explode("\0", (string) $group, 2);
            $sets = LedgerCost::sets($this->conn, $userId, null, 0, 0, array_map(static fn (array $r): string => (string) $r['ad_ref'], $zoneRows), $platform);
            if ($sets === []) {
                continue;
            }
            $days = array_map(static fn (array $r): string => (string) $r['day'], $zoneRows);
            $tz = new \DateTimeZone($zone);
            $from = (new \DateTimeImmutable(min($days) . ' 00:00:00', $tz))->getTimestamp();
            $to = (new \DateTimeImmutable(max($days) . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp() - 1;
            $setRows = [];
            foreach ($sets as $set) {
                $setRows[] = 'SELECT ' . $set['set_no'] . ' AS set_no, ' . $set['network_id'] . ' AS network_id';
            }
            $refOf = [];
            foreach ($sets as $set) {
                $refOf[$set['set_no'] . ':' . $set['network_id']] = $set['ad_ref'];
            }
            $local = LocalTime::datetimeSql('d.click_time', $zone);
            $stmt = $this->conn->prepareRead(
                "SELECT m.set_no, m.network_id, DATE_FORMAT($local, '%Y-%m-%d') AS day, SUM(d.leads) AS leads"
                . ' FROM 202_dataengine d JOIN (' . implode(' UNION ALL ', $setRows) . ') m'
                . ' ON m.set_no = CAST(d.variable_set_id AS UNSIGNED) AND m.network_id = d.ppc_network_id'
                . ' WHERE d.user_id = ? AND d.click_time >= ? AND d.click_time <= ?'
                . ' GROUP BY m.set_no, m.network_id, day'
            );
            $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
            foreach ($this->conn->fetchAll($stmt) as $hit) {
                $ref = $refOf[(int) $hit['set_no'] . ':' . (int) $hit['network_id']] ?? null;
                if ($ref === null) {
                    continue;
                }
                // As sets() matched the click's ad to the platform's (LedgerCost::tieKey()).
                $key = $platform . "\0" . LedgerCost::tieKey($ref) . "\0" . (string) $hit['day'] . "\0" . $zone;
                $out[$key] = ($out[$key] ?? 0) + (int) $hit['leads'];
            }
        }

        return $out;
    }
}
