<?php

declare(strict_types=1);

namespace Prosper202\Spend;

use Api\V3\Controllers\UsersController;
use Prosper202\Database\Connection;
use Prosper202\Report\LocalTime;

/**
 * The spend ledger (202_spend_ledger): what each ad platform billed per
 * external ad per hour, written by whoever reads the platform (the
 * in-install ChatGPT Ads puller, POST /api/v3/spend-ledger) and read by the
 * reports as cost where present (LedgerCost).
 *
 * A row is keyed by account, platform, ad account, ad and hour, so pulling
 * an hour again replaces its row: spend settles after the clicks, and a
 * re-read of the trailing hours must correct, never add (CLAUDE.md #25).
 * Writing the same rows twice changes nothing, so a retry after an unknown
 * outcome is safe by the key itself (#13).
 *
 * Amounts are the account's currency only: a row in another one is refused
 * naming both, since nothing here converts currencies.
 */
final class SpendLedger
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(private readonly Connection $conn, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /**
     * Read a body's `rows` into SpendRows, or the field errors of every row
     * that cannot be read, a repeated key and a currency that is not the
     * account's among them.
     *
     * @return array{0: list<SpendRow>, 1: array<string, string>}
     */
    public function parse(int $userId, mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || $rows === []) {
            return [[], ['rows' => 'must be a list of 1 to ' . SpendRow::MAX_ROWS . ' spend rows (fields: ' . implode(', ', SpendRow::FIELDS) . ')']];
        }
        if (count($rows) > SpendRow::MAX_ROWS) {
            return [[], ['rows' => 'holds ' . count($rows) . ' rows; send at most ' . SpendRow::MAX_ROWS . ' per request']];
        }
        $now = $this->now();
        $currency = $this->accountCurrency($userId);
        $parsed = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $i => $entry) {
            if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                $errors["rows.$i"] = 'must be an object (fields: ' . implode(', ', SpendRow::FIELDS) . ')';
                continue;
            }
            [$row, $rowErrors] = SpendRow::parse($entry, "rows.$i.", $now);
            if ($row === null) {
                $errors += $rowErrors;
                continue;
            }
            if ($row->currency !== $currency) {
                $errors["rows.$i.currency"] = "is {$row->currency}, and this account records money in $currency;"
                    . ' spend in another currency is not converted, so it is not recorded';
                continue;
            }
            if (isset($seen[$row->key()])) {
                $errors["rows.$i"] = 'names the same platform, ad account, ad and hour as rows.' . $seen[$row->key()] . '; send each hour of an ad once';
                continue;
            }
            $seen[$row->key()] = $i;
            $parsed[] = $row;
        }

        return [$errors === [] ? $parsed : [], $errors];
    }

    /**
     * Write $rows, each replacing the row stored for its key. Answers how
     * many were new, how many replaced a different row and how many were
     * already stored as sent. One transaction: all or none.
     *
     * @param list<SpendRow> $rows rows parse() answered for this account
     * @return array{inserted: int, replaced: int, unchanged: int}
     */
    public function write(int $userId, array $rows): array
    {
        $now = $this->now();

        return $this->conn->transaction(function () use ($userId, $rows, $now): array {
            $counts = ['inserted' => 0, 'replaced' => 0, 'unchanged' => 0];
            foreach ($rows as $row) {
                // updated_at is assigned first, so its IF reads the stored
                // values before the assignments after it replace them. The
                // affected-row count is then 1 for a new row, 2 for a changed
                // one and 0 for one stored as sent.
                $stmt = $this->conn->prepareWrite(
                    'INSERT INTO 202_spend_ledger (user_id, platform, account_ref, ad_ref, hour_start, campaign_ref,'
                    . ' ad_group_ref, impressions, platform_clicks, spend, currency, created_at, updated_at)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE'
                    . ' updated_at = IF(campaign_ref = VALUES(campaign_ref) AND ad_group_ref = VALUES(ad_group_ref)'
                    . ' AND impressions = VALUES(impressions) AND platform_clicks = VALUES(platform_clicks)'
                    . ' AND spend = VALUES(spend) AND currency = VALUES(currency), updated_at, VALUES(updated_at)),'
                    . ' campaign_ref = VALUES(campaign_ref), ad_group_ref = VALUES(ad_group_ref),'
                    . ' impressions = VALUES(impressions), platform_clicks = VALUES(platform_clicks),'
                    . ' spend = VALUES(spend), currency = VALUES(currency)'
                );
                $this->conn->bind($stmt, 'isssissiissii', [
                    $userId, $row->platform, $row->accountRef, $row->adRef, $row->hourStart, $row->campaignRef,
                    $row->adGroupRef, $row->impressions, $row->platformClicks, $row->spend, $row->currency, $now, $now,
                ]);
                $affected = $this->conn->executeUpdate($stmt);
                match ($affected) {
                    1 => $counts['inserted']++,
                    2 => $counts['replaced']++,
                    0 => $counts['unchanged']++,
                    default => throw new \RuntimeException("A spend ledger write affected $affected rows for one row"),
                };
            }

            return $counts;
        });
    }

    /**
     * What write() would answer for $rows, writing nothing.
     *
     * @param list<SpendRow> $rows
     * @return array{inserted: int, replaced: int, unchanged: int}
     */
    public function preview(int $userId, array $rows): array
    {
        $counts = ['inserted' => 0, 'replaced' => 0, 'unchanged' => 0];
        foreach ($rows as $row) {
            $stmt = $this->conn->prepareWrite(
                'SELECT campaign_ref, ad_group_ref, impressions, platform_clicks, spend, currency FROM 202_spend_ledger'
                . ' WHERE user_id = ? AND platform = ? AND account_ref = ? AND ad_ref = ? AND hour_start = ?'
            );
            $this->conn->bind($stmt, 'isssi', [$userId, $row->platform, $row->accountRef, $row->adRef, $row->hourStart]);
            $held = $this->conn->fetchOne($stmt);
            if ($held === null) {
                $counts['inserted']++;
                continue;
            }
            $stored = [(string) $held['campaign_ref'], (string) $held['ad_group_ref'], (string) $held['impressions'],
                (string) $held['platform_clicks'], (string) $held['spend'], (string) $held['currency']];
            $sent = [$row->campaignRef, $row->adGroupRef, (string) $row->impressions,
                (string) $row->platformClicks, $row->spend, $row->currency];
            $counts[$stored === $sent ? 'unchanged' : 'replaced']++;
        }

        return $counts;
    }

    /**
     * The end of the latest hour the account's ledger holds (unix seconds,
     * exclusive), or null when it holds none: how far its spend data reaches.
     */
    public function reaches(int $userId): ?int
    {
        $stmt = $this->conn->prepareRead('SELECT MAX(hour_start) AS latest FROM 202_spend_ledger WHERE user_id = ?');
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException('Reading how far the spend ledger reaches answered no row');
        }

        return $row['latest'] === null ? null : (int) $row['latest'] + 3600;
    }

    /**
     * The account's ledger, summed per period (`hour` or `day` in the
     * account's zone, each row's own instant converted, CLAUDE.md #29) and
     * per platform, ad account, campaign, ad group and ad, newest first,
     * with how many of the account's clicks carry the ad in those hours and
     * the spend of the hours no click carries it in (unallocated).
     *
     * @param ?int $from first second, inclusive; null for no bound
     * @param ?int $to last second, inclusive; null for no bound
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function read(int $userId, ?int $from, ?int $to, string $interval, string $timezone, ?string $platform, int $limit): array
    {
        $local = LocalTime::datetimeSql('s.hour_start', $timezone);
        $period = match ($interval) {
            'hour' => "DATE_FORMAT($local, '%Y-%m-%d %H:00')",
            'day' => "DATE_FORMAT($local, '%Y-%m-%d')",
            default => throw new \InvalidArgumentException("No spend interval named $interval"),
        };
        [$lo, $hi] = LedgerCost::hours($from, $to);
        $where = 's.user_id = ? AND s.hour_start >= ? AND s.hour_start <= ?';
        $binds = [$userId, $lo, $hi];
        $types = 'iii';
        if ($platform !== null) {
            $where .= ' AND s.platform = ?';
            $binds[] = $platform;
            $types .= 's';
        }
        $sql = "SELECT $period AS period, s.platform, s.account_ref, s.campaign_ref, s.ad_group_ref, s.ad_ref, s.currency,"
            . ' SUM(s.impressions) AS impressions, SUM(s.platform_clicks) AS platform_clicks, SUM(s.spend) AS spend,'
            . ' COALESCE(SUM(m.clicks), 0) AS matched_clicks,'
            . ' SUM(CASE WHEN m.clicks IS NULL THEN s.spend ELSE 0 END) AS unallocated_spend,'
            . ' MAX(s.updated_at) AS updated_at'
            . ' FROM 202_spend_ledger s'
            . ' LEFT JOIN (SELECT c.ad_ref, c.hour_start, COUNT(*) AS clicks FROM (' . LedgerCost::clicksSql() . ') c'
            . ' GROUP BY c.ad_ref, c.hour_start) m ON m.ad_ref = s.ad_ref AND m.hour_start = s.hour_start'
            . " WHERE $where"
            . ' GROUP BY period, s.platform, s.account_ref, s.campaign_ref, s.ad_group_ref, s.ad_ref, s.currency'
            . ' ORDER BY period DESC, s.platform, s.account_ref, s.ad_ref'
            . ' LIMIT ?';
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'iii' . $types . 'i', [$userId, $lo, $hi + 3599, ...$binds, $limit + 1]);
        $rows = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $rows[] = [
                'period' => (string) $row['period'],
                'platform' => (string) $row['platform'],
                'account_ref' => (string) $row['account_ref'],
                'campaign_ref' => $row['campaign_ref'] === '' ? null : (string) $row['campaign_ref'],
                'ad_group_ref' => $row['ad_group_ref'] === '' ? null : (string) $row['ad_group_ref'],
                'ad_ref' => (string) $row['ad_ref'],
                'impressions' => (int) $row['impressions'],
                'platform_clicks' => (int) $row['platform_clicks'],
                'spend' => (float) $row['spend'],
                'currency' => (string) $row['currency'],
                'matched_clicks' => (int) $row['matched_clicks'],
                'unallocated_spend' => (float) $row['unallocated_spend'],
                'updated_at' => (int) $row['updated_at'],
            ];
        }
        $truncated = count($rows) > $limit;

        return ['rows' => $truncated ? array_slice($rows, 0, $limit) : $rows, 'truncated' => $truncated];
    }

    /** The currency the account records money in (the reports'). */
    public function accountCurrency(int $userId): string
    {
        $stmt = $this->conn->prepareRead('SELECT user_account_currency FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$userId]);

        return UsersController::normalizeCurrency($this->conn->fetchOne($stmt)['user_account_currency'] ?? null);
    }
}
