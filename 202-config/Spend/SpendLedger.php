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
    /** How many times write() runs a transaction that a deadlock rolled back. */
    public const DEADLOCK_ATTEMPTS = 4;

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
        $adOwner = [];
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
            $owner = $row->platform . "\0" . $row->accountRef;
            if (isset($adOwner[$row->adRef]) && $adOwner[$row->adRef][0] !== $owner) {
                $errors["rows.$i.ad_ref"] = 'names ad ' . $row->adRef . ' under another platform or ad account than rows.'
                    . $adOwner[$row->adRef][1] . '; an ad id names one ad, whose clicks carry it';
                continue;
            }
            $adOwner[$row->adRef] ??= [$owner, $i];
            $parsed[] = $row;
        }
        if ($errors === []) {
            $errors = $this->ownerErrors($userId, $parsed, false);
        }

        return [$errors === [] ? $parsed : [], $errors];
    }

    /**
     * The rows whose ad the ledger already holds under another platform or
     * ad account. Clicks are matched to the ledger by the ad id alone (they
     * carry no platform), so one id must name one ad, or its clicks would
     * be costed at two platforms' spend. $lock reads with FOR UPDATE, inside
     * write()'s transaction, so two writers cannot both pass.
     *
     * @param array<int, SpendRow> $rows keyed by their position in the request
     * @return array<string, string>
     */
    private function ownerErrors(int $userId, array $rows, bool $lock): array
    {
        $refs = [];
        foreach ($rows as $i => $row) {
            $refs[$row->adRef] ??= [$row->platform, $row->accountRef, $i];
        }
        $errors = [];
        foreach (array_chunk(array_keys($refs), 500, false) as $chunk) {
            $chunk = array_map('strval', $chunk);
            // Locking, the rows themselves (and the gaps of their ad index, so
            // no other writer adds the ad meanwhile); otherwise one per owner.
            $stmt = $this->conn->prepareWrite(
                ($lock ? 'SELECT ad_ref, platform, account_ref' : 'SELECT DISTINCT ad_ref, platform, account_ref')
                . ' FROM 202_spend_ledger WHERE user_id = ? AND ad_ref IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')' . ($lock ? ' FOR UPDATE' : '')
            );
            $this->conn->bind($stmt, 'i' . str_repeat('s', count($chunk)), [$userId, ...$chunk]);
            foreach ($this->conn->fetchAll($stmt) as $held) {
                [$platform, $account, $i] = $refs[(string) $held['ad_ref']] ?? [null, null, null];
                if ($i === null || ($platform === (string) $held['platform'] && $account === (string) $held['account_ref'])) {
                    continue;
                }
                $errors["rows.$i.ad_ref"] = 'ad ' . $held['ad_ref'] . ' is already recorded for ' . $held['platform']
                    . ' ad account ' . $held['account_ref'] . '; an ad id names one ad, whose clicks carry it';
            }
        }

        return $errors;
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

        // In key order, whatever order they were sent in: two writers of
        // overlapping hours then lock rows in one order and cannot deadlock
        // (CLAUDE.md #34).
        uasort($rows, static fn (SpendRow $a, SpendRow $b): int => strcmp($a->key(), $b->key()));

        // Two writers adding the same new ad still deadlock: each one's
        // locking read takes the gap the other inserts into. MySQL rolls the
        // loser back whole, so it runs again from the start, when it reads
        // the winner's rows (CLAUDE.md #34: a deadlock, never a lock wait
        // timeout, by its errno).
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->writeOnce($userId, $rows, $now);
            } catch (\Throwable $e) {
                if ($attempt >= self::DEADLOCK_ATTEMPTS || !Connection::isMysqlError($e, 1213, 'Deadlock found')) {
                    throw $e;
                }
                usleep(random_int(5_000, 25_000) * $attempt);
            }
        }
    }

    /**
     * write()'s one transaction.
     *
     * @param array<int, SpendRow> $rows in key order
     * @return array{inserted: int, replaced: int, unchanged: int}
     */
    private function writeOnce(int $userId, array $rows, int $now): array
    {
        return $this->conn->transaction(function () use ($userId, $rows, $now): array {
            $errors = $this->ownerErrors($userId, $rows, true);
            if ($errors !== []) {
                throw new SpendLedgerRefused($errors);
            }
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
     * How far the account's spend data reaches: the end of the latest hour
     * (unix seconds, exclusive) of the platform that reaches least, so a
     * current platform does not hide a lagging one; null when the ledger
     * holds none.
     */
    public function reaches(int $userId): ?int
    {
        $byPlatform = $this->reachesByPlatform($userId);

        return $byPlatform === [] ? null : min($byPlatform);
    }

    /**
     * The end of the latest hour each platform's rows reach: platform =>
     * unix seconds (exclusive).
     *
     * @return array<string, int>
     */
    public function reachesByPlatform(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT platform, MAX(hour_start) AS latest FROM 202_spend_ledger WHERE user_id = ? GROUP BY platform ORDER BY platform'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[(string) $row['platform']] = (int) $row['latest'] + 3600;
        }

        return $out;
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
            // %i: an hour starts at :30 in a half-hour zone.
            'hour' => "DATE_FORMAT($local, '%Y-%m-%d %H:%i')",
            'day' => "DATE_FORMAT($local, '%Y-%m-%d')",
            default => throw new \InvalidArgumentException("No spend interval named $interval"),
        };
        [$lo, $hi] = LedgerCost::hours($from, $to);
        // The clicks are read only over the hours the ledger holds.
        $stmt = $this->conn->prepareRead('SELECT MIN(hour_start) AS lo, MAX(hour_start) AS hi FROM 202_spend_ledger'
            . ' WHERE user_id = ? AND hour_start >= ? AND hour_start <= ?');
        $this->conn->bind($stmt, 'iii', [$userId, $lo, $hi]);
        $extent = $this->conn->fetchOne($stmt);
        if ($extent === null) {
            throw new \RuntimeException('Reading the spend ledger\'s hours answered no row');
        }
        if ($extent['lo'] === null) {
            return ['rows' => [], 'truncated' => false];
        }
        $plan = LedgerCost::plan($this->conn, $userId, null, (int) $extent['lo'], (int) $extent['hi']);
        $where = 's.user_id = ? AND s.hour_start >= ? AND s.hour_start <= ?';
        $binds = [$userId, $lo, $hi];
        $types = 'iii';
        if ($platform !== null) {
            $where .= ' AND s.platform = ?';
            $binds[] = $platform;
            $types .= 's';
        }
        // Each row reaches the clicks carrying its ad in its hour through
        // the ad's number and the hour's (LedgerCost::plan()).
        $matched = '0';
        $join = '';
        $joinBinds = [];
        $joinTypes = '';
        if ($plan !== null) {
            [$ads, $adBinds, $adTypes] = $plan['ads'];
            [$clicks, $clickBinds, $clickTypes] = $plan['clicks'];
            $join = " LEFT JOIN ($ads) a ON a.ad_ref = s.ad_ref"
                . " LEFT JOIN ($clicks) m ON m.ad_no = a.ad_no AND m.h = s.hour_start DIV 3600";
            $joinBinds = [...$adBinds, ...$clickBinds];
            $joinTypes = $adTypes . $clickTypes;
            $matched = 'm.clicks';
        }
        $sql = "SELECT $period AS period, s.platform, s.account_ref, s.campaign_ref, s.ad_group_ref, s.ad_ref, s.currency,"
            . ' SUM(s.impressions) AS impressions, SUM(s.platform_clicks) AS platform_clicks, SUM(s.spend) AS spend,'
            . " COALESCE(SUM($matched), 0) AS matched_clicks,"
            . " SUM(CASE WHEN COALESCE($matched, 0) = 0 THEN s.spend ELSE 0 END) AS unallocated_spend,"
            . ' MAX(s.updated_at) AS updated_at'
            . ' FROM 202_spend_ledger s' . $join
            . " WHERE $where"
            . ' GROUP BY period, s.platform, s.account_ref, s.campaign_ref, s.ad_group_ref, s.ad_ref, s.currency'
            . ' ORDER BY period DESC, s.platform, s.account_ref, s.ad_ref'
            . ' LIMIT ?';
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $joinTypes . $types . 'i', [...$joinBinds, ...$binds, $limit + 1]);
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
