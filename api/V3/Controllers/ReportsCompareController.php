<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;

/**
 * Period-over-period comparison and top/bottom analysis.
 */
class ReportsCompareController
{
    private const array BREAKDOWNS = [
        'campaign'     => ['table' => '202_aff_campaigns',      'id' => 'aff_campaign_id',  'name' => 'aff_campaign_name',  'de_id' => 'aff_campaign_id'],
        'aff_network'  => ['table' => '202_aff_networks',       'id' => 'aff_network_id',   'name' => 'aff_network_name',   'de_id' => 'aff_network_id'],
        'ppc_account'  => ['table' => '202_ppc_accounts',       'id' => 'ppc_account_id',   'name' => 'ppc_account_name',   'de_id' => 'ppc_account_id'],
        'ppc_network'  => ['table' => '202_ppc_networks',       'id' => 'ppc_network_id',   'name' => 'ppc_network_name',   'de_id' => 'ppc_network_id'],
        'landing_page' => ['table' => '202_landing_pages',      'id' => 'landing_page_id',  'name' => 'landing_page_url',   'de_id' => 'landing_page_id'],
        'keyword'      => ['table' => '202_keywords',           'id' => 'keyword_id',       'name' => 'keyword',            'de_id' => 'keyword_id'],
        'country'      => ['table' => '202_locations_country',  'id' => 'country_id',       'name' => 'country_name',       'de_id' => 'country_id'],
        'city'         => ['table' => '202_locations_city',      'id' => 'city_id',          'name' => 'city_name',          'de_id' => 'city_id'],
        'browser'      => ['table' => '202_browsers',           'id' => 'browser_id',       'name' => 'browser_name',       'de_id' => 'browser_id'],
        'platform'     => ['table' => '202_platforms',           'id' => 'platform_id',      'name' => 'platform_name',      'de_id' => 'platform_id'],
        'device'       => ['table' => '202_device_models',       'id' => 'device_id',        'name' => 'device_name',        'de_id' => 'device_id'],
        'isp'          => ['table' => '202_locations_isp',       'id' => 'isp_id',           'name' => 'isp_name',           'de_id' => 'isp_id'],
        'text_ad'      => ['table' => '202_text_ads',            'id' => 'text_ad_id',       'name' => 'text_ad_name',       'de_id' => 'text_ad_id'],
    ];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * Compare two time periods side by side with absolute and percentage change.
     *
     * Required params: period_a_from, period_a_to, period_b_from, period_b_to
     * Optional: breakdown (dimension to compare by)
     */
    public function compare(array $params): array
    {
        $requiredFields = ['period_a_from', 'period_a_to', 'period_b_from', 'period_b_to'];
        foreach ($requiredFields as $field) {
            if (empty($params[$field])) {
                throw new ValidationException("Missing required parameter: $field", [$field => 'Required unix timestamp']);
            }
        }

        $aFrom = (int)$params['period_a_from'];
        $aTo   = (int)$params['period_a_to'];
        $bFrom = (int)$params['period_b_from'];
        $bTo   = (int)$params['period_b_to'];

        $breakdown = $params['breakdown'] ?? '';
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));

        if ($breakdown !== '' && !isset(self::BREAKDOWNS[$breakdown])) {
            throw new ValidationException('Invalid breakdown', ['breakdown' => 'Valid: ' . implode(', ', array_keys(self::BREAKDOWNS))]);
        }

        $entityFilters = $this->collectEntityFilters($params);

        if ($breakdown === '') {
            return $this->compareSummary($aFrom, $aTo, $bFrom, $bTo, $entityFilters);
        }

        return $this->compareByDimension($aFrom, $aTo, $bFrom, $bTo, $breakdown, $limit, $entityFilters);
    }

    private function compareSummary(int $aFrom, int $aTo, int $bFrom, int $bTo, array $entityFilters): array
    {
        $periodA = $this->fetchSummary($aFrom, $aTo, $entityFilters);
        $periodB = $this->fetchSummary($bFrom, $bTo, $entityFilters);

        $result = [];
        foreach ($periodA as $metric => $valA) {
            $valB = $periodB[$metric] ?? 0;
            $result[$metric] = [
                'period_a' => $valA,
                'period_b' => $valB,
                'change'   => $valB - $valA,
                'change_pct' => $valA != 0 ? round(($valB - $valA) / abs($valA) * 100, 2) : null,
            ];
        }

        return ['data' => $result];
    }

    private function compareByDimension(int $aFrom, int $aTo, int $bFrom, int $bTo, string $breakdown, int $limit, array $entityFilters): array
    {
        $bd = self::BREAKDOWNS[$breakdown];
        $rowsA = $this->fetchBreakdown($aFrom, $aTo, $bd, $entityFilters);
        $rowsB = $this->fetchBreakdown($bFrom, $bTo, $bd, $entityFilters);

        $merged = [];
        foreach ($rowsA as $id => $a) {
            $b = $rowsB[$id] ?? $this->zeroMetrics();
            $merged[$id] = [
                'id'   => $id,
                'name' => $a['name'],
                'period_a_clicks'   => (int)$a['total_clicks'],
                'period_b_clicks'   => (int)$b['total_clicks'],
                'clicks_change_pct' => $a['total_clicks'] > 0
                    ? round(((float)$b['total_clicks'] - (float)$a['total_clicks']) / (float)$a['total_clicks'] * 100, 2) : null,
                'period_a_revenue'  => (float)$a['total_income'],
                'period_b_revenue'  => (float)$b['total_income'],
                'revenue_change_pct' => $a['total_income'] > 0
                    ? round(((float)$b['total_income'] - (float)$a['total_income']) / abs((float)$a['total_income']) * 100, 2) : null,
                'period_a_cost'     => (float)$a['total_cost'],
                'period_b_cost'     => (float)$b['total_cost'],
                'period_a_roi'      => (float)$a['roi'],
                'period_b_roi'      => (float)$b['roi'],
                'period_a_conv_rate' => (float)$a['conv_rate'],
                'period_b_conv_rate' => (float)$b['conv_rate'],
            ];
        }
        foreach ($rowsB as $id => $b) {
            if (!isset($merged[$id])) {
                $merged[$id] = [
                    'id'   => $id,
                    'name' => $b['name'],
                    'period_a_clicks'   => 0,
                    'period_b_clicks'   => (int)$b['total_clicks'],
                    'clicks_change_pct' => null,
                    'period_a_revenue'  => 0.0,
                    'period_b_revenue'  => (float)$b['total_income'],
                    'revenue_change_pct' => null,
                    'period_a_cost'     => 0.0,
                    'period_b_cost'     => (float)$b['total_cost'],
                    'period_a_roi'      => 0.0,
                    'period_b_roi'      => (float)$b['roi'],
                    'period_a_conv_rate' => 0.0,
                    'period_b_conv_rate' => (float)$b['conv_rate'],
                ];
            }
        }

        usort($merged, fn($a, $b) => abs($b['revenue_change_pct'] ?? 0) <=> abs($a['revenue_change_pct'] ?? 0));

        return [
            'data'      => array_slice(array_values($merged), 0, $limit),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Get top N and bottom N performers by a given metric.
     */
    public function topBottom(array $params): array
    {
        $breakdown = $params['breakdown'] ?? 'campaign';
        if (!isset(self::BREAKDOWNS[$breakdown])) {
            throw new ValidationException('Invalid breakdown', ['breakdown' => 'Valid: ' . implode(', ', array_keys(self::BREAKDOWNS))]);
        }

        $metric = $params['metric'] ?? 'total_net';
        $validMetrics = ['total_clicks', 'total_leads', 'total_income', 'total_cost', 'total_net', 'roi', 'epc', 'conv_rate', 'cpa'];
        if (!in_array($metric, $validMetrics, true)) {
            throw new ValidationException('Invalid metric', ['metric' => 'Valid: ' . implode(', ', $validMetrics)]);
        }

        $n = max(1, min(50, (int)($params['n'] ?? 10)));
        $minClicks = max(0, (int)($params['min_clicks'] ?? 0));

        $bd = self::BREAKDOWNS[$breakdown];
        $entityFilters = $this->collectEntityFilters($params);

        $where = ['de.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        $this->applyTimeFilters($params, $where, $binds, $types);
        $this->applyEntityFiltersSql($entityFilters, $where, $binds, $types);

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $havingClause = $minClicks > 0 ? "HAVING SUM(de.clicks) >= $minClicks" : '';

        $sql = "SELECT
                ref.{$bd['id']} as id,
                ref.{$bd['name']} as name,
                SUM(de.clicks) as total_clicks,
                SUM(de.click_out) as total_click_throughs,
                SUM(de.leads) as total_leads,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as total_net,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi,
                CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cpa
            FROM 202_dataengine de
            INNER JOIN {$bd['table']} ref ON de.{$bd['de_id']} = ref.{$bd['id']}
            $whereClause
            GROUP BY ref.{$bd['id']}, ref.{$bd['name']}
            $havingClause
            ORDER BY $metric DESC";

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Top/bottom query failed');
        }
        $result = $stmt->get_result();

        $all = [];
        while ($row = $result->fetch_assoc()) {
            $all[] = $row;
        }
        $stmt->close();

        $top = array_slice($all, 0, $n);
        $bottom = array_slice(array_reverse($all), 0, $n);

        return [
            'data' => [
                'top'    => $top,
                'bottom' => $bottom,
            ],
            'metric'    => $metric,
            'breakdown' => $breakdown,
            'n'         => $n,
            'total_entities' => count($all),
        ];
    }

    /**
     * Conversion funnel analysis: clicks -> click-throughs -> conversions.
     */
    public function funnel(array $params): array
    {
        $breakdown = $params['breakdown'] ?? '';
        $limit = max(1, min(100, (int)($params['limit'] ?? 20)));

        if ($breakdown !== '' && !isset(self::BREAKDOWNS[$breakdown])) {
            throw new ValidationException('Invalid breakdown', ['breakdown' => 'Valid: ' . implode(', ', array_keys(self::BREAKDOWNS))]);
        }

        $entityFilters = $this->collectEntityFilters($params);

        $where = ['de.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        $this->applyTimeFilters($params, $where, $binds, $types);
        $this->applyEntityFiltersSql($entityFilters, $where, $binds, $types);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        if ($breakdown === '') {
            $sql = "SELECT
                    SUM(de.clicks) as clicks,
                    SUM(de.click_out) as click_throughs,
                    SUM(de.leads) as conversions,
                    SUM(de.income) as revenue,
                    SUM(de.cost) as cost,
                    CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.click_out) / SUM(de.clicks) * 100 ELSE 0 END as click_through_rate,
                    CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conversion_rate,
                    CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.leads) / SUM(de.clicks) * 100 ELSE 0 END as overall_conversion_rate,
                    CASE WHEN SUM(de.leads) > 0 THEN SUM(de.income) / SUM(de.leads) ELSE 0 END as revenue_per_conversion,
                    CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cost_per_conversion
                FROM 202_dataengine de
                $whereClause";

            $stmt = $this->prepare($sql);
            $stmt->bind_param($types, ...$binds);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new DatabaseException('Funnel query failed');
            }
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return ['data' => $row];
        }

        $bd = self::BREAKDOWNS[$breakdown];
        $sql = "SELECT
                ref.{$bd['id']} as id,
                ref.{$bd['name']} as name,
                SUM(de.clicks) as clicks,
                SUM(de.click_out) as click_throughs,
                SUM(de.leads) as conversions,
                SUM(de.income) as revenue,
                SUM(de.cost) as cost,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.click_out) / SUM(de.clicks) * 100 ELSE 0 END as click_through_rate,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conversion_rate,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.leads) / SUM(de.clicks) * 100 ELSE 0 END as overall_conversion_rate,
                CASE WHEN SUM(de.leads) > 0 THEN SUM(de.income) / SUM(de.leads) ELSE 0 END as revenue_per_conversion,
                CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cost_per_conversion
            FROM 202_dataengine de
            INNER JOIN {$bd['table']} ref ON de.{$bd['de_id']} = ref.{$bd['id']}
            $whereClause
            GROUP BY ref.{$bd['id']}, ref.{$bd['name']}
            ORDER BY SUM(de.clicks) DESC
            LIMIT ? OFFSET 0";

        $binds[] = $limit;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Funnel breakdown query failed');
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return ['data' => $rows, 'breakdown' => $breakdown];
    }

    /**
     * Two-dimensional pivot: primary_breakdown x secondary_breakdown.
     */
    public function pivot(array $params): array
    {
        $primary = $params['primary'] ?? '';
        $secondary = $params['secondary'] ?? '';
        $metric = $params['metric'] ?? 'total_net';

        if (!isset(self::BREAKDOWNS[$primary])) {
            throw new ValidationException('Invalid primary', ['primary' => 'Valid: ' . implode(', ', array_keys(self::BREAKDOWNS))]);
        }
        if (!isset(self::BREAKDOWNS[$secondary])) {
            throw new ValidationException('Invalid secondary', ['secondary' => 'Valid: ' . implode(', ', array_keys(self::BREAKDOWNS))]);
        }
        if ($primary === $secondary) {
            throw new ValidationException('primary and secondary must differ', []);
        }

        $validMetrics = ['total_clicks', 'total_leads', 'total_income', 'total_cost', 'total_net', 'roi', 'epc', 'conv_rate'];
        if (!in_array($metric, $validMetrics, true)) {
            throw new ValidationException('Invalid metric', ['metric' => 'Valid: ' . implode(', ', $validMetrics)]);
        }

        $limit = max(1, min(50, (int)($params['limit'] ?? 10)));
        $entityFilters = $this->collectEntityFilters($params);

        $p = self::BREAKDOWNS[$primary];
        $s = self::BREAKDOWNS[$secondary];

        $where = ['de.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        $this->applyTimeFilters($params, $where, $binds, $types);
        $this->applyEntityFiltersSql($entityFilters, $where, $binds, $types);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $metricExpr = match ($metric) {
            'total_clicks' => 'SUM(de.clicks)',
            'total_leads'  => 'SUM(de.leads)',
            'total_income' => 'SUM(de.income)',
            'total_cost'   => 'SUM(de.cost)',
            'total_net'    => 'SUM(de.income) - SUM(de.cost)',
            'roi'          => 'CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END',
            'epc'          => 'CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END',
            'conv_rate'    => 'CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END',
        };

        $sql = "SELECT
                p_ref.{$p['id']} as primary_id,
                p_ref.{$p['name']} as primary_name,
                s_ref.{$s['id']} as secondary_id,
                s_ref.{$s['name']} as secondary_name,
                $metricExpr as metric_value
            FROM 202_dataengine de
            INNER JOIN {$p['table']} p_ref ON de.{$p['de_id']} = p_ref.{$p['id']}
            INNER JOIN {$s['table']} s_ref ON de.{$s['de_id']} = s_ref.{$s['id']}
            $whereClause
            GROUP BY p_ref.{$p['id']}, p_ref.{$p['name']}, s_ref.{$s['id']}, s_ref.{$s['name']}
            ORDER BY $metricExpr DESC
            LIMIT ?";

        $binds[] = $limit * $limit;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Pivot query failed');
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return [
            'data'      => $rows,
            'primary'   => $primary,
            'secondary' => $secondary,
            'metric'    => $metric,
        ];
    }

    private function fetchSummary(int $from, int $to, array $entityFilters): array
    {
        $where = ['de.user_id = ?', 'de.click_time >= ?', 'de.click_time <= ?'];
        $binds = [$this->userId, $from, $to];
        $types = 'iii';

        $this->applyEntityFiltersSql($entityFilters, $where, $binds, $types);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                COALESCE(SUM(de.clicks), 0) as total_clicks,
                COALESCE(SUM(de.click_out), 0) as total_click_throughs,
                COALESCE(SUM(de.leads), 0) as total_leads,
                COALESCE(SUM(de.income), 0) as total_income,
                COALESCE(SUM(de.cost), 0) as total_cost,
                COALESCE(SUM(de.income), 0) - COALESCE(SUM(de.cost), 0) as total_net,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi,
                CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cpa
            FROM 202_dataengine de
            $whereClause";

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Compare summary query failed');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: $this->zeroMetrics();
    }

    private function fetchBreakdown(int $from, int $to, array $bd, array $entityFilters): array
    {
        $where = ['de.user_id = ?', 'de.click_time >= ?', 'de.click_time <= ?'];
        $binds = [$this->userId, $from, $to];
        $types = 'iii';

        $this->applyEntityFiltersSql($entityFilters, $where, $binds, $types);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                ref.{$bd['id']} as id,
                ref.{$bd['name']} as name,
                SUM(de.clicks) as total_clicks,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as total_net,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi
            FROM 202_dataengine de
            INNER JOIN {$bd['table']} ref ON de.{$bd['de_id']} = ref.{$bd['id']}
            $whereClause
            GROUP BY ref.{$bd['id']}, ref.{$bd['name']}";

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Compare breakdown query failed');
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(int)$row['id']] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function zeroMetrics(): array
    {
        return [
            'total_clicks' => 0, 'total_click_throughs' => 0, 'total_leads' => 0,
            'total_income' => 0, 'total_cost' => 0, 'total_net' => 0,
            'epc' => 0, 'conv_rate' => 0, 'roi' => 0, 'cpa' => 0,
        ];
    }

    private function collectEntityFilters(array $params): array
    {
        $filters = [];
        foreach (['aff_campaign_id', 'aff_network_id', 'ppc_account_id', 'ppc_network_id', 'landing_page_id', 'country_id'] as $f) {
            if (!empty($params[$f])) {
                $filters[$f] = (int)$params[$f];
            }
        }
        return $filters;
    }

    private function applyEntityFiltersSql(array $filters, array &$where, array &$binds, string &$types): void
    {
        foreach ($filters as $col => $val) {
            $where[] = "de.$col = ?";
            $binds[] = $val;
            $types .= 'i';
        }
    }

    private function applyTimeFilters(array $params, array &$where, array &$binds, string &$types): void
    {
        if (!empty($params['time_from'])) {
            $where[] = 'de.click_time >= ?';
            $binds[] = (int)$params['time_from'];
            $types .= 'i';
        }
        if (!empty($params['time_to'])) {
            $where[] = 'de.click_time <= ?';
            $binds[] = (int)$params['time_to'];
            $types .= 'i';
        }
        if (!empty($params['period'])) {
            $now = time();
            $todayStart = strtotime('today midnight');
            [$from, $to] = match ($params['period']) {
                'today'     => [$todayStart, $now],
                'yesterday' => [$todayStart - 86400, $todayStart - 1],
                'last7'     => [$now - (7 * 86400), $now],
                'last30'    => [$now - (30 * 86400), $now],
                'last90'    => [$now - (90 * 86400), $now],
                default     => [0, $now],
            };
            $where[] = 'de.click_time >= ?';
            $binds[] = $from;
            $types .= 'i';
            $where[] = 'de.click_time <= ?';
            $binds[] = $to;
            $types .= 'i';
        }
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }
}
