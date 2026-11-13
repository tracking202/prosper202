<?php

declare(strict_types=1);

namespace Prosper202\Spend;

use Prosper202\Database\Connection;

/**
 * A report's cost read from the spend ledger where it has the click's ad
 * and hour, and from the click's own CPC everywhere else.
 *
 * A click is matched to the ledger by the ad id its tracking link carried:
 * the value of its traffic source variable whose parameter is `ad_id` (the
 * ChatGPT Ads tracking template sets `ad_id={ad_id}`), and by the UTC hour
 * it arrived in. A platform that has a traffic source mark (SOURCE_PLATFORMS)
 * has its spend matched only to clicks of a source carrying that mark, on an
 * account that has such a source, so a click of another source with the same
 * ad id keeps its own CPC. On an account with no marked source (one set up by
 * hand) the ad id alone matches, as for every other platform. An hour's spend for an ad is shared equally by the
 * account's clicks that carry that ad in that hour; the share replaces each
 * click's CPC. Spend in an hour no click carries the ad in is unallocated:
 * reported beside the figures, never spread onto other hours or clicks.
 *
 * forReport() answers null when the account's ledger has nothing in the
 * window: the report then runs exactly as it did before the ledger
 * existed, so an install that never writes a ledger row reads the same
 * figures to the byte (LedgerCostIntegrationTest).
 *
 * How it stays fast: the variable sets that carry a ledger ad are read
 * first, from the ledger's ads (a few rows, sets()), and handed to the
 * report as a table of whole numbers, so each click reaches its ad and hour
 * by a key on numbers rather than through its variables, and every GROUP BY
 * is on numbers (one on text took seconds per 100,000 clicks; measured on
 * MariaDB 10.11).
 */
final class LedgerCost
{
    /** The traffic source variable parameter that names the click's ad. */
    public const string AD_PARAMETER = 'ad_id';

    /**
     * Ledger platform => the 202_ppc_networks.ppc_network_platform its
     * clicks come from. On an account with a traffic source of that mark
     * (removed or not), a platform listed here is matched only to clicks of
     * such a source. One not listed, or an account with no such source (a
     * source set up by hand, never marked by Add ChatGPT Ads), is matched by
     * the ad id alone.
     */
    public const array SOURCE_PLATFORMS = ['openai_ads' => \Prosper202\Setup\ChatGptAdsSource::PLATFORM];

    /** The derived tables' alias prefix in a report, outside ReportsController's join aliases. */
    public const string ALIAS = 'sl';

    /**
     * At most this many ad-hours are read into memory at once (stage());
     * LedgerCostIntegrationTest sets it to 1 to read each hour alone.
     */
    private static int $slicePairs = 50000;

    /**
     * @param list<int|string> $binds the join's, which come before the report's own
     * @param array<string, int> $reachesByPlatform
     * @param int $staged the number of this report's rows in stage()'s tables on $staging's connection, 0 for none
     */
    private function __construct(
        public readonly string $join,
        public readonly array $binds,
        public readonly string $types,
        public readonly int $reaches,
        public readonly array $reachesByPlatform,
        public readonly string $unallocated,
        public readonly int $otherCurrencyRows,
        private readonly string $row = 'de',
        private readonly ?Connection $staging = null,
        private readonly int $staged = 0,
        private readonly bool $tied = false,
    ) {
    }

    /**
     * The ledger's part in a report over [$from, $to] (inclusive, null for
     * no bound) of $userId's clicks, or null when the ledger holds no row
     * of the account in that window. $row is the alias the report gives
     * 202_dataengine (`de` in the REST reports, `2st` on the pages).
     */
    public static function forReport(Connection $conn, int $userId, ?int $from, ?int $to, string $currency, string $row = 'de'): ?self
    {
        [$lo, $hi] = self::hours($from, $to);
        $stmt = $conn->prepareRead(
            'SELECT COUNT(*) AS n, COALESCE(SUM(currency <> ?), 0) AS other,'
            . ' COALESCE(SUM(CASE WHEN currency = ? THEN spend END), 0) AS spend,'
            . ' MIN(CASE WHEN currency = ? THEN hour_start END) AS first_hour,'
            . ' MAX(CASE WHEN currency = ? THEN hour_start END) AS last_hour FROM 202_spend_ledger'
            . ' WHERE user_id = ? AND hour_start >= ? AND hour_start <= ?'
        );
        $conn->bind($stmt, 'ssssiii', [$currency, $currency, $currency, $currency, $userId, $lo, $hi]);
        $held = $conn->fetchOne($stmt);
        if ($held === null) {
            throw new \RuntimeException('Counting the spend ledger rows of a report answered no row');
        }
        if ((int) $held['n'] === 0) {
            return null;
        }
        $byPlatform = (new SpendLedger($conn))->reachesByPlatform($userId);
        if ($byPlatform === []) {
            throw new \RuntimeException('The spend ledger held rows for a report and then none');
        }
        $other = (int) $held['other'];
        if ($held['first_hour'] === null) {
            // Only rows in another currency: nothing is costed from them.
            return new self('', [], '', min($byPlatform), $byPlatform, '0', $other, $row);
        }
        // Only the hours the ledger holds in the account's currency are
        // read, so a report with no window does not scan every click.
        $lo = max($lo, (int) $held['first_hour']);
        $hi = min($hi, (int) $held['last_hour']);

        $sets = self::sets($conn, $userId, $currency, $lo, $hi);
        if ($sets === []) {
            // No click can carry a ledger ad: all of it is unallocated.
            return new self('', [], '', min($byPlatform), $byPlatform, (string) $held['spend'], $other, $row);
        }
        $alias = self::ALIAS;
        if (preg_match('/^[a-z0-9_]+$/D', $row) !== 1) {
            throw new \InvalidArgumentException("Not a table alias: $row");
        }

        $staged = self::stage($conn, $userId, $currency, $lo, $hi, $sets);
        if ($staged !== null) {
            $join = "LEFT JOIN p202_ledger_sets {$alias}m ON {$alias}m.k = $staged"
                . " AND {$alias}m.set_no = CAST($row.variable_set_id AS UNSIGNED) AND {$alias}m.network_id = $row.ppc_network_id"
                . " LEFT JOIN p202_ledger_hours $alias ON $alias.k = $staged AND $alias.ad_no = {$alias}m.ad_no"
                . " AND $alias.h = $row.click_time DIV 3600";
            $statsSql = "SELECT spend FROM p202_ledger_hours WHERE k = $staged";
            $statsBinds = [];
            $statsTypes = '';
        } else {
            $plan = self::plan($conn, $userId, $currency, $lo, $hi, $sets)
                ?? throw new \LogicException('The spend ledger plan answered nothing for variable sets that carry its ads');
            [$stats, $statsBinds, $statsTypes] = $plan['stats'];
            $join = 'LEFT JOIN (' . $plan['sets'] . ") {$alias}m ON {$alias}m.set_no = CAST($row.variable_set_id AS UNSIGNED)"
                . " AND {$alias}m.network_id = $row.ppc_network_id"
                . " LEFT JOIN ($stats) $alias ON $alias.ad_no = {$alias}m.ad_no AND $alias.h = $row.click_time DIV 3600"
                . " AND $alias.clicks > 0 AND $alias.ledger_rows > 0";
            $statsSql = "SELECT g.spend FROM ($stats) g WHERE g.clicks > 0 AND g.ledger_rows > 0";
        }

        try {
            // Unallocated is what the window holds less what clicks carry, in
            // DECIMAL so no cent is lost to a float.
            $stmt = $conn->prepareRead(
                'SELECT CAST(? AS DECIMAL(40,24)) - COALESCE(SUM(u.spend), 0) AS spend FROM (' . $statsSql . ') u'
            );
            $conn->bind($stmt, 's' . $statsTypes, [(string) $held['spend'], ...$statsBinds]);
            $left = $conn->fetchOne($stmt);
            if ($left === null) {
                throw new \RuntimeException('Summing the unallocated spend of a report answered no row');
            }
        } catch (\Throwable $e) {
            if ($staged !== null) {
                self::unstage($conn, $staged);
            }
            throw $e;
        }

        // The note says spend goes to one source only where the tie applied.
        $marked = array_intersect_key(self::SOURCE_PLATFORMS, $byPlatform);
        $tied = array_intersect_key(self::marks($conn, $userId), array_flip($marked)) !== [];

        return new self(
            $join,
            $statsBinds,
            $statsTypes,
            min($byPlatform),
            $byPlatform,
            (string) $left['spend'],
            $other,
            $row,
            $staged === null ? null : $conn,
            $staged ?? 0,
            $tied,
        );
    }

    /**
     * The per-ad, per-hour figures a report joins, written once into
     * temporary tables of this connection keyed as the report reads them:
     * the variable sets carrying a ledger ad (set_no, network_id -> ad_no),
     * the ledger's ads (ad_ref -> ad_no) and, per ad and UTC hour that both
     * the ledger and a click have, the hour's spend and the account's clicks
     * carrying the ad (ad_no, h). A join of the report's clicks with derived
     * tables of the same was read row by row, and each report computed it
     * twice.
     *
     * The rows are read with plain reads and written by this process: an
     * INSERT ... SELECT from 202_dataengine share-locks every click it reads
     * (CLAUDE.md #36). They are read in slices of hours so no slice holds
     * more than $slicePairs ad-hours in memory. Each report's rows carry a
     * random number of its own (`k`, the answer), are deleted with this
     * object (or go with the connection), and are exactly what plan()'s
     * `stats` answers, rows with no click or no ledger row left out.
     *
     * Null when this connection cannot create a temporary table (the
     * privilege is missing, a read-only server): forReport() then joins
     * plan()'s derived tables, which answer the same, slower.
     *
     * @param list<array{set_no: int, network_id: int, ad_ref: string}> $sets
     */
    private static function stage(Connection $conn, int $userId, string $currency, int $lo, int $hi, array $sets): ?int
    {
        try {
            foreach (
                [
                    'CREATE TEMPORARY TABLE IF NOT EXISTS p202_ledger_sets (k BIGINT UNSIGNED NOT NULL,'
                    . ' set_no BIGINT UNSIGNED NOT NULL, network_id BIGINT UNSIGNED NOT NULL, ad_no INT UNSIGNED NOT NULL,'
                    . ' PRIMARY KEY (k, set_no, network_id)) ENGINE=InnoDB',
                    'CREATE TEMPORARY TABLE IF NOT EXISTS p202_ledger_hours (k BIGINT UNSIGNED NOT NULL,'
                    . ' ad_no INT UNSIGNED NOT NULL, h INT UNSIGNED NOT NULL, spend DECIMAL(40,24) NOT NULL,'
                    . ' clicks BIGINT NOT NULL, ledger_rows BIGINT NOT NULL, PRIMARY KEY (k, ad_no, h)) ENGINE=InnoDB',
                    'CREATE TEMPORARY TABLE IF NOT EXISTS p202_ledger_ads (k BIGINT UNSIGNED NOT NULL,'
                    . ' ad_ref VARCHAR(350) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,'
                    . ' ad_no INT UNSIGNED NOT NULL, PRIMARY KEY (k, ad_ref)) ENGINE=InnoDB',
                ] as $ddl
            ) {
                $stmt = $conn->prepareRead($ddl);
                $conn->execute($stmt);
                $stmt->close();
            }
        } catch (\Throwable $e) {
            error_log('Spend ledger temporary tables unavailable, the report joins derived tables: ' . $e->getMessage());

            return null;
        }

        $k = random_int(1, PHP_INT_MAX);
        try {
            $adNo = self::adNumbers($sets);
            $rows = [];
            foreach ($sets as $set) {
                $rows[] = "($k, " . $set['set_no'] . ', ' . $set['network_id'] . ', ' . $adNo[self::adKey($set['ad_ref'])] . ')';
            }
            self::insertRows($conn, 'INSERT INTO p202_ledger_sets (k, set_no, network_id, ad_no) VALUES ', $rows);

            // The ads by bound value: an ad id is the platform's text.
            $binds = [];
            foreach ($adNo as $ref => $no) {
                $binds[] = [(string) $ref, $no];
            }
            self::insertRows(
                $conn,
                'INSERT INTO p202_ledger_ads (k, ad_ref, ad_no) VALUES ',
                array_fill(0, count($binds), "($k, ?, ?)"),
                $binds,
                'si'
            );
            $ledgerSql = 'SELECT a.ad_no, l.hour_start DIV 3600 AS h,'
                . ' CAST(SUM(l.spend) AS DECIMAL(40,24)) AS spend, COUNT(*) AS ledger_rows FROM 202_spend_ledger l'
                . " JOIN p202_ledger_ads a ON a.k = $k AND a.ad_ref = l.ad_ref"
                . ' WHERE l.user_id = ? AND l.currency = ? AND l.hour_start >= ? AND l.hour_start <= ?'
                . ' GROUP BY a.ad_no, h';
            $clickSql = 'SELECT m.ad_no, d.click_time DIV 3600 AS h, COUNT(*) AS clicks FROM 202_dataengine d'
                . " JOIN p202_ledger_sets m ON m.k = $k"
                . ' AND m.set_no = CAST(d.variable_set_id AS UNSIGNED) AND m.network_id = d.ppc_network_id'
                . ' WHERE d.user_id = ? AND d.click_time >= ? AND d.click_time <= ?'
                . ' GROUP BY m.ad_no, h';

            // Slices of whole UTC hours; each reads the ledger rows and the
            // clicks of its hours that the whole read would have.
            $span = max(1, intdiv(self::$slicePairs, count($adNo)));
            for ($first = intdiv($lo, 3600); $first <= intdiv($hi, 3600); $first += $span) {
                $last = $first + $span - 1;
                $stmt = $conn->prepareRead($ledgerSql);
                $conn->bind($stmt, 'isii', [$userId, $currency, max($lo, $first * 3600), min($hi, $last * 3600 + 3599)]);
                $spent = [];
                foreach ($conn->fetchAll($stmt) as $l) {
                    $spent[$l['ad_no'] . ':' . $l['h']] = $l;
                }
                if ($spent === []) {
                    continue;
                }
                $stmt = $conn->prepareRead($clickSql);
                $conn->bind($stmt, 'iii', [$userId, max($lo, $first * 3600), min($hi + 3599, $last * 3600 + 3599)]);
                $rows = [];
                foreach ($conn->fetchAll($stmt) as $c) {
                    $l = $spent[$c['ad_no'] . ':' . $c['h']] ?? null;
                    if ($l === null || (int) $c['clicks'] <= 0) {
                        continue;
                    }
                    $spend = (string) $l['spend'];
                    if (preg_match('/^-?\d+(\.\d+)?$/D', $spend) !== 1) {
                        throw new \RuntimeException('The spend ledger summed an hour to a value that is not a number');
                    }
                    $rows[] = "($k, " . (int) $c['ad_no'] . ', ' . (int) $c['h'] . ', ' . $spend . ', '
                        . (int) $c['clicks'] . ', ' . (int) $l['ledger_rows'] . ')';
                }
                self::insertRows($conn, 'INSERT INTO p202_ledger_hours (k, ad_no, h, spend, clicks, ledger_rows) VALUES ', $rows);
            }
        } catch (\Throwable $e) {
            self::unstage($conn, $k);
            throw $e;
        }

        return $k;
    }

    /**
     * Each ad of $sets numbered from 1 in the order first seen, by adKey().
     *
     * @param list<array{set_no: int, network_id: int, ad_ref: string}> $sets
     * @return array<string, int>
     */
    private static function adNumbers(array $sets): array
    {
        $adNo = [];
        foreach ($sets as $set) {
            $adNo[self::adKey($set['ad_ref'])] ??= count($adNo) + 1;
        }

        return $adNo;
    }

    /**
     * An ad id as the ledger's utf8mb4_bin column compares it: PAD SPACE, so
     * trailing spaces do not count. A click may carry `ad_A ` where another
     * carries `ad_A`; both are the ledger's one ad (a ledger ad_ref never ends
     * in a space, SpendRow refuses it), and keyed apart they would collide in
     * the staged ads table's key or split one hour's spend.
     */
    private static function adKey(string $ref): string
    {
        return rtrim($ref, ' ');
    }

    /**
     * The SOURCE_PLATFORMS marks the account's traffic sources carry, removed
     * ones included (their clicks still carry the mark), as mark => true.
     *
     * @return array<string, true>
     */
    private static function marks(Connection $conn, int $userId): array
    {
        $marks = array_values(self::SOURCE_PLATFORMS);
        $stmt = $conn->prepareRead('SELECT DISTINCT ppc_network_platform FROM 202_ppc_networks WHERE user_id = ?'
            . ' AND ppc_network_platform IN (' . implode(', ', array_fill(0, count($marks), '?')) . ')');
        $conn->bind($stmt, 'i' . str_repeat('s', count($marks)), [$userId, ...$marks]);
        $out = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $out[(string) $row['ppc_network_platform']] = true;
        }

        return $out;
    }

    /**
     * Rows after $insert (`INSERT INTO <one of stage()'s tables> (...) VALUES `),
     * a thousand a statement.
     *
     * @param list<string> $rows each a VALUES tuple of numbers, or of numbers and placeholders
     * @param list<list<int|string>> $binds each row's values for its placeholders, typed $types
     */
    private static function insertRows(Connection $conn, string $insert, array $rows, array $binds = [], string $types = ''): void
    {
        foreach (array_chunk($rows, 1000, true) as $chunk) {
            $stmt = $conn->prepareRead($insert . implode(', ', $chunk));
            if ($binds !== []) {
                $values = [];
                foreach (array_keys($chunk) as $i) {
                    array_push($values, ...$binds[$i]);
                }
                $conn->bind($stmt, str_repeat($types, count($chunk)), $values);
            }
            $conn->execute($stmt);
            $stmt->close();
        }
    }

    /** Removes report $k's rows from stage()'s tables. */
    private static function unstage(Connection $conn, int $k): void
    {
        foreach (
            [
                'DELETE FROM p202_ledger_sets WHERE k = ?',
                'DELETE FROM p202_ledger_hours WHERE k = ?',
                'DELETE FROM p202_ledger_ads WHERE k = ?',
            ] as $delete
        ) {
            try {
                $stmt = $conn->prepareRead($delete);
                $conn->bind($stmt, 'i', [$k]);
                $conn->execute($stmt);
                $stmt->close();
            } catch (\Throwable $e) {
                // The connection's end drops the table.
                error_log('Clearing a spend ledger temporary table failed: ' . $e->getMessage());
            }
        }
    }

    /** A report's staged rows go with the report that joined them. */
    public function __destruct()
    {
        if ($this->staging !== null && $this->staged !== 0) {
            self::unstage($this->staging, $this->staged);
        }
    }

    /** The cost of one row of 202_dataengine (the report's alias for it), for SUM(). */
    public function costSql(): string
    {
        return $this->join === '' ? $this->row . '.cost'
            : 'COALESCE(' . self::ALIAS . '.spend / ' . self::ALIAS . '.clicks, ' . $this->row . '.cost)';
    }

    /**
     * What a report says beside figures that use the ledger: how far the
     * spend data reaches (the platform that reaches least; later clicks are
     * costed at their CPC) and the spend that no click carries.
     * unallocated.total_cost is masked with the report's other money
     * (CampaignFigures::REPORT).
     *
     * @return array<string, mixed>
     */
    public function meta(string $timezone): array
    {
        $local = (new \DateTimeImmutable('@' . $this->reaches))->setTimezone(new \DateTimeZone($timezone));
        $meta = [
            'reaches' => $this->reaches,
            'reaches_at' => gmdate('Y-m-d\TH:i:s\Z', $this->reaches),
            'reaches_by_platform' => $this->reachesByPlatform,
            'note' => 'Spend data reaches ' . $local->format('Y-m-d H:i') . " ($timezone)"
                . (count($this->reachesByPlatform) > 1 ? ' for every platform' : '')
                . '. Clicks after that are costed at their CPC.',
            'unallocated' => [
                'total_cost' => (float) $this->unallocated,
                'note' => 'Spend in this window that no click here carries the ad of, for the whole account; in no row or total.'
                    . ($this->tied
                        ? ' ChatGPT Ads spend goes only to clicks of your ChatGPT Ads traffic source (the one Add ChatGPT Ads'
                            . ' set up); another source\'s click with the same ad id keeps its CPC.'
                        : ''),
            ],
        ];
        if ($this->otherCurrencyRows > 0) {
            $meta['other_currency_rows'] = $this->otherCurrencyRows;
            $meta['other_currency_note'] = 'Ledger rows in a currency other than the account\'s are not counted.';
        }

        return $meta;
    }

    /**
     * The whole UTC hours a window touches: the first hour's start and the
     * last hour's start, both inclusive (0 and the largest stored second
     * when unbounded).
     *
     * @return array{0: int, 1: int}
     */
    public static function hours(?int $from, ?int $to): array
    {
        $lo = $from === null ? 0 : max(0, $from - $from % 3600);
        $hi = $to === null ? 4294963200 : max(0, $to - $to % 3600);

        return [$lo, $hi];
    }

    /**
     * What a read of the ledger's hours [$lo, $hi] (in $currency, or every
     * currency when null) joins clicks with, or null when no variable set of
     * the account carries one of its ads:
     *
     *  - `sets`: set_no, network_id, ad_no: the variable sets carrying a
     *    ledger ad, as SQL of whole numbers (no binds);
     *  - `ads`: ad_ref, ad_no, with its binds;
     *  - `stats`: ad_no, h (the UTC hour's number), spend (the ledger's, at
     *    24 decimals), clicks (the account's clicks carrying the ad in that
     *    hour) and ledger_rows, one row per ledger ad and hour, with its
     *    binds;
     *  - `clicks`: ad_no, h, clicks, with its binds.
     *
     * $sets, when given, is sets()'s answer for the same arguments.
     *
     * @param list<array{set_no: int, network_id: int, ad_ref: string}>|null $sets
     * @return array{sets: string, ads: array{0: string, 1: list<int|string>, 2: string},
     *     stats: array{0: string, 1: list<int|string>, 2: string},
     *     clicks: array{0: string, 1: list<int|string>, 2: string}}|null
     */
    public static function plan(Connection $conn, int $userId, ?string $currency, int $lo, int $hi, ?array $sets = null): ?array
    {
        $sets ??= self::sets($conn, $userId, $currency, $lo, $hi);
        if ($sets === []) {
            return null;
        }
        $adNo = self::adNumbers($sets);
        $setRows = [];
        foreach ($sets as $set) {
            $setRows[] = 'SELECT ' . $set['set_no'] . ' AS set_no, ' . $set['network_id'] . ' AS network_id, '
                . $adNo[self::adKey($set['ad_ref'])] . ' AS ad_no';
        }
        $setsSql = implode(' UNION ALL ', $setRows);
        $adRows = [];
        $adBinds = [];
        foreach ($adNo as $ref => $no) {
            $adRows[] = 'SELECT ? AS ad_ref, ' . $no . ' AS ad_no';
            $adBinds[] = (string) $ref;
        }
        $adsSql = implode(' UNION ALL ', $adRows);
        $adTypes = str_repeat('s', count($adBinds));

        $clickRows = 'SELECT m.ad_no, d.click_time DIV 3600 AS h FROM 202_dataengine d'
            . " JOIN ($setsSql) m ON m.set_no = CAST(d.variable_set_id AS UNSIGNED) AND m.network_id = d.ppc_network_id"
            . ' WHERE d.user_id = ? AND d.click_time >= ? AND d.click_time <= ?';
        $clickBinds = [$userId, $lo, $hi + 3599];
        $clicks = "SELECT c.ad_no, c.h, COUNT(*) AS clicks FROM ($clickRows) c GROUP BY c.ad_no, c.h";

        // The ad's number by a CASE: a join with a derived table of the ads
        // was read row by row (0.5 s over 36,000 rows).
        $adCase = 'CASE l.ad_ref' . str_repeat(' WHEN ? THEN ?', count($adBinds)) . ' END';
        $caseBinds = [];
        foreach ($adNo as $ref => $no) {
            $caseBinds[] = (string) $ref;
            $caseBinds[] = $no;
        }
        $ledgerRows = "SELECT $adCase AS ad_no, l.hour_start DIV 3600 AS h, l.spend FROM 202_spend_ledger l"
            . ' WHERE l.user_id = ?' . ($currency === null ? '' : ' AND l.currency = ?') . ' AND l.hour_start >= ? AND l.hour_start <= ?'
            . ' AND l.ad_ref IN (' . implode(', ', array_fill(0, count($adBinds), '?')) . ')';
        $ledgerBinds = [...$caseBinds, $userId, ...($currency === null ? [] : [$currency]), $lo, $hi, ...$adBinds];
        $ledgerTypes = str_repeat('si', count($adBinds)) . 'i' . ($currency === null ? '' : 's') . 'ii' . $adTypes;
        $stats = 'SELECT g.ad_no, g.h, CAST(g.spend AS DECIMAL(40,24)) AS spend, COALESCE(k.clicks, 0) AS clicks, g.ledger_rows'
            . ' FROM (SELECT z.ad_no, z.h, SUM(z.spend) AS spend, COUNT(*) AS ledger_rows'
            . " FROM ($ledgerRows) z GROUP BY z.ad_no, z.h) g"
            . " LEFT JOIN ($clicks) k ON k.ad_no = g.ad_no AND k.h = g.h";

        return [
            'sets' => $setsSql,
            'ads' => [$adsSql, $adBinds, $adTypes],
            'stats' => [$stats, [...$ledgerBinds, ...$clickBinds], $ledgerTypes . 'iii'],
            'clicks' => [$clicks, $clickBinds, 'iii'],
        ];
    }

    /**
     * The account's variable sets that carry one ad of the ledger's hours
     * [$lo, $hi] (in $currency, or any when null).
     *
     * The ad id is the set's value of the variable whose parameter is
     * `ad_id`, compared as written (not `AD_ID`), joined as the variables
     * report joins it (the variable or the one a split copied it from)
     * within the account's own traffic sources (CLAUDE.md #27). A set that
     * carries two different values for it is ambiguous and matches nothing.
     * $adRefs, when given, names the ads instead of the ledger's ($currency,
     * $lo and $hi are then not read), all of $platform's.
     *
     * An ad of a platform in SOURCE_PLATFORMS is carried only by the sets of
     * a traffic source with that platform's mark, when the account has one
     * (marks()); the ledger holds one platform per ad id
     * (SpendLedger::ownerErrors()).
     *
     * @param list<string>|null $adRefs
     * @return list<array{set_no: int, network_id: int, ad_ref: string}>
     */
    public static function sets(
        Connection $conn,
        int $userId,
        ?string $currency,
        int $lo,
        int $hi,
        ?array $adRefs = null,
        ?string $platform = null
    ): array {
        $param = "pv.parameter COLLATE utf8mb4_bin = '" . self::AD_PARAMETER . "'";
        if ($adRefs !== null) {
            // The ads named, not the ledger's: another record keyed by ad
            // (the platform's reported conversions).
            $adRefs = array_values(array_unique(array_map('strval', $adRefs)));
            if ($adRefs === []) {
                return [];
            }
            $ads = implode(' UNION ALL ', array_fill(0, count($adRefs), 'SELECT ? AS ad_ref'));
        } else {
            $ads = 'SELECT DISTINCT la.ad_ref FROM 202_spend_ledger la'
                . ' WHERE la.user_id = ?' . ($currency === null ? '' : ' AND la.currency = ?') . ' AND la.hour_start >= ? AND la.hour_start <= ?';
        }
        $candidates = 'SELECT DISTINCT vc.variable_set_id FROM (' . $ads . ') ads'
            . ' JOIN 202_custom_variables cc ON cc.variable = ads.ad_ref COLLATE utf8mb4_general_ci'
            // The column's own collation: CAST AS CHAR takes the connection's
            // (utf8mb4_0900_ai_ci on MySQL 8.0), and = between the two is refused.
            . ' JOIN 202_variable_sets2 vc ON vc.variables = CONVERT(CAST(cc.custom_variable_id AS CHAR) USING utf8mb4) COLLATE utf8mb4_general_ci';
        $sql = 'SELECT vs.variable_set_id AS set_no, pv.ppc_network_id AS network_id,'
            . ' MIN(CONVERT(cv.variable USING utf8mb4) COLLATE utf8mb4_bin) AS ad_ref,'
            . ' MIN(pn.ppc_network_platform) AS source_platform'
            . ' FROM 202_variable_sets2 vs'
            . ' JOIN 202_custom_variables cv ON cv.custom_variable_id = CAST(vs.variables AS UNSIGNED)'
            . ' JOIN 202_ppc_network_variables pv ON (cv.ppc_variable_id = pv.ppc_variable_id'
            . ' OR cv.ppc_variable_id = pv.copied_from_ppc_variable_id) AND ' . $param
            . ' JOIN 202_ppc_networks pn ON pn.ppc_network_id = pv.ppc_network_id AND pn.user_id = ?'
            . ' WHERE vs.variable_set_id IN (' . $candidates . ") AND cv.variable <> ''"
            . ' GROUP BY vs.variable_set_id, pv.ppc_network_id'
            . ' HAVING COUNT(DISTINCT CONVERT(cv.variable USING utf8mb4) COLLATE utf8mb4_bin) = 1';
        $stmt = $conn->prepareRead($sql);
        if ($adRefs !== null) {
            $conn->bind($stmt, 'i' . str_repeat('s', count($adRefs)), [$userId, ...$adRefs]);
        } elseif ($currency === null) {
            $conn->bind($stmt, 'iiii', [$userId, $userId, $lo, $hi]);
        } else {
            $conn->bind($stmt, 'iisii', [$userId, $userId, $currency, $lo, $hi]);
        }
        $rows = $conn->fetchAll($stmt);
        if ($rows === []) {
            return [];
        }
        // Which platform each ad is: the one named, or the ledger's own.
        $platformOf = [];
        if ($adRefs !== null) {
            foreach ($adRefs as $ref) {
                $platformOf[self::adKey($ref)] = $platform;
            }
        } else {
            $stmt = $conn->prepareRead('SELECT DISTINCT ad_ref, platform FROM 202_spend_ledger WHERE user_id = ?'
                . ($currency === null ? '' : ' AND currency = ?') . ' AND hour_start >= ? AND hour_start <= ?');
            if ($currency === null) {
                $conn->bind($stmt, 'iii', [$userId, $lo, $hi]);
            } else {
                $conn->bind($stmt, 'isii', [$userId, $currency, $lo, $hi]);
            }
            foreach ($conn->fetchAll($stmt) as $held) {
                $platformOf[self::adKey((string) $held['ad_ref'])] = (string) $held['platform'];
            }
        }
        $marks = self::marks($conn, $userId);
        $out = [];
        foreach ($rows as $row) {
            $mark = self::SOURCE_PLATFORMS[$platformOf[self::adKey((string) $row['ad_ref'])] ?? ''] ?? null;
            if ($mark !== null && isset($marks[$mark]) && (string) ($row['source_platform'] ?? '') !== $mark) {
                continue;
            }
            $out[] = ['set_no' => (int) $row['set_no'], 'network_id' => (int) $row['network_id'], 'ad_ref' => (string) $row['ad_ref']];
        }

        return $out;
    }
}
