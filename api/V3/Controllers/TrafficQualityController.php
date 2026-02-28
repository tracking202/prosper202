<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;

/**
 * Traffic quality analysis: bot rates, conversion rates, anomaly detection.
 */
class TrafficQualityController
{
    private const array BREAKDOWNS = [
        'campaign'     => ['table' => '202_aff_campaigns',      'id' => 'aff_campaign_id',  'name' => 'aff_campaign_name',  'de_id' => 'aff_campaign_id'],
        'ppc_account'  => ['table' => '202_ppc_accounts',       'id' => 'ppc_account_id',   'name' => 'ppc_account_name',   'de_id' => 'ppc_account_id'],
        'landing_page' => ['table' => '202_landing_pages',      'id' => 'landing_page_id',  'name' => 'landing_page_url',   'de_id' => 'landing_page_id'],
        'country'      => ['table' => '202_locations_country',  'id' => 'country_id',       'name' => 'country_name',       'de_id' => 'country_id'],
        'isp'          => ['table' => '202_locations_isp',       'id' => 'isp_id',           'name' => 'isp_name',           'de_id' => 'isp_id'],
        'device'       => ['table' => '202_device_models',       'id' => 'device_id',        'name' => 'device_name',        'de_id' => 'device_id'],
        'browser'      => ['table' => '202_browsers',           'id' => 'browser_id',       'name' => 'browser_name',       'de_id' => 'browser_id'],
        'platform'     => ['table' => '202_platforms',           'id' => 'platform_id',      'name' => 'platform_name',      'de_id' => 'platform_id'],
    ];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * Traffic quality scorecard: bot rate, conversion quality, cost efficiency by dimension.
     */
    public function quality(array $params): array
    {
        $breakdown = $params['breakdown'] ?? 'campaign';
        if (!isset(self::BREAKDOWNS[$breakdown])) {
            throw new ValidationException('Invalid breakdown', ['breakdown' => 'Valid: ' . implode(', ', array_keys(self::BREAKDOWNS))]);
        }

        $limit = max(1, min(200, (int)($params['limit'] ?? 50)));
        $minClicks = max(0, (int)($params['min_clicks'] ?? 10));

        $bd = self::BREAKDOWNS[$breakdown];

        $where = ['c.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        if (!empty($params['time_from'])) {
            $where[] = 'c.click_time >= ?';
            $binds[] = (int)$params['time_from'];
            $types .= 'i';
        }
        if (!empty($params['time_to'])) {
            $where[] = 'c.click_time <= ?';
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
            $where[] = 'c.click_time >= ?';
            $binds[] = $from;
            $types .= 'i';
            $where[] = 'c.click_time <= ?';
            $binds[] = $to;
            $types .= 'i';
        }

        foreach (['aff_campaign_id', 'ppc_account_id', 'landing_page_id'] as $f) {
            if (!empty($params[$f])) {
                $where[] = "c.$f = ?";
                $binds[] = (int)$params[$f];
                $types .= 'i';
            }
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // Query clicks table directly for bot detection stats
        $joinField = $bd['de_id'];
        $sql = "SELECT
                ref.{$bd['id']} as id,
                ref.{$bd['name']} as name,
                COUNT(*) as total_clicks,
                SUM(CASE WHEN c.click_bot = 1 THEN 1 ELSE 0 END) as bot_clicks,
                SUM(CASE WHEN c.click_filtered = 1 THEN 1 ELSE 0 END) as filtered_clicks,
                SUM(CASE WHEN c.click_lead = 1 THEN 1 ELSE 0 END) as conversions,
                SUM(CASE WHEN cr.click_out = 1 THEN 1 ELSE 0 END) as click_throughs,
                ROUND(SUM(CASE WHEN c.click_bot = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as bot_rate,
                ROUND(SUM(CASE WHEN c.click_filtered = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as filtered_rate,
                CASE WHEN SUM(CASE WHEN cr.click_out = 1 THEN 1 ELSE 0 END) > 0
                    THEN ROUND(SUM(CASE WHEN c.click_lead = 1 THEN 1 ELSE 0 END) / SUM(CASE WHEN cr.click_out = 1 THEN 1 ELSE 0 END) * 100, 2)
                    ELSE 0 END as conv_rate,
                COALESCE(SUM(c.click_payout), 0) as total_revenue,
                COALESCE(SUM(c.click_cpc), 0) as total_cost,
                CASE WHEN COUNT(*) > 0 THEN ROUND(COALESCE(SUM(c.click_payout), 0) / COUNT(*), 4) ELSE 0 END as epc
            FROM 202_clicks c
            LEFT JOIN 202_clicks_record cr ON c.click_id = cr.click_id
            INNER JOIN 202_clicks_advance ca ON c.click_id = ca.click_id
            INNER JOIN {$bd['table']} ref ON ca.{$joinField} = ref.{$bd['id']}
            $whereClause
            GROUP BY ref.{$bd['id']}, ref.{$bd['name']}
            HAVING COUNT(*) >= ?
            ORDER BY bot_rate DESC, total_clicks DESC
            LIMIT ?";

        $binds[] = $minClicks;
        $types .= 'i';
        $binds[] = $limit;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Traffic quality query failed');
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $quality = 'good';
            $botRate = (float)$row['bot_rate'];
            $filteredRate = (float)$row['filtered_rate'];
            if ($botRate > 30 || $filteredRate > 50) {
                $quality = 'poor';
            } elseif ($botRate > 10 || $filteredRate > 25) {
                $quality = 'fair';
            }
            $row['quality_rating'] = $quality;
            $rows[] = $row;
        }
        $stmt->close();

        return [
            'data'      => $rows,
            'breakdown' => $breakdown,
            'min_clicks' => $minClicks,
        ];
    }

    /**
     * Detect anomalies by comparing recent period against rolling average.
     */
    public function anomalies(array $params): array
    {
        $metric = $params['metric'] ?? 'total_cost';
        $validMetrics = ['total_clicks', 'total_leads', 'total_income', 'total_cost', 'total_net', 'conv_rate', 'epc', 'roi'];
        if (!in_array($metric, $validMetrics, true)) {
            throw new ValidationException('Invalid metric', ['metric' => 'Valid: ' . implode(', ', $validMetrics)]);
        }

        $threshold = max(1.0, min(5.0, (float)($params['threshold'] ?? 2.0)));
        $lookbackDays = max(7, min(90, (int)($params['lookback_days'] ?? 30)));
        $interval = $params['interval'] ?? 'day';
        if (!in_array($interval, ['hour', 'day'], true)) {
            $interval = 'day';
        }

        $now = time();
        $lookbackStart = $now - ($lookbackDays * 86400);

        $where = ['de.user_id = ?', 'de.click_time >= ?', 'de.click_time <= ?'];
        $binds = [$this->userId, $lookbackStart, $now];
        $types = 'iii';

        foreach (['aff_campaign_id', 'ppc_account_id'] as $f) {
            if (!empty($params[$f])) {
                $where[] = "de.$f = ?";
                $binds[] = (int)$params[$f];
                $types .= 'i';
            }
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $groupExpr = match ($interval) {
            'hour' => "FROM_UNIXTIME(de.click_time, '%Y-%m-%d %H:00')",
            'day'  => "FROM_UNIXTIME(de.click_time, '%Y-%m-%d')",
        };

        $metricExpr = match ($metric) {
            'total_clicks' => 'SUM(de.clicks)',
            'total_leads'  => 'SUM(de.leads)',
            'total_income' => 'SUM(de.income)',
            'total_cost'   => 'SUM(de.cost)',
            'total_net'    => 'SUM(de.income) - SUM(de.cost)',
            'conv_rate'    => 'CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END',
            'epc'          => 'CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END',
            'roi'          => 'CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END',
        };

        $sql = "SELECT
                $groupExpr as period,
                $metricExpr as metric_value,
                SUM(de.clicks) as total_clicks,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost
            FROM 202_dataengine de
            $whereClause
            GROUP BY period
            ORDER BY period ASC";

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Anomaly query failed');
        }
        $result = $stmt->get_result();
        $series = [];
        while ($row = $result->fetch_assoc()) {
            $series[] = $row;
        }
        $stmt->close();

        if (count($series) < 3) {
            return ['data' => ['anomalies' => [], 'series' => $series], 'metric' => $metric, 'threshold' => $threshold];
        }

        // Calculate rolling mean and standard deviation
        $values = array_map(fn($r) => (float)$r['metric_value'], $series);
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / count($values);
        $stddev = sqrt($variance);

        $anomalies = [];
        foreach ($series as $i => $row) {
            $val = (float)$row['metric_value'];
            if ($stddev > 0) {
                $zScore = ($val - $mean) / $stddev;
                if (abs($zScore) >= $threshold) {
                    $anomalies[] = [
                        'period'       => $row['period'],
                        'metric_value' => $val,
                        'mean'         => round($mean, 4),
                        'stddev'       => round($stddev, 4),
                        'z_score'      => round($zScore, 2),
                        'direction'    => $zScore > 0 ? 'above' : 'below',
                        'severity'     => abs($zScore) >= 3 ? 'critical' : 'warning',
                    ];
                }
            }
        }

        return [
            'data' => [
                'anomalies' => $anomalies,
                'stats'     => [
                    'mean'   => round($mean, 4),
                    'stddev' => round($stddev, 4),
                    'periods_analyzed' => count($series),
                ],
            ],
            'metric'    => $metric,
            'threshold' => $threshold,
            'interval'  => $interval,
        ];
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
