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

    public function __construct(private Connection $conn)
    {
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
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function breakdown(int $userId, ?int $modelId, ?int $compareModelId, int $defaultModelId, string $groupBy, int $from, int $to, int $limit): array
    {
        if (!isset(self::DIMENSIONS[$groupBy])) {
            throw new \InvalidArgumentException('unknown dimension ' . $groupBy);
        }

        $primary = $this->creditsBy($userId, $modelId, $defaultModelId, $groupBy, $from, $to);
        $compare = $compareModelId !== null ? $this->creditsBy($userId, $compareModelId, $defaultModelId, $groupBy, $from, $to) : null;
        $cost = $this->costBy($userId, $groupBy, $from, $to);
        $assists = $this->assistsBy($userId, $groupBy, $from, $to);

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

        $totals = $this->totals($userId, $modelId, $defaultModelId, $from, $to);
        if ($compareModelId !== null) {
            $ct = $this->totals($userId, $compareModelId, $defaultModelId, $from, $to);
            $totals['compare_attributed_conversions'] = $ct['attributed_conversions'];
            $totals['compare_attributed_revenue'] = $ct['attributed_revenue'];
        }

        return ['rows' => array_slice($rows, 0, max(1, min(self::MAX_LIMIT, $limit))), 'totals' => $totals];
    }

    /**
     * @return array<string, array{name: string|null, conversions: string, revenue: string}>
     */
    private function creditsBy(int $userId, ?int $modelId, int $defaultModelId, string $groupBy, int $from, int $to): array
    {
        [$key, $name, $joins] = $this->dimensionSql($groupBy, 'cr.conv_time');
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
            return [
                '202_attribution_credits cr',
                'cr.model_id = ? AND cr.conv_time >= ? AND cr.conv_time <= ?',
                'iii',
                [$modelId, $from, $to],
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
        [$key, $name, $joins] = $this->dimensionSql($groupBy, 'c.click_time');
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
        [$key, $name, $joins] = $this->dimensionSql($groupBy, 'jm.conv_time');
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
     * @return array<string, mixed>
     */
    public function journeyMetrics(int $userId, int $from, int $to): array
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
            "SELECT CASE
                        WHEN jm.conv_time - j.click_time < 3600 THEN 'under_1h'
                        WHEN jm.conv_time - j.click_time < 86400 THEN '1h_to_1d'
                        WHEN jm.conv_time - j.click_time < 604800 THEN '1d_to_7d'
                        WHEN jm.conv_time - j.click_time < 2592000 THEN '7d_to_30d'
                        ELSE 'over_30d' END AS bucket,
                    COUNT(*) AS conversions
             FROM 202_attribution_journey_meta jm
             JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position = 0
             WHERE jm.user_id = ? AND jm.conv_time >= ? AND jm.conv_time <= ?
             GROUP BY bucket"
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
        $ttc = ['under_1h' => 0, '1h_to_1d' => 0, '1d_to_7d' => 0, '7d_to_30d' => 0, 'over_30d' => 0];
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
        $browsers = array_map(static function (array $r): array {
            $n = (int) $r['conversions'];
            $one = (int) $r['one_touch'];
            return ['browser' => (string) $r['browser'], 'conversions' => $n, 'one_touch' => $one, 'one_touch_share' => $n > 0 ? round($one / $n, 4) : 0.0];
        }, $this->conn->fetchAll($stmt));

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
        ];
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
            'amount' => (string) $conv['click_payout'],
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
     * @return array{0: string, 1: string, 2: string}
     */
    private function dimensionSql(string $groupBy, string $timeColumn): array
    {
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
