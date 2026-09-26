<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;

/**
 * The multi-touch reads (plan §6.3 "Reports"): grouped credits joined to
 * the clicks' own dimensions, the model comparison, the journey metrics and
 * one conversion's journey explained.
 *
 * Three independent aggregates meet in a breakdown row, each over its own
 * rows and its own clock, because they answer different questions:
 *
 * - attributed conversions and revenue: Σ credit and Σ revenue of the
 *   touches in the dimension, for conversions whose conv_time is in range;
 * - clicks and cost: the dimension's own clicks whose click_time is in
 *   range, so ROI per source is its revenue against its own spend (the old
 *   engine charged every conversion the converting click's cost). Bots are
 *   left out and repeat-IP ("filtered") clicks are counted, the same clicks
 *   a journey can contain: they were paid for, and they are touches;
 * - assisted conversions: conversions in range whose journey has a touch in
 *   the dimension that is not the converting click. Journey-wide, so the
 *   same under every model.
 *
 * Every read is bounded by the account (a model id belongs to one account,
 * and each query names the account besides), and every money value is the
 * exact DECIMAL sum MySQL computes, returned as a string.
 *
 * A breakdown reads the report rollup (AttributionRollup) for the whole
 * hours of its range that are summed and clean, and computes the rest — the
 * part-hours at its edges, dirty hours, hours not summed yet — exactly, in
 * the same statement; the answer is the full computation's, byte for byte
 * (tests/Attribution/RollupMatchesFullComputationTest). When the rollup
 * cannot serve any of it, the full computation below runs as it always did.
 * The journey metrics' counts are read the same way, from the rollup's
 * journey part.
 */
final class AttributionReports
{
    /** @var array<string, array{key: string, name: string, joins: list<string>}> */
    private const DIMENSIONS = [
        'campaign' => [
            'key' => 'c.aff_campaign_id',
            'name' => 'dn.aff_campaign_name',
            'joins' => ['LEFT JOIN 202_aff_campaigns dn ON dn.aff_campaign_id = c.aff_campaign_id'],
        ],
        'traffic_source' => [
            'key' => 'c.ppc_account_id',
            'name' => 'dn.ppc_account_name',
            'joins' => ['LEFT JOIN 202_ppc_accounts dn ON dn.ppc_account_id = c.ppc_account_id'],
        ],
        'landing_page' => [
            'key' => 'c.landing_page_id',
            'name' => 'dn.landing_page_nickname',
            'joins' => ['LEFT JOIN 202_landing_pages dn ON dn.landing_page_id = c.landing_page_id'],
        ],
        'keyword' => [
            'key' => 'ca.keyword_id',
            'name' => 'dn.keyword',
            'joins' => [
                'LEFT JOIN 202_clicks_advance ca ON ca.click_id = c.click_id',
                'LEFT JOIN 202_keywords dn ON dn.keyword_id = ca.keyword_id',
            ],
        ],
        'c1' => ['key' => 'ct.c1_id', 'name' => 'dn.c1', 'joins' => [
            'LEFT JOIN 202_clicks_tracking ct ON ct.click_id = c.click_id',
            'LEFT JOIN 202_tracking_c1 dn ON dn.c1_id = ct.c1_id',
        ]],
        'c2' => ['key' => 'ct.c2_id', 'name' => 'dn.c2', 'joins' => [
            'LEFT JOIN 202_clicks_tracking ct ON ct.click_id = c.click_id',
            'LEFT JOIN 202_tracking_c2 dn ON dn.c2_id = ct.c2_id',
        ]],
        'c3' => ['key' => 'ct.c3_id', 'name' => 'dn.c3', 'joins' => [
            'LEFT JOIN 202_clicks_tracking ct ON ct.click_id = c.click_id',
            'LEFT JOIN 202_tracking_c3 dn ON dn.c3_id = ct.c3_id',
        ]],
        'c4' => ['key' => 'ct.c4_id', 'name' => 'dn.c4', 'joins' => [
            'LEFT JOIN 202_clicks_tracking ct ON ct.click_id = c.click_id',
            'LEFT JOIN 202_tracking_c4 dn ON dn.c4_id = ct.c4_id',
        ]],
        'country' => [
            'key' => 'ca.country_id',
            'name' => 'dn.country_name',
            'joins' => [
                'LEFT JOIN 202_clicks_advance ca ON ca.click_id = c.click_id',
                'LEFT JOIN 202_locations_country dn ON dn.country_id = ca.country_id',
            ],
        ],
        'device' => [
            'key' => 'dm.device_type',
            'name' => 'dn.type_name',
            'joins' => [
                'LEFT JOIN 202_clicks_advance ca ON ca.click_id = c.click_id',
                'LEFT JOIN 202_device_models dm ON dm.device_id = ca.device_id',
                'LEFT JOIN 202_device_types dn ON dn.type_id = dm.device_type',
            ],
        ],
        // Day: the conversion's day for credits and assists, the click's day
        // for clicks and cost (the {time} placeholder), in the database
        // session's time zone like /reports/timeseries.
        'day' => ['key' => "FROM_UNIXTIME({time}, '%Y-%m-%d')", 'name' => "FROM_UNIXTIME({time}, '%Y-%m-%d')", 'joins' => []],
    ];

    public const MAX_LIMIT = 1000;

    /** A plan whose guard fails (the rollup moved under it) is made again, this often. */
    private const ROLLUP_ATTEMPTS = 3;
    /** More separate runs of servable hours than this and the report is computed in full. */
    private const MAX_RUNS = 2000;

    /** How many hours the last breakdown or journey metrics read from the rollup (0: computed in full). */
    private int $servedHours = 0;

    /**
     * @param bool $useRollup false computes every breakdown in full: the
     *                        reference the rollup is tested against
     */
    public function __construct(private Connection $conn, private bool $useRollup = true)
    {
    }

    /** How many whole hours the last breakdown or journey metrics read from the rollup; 0 when it was computed in full. */
    public function lastServedHours(): int
    {
        return $this->servedHours;
    }

    /** @return list<string> */
    public static function dimensions(): array
    {
        return array_keys(self::DIMENSIONS);
    }

    /**
     * One row per dimension value.
     *
     * @param int|null $modelId  null: each conversion under its campaign's
     *                           model override when that model is active,
     *                           else the account default ("effective")
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, groups: int}
     */
    public function breakdown(int $userId, ?int $modelId, ?int $compareModelId, int $defaultModelId, string $groupBy, int $from, int $to, int $limit): array
    {
        $all = $this->breakdownAll($userId, $modelId, $compareModelId, $defaultModelId, $groupBy, $from, $to);

        return [
            'rows' => array_slice($all['rows'], 0, max(1, min(self::MAX_LIMIT, $limit))),
            'totals' => $all['totals'],
            'groups' => count($all['rows']),
        ];
    }

    /**
     * Every row of a breakdown, unsliced, in the report's order (attributed
     * revenue, highest first). The export reads this; a page or an API
     * response reads breakdown(), which says how many groups it left out.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function breakdownAll(int $userId, ?int $modelId, ?int $compareModelId, int $defaultModelId, string $groupBy, int $from, int $to): array
    {
        if (!isset(self::DIMENSIONS[$groupBy])) {
            throw new \InvalidArgumentException('unknown dimension ' . $groupBy);
        }

        $this->servedHours = 0;
        $parts = $this->useRollup ? $this->rolledParts($userId, $modelId, $compareModelId, $defaultModelId, $groupBy, $from, $to) : null;
        if ($parts !== null) {
            ['primary' => $primary, 'compare' => $compare, 'cost' => $cost, 'assists' => $assists,
                'totals' => $totals, 'compareTotals' => $compareTotals] = $parts;
        } else {
            $primary = $this->creditsBy($userId, $modelId, $defaultModelId, $groupBy, $from, $to);
            $compare = $compareModelId !== null ? $this->creditsBy($userId, $compareModelId, $defaultModelId, $groupBy, $from, $to) : null;
            $cost = $this->costBy($userId, $groupBy, $from, $to);
            $assists = $this->assistsBy($userId, $groupBy, $from, $to);
            $totals = $this->totals($userId, $modelId, $defaultModelId, $from, $to);
            $compareTotals = $compareModelId !== null ? $this->totals($userId, $compareModelId, $defaultModelId, $from, $to) : null;
        }

        $keys = array_unique(array_merge(array_keys($primary), array_keys($cost), array_keys($assists), array_keys($compare ?? [])));
        $rows = [];
        foreach ($keys as $k) {
            $k = (string) $k;
            $p = $primary[$k] ?? null;
            $cst = $cost[$k] ?? null;
            $name = $p['name'] ?? $cst['name'] ?? ($assists[$k]['name'] ?? ($compare[$k]['name'] ?? null));
            $costValue = $cst['cost'] ?? '0.00000';
            $row = [
                'key' => $k,
                'name' => $name,
                'clicks' => (int) ($cst['clicks'] ?? 0),
                'cost' => $costValue,
                'attributed_conversions' => $p['conversions'] ?? '0.00000000',
                'attributed_revenue' => $p['revenue'] ?? '0.00000',
                'roi' => self::roi($p['revenue'] ?? '0', $costValue),
                'assisted_conversions' => (int) ($assists[$k]['assists'] ?? 0),
            ];
            if ($compare !== null) {
                $c = $compare[$k] ?? null;
                $row['compare_attributed_conversions'] = $c['conversions'] ?? '0.00000000';
                $row['compare_attributed_revenue'] = $c['revenue'] ?? '0.00000';
                $row['compare_roi'] = self::roi($c['revenue'] ?? '0', $costValue);
            }
            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            $byRevenue = self::compareDecimal((string) $b['attributed_revenue'], (string) $a['attributed_revenue']);
            return $byRevenue !== 0 ? $byRevenue : strcmp((string) $a['key'], (string) $b['key']);
        });

        if ($compareTotals !== null) {
            $totals['compare_attributed_conversions'] = $compareTotals['attributed_conversions'];
            $totals['compare_attributed_revenue'] = $compareTotals['attributed_revenue'];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @return array<string, array{name: string|null, conversions: string, revenue: string}>
     */
    private function creditsBy(int $userId, ?int $modelId, int $defaultModelId, string $groupBy, int $from, int $to): array
    {
        [$key, $name, $joins] = self::dimensionSql($groupBy, 'cr.conv_time');
        [$source, $where, $types, $binds] = $this->creditSource($userId, $modelId, $defaultModelId, $from, $to);

        $stmt = $this->conn->prepareRead(
            "SELECT COALESCE($key, 0) AS k, MAX($name) AS n, SUM(cr.credit) AS conversions, SUM(cr.revenue) AS revenue
             FROM $source
             JOIN 202_clicks c ON c.click_id = cr.click_id
             $joins
             WHERE $where
             GROUP BY k"
        );
        $this->conn->bind($stmt, $types, $binds);

        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $out[(string) $r['k']] = ['name' => $r['n'] !== null ? (string) $r['n'] : null, 'conversions' => (string) $r['conversions'], 'revenue' => (string) $r['revenue']];
        }

        return $out;
    }

    /**
     * The credit rows a report reads: one model's, or each conversion's
     * effective model's.
     *
     * @return array{0: string, 1: string, 2: string, 3: list<int>}
     */
    private function creditSource(int $userId, ?int $modelId, int $defaultModelId, int $from, int $to): array
    {
        if ($modelId !== null) {
            // Ownership is part of the query, not only the caller's check: a
            // model id of another account matches no credit row here.
            return [
                '202_attribution_credits cr',
                'cr.model_id = (SELECT om.model_id FROM 202_attribution_models om WHERE om.model_id = ? AND om.user_id = ?)
                 AND cr.conv_time >= ? AND cr.conv_time <= ?',
                'iiii',
                [$modelId, $userId, $from, $to],
            ];
        }

        return [
            "202_attribution_credits cr
             JOIN 202_conversion_logs cl ON cl.conv_id = cr.conv_id
             LEFT JOIN 202_aff_campaigns oc ON oc.aff_campaign_id = cl.campaign_id
             LEFT JOIN 202_attribution_models om
                ON om.model_id = oc.attribution_model_id AND om.user_id = cl.user_id AND om.status = 'active'",
            'cl.user_id = ? AND cr.conv_time >= ? AND cr.conv_time <= ? AND cr.model_id = COALESCE(om.model_id, ?)',
            'iiii',
            [$userId, $from, $to, $defaultModelId],
        ];
    }

    /** @return array<string, array{name: string|null, clicks: int, cost: string}> */
    private function costBy(int $userId, string $groupBy, int $from, int $to): array
    {
        [$key, $name, $joins] = self::dimensionSql($groupBy, 'c.click_time');
        $stmt = $this->conn->prepareRead(
            "SELECT COALESCE($key, 0) AS k, MAX($name) AS n, COUNT(*) AS clicks, SUM(c.click_cpc) AS cost
             FROM 202_clicks c
             $joins
             WHERE c.user_id = ? AND c.click_time >= ? AND c.click_time <= ? AND c.click_bot = 0
             GROUP BY k"
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);

        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $out[(string) $r['k']] = ['name' => $r['n'] !== null ? (string) $r['n'] : null, 'clicks' => (int) $r['clicks'], 'cost' => (string) $r['cost']];
        }

        return $out;
    }

    /** @return array<string, array{name: string|null, assists: int}> */
    private function assistsBy(int $userId, string $groupBy, int $from, int $to): array
    {
        [$key, $name, $joins] = self::dimensionSql($groupBy, 'jm.conv_time');
        $stmt = $this->conn->prepareRead(
            "SELECT COALESCE($key, 0) AS k, MAX($name) AS n, COUNT(DISTINCT j.conv_id) AS assists
             FROM 202_attribution_journey_meta jm
             JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position + 1 < jm.touches
             JOIN 202_clicks c ON c.click_id = j.click_id
             $joins
             WHERE jm.user_id = ? AND jm.conv_time >= ? AND jm.conv_time <= ?
             GROUP BY k"
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);

        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $out[(string) $r['k']] = ['name' => $r['n'] !== null ? (string) $r['n'] : null, 'assists' => (int) $r['assists']];
        }

        return $out;
    }

    /** @return array{conversions: int, attributed_conversions: string, attributed_revenue: string} */
    private function totals(int $userId, ?int $modelId, int $defaultModelId, int $from, int $to): array
    {
        [$source, $where, $types, $binds] = $this->creditSource($userId, $modelId, $defaultModelId, $from, $to);
        $stmt = $this->conn->prepareRead(
            "SELECT COUNT(DISTINCT cr.conv_id) AS conversions, COALESCE(SUM(cr.credit), 0) AS credit, COALESCE(SUM(cr.revenue), 0) AS revenue
             FROM $source WHERE $where"
        );
        $this->conn->bind($stmt, $types, $binds);
        $r = $this->conn->fetchOne($stmt) ?? [];

        return [
            'conversions' => (int) ($r['conversions'] ?? 0),
            'attributed_conversions' => (string) ($r['credit'] ?? '0'),
            'attributed_revenue' => (string) ($r['revenue'] ?? '0'),
        ];
    }

    /**
     * Journey metrics over conversions in range: length distribution, time
     * to convert, and the one-touch share by the converting click's browser
     * (plan §6.2: how much journey the browser's storage limits cost).
     *
     * The counts are read through the rollup like a breakdown (its journey
     * part, AttributionRollup::PART_JOURNEYS) and computed in full when it
     * can serve none of the range; the recent conversions are always read
     * from the rows (the newest 25 by an index).
     *
     * @return array<string, mixed>
     */
    public function journeyMetrics(int $userId, int $from, int $to): array
    {
        $this->servedHours = 0;
        $counts = $this->useRollup ? $this->rolledJourneyCounts($userId, $from, $to) : null;
        ['summary' => $s, 'lengths' => $lengths, 'ttc' => $ttc, 'browsers' => $browsers] = $counts ?? $this->journeyCounts($userId, $from, $to);

        $browsers = array_map(static function (array $r): array {
            $n = (int) $r['conversions'];
            $one = (int) $r['one_touch'];
            return ['browser' => (string) $r['browser'], 'conversions' => $n, 'one_touch' => $one, 'one_touch_share' => $n > 0 ? round($one / $n, 4) : 0.0];
        }, $browsers);

        $conversions = (int) ($s['conversions'] ?? 0);

        return [
            'conversions' => $conversions,
            'one_touch' => (int) ($s['one_touch'] ?? 0),
            'one_touch_share' => $conversions > 0 ? round((int) $s['one_touch'] / $conversions, 4) : 0.0,
            'truncated' => (int) ($s['truncated'] ?? 0),
            'unidentified' => (int) ($s['unidentified'] ?? 0),
            'average_touches' => round((float) ($s['avg_touches'] ?? 0), 2),
            'length_distribution' => $lengths,
            'time_to_convert' => $ttc,
            'one_touch_by_browser' => $browsers,
            'recent_conversions' => $this->recentJourneys($userId, $from, $to, self::RECENT_JOURNEYS),
        ];
    }

    /**
     * Time to convert, measured from the journey's first touch: the upper
     * bound (exclusive, in seconds) of each bucket but the last, in order.
     * The difference is signed — a conversion can be dated before its own
     * click, and an unsigned one raised "BIGINT UNSIGNED value is out of
     * range" for the whole report — so such a conversion is under an hour.
     */
    private const TIME_TO_CONVERT = ['under_1h' => 3600, '1h_to_1d' => 86400, '1d_to_7d' => 604800, '7d_to_30d' => 2592000, 'over_30d' => null];

    /**
     * The time-to-convert bucket of a journey as SQL: its name, or (for the
     * rollup) its position in TIME_TO_CONVERT.
     */
    public static function timeToConvertSql(string $convTime, string $firstClickTime, bool $asIndex): string
    {
        $diff = "CAST($convTime AS SIGNED) - CAST($firstClickTime AS SIGNED)";
        $sql = 'CASE';
        $i = 0;
        foreach (self::TIME_TO_CONVERT as $name => $below) {
            $value = $asIndex ? (string) $i : "'$name'";
            $sql .= $below !== null ? " WHEN $diff < $below THEN $value" : " ELSE $value END";
            $i++;
        }

        return $sql;
    }

    /**
     * The journey counts computed in full from the rows.
     *
     * @return array{summary: array<string, mixed>, lengths: list<array{touches: int, conversions: int}>, ttc: array<string, int>, browsers: list<array<string, mixed>>}
     */
    private function journeyCounts(int $userId, int $from, int $to): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT COUNT(*) AS conversions, COALESCE(SUM(touches = 1), 0) AS one_touch,
                    COALESCE(SUM(truncated), 0) AS truncated, COALESCE(SUM(identified = 0), 0) AS unidentified,
                    COALESCE(AVG(touches), 0) AS avg_touches
             FROM 202_attribution_journey_meta WHERE user_id = ? AND conv_time >= ? AND conv_time <= ?'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
        $s = $this->conn->fetchOne($stmt) ?? [];

        $stmt = $this->conn->prepareRead(
            'SELECT touches, COUNT(*) AS conversions FROM 202_attribution_journey_meta
             WHERE user_id = ? AND conv_time >= ? AND conv_time <= ? GROUP BY touches ORDER BY touches'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
        $lengths = array_map(static fn (array $r): array => ['touches' => (int) $r['touches'], 'conversions' => (int) $r['conversions']], $this->conn->fetchAll($stmt));

        $stmt = $this->conn->prepareRead(
            'SELECT ' . self::timeToConvertSql('jm.conv_time', 'j.click_time', false) . ' AS bucket,
                    COUNT(*) AS conversions
             FROM 202_attribution_journey_meta jm
             JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position = 0
             WHERE jm.user_id = ? AND jm.conv_time >= ? AND jm.conv_time <= ?
             GROUP BY bucket'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
        $ttc = array_map(static fn (): int => 0, self::TIME_TO_CONVERT);
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $ttc[(string) $r['bucket']] = (int) $r['conversions'];
        }

        $stmt = $this->conn->prepareRead(
            "SELECT COALESCE(b.browser_name, 'Unknown') AS browser, COUNT(*) AS conversions, SUM(jm.touches = 1) AS one_touch
             FROM 202_attribution_journey_meta jm
             JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position + 1 = jm.touches
             LEFT JOIN 202_clicks_advance ca ON ca.click_id = j.click_id
             LEFT JOIN 202_browsers b ON b.browser_id = ca.browser_id
             WHERE jm.user_id = ? AND jm.conv_time >= ? AND jm.conv_time <= ?
             GROUP BY browser ORDER BY conversions DESC, browser"
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);

        return ['summary' => $s, 'lengths' => $lengths, 'ttc' => $ttc, 'browsers' => $this->conn->fetchAll($stmt)];
    }

    /**
     * The journey counts through the rollup, or null when it can serve none
     * of the range. Two statements, each the rollup's journey rows of the
     * planned hours unioned with the exact rows of the rest and carrying the
     * plan's guard (rolledParts() for a breakdown): the length, flag and
     * time-to-convert counts, and the converting clicks' browsers — grouped
     * by name when the report runs, as the full computation groups them.
     *
     * @return array{summary: array<string, mixed>, lengths: list<array{touches: int, conversions: int}>, ttc: array<string, int>, browsers: list<array<string, mixed>>}|null
     */
    private function rolledJourneyCounts(int $userId, int $from, int $to): ?array
    {
        for ($attempt = 0; $attempt < self::ROLLUP_ATTEMPTS; $attempt++) {
            $plan = $this->rollupPlan($userId, false, [AttributionRollup::EFFECTIVE], 0, 'journeys', $from, $to);
            if ($plan === null) {
                return null;
            }
            $counts = $this->rolledJourneyTallies($plan);
            $browsers = $counts !== null ? $this->rolledJourneyBrowsers($plan) : null;
            if ($counts === null || $browsers === null) {
                continue;
            }
            $this->servedHours = array_sum(array_map(static fn (array $r): int => $r[1] - $r[0] + 1, $plan['runs']));

            $byTouches = $counts[AttributionRollup::JOURNEY_LENGTH] ?? [];
            ksort($byTouches);
            $conversions = array_sum($byTouches);
            $touches = 0;
            $lengths = [];
            foreach ($byTouches as $k => $n) {
                $touches += $k * $n;
                $lengths[] = ['touches' => $k, 'conversions' => $n];
            }
            $ttc = [];
            $i = 0;
            foreach (array_keys(self::TIME_TO_CONVERT) as $name) {
                $ttc[$name] = $counts[AttributionRollup::JOURNEY_TIME_TO_CONVERT][$i++] ?? 0;
            }

            return [
                'summary' => [
                    'conversions' => $conversions,
                    'one_touch' => $byTouches[1] ?? 0,
                    'truncated' => array_sum($counts[AttributionRollup::JOURNEY_TRUNCATED] ?? []),
                    'unidentified' => array_sum($counts[AttributionRollup::JOURNEY_UNIDENTIFIED] ?? []),
                    'avg_touches' => $conversions > 0 ? $this->averageTouches($touches, $conversions) : 0,
                ],
                'lengths' => $lengths,
                'ttc' => $ttc,
                'browsers' => $browsers,
            ];
        }

        return null;
    }

    /**
     * AVG(touches) as the full computation's statement returns it: MySQL's
     * own decimal division of the same two integers (AVG over integers is
     * that division, at the same div_precision_increment).
     */
    private function averageTouches(int $touches, int $conversions): string
    {
        $stmt = $this->conn->prepareRead('SELECT ? / ? AS a');
        $this->conn->bind($stmt, 'ii', [$touches, $conversions]);
        $r = $this->conn->fetchOne($stmt);
        if ($r === null || $r['a'] === null) {
            throw new \RuntimeException('the average journey length could not be computed');
        }

        return (string) $r['a'];
    }

    /**
     * The length, flag and time-to-convert counts: dimension code => key =>
     * count, or null when the guard failed.
     *
     * @param array<string, mixed> $plan
     * @return array<int, array<int, int>>|null
     */
    private function rolledJourneyTallies(array $plan): ?array
    {
        $u = (int) $plan['user'];
        $exact = self::rangesSql('jm.conv_time', $plan['exact']);
        $branches = [];
        foreach ([AttributionRollup::JOURNEY_LENGTH, AttributionRollup::JOURNEY_TRUNCATED, AttributionRollup::JOURNEY_UNIDENTIFIED, AttributionRollup::JOURNEY_TIME_TO_CONVERT] as $dim) {
            array_push($branches, ...self::rollupBranches($plan, AttributionRollup::PART_JOURNEYS, $dim, AttributionRollup::EFFECTIVE,
                ['r.dim AS dim', 'r.dim_key AS k', 'r.n AS n', 'NULL AS guard']));
        }
        $meta = "FROM 202_attribution_journey_meta jm WHERE jm.user_id = $u AND $exact";
        $branches[] = 'SELECT ' . AttributionRollup::JOURNEY_LENGTH . ", jm.touches, 1, NULL $meta";
        $branches[] = 'SELECT ' . AttributionRollup::JOURNEY_TRUNCATED . ", jm.touches, jm.truncated, NULL $meta";
        $branches[] = 'SELECT ' . AttributionRollup::JOURNEY_UNIDENTIFIED . ", jm.touches, jm.identified = 0, NULL $meta";
        $branches[] = 'SELECT ' . AttributionRollup::JOURNEY_TIME_TO_CONVERT . ', ' . self::timeToConvertSql('jm.conv_time', 'j.click_time', true) . ", 1, NULL
                       FROM 202_attribution_journey_meta jm
                       JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position = 0
                       WHERE jm.user_id = $u AND $exact";
        $branches[] = 'SELECT NULL, NULL, NULL, ' . self::guardSql($plan, false);

        $stmt = $this->conn->prepareRead(
            'SELECT u.dim AS dim, u.k AS k, SUM(u.n) AS n, MAX(u.guard) AS guard
             FROM (' . implode("\n UNION ALL ", $branches) . ') u GROUP BY u.dim, u.k'
        );
        $out = [];
        $guard = null;
        foreach ($this->conn->fetchAll($stmt) as $r) {
            if ($r['dim'] === null) {
                $guard = (int) $r['guard'];
                continue;
            }
            $out[(int) $r['dim']][(int) $r['k']] = (int) $r['n'];
        }

        return $guard === 1 ? $out : null;
    }

    /**
     * The converting clicks' browsers, grouped and ordered by name exactly
     * as the full computation's statement does, or null when the guard
     * failed. A NULL browser (no clicks_advance row) and a browser id with
     * no name both read 'Unknown' there, so the rollup keeps NULL apart
     * from any id (key_null) and the name is joined here.
     *
     * @param array<string, mixed> $plan
     * @return list<array<string, mixed>>|null
     */
    private function rolledJourneyBrowsers(array $plan): ?array
    {
        $u = (int) $plan['user'];
        $columns = static fn (string $conversions, string $oneTouch): array => [
            '0 AS is_guard', 'IF(r.key_null = 1, NULL, r.dim_key) AS browser_id', "$conversions AS conv_n", "$oneTouch AS one_n", 'NULL AS guard',
        ];
        $branches = array_merge(
            self::rollupBranches($plan, AttributionRollup::PART_JOURNEYS, AttributionRollup::JOURNEY_BROWSER, AttributionRollup::EFFECTIVE, $columns('r.n', '0')),
            self::rollupBranches($plan, AttributionRollup::PART_JOURNEYS, AttributionRollup::JOURNEY_BROWSER_ONE_TOUCH, AttributionRollup::EFFECTIVE, $columns('0', 'r.n')),
        );
        $branches[] = "SELECT 0, ca.browser_id, 1, jm.touches = 1, NULL
                       FROM 202_attribution_journey_meta jm
                       JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position + 1 = jm.touches
                       LEFT JOIN 202_clicks_advance ca ON ca.click_id = j.click_id
                       WHERE jm.user_id = $u AND " . self::rangesSql('jm.conv_time', $plan['exact']);
        $branches[] = 'SELECT 1, NULL, 0, 0, ' . self::guardSql($plan, false);

        $stmt = $this->conn->prepareRead(
            "SELECT u.is_guard, COALESCE(b.browser_name, 'Unknown') AS browser, SUM(u.conv_n) AS conversions,
                    SUM(u.one_n) AS one_touch, MAX(u.guard) AS guard
             FROM (" . implode("\n UNION ALL ", $branches) . ') u
             LEFT JOIN 202_browsers b ON b.browser_id = u.browser_id
             GROUP BY u.is_guard, browser
             HAVING u.is_guard = 1 OR SUM(u.conv_n) > 0
             ORDER BY u.is_guard, conversions DESC, browser'
        );
        $out = [];
        $guard = null;
        foreach ($this->conn->fetchAll($stmt) as $r) {
            if ((int) $r['is_guard'] === 1) {
                $guard = (int) $r['guard'];
                continue;
            }
            $out[] = ['browser' => $r['browser'], 'conversions' => $r['conversions'], 'one_touch' => $r['one_touch']];
        }

        return $guard === 1 ? $out : null;
    }

    /** How many of the newest journeys journeyMetrics() lists for a drill-down. */
    public const RECENT_JOURNEYS = 25;

    /**
     * The newest attributed conversions in range, each with the shape of its
     * journey — the entry points of the journey drill-down. `amount` is what
     * the conversion counts for (CountedAmount: net of its reversals), the
     * amount its credits sum to; `recorded_amount` is the row's own.
     *
     * @return list<array{conv_id: int, conv_time: int, touches: int, identified: bool, truncated: bool, amount: string, recorded_amount: string, counted: bool, campaign_name: string|null}>
     */
    public function recentJourneys(int $userId, int $from, int $to, int $limit): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT jm.conv_id, jm.conv_time, jm.touches, jm.identified, jm.truncated, cl.click_payout,
                    cl.payable, cl.deleted, cl.superseded_reason, cl.reverses_conv_id, ac.aff_campaign_name
             FROM 202_attribution_journey_meta jm
             JOIN 202_conversion_logs cl ON cl.conv_id = jm.conv_id AND cl.user_id = jm.user_id
             LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = cl.campaign_id
             WHERE jm.user_id = ? AND jm.conv_time >= ? AND jm.conv_time <= ?
             ORDER BY jm.conv_time DESC, jm.conv_id DESC LIMIT ?'
        );
        $this->conn->bind($stmt, 'iiii', [$userId, $from, $to, max(1, min(500, $limit))]);

        return array_map(fn (array $r): array => [
            'conv_id' => (int) $r['conv_id'],
            'conv_time' => (int) $r['conv_time'],
            'touches' => (int) $r['touches'],
            'identified' => (int) $r['identified'] === 1,
            'truncated' => (int) $r['truncated'] === 1,
            ...CountedAmount::fields($this->conn, $r),
            'campaign_name' => $r['aff_campaign_name'] !== null ? (string) $r['aff_campaign_name'] : null,
        ], $this->conn->fetchAll($stmt));
    }

    /**
     * One conversion's journey, each touch's evidence (the identity signals
     * its click carried) and every model's credit — or null when the
     * conversion is not the account's.
     *
     * @return array<string, mixed>|null
     */
    public function journey(int $userId, int $convId): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT conv_id, click_id, click_payout, payable, deleted, superseded_reason, reverses_conv_id, conv_time
             FROM 202_conversion_logs WHERE conv_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$convId, $userId]);
        $conv = $this->conn->fetchOne($stmt);
        if ($conv === null) {
            return null;
        }

        $stmt = $this->conn->prepareRead(
            'SELECT touches, built_lookback_days, built_at, truncated, identified FROM 202_attribution_journey_meta WHERE conv_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$convId]);
        $meta = $this->conn->fetchOne($stmt);

        $stmt = $this->conn->prepareRead(
            "SELECT j.position, j.click_id, j.click_time, c.aff_campaign_id, ac.aff_campaign_name,
                    c.ppc_account_id, pa.ppc_account_name,
                    (SELECT GROUP_CONCAT(DISTINCT o.signal_type ORDER BY o.signal_type SEPARATOR ',')
                       FROM 202_identity_observations o WHERE o.click_id = j.click_id) AS signals
             FROM 202_attribution_journeys j
             LEFT JOIN 202_clicks c ON c.click_id = j.click_id
             LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id
             LEFT JOIN 202_ppc_accounts pa ON pa.ppc_account_id = c.ppc_account_id
             WHERE j.conv_id = ? ORDER BY j.position"
        );
        $this->conn->bind($stmt, 'i', [$convId]);
        $touches = array_map(static fn (array $r): array => [
            'position' => (int) $r['position'],
            'click_id' => (int) $r['click_id'],
            'click_time' => (int) $r['click_time'],
            'campaign_id' => $r['aff_campaign_id'] !== null ? (int) $r['aff_campaign_id'] : null,
            'campaign_name' => $r['aff_campaign_name'],
            'ppc_account_id' => $r['ppc_account_id'] !== null ? (int) $r['ppc_account_id'] : null,
            'ppc_account_name' => $r['ppc_account_name'],
            'signals' => $r['signals'] !== null && $r['signals'] !== '' ? explode(',', (string) $r['signals']) : [],
        ], $this->conn->fetchAll($stmt));

        $stmt = $this->conn->prepareRead(
            'SELECT cr.model_id, m.model_name, m.model_type, cr.position, cr.click_id, cr.credit, cr.revenue
             FROM 202_attribution_credits cr
             JOIN 202_attribution_models m ON m.model_id = cr.model_id AND m.user_id = ?
             WHERE cr.conv_id = ? ORDER BY cr.model_id, cr.position'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $convId]);
        $credits = [];
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $mid = (int) $r['model_id'];
            $credits[$mid] ??= ['model_id' => $mid, 'model_name' => (string) $r['model_name'], 'model_type' => (string) $r['model_type'], 'touches' => []];
            $credits[$mid]['touches'][] = [
                'position' => (int) $r['position'],
                'click_id' => (int) $r['click_id'],
                'credit' => (string) $r['credit'],
                'revenue' => (string) $r['revenue'],
            ];
        }

        $stmt = $this->conn->prepareRead(
            'SELECT reason, enqueued_at, attempts, last_error, retry_at FROM 202_attribution_pending WHERE conv_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$convId]);
        $pending = $this->conn->fetchOne($stmt);

        return [
            'conv_id' => (int) $conv['conv_id'],
            'click_id' => (int) $conv['click_id'],
            // What the credits below sum to: the amount net of the
            // reversals naming it, the worker's own rule (CountedAmount).
            ...CountedAmount::fields($this->conn, $conv),
            'conv_time' => (int) $conv['conv_time'],
            'journey' => $meta === null ? null : [
                'touches' => (int) $meta['touches'],
                'built_lookback_days' => (int) $meta['built_lookback_days'],
                'built_at' => (int) $meta['built_at'],
                'truncated' => (int) $meta['truncated'] === 1,
                'identified' => (int) $meta['identified'] === 1,
            ],
            'touches' => $touches,
            'credits' => array_values($credits),
            'pending' => $pending === null ? null : [
                'reason' => (string) $pending['reason'],
                'enqueued_at' => (int) $pending['enqueued_at'],
                'attempts' => (int) $pending['attempts'],
                'last_error' => $pending['last_error'],
                'retry_at' => (int) $pending['retry_at'],
            ],
        ];
    }

    /**
     * The worker's backlog for one account: what is waiting, what is failing
     * and why, and the merges and model changes not yet fanned out.
     *
     * @return array<string, mixed>
     */
    public function queue(int $userId, int $limit = 50): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT COUNT(*) AS pending, MIN(p.enqueued_at) AS oldest, COALESCE(SUM(p.attempts > 0), 0) AS failing
             FROM 202_attribution_pending p JOIN 202_conversion_logs cl ON cl.conv_id = p.conv_id
             WHERE cl.user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $s = $this->conn->fetchOne($stmt) ?? [];

        $stmt = $this->conn->prepareRead(
            'SELECT p.conv_id, p.reason, p.enqueued_at, p.attempts, p.last_error, p.retry_at
             FROM 202_attribution_pending p JOIN 202_conversion_logs cl ON cl.conv_id = p.conv_id
             WHERE cl.user_id = ? ORDER BY p.attempts DESC, p.enqueued_at, p.conv_id LIMIT ?'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $limit]);
        $rows = array_map(static fn (array $r): array => [
            'conv_id' => (int) $r['conv_id'],
            'reason' => (string) $r['reason'],
            'enqueued_at' => (int) $r['enqueued_at'],
            'attempts' => (int) $r['attempts'],
            'last_error' => $r['last_error'],
            'retry_at' => (int) $r['retry_at'],
        ], $this->conn->fetchAll($stmt));

        $stmt = $this->conn->prepareRead('SELECT COUNT(*) AS c FROM 202_identity_merges WHERE user_id = ? AND requeued_at IS NULL');
        $this->conn->bind($stmt, 'i', [$userId]);
        $merges = (int) (($this->conn->fetchOne($stmt) ?? [])['c'] ?? 0);

        $stmt = $this->conn->prepareRead(
            'SELECT COUNT(*) AS c FROM 202_attribution_models WHERE user_id = ? AND recompute_requested_at IS NOT NULL'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $models = (int) (($this->conn->fetchOne($stmt) ?? [])['c'] ?? 0);

        return [
            'pending' => (int) ($s['pending'] ?? 0),
            'oldest_enqueued_at' => isset($s['oldest']) ? (int) $s['oldest'] : null,
            'failing' => (int) ($s['failing'] ?? 0),
            'merges_awaiting_requeue' => $merges,
            'models_awaiting_recompute' => $models,
            'rows' => $rows,
        ];
    }

    /**
     * The breakdown's six reads through the rollup, or null when the
     * rollup can serve none of the range (the caller then computes it in
     * full). Each read is one statement that unions the rollup rows of the
     * planned hours with the exact rows of everything else and carries the
     * plan's guard; a guard that fails means the rollup moved between the
     * plan and the read, and the whole breakdown is planned again.
     *
     * @return array{primary: array<string, array{name: string|null, conversions: string, revenue: string}>,
     *               compare: array<string, array{name: string|null, conversions: string, revenue: string}>|null,
     *               cost: array<string, array{name: string|null, clicks: int, cost: string}>,
     *               assists: array<string, array{name: string|null, assists: int}>,
     *               totals: array{conversions: int, attributed_conversions: string, attributed_revenue: string},
     *               compareTotals: array{conversions: int, attributed_conversions: string, attributed_revenue: string}|null}|null
     */
    private function rolledParts(int $userId, ?int $modelId, ?int $compareModelId, int $defaultModelId, string $groupBy, int $from, int $to): ?array
    {
        for ($attempt = 0; $attempt < self::ROLLUP_ATTEMPTS; $attempt++) {
            $models = [AttributionRollup::EFFECTIVE];
            foreach ([$modelId, $compareModelId] as $m) {
                if ($m !== null) {
                    $models[] = $m;
                }
            }
            $plan = $this->rollupPlan($userId, $modelId === null, $models, $defaultModelId, $groupBy, $from, $to);
            if ($plan === null) {
                return null;
            }
            $primary = $this->rolledCredits($plan, $modelId);
            $compare = $compareModelId !== null ? $this->rolledCredits($plan, $compareModelId) : null;
            $cost = $this->rolledCost($plan);
            $assists = $this->rolledAssists($plan);
            $totals = $this->rolledTotals($plan, $modelId);
            $compareTotals = $compareModelId !== null ? $this->rolledTotals($plan, $compareModelId) : null;
            if ($primary === null || $cost === null || $assists === null || $totals === null
                || ($compareModelId !== null && ($compare === null || $compareTotals === null))) {
                continue;
            }
            $this->servedHours = array_sum(array_map(static fn (array $r): int => $r[1] - $r[0] + 1, $plan['runs']));

            return [
                'primary' => $primary,
                'compare' => $compare,
                'cost' => $cost,
                'assists' => $assists,
                'totals' => $totals,
                'compareTotals' => $compareTotals,
            ];
        }

        return null;
    }

    /**
     * Which whole hours of [$from, $to] the rollup serves, as runs of
     * hours, the UTC days among them (not for the day dimension, which is
     * grouped by each hour's local date), and the second ranges left to
     * compute exactly. An hour is served when it is summed (below the
     * account's built_through_hour), not dirty, no changed click of the
     * account is unresolved, and — for the effective model — the rows were
     * summed under the live overrides and the default asked for; for the
     * day dimension, also when every second of it falls on one local date;
     * for the journey counts ($groupBy 'journeys'), only while the account's
     * rollup carries the journey part (AttributionRollup::journeysMarkerSql()).
     *
     * @param bool $effective the effective rows are read ($defaultModelId is the default asked for)
     * @param list<int> $models the model ids whose rows are read (EFFECTIVE for the parts no model shapes)
     * @return array<string, mixed>|null
     */
    private function rollupPlan(int $userId, bool $effective, array $models, int $defaultModelId, string $groupBy, int $from, int $to): ?array
    {
        $journeys = $groupBy === 'journeys';
        $stmt = $this->conn->prepareRead(
            'SELECT s.built_through_hour AS built, s.default_model_id AS dm,
                    EXISTS (SELECT 1 FROM 202_attribution_rollup_dirty_clicks x WHERE x.user_id = s.user_id) AS dc,
                    ' . AttributionRollup::overridesMatchSql($userId) . ' AS map_ok,
                    ' . AttributionRollup::journeysMarkerSql($userId) . ' AS jm_ok
             FROM 202_attribution_rollup_state s WHERE s.user_id = ' . $userId
        );
        $state = $this->conn->fetchOne($stmt);
        if ($state === null || (int) $state['dc'] !== 0 || ($journeys && (int) $state['jm_ok'] !== 1)) {
            return null;
        }
        if ($effective && ((int) $state['map_ok'] !== 1 || $state['dm'] === null || (int) $state['dm'] !== $defaultModelId)) {
            return null;
        }

        $first = intdiv($from + 3599, 3600);
        $last = min(intdiv($to + 1, 3600) - 1, (int) $state['built'] - 1);
        if ($from < 0 || $first > $last) {
            return null;
        }

        $excluded = [];
        $stmt = $this->conn->prepareRead(
            "SELECT hour_from, MAX(hour_to) AS hour_to FROM 202_attribution_rollup_dirty
             WHERE user_id = $userId AND hour_from <= $last AND hour_to >= $first GROUP BY hour_from"
        );
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $excluded[] = [max($first, (int) $r['hour_from']), min($last, (int) $r['hour_to'])];
        }

        $dayDimension = $groupBy === 'day';
        if ($dayDimension) {
            // Hours that straddle a local midnight (or a change of offset)
            // cannot be given one date: compute those exactly.
            $stmt = $this->conn->prepareRead(
                'SELECT DISTINCT r.bucket FROM 202_attribution_rollup r
                 WHERE r.user_id = ' . $userId . ' AND r.part IN (' . AttributionRollup::PART_CREDITS . ', ' . AttributionRollup::PART_COST . ', ' . AttributionRollup::PART_ASSISTS . ')
                   AND r.dim = ' . AttributionRollup::DIMENSION_CODES['day'] . ' AND r.model_id IN (' . AttributionRollup::intList($models) . ')
                   AND r.grain = ' . AttributionRollup::GRAIN_HOUR . " AND r.bucket BETWEEN $first AND $last
                   AND NOT " . AttributionRollup::hourIsOneLocalDateSql('r.bucket')
            );
            foreach ($this->conn->fetchAll($stmt) as $r) {
                $excluded[] = [(int) $r['bucket'], (int) $r['bucket']];
            }
        }

        $runs = self::subtract([[$first, $last]], $excluded);
        if ($runs === [] || count($runs) > self::MAX_RUNS) {
            return null;
        }

        $modelIds = [];
        if ($effective) {
            $stmt = $this->conn->prepareRead('SELECT model_id FROM 202_attribution_models WHERE user_id = ' . $userId);
            $modelIds = array_map(static fn (array $r): int => (int) $r['model_id'], $this->conn->fetchAll($stmt));
            $modelIds[] = $defaultModelId;
        }

        // Whole UTC days inside the served hours read the day rows.
        $days = [];
        $hourRuns = $runs;
        if (!$dayDimension) {
            $dayHours = [];
            foreach ($runs as [$a, $b]) {
                for ($d = intdiv($a + 23, 24); ($d + 1) * 24 - 1 <= $b; $d++) {
                    $days[] = $d;
                    $dayHours[] = [$d * 24, $d * 24 + 23];
                }
            }
            $hourRuns = self::subtract($runs, $dayHours);
        }

        $served = array_map(static fn (array $r): array => [$r[0] * 3600, $r[1] * 3600 + 3599], $runs);

        return [
            'user' => $userId,
            'groupBy' => $groupBy,
            'effective' => $effective,
            'default' => $defaultModelId,
            'modelIds' => $modelIds,
            'models' => $models,
            'runs' => $runs,
            'days' => $days,
            'hourRuns' => $hourRuns,
            'exact' => self::subtract([[$from, $to]], $served),
            'maxHour' => $runs[count($runs) - 1][1],
        ];
    }

    /**
     * 1 when, in the reading statement's own snapshot, every planned hour is
     * still summed and clean, the effective rows still answer for the
     * overrides and default they were planned under, and the journey rows
     * are still kept for the account.
     *
     * @param array<string, mixed> $plan
     */
    private static function guardSql(array $plan, bool $effective): string
    {
        $u = (int) $plan['user'];
        $sql = "(SELECT COUNT(*) FROM 202_attribution_rollup_state s WHERE s.user_id = $u AND s.built_through_hour > " . (int) $plan['maxHour']
            . ($effective ? ' AND s.default_model_id = ' . (int) $plan['default'] : '') . ') = 1'
            . " AND NOT EXISTS (SELECT 1 FROM 202_attribution_rollup_dirty d WHERE d.user_id = $u AND ("
            . implode(' OR ', array_map(static fn (array $r): string => '(d.hour_from <= ' . (int) $r[1] . ' AND d.hour_to >= ' . (int) $r[0] . ')', $plan['runs']))
            . '))'
            . " AND NOT EXISTS (SELECT 1 FROM 202_attribution_rollup_dirty_clicks x WHERE x.user_id = $u)";
        if ($effective) {
            $sql .= ' AND ' . AttributionRollup::overridesMatchSql($u)
                . " AND NOT EXISTS (SELECT 1 FROM 202_attribution_models m WHERE m.user_id = $u AND m.model_id NOT IN (" . AttributionRollup::intList($plan['modelIds']) . '))';
        }
        if ($plan['groupBy'] === 'journeys') {
            $sql .= ' AND ' . AttributionRollup::journeysMarkerSql($u);
        }
        if ($plan['groupBy'] === 'day') {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM 202_attribution_rollup r2 WHERE r2.user_id = $u"
                . ' AND r2.part IN (' . AttributionRollup::PART_CREDITS . ', ' . AttributionRollup::PART_COST . ', ' . AttributionRollup::PART_ASSISTS . ')'
                . ' AND r2.dim = ' . AttributionRollup::DIMENSION_CODES['day'] . ' AND r2.model_id IN (' . AttributionRollup::intList($plan['models']) . ')'
                . ' AND r2.grain = ' . AttributionRollup::GRAIN_HOUR . ' AND ' . self::rangesSql('r2.bucket', $plan['runs'])
                . ' AND NOT ' . AttributionRollup::hourIsOneLocalDateSql('r2.bucket') . ')';
        }

        return '(' . $sql . ')';
    }

    /**
     * The rollup rows of the plan for one part, as UNION ALL branches with
     * the given column list (each item a column of `r`, or an expression).
     *
     * @param array<string, mixed> $plan
     * @param list<string> $columns
     * @return list<string>
     */
    private static function rollupBranches(array $plan, int $part, int $dim, int $model, array $columns): array
    {
        $u = (int) $plan['user'];
        $base = "FROM 202_attribution_rollup r WHERE r.user_id = $u AND r.part = $part AND r.dim = $dim AND r.model_id = $model";
        $select = 'SELECT ' . implode(', ', $columns);
        $branches = [];
        if ($plan['days'] !== []) {
            $branches[] = "$select $base AND r.grain = " . AttributionRollup::GRAIN_DAY . ' AND r.bucket IN (' . AttributionRollup::intList($plan['days']) . ')';
        }
        if ($plan['hourRuns'] !== []) {
            $branches[] = "$select $base AND r.grain = " . AttributionRollup::GRAIN_HOUR . ' AND ' . self::rangesSql('r.bucket', $plan['hourRuns']);
        }

        return $branches;
    }

    /**
     * The group key and whether it had a value, for rollup rows.
     *
     * @return array{0: string, 1: string}
     */
    private static function rolledKey(string $groupBy): array
    {
        if ($groupBy === 'day') {
            return ["FROM_UNIXTIME(r.bucket * 3600, '%Y-%m-%d')", '1'];
        }

        return ['r.dim_key', '1 - r.key_null'];
    }

    /**
     * The name of each group, looked up now: what MAX(name) over the group's
     * rows is in the full computation, since every name table is joined on
     * its primary key and a row without a value has no name.
     */
    private static function nameSql(string $groupBy): string
    {
        if ($groupBy === 'day') {
            return 'g.k';
        }
        [$table, $id, $name] = self::nameLookup($groupBy);

        return "IF(g.named = 1, (SELECT dn.`$name` FROM `$table` dn WHERE dn.`$id` = g.k), NULL)";
    }

    /**
     * The table, key column and name column a dimension's names come from,
     * read from its own join so the two cannot disagree.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function nameLookup(string $groupBy): array
    {
        $d = self::DIMENSIONS[$groupBy] ?? throw new \InvalidArgumentException('unknown dimension ' . $groupBy);
        if (preg_match('/^dn\.(\w+)$/D', $d['name'], $n) !== 1) {
            throw new \LogicException('dimension ' . $groupBy . ' has no name column');
        }
        foreach ($d['joins'] as $join) {
            if (preg_match('/^LEFT JOIN (\w+) dn ON dn\.(\w+) = [\w.]+$/D', $join, $m) === 1) {
                return [$m[1], $m[2], $n[1]];
            }
        }
        throw new \LogicException('dimension ' . $groupBy . ' has no name join');
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, array{name: string|null, conversions: string, revenue: string}>|null null: guard failed
     */
    private function rolledCredits(array $plan, ?int $modelId): ?array
    {
        $groupBy = (string) $plan['groupBy'];
        [$rk, $rnamed] = self::rolledKey($groupBy);
        [$key, , $joins] = AttributionRollup::keySqlWithTime($groupBy, 'cr.conv_time');
        [$source, $where] = $this->exactCreditSource($plan, $modelId, self::rangesSql('cr.conv_time', $plan['exact']));
        $branches = self::rollupBranches($plan, AttributionRollup::PART_CREDITS, AttributionRollup::DIMENSION_CODES[$groupBy],
            $modelId ?? AttributionRollup::EFFECTIVE, ["$rk AS k", "$rnamed AS named", 'r.credit AS credit', 'r.revenue AS revenue', 'NULL AS guard']);
        $branches[] = "SELECT COALESCE($key, 0), ($key) IS NOT NULL, cr.credit, cr.revenue, NULL
                       FROM $source JOIN 202_clicks c ON c.click_id = cr.click_id $joins WHERE $where";
        $branches[] = 'SELECT NULL, 0, NULL, NULL, ' . self::guardSql($plan, $modelId === null);

        $stmt = $this->conn->prepareRead(
            'SELECT g.k AS k, ' . self::nameSql($groupBy) . ' AS n, g.conversions, g.revenue, g.guard FROM (
                SELECT u.k, MAX(u.named) AS named, SUM(u.credit) AS conversions, SUM(u.revenue) AS revenue, MAX(u.guard) AS guard
                FROM (' . implode("\n UNION ALL ", $branches) . ') u GROUP BY u.k) g'
        );
        $out = [];
        $guard = null;
        foreach ($this->conn->fetchAll($stmt) as $r) {
            if ($r['k'] === null) {
                $guard = (int) $r['guard'];
                continue;
            }
            $out[(string) $r['k']] = ['name' => $r['n'] !== null ? (string) $r['n'] : null, 'conversions' => (string) $r['conversions'], 'revenue' => (string) $r['revenue']];
        }

        return $guard === 1 ? $out : null;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, array{name: string|null, clicks: int, cost: string}>|null
     */
    private function rolledCost(array $plan): ?array
    {
        $groupBy = (string) $plan['groupBy'];
        $u = (int) $plan['user'];
        [$rk, $rnamed] = self::rolledKey($groupBy);
        [$key, , $joins] = AttributionRollup::keySqlWithTime($groupBy, 'c.click_time');
        $branches = self::rollupBranches($plan, AttributionRollup::PART_COST, AttributionRollup::DIMENSION_CODES[$groupBy],
            AttributionRollup::EFFECTIVE, ["$rk AS k", "$rnamed AS named", 'r.n AS n', 'r.cost AS cost', 'NULL AS guard']);
        $branches[] = "SELECT COALESCE($key, 0), ($key) IS NOT NULL, 1, c.click_cpc, NULL
                       FROM 202_clicks c $joins
                       WHERE c.user_id = $u AND " . self::rangesSql('c.click_time', $plan['exact']) . ' AND c.click_bot = 0';
        $branches[] = 'SELECT NULL, 0, NULL, NULL, ' . self::guardSql($plan, false);

        $stmt = $this->conn->prepareRead(
            'SELECT g.k AS k, ' . self::nameSql($groupBy) . ' AS n, g.clicks, g.cost, g.guard FROM (
                SELECT u.k, MAX(u.named) AS named, SUM(u.n) AS clicks, SUM(u.cost) AS cost, MAX(u.guard) AS guard
                FROM (' . implode("\n UNION ALL ", $branches) . ') u GROUP BY u.k) g'
        );
        $out = [];
        $guard = null;
        foreach ($this->conn->fetchAll($stmt) as $r) {
            if ($r['k'] === null) {
                $guard = (int) $r['guard'];
                continue;
            }
            $out[(string) $r['k']] = ['name' => $r['n'] !== null ? (string) $r['n'] : null, 'clicks' => (int) $r['clicks'], 'cost' => (string) $r['cost']];
        }

        return $guard === 1 ? $out : null;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, array{name: string|null, assists: int}>|null
     */
    private function rolledAssists(array $plan): ?array
    {
        $groupBy = (string) $plan['groupBy'];
        $u = (int) $plan['user'];
        [$rk, $rnamed] = self::rolledKey($groupBy);
        [$key, , $joins] = AttributionRollup::keySqlWithTime($groupBy, 'jm.conv_time');
        $branches = self::rollupBranches($plan, AttributionRollup::PART_ASSISTS, AttributionRollup::DIMENSION_CODES[$groupBy],
            AttributionRollup::EFFECTIVE, ["$rk AS k", "$rnamed AS named", 'r.n AS n', 'NULL AS conv_id', 'NULL AS guard']);
        $branches[] = "SELECT COALESCE($key, 0), ($key) IS NOT NULL, NULL, j.conv_id, NULL
                       FROM 202_attribution_journey_meta jm
                       JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position + 1 < jm.touches
                       JOIN 202_clicks c ON c.click_id = j.click_id
                       $joins
                       WHERE jm.user_id = $u AND " . self::rangesSql('jm.conv_time', $plan['exact']);
        $branches[] = 'SELECT NULL, 0, NULL, NULL, ' . self::guardSql($plan, false);

        $stmt = $this->conn->prepareRead(
            'SELECT g.k AS k, ' . self::nameSql($groupBy) . ' AS n, g.assists, g.guard FROM (
                SELECT u.k, MAX(u.named) AS named, COALESCE(SUM(u.n), 0) + COUNT(DISTINCT u.conv_id) AS assists, MAX(u.guard) AS guard
                FROM (' . implode("\n UNION ALL ", $branches) . ') u GROUP BY u.k) g'
        );
        $out = [];
        $guard = null;
        foreach ($this->conn->fetchAll($stmt) as $r) {
            if ($r['k'] === null) {
                $guard = (int) $r['guard'];
                continue;
            }
            $out[(string) $r['k']] = ['name' => $r['n'] !== null ? (string) $r['n'] : null, 'assists' => (int) $r['assists']];
        }

        return $guard === 1 ? $out : null;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{conversions: int, attributed_conversions: string, attributed_revenue: string}|null
     */
    private function rolledTotals(array $plan, ?int $modelId): ?array
    {
        [$source, $where] = $this->exactCreditSource($plan, $modelId, self::rangesSql('cr.conv_time', $plan['exact']));
        $branches = self::rollupBranches($plan, AttributionRollup::PART_TOTALS, 0, $modelId ?? AttributionRollup::EFFECTIVE,
            ['r.n AS n', 'NULL AS conv_id', 'r.credit AS credit', 'r.revenue AS revenue', 'NULL AS guard']);
        $branches[] = "SELECT NULL, cr.conv_id, cr.credit, cr.revenue, NULL FROM $source WHERE $where";
        $branches[] = 'SELECT NULL, NULL, NULL, NULL, ' . self::guardSql($plan, $modelId === null);

        $stmt = $this->conn->prepareRead(
            'SELECT COUNT(DISTINCT u.conv_id) + COALESCE(SUM(u.n), 0) AS conversions, COALESCE(SUM(u.credit), 0) AS credit,
                    COALESCE(SUM(u.revenue), 0) AS revenue, MAX(u.guard) AS guard
             FROM (' . implode("\n UNION ALL ", $branches) . ') u'
        );
        $r = $this->conn->fetchOne($stmt);
        if ($r === null || (int) $r['guard'] !== 1) {
            return null;
        }

        return [
            'conversions' => (int) $r['conversions'],
            'attributed_conversions' => (string) $r['credit'],
            'attributed_revenue' => (string) $r['revenue'],
        ];
    }

    /**
     * The credit rows of the exact ranges: one model's, or the effective
     * ones (the account's models and the default, the set the guard holds
     * fixed).
     *
     * @param array<string, mixed> $plan
     * @return array{0: string, 1: string}
     */
    private function exactCreditSource(array $plan, ?int $modelId, string $timePredicate): array
    {
        if ($modelId !== null) {
            return ['202_attribution_credits cr', 'cr.model_id = ' . $modelId . ' AND ' . $timePredicate];
        }

        return AttributionRollup::effectiveSource((int) $plan['user'], (int) $plan['default'], $plan['modelIds'], $timePredicate);
    }

    /** @param list<array{0: int, 1: int}> $ranges */
    private static function rangesSql(string $column, array $ranges): string
    {
        if ($ranges === []) {
            return '0';
        }

        return '(' . implode(' OR ', array_map(static fn (array $r): string => $column . ' BETWEEN ' . (int) $r[0] . ' AND ' . (int) $r[1], $ranges)) . ')';
    }

    /**
     * Closed integer ranges minus closed integer ranges, merged and sorted.
     *
     * @param list<array{0: int, 1: int}> $ranges
     * @param list<array{0: int, 1: int}> $minus
     * @return list<array{0: int, 1: int}>
     */
    public static function subtract(array $ranges, array $minus): array
    {
        usort($minus, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        foreach ($ranges as [$a, $b]) {
            $cur = $a;
            foreach ($minus as [$x, $y]) {
                if ($y < $cur || $x > $b) {
                    continue;
                }
                if ($x > $cur) {
                    $out[] = [$cur, $x - 1];
                }
                $cur = max($cur, $y + 1);
                if ($cur > $b) {
                    break;
                }
            }
            if ($cur <= $b) {
                $out[] = [$cur, $b];
            }
        }
        usort($out, static fn (array $p, array $q): int => $p[0] <=> $q[0]);
        $merged = [];
        foreach ($out as $r) {
            $n = count($merged);
            if ($n > 0 && $r[0] <= $merged[$n - 1][1] + 1) {
                $merged[$n - 1][1] = max($merged[$n - 1][1], $r[1]);
            } else {
                $merged[] = $r;
            }
        }

        return $merged;
    }

    /**
     * A dimension's key expression, name expression and joins, over the
     * clicks aliased `c` ({time} is the clock the part reads).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function dimensionSql(string $groupBy, string $timeColumn): array
    {
        if (!isset(self::DIMENSIONS[$groupBy])) {
            throw new \InvalidArgumentException('unknown dimension ' . $groupBy);
        }
        $d = self::DIMENSIONS[$groupBy];

        return [
            str_replace('{time}', $timeColumn, $d['key']),
            str_replace('{time}', $timeColumn, $d['name']),
            implode("\n", $d['joins']),
        ];
    }

    private static function roi(string $revenue, string $cost): ?float
    {
        $c = (float) $cost;
        if ($c <= 0.0) {
            return null;
        }

        return round(((float) $revenue - $c) / $c * 100, 2);
    }

    /**
     * Compare two decimal strings exactly, by their 1e-8 integer value (no
     * bcmath dependency). The values are MySQL DECIMAL sums, which always
     * match the pattern; anything else is a bug and throws.
     */
    private static function compareDecimal(string $a, string $b): int
    {
        return self::decimalUnits($a) <=> self::decimalUnits($b);
    }

    private static function decimalUnits(string $v): int
    {
        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,8}))?$/D', trim($v), $m) !== 1) {
            throw new \UnexpectedValueException('"' . $v . '" is not a decimal amount');
        }
        $units = (int) $m[2] * 100_000_000 + (int) str_pad($m[3] ?? '', 8, '0');

        return ($m[1] ?? '') === '-' ? -$units : $units;
    }
}
