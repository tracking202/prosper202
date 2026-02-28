<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;

/**
 * A/B test analysis with statistical significance for rotator split tests.
 */
class TestAnalyzeController
{
    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * Analyze a rotator's split test variants for statistical significance.
     */
    public function analyze(int $rotatorId, array $params): array
    {
        $metric = $params['metric'] ?? 'conv_rate';
        $validMetrics = ['conv_rate', 'epc', 'roi'];
        if (!in_array($metric, $validMetrics, true)) {
            throw new ValidationException('Invalid metric', ['metric' => 'Valid: ' . implode(', ', $validMetrics)]);
        }

        $confidenceLevel = (float)($params['confidence_level'] ?? 0.95);
        if ($confidenceLevel < 0.8 || $confidenceLevel > 0.99) {
            throw new ValidationException('confidence_level must be 0.80-0.99', []);
        }

        // Verify rotator exists and belongs to user
        $stmt = $this->prepare(
            'SELECT r.rotator_id, r.rotator_name FROM 202_rotators r WHERE r.rotator_id = ? AND r.user_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $rotatorId, $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Rotator lookup failed');
        }
        $rotator = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$rotator) {
            throw new NotFoundException('Rotator not found');
        }

        // Get rules (variants) with their redirects
        $stmt = $this->prepare(
            'SELECT rr.rule_id, rr.rule_name, rr.rule_splittest
             FROM 202_rotator_rules rr
             WHERE rr.rotator_id = ?
             ORDER BY rr.rule_id ASC'
        );
        $stmt->bind_param('i', $rotatorId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Rules query failed');
        }
        $result = $stmt->get_result();
        $rules = [];
        while ($row = $result->fetch_assoc()) {
            $rules[] = $row;
        }
        $stmt->close();

        if (count($rules) < 2) {
            return [
                'data' => [
                    'rotator' => $rotator,
                    'error'   => 'Need at least 2 variants for A/B test analysis',
                ],
            ];
        }

        // Get performance for each rule
        $where = ['c.user_id = ?', 'c.rotator_id = ?'];
        $binds = [$this->userId, $rotatorId];
        $types = 'ii';

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

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                c.rule_id,
                COUNT(*) as total_clicks,
                SUM(CASE WHEN cr.click_out = 1 THEN 1 ELSE 0 END) as click_throughs,
                SUM(CASE WHEN c.click_lead = 1 THEN 1 ELSE 0 END) as conversions,
                COALESCE(SUM(c.click_payout), 0) as total_income,
                COALESCE(SUM(c.click_cpc), 0) as total_cost,
                COALESCE(SUM(c.click_payout), 0) - COALESCE(SUM(c.click_cpc), 0) as net_profit
            FROM 202_clicks c
            LEFT JOIN 202_clicks_record cr ON c.click_id = cr.click_id
            $whereClause
            GROUP BY c.rule_id";

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Test analysis query failed');
        }
        $result = $stmt->get_result();
        $ruleStats = [];
        while ($row = $result->fetch_assoc()) {
            $ruleStats[(int)$row['rule_id']] = $row;
        }
        $stmt->close();

        // Build variants with calculated metrics
        $variants = [];
        foreach ($rules as $rule) {
            $ruleId = (int)$rule['rule_id'];
            $stats = $ruleStats[$ruleId] ?? [
                'total_clicks' => 0, 'click_throughs' => 0, 'conversions' => 0,
                'total_income' => 0, 'total_cost' => 0, 'net_profit' => 0,
            ];

            $clicks = (int)$stats['total_clicks'];
            $clickThroughs = (int)$stats['click_throughs'];
            $conversions = (int)$stats['conversions'];
            $income = (float)$stats['total_income'];
            $cost = (float)$stats['total_cost'];

            $convRate = $clickThroughs > 0 ? $conversions / $clickThroughs : 0;
            $epc = $clicks > 0 ? $income / $clicks : 0;
            $roi = $cost > 0 ? ($income - $cost) / $cost * 100 : 0;

            $variants[] = [
                'rule_id'      => $ruleId,
                'rule_name'    => $rule['rule_name'],
                'total_clicks' => $clicks,
                'click_throughs' => $clickThroughs,
                'conversions'  => $conversions,
                'total_income' => round($income, 2),
                'total_cost'   => round($cost, 2),
                'net_profit'   => round((float)$stats['net_profit'], 2),
                'conv_rate'    => round($convRate * 100, 2),
                'epc'          => round($epc, 4),
                'roi'          => round($roi, 2),
            ];
        }

        // Statistical significance test (two-proportion z-test for conv_rate, Welch's t-test approximation for epc/roi)
        $comparisons = [];
        if (count($variants) >= 2) {
            // Find control (first variant) and challengers
            $control = $variants[0];
            for ($i = 1; $i < count($variants); $i++) {
                $challenger = $variants[$i];
                $result = $this->calculateSignificance($control, $challenger, $metric, $confidenceLevel);
                $comparisons[] = $result;
            }
        }

        // Determine winner
        $winner = null;
        $bestMetric = -INF;
        foreach ($variants as $v) {
            $metricKey = $metric === 'conv_rate' ? 'conv_rate' : $metric;
            if ($v[$metricKey] > $bestMetric) {
                $bestMetric = $v[$metricKey];
                $winner = $v;
            }
        }

        $allSignificant = !empty($comparisons) && count(array_filter($comparisons, fn($c) => $c['is_significant'])) === count($comparisons);

        return [
            'data' => [
                'rotator'      => $rotator,
                'variants'     => $variants,
                'comparisons'  => $comparisons,
                'winner'       => $winner ? [
                    'rule_id'        => $winner['rule_id'],
                    'rule_name'      => $winner['rule_name'],
                    'is_significant' => $allSignificant,
                    'metric_value'   => $winner[$metric === 'conv_rate' ? 'conv_rate' : $metric],
                ] : null,
                'recommendation' => $allSignificant && $winner
                    ? "Variant '{$winner['rule_name']}' is the statistically significant winner. Consider directing 100% traffic."
                    : 'No statistically significant winner yet. Continue testing.',
            ],
            'metric'           => $metric,
            'confidence_level' => $confidenceLevel,
        ];
    }

    private function calculateSignificance(array $control, array $challenger, string $metric, float $confidenceLevel): array
    {
        $zThreshold = $this->confidenceToZ($confidenceLevel);

        if ($metric === 'conv_rate') {
            // Two-proportion z-test
            $n1 = max(1, $control['click_throughs']);
            $n2 = max(1, $challenger['click_throughs']);
            $p1 = $control['conversions'] / $n1;
            $p2 = $challenger['conversions'] / $n2;
            $pPooled = ($control['conversions'] + $challenger['conversions']) / ($n1 + $n2);

            $se = sqrt($pPooled * (1 - $pPooled) * (1 / $n1 + 1 / $n2));
            $zStat = $se > 0 ? ($p2 - $p1) / $se : 0;
            $pValue = 2 * (1 - $this->normalCdf(abs($zStat)));
            $isSignificant = abs($zStat) >= $zThreshold;
            $lift = $p1 > 0 ? ($p2 - $p1) / $p1 * 100 : 0;

            // Sample size needed for significance (if not yet significant)
            $mde = abs($p2 - $p1);
            $sampleNeeded = $mde > 0
                ? (int)ceil(($zThreshold ** 2 * 2 * $pPooled * (1 - $pPooled)) / ($mde ** 2))
                : null;

        } else {
            // Approximate using per-click metrics
            $n1 = max(1, $control['total_clicks']);
            $n2 = max(1, $challenger['total_clicks']);
            $m1 = $metric === 'epc' ? $control['epc'] : $control['roi'];
            $m2 = $metric === 'epc' ? $challenger['epc'] : $challenger['roi'];

            // Approximate SE using metric variation (assume coefficient of variation ~ 1 for revenue metrics)
            $se1 = abs($m1) / sqrt($n1);
            $se2 = abs($m2) / sqrt($n2);
            $se = sqrt($se1 ** 2 + $se2 ** 2);

            $zStat = $se > 0 ? ($m2 - $m1) / $se : 0;
            $pValue = 2 * (1 - $this->normalCdf(abs($zStat)));
            $isSignificant = abs($zStat) >= $zThreshold;
            $lift = $m1 != 0 ? ($m2 - $m1) / abs($m1) * 100 : 0;
            $sampleNeeded = null;
        }

        return [
            'control_id'      => $control['rule_id'],
            'control_name'    => $control['rule_name'],
            'challenger_id'   => $challenger['rule_id'],
            'challenger_name' => $challenger['rule_name'],
            'z_statistic'     => round($zStat, 4),
            'p_value'         => round($pValue, 6),
            'is_significant'  => $isSignificant,
            'lift_pct'        => round($lift, 2),
            'winner'          => $isSignificant ? ($zStat > 0 ? 'challenger' : 'control') : 'none',
            'sample_size_needed' => $sampleNeeded,
        ];
    }

    private function confidenceToZ(float $confidence): float
    {
        return match (true) {
            $confidence >= 0.99 => 2.576,
            $confidence >= 0.95 => 1.96,
            $confidence >= 0.90 => 1.645,
            $confidence >= 0.85 => 1.44,
            default             => 1.28,
        };
    }

    /**
     * Approximate normal CDF using Abramowitz and Stegun formula.
     */
    private function normalCdf(float $x): float
    {
        $a1 = 0.254829592;
        $a2 = -0.284496736;
        $a3 = 1.421413741;
        $a4 = -1.453152027;
        $a5 = 1.061405429;
        $p = 0.3275911;

        $sign = $x < 0 ? -1 : 1;
        $x = abs($x) / sqrt(2);
        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return 0.5 * (1.0 + $sign * $y);
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
