<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;

/**
 * Optimization recommendations: budget allocation, dayparting, geo targeting.
 *
 * All methods return data-driven recommendations based on historical performance.
 * The LLM agent interprets and acts on these recommendations.
 */
class OptimizeController
{
    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * Budget allocation recommendations across campaigns.
     *
     * Ranks campaigns by efficiency (ROI, EPC) weighted by volume confidence
     * and recommends budget distribution for a given total budget.
     */
    public function budget(array $params): array
    {
        $totalBudget = (float)($params['total_budget'] ?? 0);
        if ($totalBudget <= 0) {
            throw new ValidationException('total_budget must be positive', ['total_budget' => 'Required positive number']);
        }

        $targetMetric = $params['target_metric'] ?? 'roi';
        if (!in_array($targetMetric, ['roi', 'epc', 'conv_rate', 'total_net'], true)) {
            throw new ValidationException('Invalid target_metric', ['target_metric' => 'Valid: roi, epc, conv_rate, total_net']);
        }

        $minClicks = max(1, (int)($params['min_clicks'] ?? 50));
        $lookbackDays = max(7, min(90, (int)($params['lookback_days'] ?? 30)));

        $now = time();
        $from = $now - ($lookbackDays * 86400);

        $where = ['de.user_id = ?', 'de.click_time >= ?', 'de.click_time <= ?'];
        $binds = [$this->userId, $from, $now];
        $types = 'iii';

        foreach (['ppc_account_id', 'ppc_network_id'] as $f) {
            if (!empty($params[$f])) {
                $where[] = "de.$f = ?";
                $binds[] = (int)$params[$f];
                $types .= 'i';
            }
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                c.aff_campaign_id as campaign_id,
                c.aff_campaign_name as campaign_name,
                SUM(de.clicks) as total_clicks,
                SUM(de.click_out) as total_click_throughs,
                SUM(de.leads) as total_leads,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as total_net,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.cost) / SUM(de.clicks) ELSE 0 END as avg_cpc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi,
                COUNT(DISTINCT FROM_UNIXTIME(de.click_time, '%Y-%m-%d')) as active_days
            FROM 202_dataengine de
            INNER JOIN 202_aff_campaigns c ON de.aff_campaign_id = c.aff_campaign_id
            $whereClause
            GROUP BY c.aff_campaign_id, c.aff_campaign_name
            HAVING SUM(de.clicks) >= ?
            ORDER BY $targetMetric DESC";

        $binds[] = $minClicks;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Budget optimization query failed');
        }
        $result = $stmt->get_result();
        $campaigns = [];
        while ($row = $result->fetch_assoc()) {
            $campaigns[] = $row;
        }
        $stmt->close();

        if (empty($campaigns)) {
            return ['data' => ['recommendations' => [], 'unallocated' => $totalBudget], 'total_budget' => $totalBudget];
        }

        // Score each campaign: weighted combination of target metric and volume
        $maxClicks = max(array_map(fn($c) => (float)$c['total_clicks'], $campaigns));
        $scored = [];
        foreach ($campaigns as $c) {
            $metricVal = (float)$c[$targetMetric];
            $volumeWeight = $maxClicks > 0 ? (float)$c['total_clicks'] / $maxClicks : 0;

            // Campaigns with negative ROI get zero or near-zero allocation
            if ($targetMetric === 'roi' && $metricVal < 0) {
                $score = 0;
            } else {
                $score = max(0, $metricVal) * (0.7 + 0.3 * $volumeWeight);
            }

            $c['score'] = $score;
            $scored[] = $c;
        }

        $totalScore = array_sum(array_map(fn($c) => $c['score'], $scored));
        $recommendations = [];
        $allocated = 0.0;

        foreach ($scored as $c) {
            $pct = $totalScore > 0 ? $c['score'] / $totalScore : 0;
            $amount = round($totalBudget * $pct, 2);
            $allocated += $amount;

            $avgCpc = (float)$c['avg_cpc'];
            $dailyBudget = $lookbackDays > 0 ? round($amount / $lookbackDays, 2) : $amount;

            $recommendations[] = [
                'campaign_id'     => (int)$c['campaign_id'],
                'campaign_name'   => $c['campaign_name'],
                'recommended_budget' => $amount,
                'budget_pct'      => round($pct * 100, 1),
                'daily_budget'    => $dailyBudget,
                'historical_roi'  => round((float)$c['roi'], 2),
                'historical_epc'  => round((float)$c['epc'], 4),
                'historical_conv_rate' => round((float)$c['conv_rate'], 2),
                'avg_cpc'         => round($avgCpc, 4),
                'estimated_clicks' => $avgCpc > 0 ? (int)floor($amount / $avgCpc) : 0,
                'active_days'     => (int)$c['active_days'],
                'score'           => round($c['score'], 4),
                'action'          => $c['score'] > 0 ? ($pct > 0.15 ? 'scale' : 'maintain') : 'pause_or_reduce',
            ];
        }

        usort($recommendations, fn($a, $b) => $b['recommended_budget'] <=> $a['recommended_budget']);

        return [
            'data' => [
                'recommendations' => $recommendations,
                'unallocated'     => round($totalBudget - $allocated, 2),
            ],
            'total_budget'  => $totalBudget,
            'target_metric' => $targetMetric,
            'lookback_days' => $lookbackDays,
        ];
    }

    /**
     * Dayparting recommendations: which hours to bid up/down based on ROI.
     */
    public function daypart(array $params): array
    {
        $lookbackDays = max(7, min(90, (int)($params['lookback_days'] ?? 30)));
        $targetRoi = (float)($params['target_roi'] ?? 0);

        $now = time();
        $from = $now - ($lookbackDays * 86400);

        $timezone = $this->resolveUserTimezone();

        $where = ['de.user_id = ?', 'de.click_time >= ?', 'de.click_time <= ?'];
        $binds = [$this->userId, $from, $now];
        $types = 'iii';

        foreach (['aff_campaign_id', 'ppc_account_id'] as $f) {
            if (!empty($params[$f])) {
                $where[] = "de.$f = ?";
                $binds[] = (int)$params[$f];
                $types .= 'i';
            }
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                COALESCE(
                    HOUR(CONVERT_TZ(FROM_UNIXTIME(de.click_time), '+00:00', ?)),
                    MOD(FLOOR(de.click_time / 3600), 24)
                ) as hour_of_day,
                SUM(de.clicks) as total_clicks,
                SUM(de.click_out) as total_click_throughs,
                SUM(de.leads) as total_leads,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as net_profit,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi,
                COUNT(DISTINCT FROM_UNIXTIME(de.click_time, '%Y-%m-%d')) as sample_days
            FROM 202_dataengine de
            $whereClause
            GROUP BY hour_of_day
            ORDER BY hour_of_day ASC";

        $stmt = $this->prepare($sql);
        $daypartBinds = array_merge([$timezone], $binds);
        $daypartTypes = 's' . $types;
        $stmt->bind_param($daypartTypes, ...$daypartBinds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Daypart optimization query failed');
        }
        $result = $stmt->get_result();

        $hours = [];
        $totalIncome = 0;
        $totalCost = 0;
        while ($row = $result->fetch_assoc()) {
            $totalIncome += (float)$row['total_income'];
            $totalCost += (float)$row['total_cost'];
            $hours[(int)$row['hour_of_day']] = $row;
        }
        $stmt->close();

        $avgRoi = $totalCost > 0 ? ($totalIncome - $totalCost) / $totalCost * 100 : 0;

        $recommendations = [];
        for ($h = 0; $h <= 23; $h++) {
            $row = $hours[$h] ?? null;
            if ($row === null) {
                $recommendations[] = [
                    'hour' => $h,
                    'total_clicks' => 0, 'total_leads' => 0,
                    'total_income' => 0, 'total_cost' => 0, 'net_profit' => 0,
                    'roi' => 0, 'epc' => 0, 'conv_rate' => 0, 'sample_days' => 0,
                    'action' => 'no_data', 'bid_modifier' => 1.0,
                ];
                continue;
            }

            $hourRoi = (float)$row['roi'];
            $hourNet = (float)$row['net_profit'];
            $sampleDays = (int)$row['sample_days'];
            $confidence = $sampleDays >= $lookbackDays * 0.7 ? 'high' : ($sampleDays >= $lookbackDays * 0.3 ? 'medium' : 'low');

            if ($hourRoi < $targetRoi && $hourNet < 0 && $confidence !== 'low') {
                $action = 'decrease';
                $modifier = max(0.1, min(0.8, 1 - abs($hourRoi - $avgRoi) / max(abs($avgRoi), 1) * 0.5));
            } elseif ($hourRoi > $avgRoi * 1.5 && $hourNet > 0) {
                $action = 'increase';
                $modifier = min(2.0, 1 + ($hourRoi - $avgRoi) / max(abs($avgRoi), 1) * 0.3);
            } else {
                $action = 'maintain';
                $modifier = 1.0;
            }

            $recommendations[] = [
                'hour'          => $h,
                'total_clicks'  => (int)$row['total_clicks'],
                'total_leads'   => (int)$row['total_leads'],
                'total_income'  => round((float)$row['total_income'], 2),
                'total_cost'    => round((float)$row['total_cost'], 2),
                'net_profit'    => round($hourNet, 2),
                'roi'           => round($hourRoi, 2),
                'epc'           => round((float)$row['epc'], 4),
                'conv_rate'     => round((float)$row['conv_rate'], 2),
                'sample_days'   => $sampleDays,
                'confidence'    => $confidence,
                'action'        => $action,
                'bid_modifier'  => round($modifier, 2),
            ];
        }

        return [
            'data' => [
                'hours'   => $recommendations,
                'summary' => [
                    'avg_roi'       => round($avgRoi, 2),
                    'target_roi'    => $targetRoi,
                    'increase_hours' => count(array_filter($recommendations, fn($r) => $r['action'] === 'increase')),
                    'decrease_hours' => count(array_filter($recommendations, fn($r) => $r['action'] === 'decrease')),
                    'maintain_hours' => count(array_filter($recommendations, fn($r) => $r['action'] === 'maintain')),
                ],
            ],
            'timezone'      => $timezone,
            'lookback_days' => $lookbackDays,
        ];
    }

    /**
     * Geo optimization: which countries/regions to target, exclude, or adjust.
     */
    public function geo(array $params): array
    {
        $level = $params['level'] ?? 'country';
        if (!in_array($level, ['country', 'region', 'city'], true)) {
            throw new ValidationException('Invalid level', ['level' => 'Valid: country, region, city']);
        }

        $lookbackDays = max(7, min(90, (int)($params['lookback_days'] ?? 30)));
        $minClicks = max(1, (int)($params['min_clicks'] ?? 20));
        $limit = max(1, min(200, (int)($params['limit'] ?? 50)));

        $now = time();
        $from = $now - ($lookbackDays * 86400);

        $geoConfig = match ($level) {
            'country' => ['table' => '202_locations_country', 'id' => 'country_id', 'name' => 'country_name', 'de_id' => 'country_id'],
            'region'  => ['table' => '202_locations_region',  'id' => 'region_id',  'name' => 'region_name',  'de_id' => 'region_id'],
            'city'    => ['table' => '202_locations_city',    'id' => 'city_id',    'name' => 'city_name',    'de_id' => 'city_id'],
        };

        $where = ['de.user_id = ?', 'de.click_time >= ?', 'de.click_time <= ?'];
        $binds = [$this->userId, $from, $now];
        $types = 'iii';

        foreach (['aff_campaign_id', 'ppc_account_id'] as $f) {
            if (!empty($params[$f])) {
                $where[] = "de.$f = ?";
                $binds[] = (int)$params[$f];
                $types .= 'i';
            }
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                ref.{$geoConfig['id']} as id,
                ref.{$geoConfig['name']} as name,
                SUM(de.clicks) as total_clicks,
                SUM(de.click_out) as total_click_throughs,
                SUM(de.leads) as total_leads,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as net_profit,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi
            FROM 202_dataengine de
            INNER JOIN {$geoConfig['table']} ref ON de.{$geoConfig['de_id']} = ref.{$geoConfig['id']}
            $whereClause
            GROUP BY ref.{$geoConfig['id']}, ref.{$geoConfig['name']}
            HAVING SUM(de.clicks) >= ?
            ORDER BY net_profit DESC
            LIMIT ?";

        $binds[] = $minClicks;
        $types .= 'i';
        $binds[] = $limit;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Geo optimization query failed');
        }
        $result = $stmt->get_result();

        $geos = [];
        while ($row = $result->fetch_assoc()) {
            $roi = (float)$row['roi'];
            $net = (float)$row['net_profit'];
            $clicks = (int)$row['total_clicks'];

            if ($roi > 50 && $net > 0) {
                $action = 'scale';
            } elseif ($roi > 0 && $net > 0) {
                $action = 'maintain';
            } elseif ($roi > -30 && $clicks >= $minClicks * 3) {
                $action = 'optimize';
            } else {
                $action = 'exclude';
            }

            $row['action'] = $action;
            $geos[] = $row;
        }
        $stmt->close();

        $summary = [
            'scale'    => count(array_filter($geos, fn($g) => $g['action'] === 'scale')),
            'maintain' => count(array_filter($geos, fn($g) => $g['action'] === 'maintain')),
            'optimize' => count(array_filter($geos, fn($g) => $g['action'] === 'optimize')),
            'exclude'  => count(array_filter($geos, fn($g) => $g['action'] === 'exclude')),
        ];

        return [
            'data' => [
                'geos'    => $geos,
                'summary' => $summary,
            ],
            'level'         => $level,
            'lookback_days' => $lookbackDays,
        ];
    }

    private function resolveUserTimezone(): string
    {
        $stmt = $this->prepare('SELECT user_timezone FROM 202_users WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return 'UTC';
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            return 'UTC';
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        $timezone = trim((string)($row['user_timezone'] ?? ''));
        if ($timezone === '') {
            return 'UTC';
        }
        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            return 'UTC';
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
