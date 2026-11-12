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
 * it arrived in. An hour's spend for an ad is shared equally by the
 * account's clicks that carry that ad in that hour; the share replaces each
 * click's CPC. Spend in an hour no click carries the ad in is unallocated:
 * reported beside the figures, never spread onto other hours or clicks.
 *
 * forReport() answers null when the account's ledger has nothing in the
 * window: the report then runs exactly as it did before the ledger
 * existed, so an install that never writes a ledger row reads the same
 * figures to the byte (LedgerCostIntegrationTest).
 */
final class LedgerCost
{
    /** The traffic source variable parameter that names the click's ad. */
    public const string AD_PARAMETER = 'ad_id';

    /** The derived table's alias in a report, outside ReportsController's join aliases. */
    public const string ALIAS = 'sl';

    /**
     * @param list<int|string> $binds the join's, which come before the report's own
     */
    private function __construct(
        public readonly string $join,
        public readonly array $binds,
        public readonly string $types,
        public readonly int $reaches,
        public readonly string $unallocated,
        public readonly int $otherCurrencyRows,
    ) {
    }

    /**
     * The ledger's part in a report over [$from, $to] (inclusive, null for
     * no bound) of $userId's clicks, or null when the ledger holds no row
     * of the account in that window.
     */
    public static function forReport(Connection $conn, int $userId, ?int $from, ?int $to, string $currency): ?self
    {
        [$lo, $hi] = self::hours($from, $to);
        $stmt = $conn->prepareRead(
            'SELECT COUNT(*) AS n, COALESCE(SUM(currency <> ?), 0) AS other FROM 202_spend_ledger'
            . ' WHERE user_id = ? AND hour_start >= ? AND hour_start <= ?'
        );
        $conn->bind($stmt, 'siii', [$currency, $userId, $lo, $hi]);
        $held = $conn->fetchOne($stmt);
        if ($held === null) {
            throw new \RuntimeException('Counting the spend ledger rows of a report answered no row');
        }
        if ((int) $held['n'] === 0) {
            return null;
        }
        $reaches = (new SpendLedger($conn))->reaches($userId);
        if ($reaches === null) {
            throw new \RuntimeException('The spend ledger held rows for a report and then none');
        }

        // The ledger's spend per ad and hour in the window, in the account's
        // currency, and the account's clicks carrying an ad in those hours
        // (whole hours, so a window starting mid-hour shares the hour's
        // spend among all of its clicks, not only the ones in the window).
        $spend = 'SELECT l.ad_ref, l.hour_start, SUM(l.spend) AS spend FROM 202_spend_ledger l'
            . ' WHERE l.user_id = ? AND l.currency = ? AND l.hour_start >= ? AND l.hour_start <= ?'
            . ' GROUP BY l.ad_ref, l.hour_start';
        $spendBinds = [$userId, $currency, $lo, $hi];
        $clickBinds = [$userId, $lo, $hi + 3599];
        $alias = self::ALIAS;
        $join = "LEFT JOIN (SELECT a.click_id, a.click_time,"
            . ' s.spend / COUNT(*) OVER (PARTITION BY a.ad_ref, a.hour_start) AS cost'
            . ' FROM (' . self::clicksSql() . ') a'
            . " JOIN ($spend) s ON s.ad_ref = a.ad_ref AND s.hour_start = a.hour_start"
            . ") $alias ON $alias.click_id = de.click_id AND $alias.click_time = de.click_time";

        $unallocatedSql = "SELECT COALESCE(SUM(s.spend), 0) AS spend FROM ($spend) s"
            . ' LEFT JOIN (SELECT DISTINCT c.ad_ref, c.hour_start FROM (' . self::clicksSql() . ') c) m'
            . ' ON m.ad_ref = s.ad_ref AND m.hour_start = s.hour_start'
            . ' WHERE m.ad_ref IS NULL';
        $stmt = $conn->prepareRead($unallocatedSql);
        $conn->bind($stmt, 'isiiiii', [...$spendBinds, ...$clickBinds]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException('Summing the unallocated spend of a report answered no row');
        }

        // The clicks' derived table comes before the ledger's in the join.
        return new self($join, [...$clickBinds, ...$spendBinds], 'iiiisii', $reaches, (string) $row['spend'], (int) $held['other']);
    }

    /** The cost of one row of 202_dataengine `de`, for SUM(). */
    public function costSql(): string
    {
        return 'COALESCE(' . self::ALIAS . '.cost, de.cost)';
    }

    /**
     * What a report says beside figures that use the ledger: how far the
     * spend data reaches (later clicks are costed at their CPC) and the
     * spend that no click carries. unallocated.total_cost is masked with
     * the report's other money (CampaignFigures::REPORT).
     *
     * @return array<string, mixed>
     */
    public function meta(string $timezone): array
    {
        $local = (new \DateTimeImmutable('@' . $this->reaches))->setTimezone(new \DateTimeZone($timezone));
        $meta = [
            'reaches' => $this->reaches,
            'reaches_at' => gmdate('Y-m-d\TH:i:s\Z', $this->reaches),
            'note' => 'Spend data reaches ' . $local->format('Y-m-d H:i') . " ($timezone). Clicks after that are costed at their CPC.",
            'unallocated' => [
                'total_cost' => (float) $this->unallocated,
                'note' => 'Spend in this window that no click here carries the ad of, for the whole account; in no row or total.',
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
     * The account's clicks in a range that carry an ad id, one row each:
     * click_id, click_time, ad_ref, hour_start. Three binds: the account,
     * the first second and the last second (inclusive).
     *
     * The ad id is the click's value of its traffic source's `ad_id`
     * variable, joined as the variables report joins it (the variable or
     * the one a split copied it from) within the click's own traffic source
     * (CLAUDE.md #27). A click that carries two different values for it is
     * ambiguous and matches nothing.
     */
    public static function clicksSql(): string
    {
        return 'SELECT d.click_id, d.click_time, d.click_time - MOD(d.click_time, 3600) AS hour_start,'
            . ' MIN(CONVERT(cv.variable USING utf8mb4) COLLATE utf8mb4_bin) AS ad_ref'
            . ' FROM 202_dataengine d'
            . ' JOIN 202_ppc_networks pn ON pn.ppc_network_id = d.ppc_network_id AND pn.user_id = d.user_id'
            . " JOIN 202_ppc_network_variables pv ON pv.ppc_network_id = pn.ppc_network_id AND pv.parameter = '" . self::AD_PARAMETER . "'"
            . ' JOIN 202_variable_sets2 vs ON vs.variable_set_id = d.variable_set_id'
            . ' JOIN 202_custom_variables cv ON cv.custom_variable_id = vs.variables'
            . ' AND (cv.ppc_variable_id = pv.ppc_variable_id OR cv.ppc_variable_id = pv.copied_from_ppc_variable_id)'
            . ' WHERE d.user_id = ? AND d.click_time >= ? AND d.click_time <= ?'
            . " AND cv.variable <> ''"
            . ' GROUP BY d.click_id, d.click_time'
            . ' HAVING COUNT(DISTINCT CONVERT(cv.variable USING utf8mb4) COLLATE utf8mb4_bin) = 1';
    }
}
