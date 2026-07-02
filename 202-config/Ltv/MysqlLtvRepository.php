<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * LTV analytics reads, mirroring the Report/ layer conventions. Customer
 * views read the cached rollups on 202_customers (reconciled from the
 * 202_revenue_events ledger by ltv_maintenance); product breakdowns read the
 * ledger line items directly.
 */
final class MysqlLtvRepository implements LtvRepositoryInterface
{
    /**
     * Acquisition breakdowns join the customer's first click to a dimension
     * table. All columns here are code-owned constants, never user input.
     */
    private const ACQUISITION_BREAKDOWNS = [
        'campaign' => [
            'join' => 'INNER JOIN 202_clicks ck ON ck.click_id = c.first_click_id
                       INNER JOIN 202_aff_campaigns ref ON ref.aff_campaign_id = ck.aff_campaign_id',
            'id' => 'ref.aff_campaign_id',
            'name' => 'ref.aff_campaign_name',
        ],
        'ppc_account' => [
            'join' => 'INNER JOIN 202_clicks ck ON ck.click_id = c.first_click_id
                       INNER JOIN 202_ppc_accounts ref ON ref.ppc_account_id = ck.ppc_account_id',
            'id' => 'ref.ppc_account_id',
            'name' => 'ref.ppc_account_name',
        ],
        'landing_page' => [
            'join' => 'INNER JOIN 202_clicks ck ON ck.click_id = c.first_click_id
                       INNER JOIN 202_landing_pages ref ON ref.landing_page_id = ck.landing_page_id',
            'id' => 'ref.landing_page_id',
            'name' => 'ref.landing_page_url',
        ],
    ];

    private const CUSTOMER_SORTS = [
        'total_revenue', 'order_count', 'last_activity_time', 'first_seen_time', 'mrr',
    ];

    /** predict() guards — documented in every response. */
    private const CHURN_FLOOR_MONTHLY = 0.01;
    private const SUBSCRIBER_LTV_CAP_MONTHS = 60;
    private const REPEAT_RATE_CAP = 0.95;
    private const MIN_COHORT_SIZE = 20;

    public function __construct(private Connection $conn)
    {
    }

    public function summary(LtvQuery $query): array
    {
        [$joins, $where, $types, $binds] = $this->buildCustomerScope($query);

        $sql = "SELECT
                COUNT(*) AS customers,
                COALESCE(SUM(c.total_revenue), 0) AS total_revenue,
                COALESCE(SUM(c.refunded_amount), 0) AS refunded_amount,
                COALESCE(SUM(c.order_count), 0) AS total_orders,
                COALESCE(AVG(c.total_revenue), 0) AS avg_ltv,
                CASE WHEN SUM(c.order_count) > 0 THEN SUM(c.total_revenue) / SUM(c.order_count) ELSE 0 END AS aov,
                SUM(CASE WHEN c.order_count >= 2 THEN 1 ELSE 0 END) AS repeat_customers,
                SUM(CASE WHEN c.order_count >= 1 THEN 1 ELSE 0 END) AS purchasing_customers,
                CASE WHEN SUM(CASE WHEN c.order_count >= 1 THEN 1 ELSE 0 END) > 0
                     THEN SUM(CASE WHEN c.order_count >= 2 THEN 1 ELSE 0 END) / SUM(CASE WHEN c.order_count >= 1 THEN 1 ELSE 0 END)
                     ELSE 0 END AS repeat_rate,
                COALESCE(SUM(c.mrr), 0) AS mrr,
                COALESCE(SUM(c.active_subscription_count), 0) AS active_subscriptions
            FROM 202_customers c
            {$joins}
            {$where}";

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);
        $row = $this->conn->fetchOne($stmt);

        return $row ?? [];
    }

    public function customers(LtvQuery $query, string $sortBy, string $sortDir, int $limit, int $offset): array
    {
        if (!in_array($sortBy, self::CUSTOMER_SORTS, true)) {
            $sortBy = 'total_revenue';
        }
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        [$joins, $where, $types, $binds] = $this->buildCustomerScope($query);

        $countStmt = $this->conn->prepareRead("SELECT COUNT(*) AS total FROM 202_customers c {$joins} {$where}");
        $this->conn->bind($countStmt, $types, $binds);
        $total = (int) ($this->conn->fetchOne($countStmt)['total'] ?? 0);

        $sql = "SELECT
                c.customer_id, c.primary_ref, c.first_name, c.last_name, c.email, c.company,
                c.city, c.region, c.country, c.status,
                c.first_seen_time, c.last_activity_time, c.first_click_id,
                c.order_count, c.total_revenue, c.refunded_amount,
                c.active_subscription_count, c.mrr
            FROM 202_customers c
            {$joins}
            {$where}
            ORDER BY c.{$sortBy} {$sortDir}, c.customer_id DESC
            LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $binds[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return ['rows' => $this->conn->fetchAll($stmt), 'total' => $total];
    }

    public function breakdown(LtvQuery $query, string $breakdownType, int $limit, int $offset): array
    {
        if ($breakdownType === 'product') {
            return $this->productBreakdown($query, $limit, $offset);
        }
        if (!isset(self::ACQUISITION_BREAKDOWNS[$breakdownType])) {
            throw new RuntimeException(
                'Invalid breakdown type: ' . $breakdownType . ' (expected campaign, ppc_account, landing_page or product)'
            );
        }
        $bd = self::ACQUISITION_BREAKDOWNS[$breakdownType];

        [$joins, $where, $types, $binds] = $this->buildCustomerScope($query);

        $sql = "SELECT
                {$bd['id']} AS id,
                {$bd['name']} AS name,
                COUNT(*) AS customers,
                COALESCE(SUM(c.total_revenue), 0) AS total_revenue,
                COALESCE(AVG(c.total_revenue), 0) AS avg_ltv,
                COALESCE(SUM(c.order_count), 0) AS total_orders,
                CASE WHEN SUM(c.order_count) > 0 THEN SUM(c.total_revenue) / SUM(c.order_count) ELSE 0 END AS aov,
                CASE WHEN SUM(CASE WHEN c.order_count >= 1 THEN 1 ELSE 0 END) > 0
                     THEN SUM(CASE WHEN c.order_count >= 2 THEN 1 ELSE 0 END) / SUM(CASE WHEN c.order_count >= 1 THEN 1 ELSE 0 END)
                     ELSE 0 END AS repeat_rate,
                COALESCE(SUM(c.mrr), 0) AS mrr
            FROM 202_customers c
            {$bd['join']}
            {$joins}
            {$where}
            GROUP BY {$bd['id']}, {$bd['name']}
            ORDER BY total_revenue DESC
            LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $binds[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return $this->conn->fetchAll($stmt);
    }

    public function mrr(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'active' THEN mrr ELSE 0 END), 0) AS mrr,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = 'trialing' THEN 1 ELSE 0 END) AS trialing,
                SUM(CASE WHEN status = 'past_due' THEN 1 ELSE 0 END) AS past_due,
                SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) AS paused,
                SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END) AS canceled_total,
                SUM(CASE WHEN status = 'canceled' AND canceled_at >= ? THEN 1 ELSE 0 END) AS canceled_90d
             FROM 202_subscriptions
             WHERE user_id = ?"
        );
        $windowStart = time() - 90 * 86400;
        $this->conn->bind($stmt, 'ii', [$windowStart, $userId]);
        $row = $this->conn->fetchOne($stmt) ?? [];

        $active = (int) ($row['active'] ?? 0);
        $canceled90 = (int) ($row['canceled_90d'] ?? 0);
        $mrr = (float) ($row['mrr'] ?? 0);

        // Documented churn definition: subscriptions canceled in the trailing
        // 90 days, divided by the population that was at risk over that window
        // (currently active + those that canceled), normalized to monthly.
        $atRisk = $active + $canceled90;
        $monthlyChurn = $atRisk > 0 ? ($canceled90 / 3) / $atRisk : 0.0;

        return [
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'active_subscriptions' => $active,
            'trialing' => (int) ($row['trialing'] ?? 0),
            'past_due' => (int) ($row['past_due'] ?? 0),
            'paused' => (int) ($row['paused'] ?? 0),
            'canceled_total' => (int) ($row['canceled_total'] ?? 0),
            'monthly_churn_rate' => round($monthlyChurn, 6),
            'churn_inputs' => [
                'window_days' => 90,
                'canceled_in_window' => $canceled90,
                'at_risk_population' => $atRisk,
            ],
        ];
    }

    public function predict(LtvQuery $query, ?string $breakdownType = null): array
    {
        $account = $this->predictFromAggregates(
            $this->summary($query),
            $this->mrr($query->userId),
            'account'
        );

        if ($breakdownType === null) {
            return $account;
        }

        $rows = [];
        foreach ($this->breakdown($query, $breakdownType, 100, 0) as $row) {
            $customers = (int) ($row['customers'] ?? 0);
            if ($customers >= self::MIN_COHORT_SIZE) {
                // Cohort MRR churn is not tracked per dimension; the cohort
                // projection uses cohort AOV/repeat-rate with account churn.
                $prediction = $this->predictFromAggregates(
                    $row,
                    $this->mrr($query->userId),
                    'cohort'
                );
            } else {
                $prediction = $account;
                $prediction['basis'] = 'account_fallback';
                $prediction['fallback_reason'] = "cohort has {$customers} customers (< " . self::MIN_COHORT_SIZE . ')';
            }
            $rows[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'customers' => $customers,
                'prediction' => $prediction,
            ];
        }

        return ['account' => $account, 'breakdown' => $rows];
    }

    /**
     * Deterministic projection from realized aggregates:
     *   non-subscriber LTV = aov * 1 / (1 - min(repeat_rate, 0.95))
     *   subscriber LTV     = mrr / max(monthly_churn, 1%), capped at 60x MRR
     * Every output carries its inputs and which caps applied.
     *
     * @param array<string, mixed> $aggregates summary()/breakdown() row
     * @param array<string, mixed> $mrrData mrr() result
     * @return array<string, mixed>
     */
    private function predictFromAggregates(array $aggregates, array $mrrData, string $basis): array
    {
        $customers = (int) ($aggregates['customers'] ?? 0);
        $aov = (float) ($aggregates['aov'] ?? 0);
        $repeatRate = (float) ($aggregates['repeat_rate'] ?? 0);
        $mrr = (float) ($mrrData['mrr'] ?? 0);
        $churn = (float) ($mrrData['monthly_churn_rate'] ?? 0);

        $caps = [];

        $cappedRepeat = min($repeatRate, self::REPEAT_RATE_CAP);
        if ($repeatRate > self::REPEAT_RATE_CAP) {
            $caps[] = 'repeat_rate_capped_at_0.95';
        }
        // Geometric repeat-purchase projection: expected orders = 1/(1-r).
        $nonSubscriberLtv = $aov > 0 ? $aov / (1 - $cappedRepeat) : 0.0;

        $flooredChurn = max($churn, self::CHURN_FLOOR_MONTHLY);
        if ($churn < self::CHURN_FLOOR_MONTHLY) {
            $caps[] = 'churn_floored_at_1pct_monthly';
        }
        $subscriberLtv = $mrr > 0 ? $mrr / $flooredChurn : 0.0;
        $subscriberCap = $mrr * self::SUBSCRIBER_LTV_CAP_MONTHS;
        if ($subscriberLtv > $subscriberCap) {
            $subscriberLtv = $subscriberCap;
            $caps[] = 'subscriber_ltv_capped_at_60_months_mrr';
        }

        if ($customers > 0 && $customers < self::MIN_COHORT_SIZE) {
            $caps[] = 'low_sample_size';
        }

        return [
            'basis' => $basis,
            'predicted_ltv_per_customer' => round($nonSubscriberLtv, 5),
            'predicted_subscriber_pool_value' => round($subscriberLtv, 5),
            'inputs' => [
                'customers' => $customers,
                'aov' => round($aov, 5),
                'repeat_rate' => round($repeatRate, 6),
                'mrr' => round($mrr, 5),
                'monthly_churn_rate' => round($churn, 6),
            ],
            'caps_applied' => $caps,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productBreakdown(LtvQuery $query, int $limit, int $offset): array
    {
        $where = ['li.user_id = ?'];
        $types = 'i';
        $binds = [$query->userId];
        if ($query->timeFrom !== null) {
            $where[] = 're.occurred_at >= ?';
            $types .= 'i';
            $binds[] = $query->timeFrom;
        }
        if ($query->timeTo !== null) {
            $where[] = 're.occurred_at <= ?';
            $types .= 'i';
            $binds[] = $query->timeTo;
        }
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                p.product_id AS id,
                COALESCE(p.name, p.sku, p.external_product_id) AS name,
                p.sku,
                COUNT(DISTINCT re.customer_id) AS customers,
                COUNT(DISTINCT re.event_id) AS orders,
                COALESCE(SUM(li.quantity), 0) AS units,
                COALESCE(SUM(li.amount), 0) AS total_revenue,
                CASE WHEN COUNT(DISTINCT re.customer_id) > 0
                     THEN SUM(li.amount) / COUNT(DISTINCT re.customer_id)
                     ELSE 0 END AS avg_revenue_per_customer
            FROM 202_revenue_line_items li
            INNER JOIN 202_revenue_events re ON re.event_id = li.event_id
            INNER JOIN 202_products p ON p.product_id = li.product_id
            {$whereClause}
            GROUP BY p.product_id, name, p.sku
            ORDER BY total_revenue DESC
            LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $binds[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return $this->conn->fetchAll($stmt);
    }

    /**
     * Shared customer scoping: account + not-merged + acquisition window +
     * up to 3 custom-field filter joins.
     *
     * @return array{0: string, 1: string, 2: string, 3: list<mixed>} joins, where, types, binds
     */
    private function buildCustomerScope(LtvQuery $query): array
    {
        // Placeholders bind in SQL text order: the custom-field JOINs render
        // before the WHERE clause, so their params must come first.
        $joins = '';
        $joinTypes = '';
        $joinBinds = [];
        foreach ($query->customFieldFilters as $i => $filter) {
            // column/op are validated against allowlists in LtvQuery; only the
            // value is bound.
            $alias = 'cfv' . $i;
            $joins .= " INNER JOIN 202_customer_field_values {$alias}
                ON {$alias}.customer_id = c.customer_id AND {$alias}.field_id = ?
                AND {$alias}.{$filter['column']} {$filter['op']} ?";
            $joinTypes .= 'i' . ($filter['column'] === 'value_text' ? 's' : 'd');
            $joinBinds[] = (int) $filter['fieldId'];
            $joinBinds[] = $filter['column'] === 'value_text' ? (string) $filter['value'] : (float) $filter['value'];
        }

        $where = ['c.user_id = ?', 'c.merged_into_customer_id IS NULL'];
        $whereTypes = 'i';
        $whereBinds = [$query->userId];
        if ($query->timeFrom !== null) {
            $where[] = 'c.first_seen_time >= ?';
            $whereTypes .= 'i';
            $whereBinds[] = $query->timeFrom;
        }
        if ($query->timeTo !== null) {
            $where[] = 'c.first_seen_time <= ?';
            $whereTypes .= 'i';
            $whereBinds[] = $query->timeTo;
        }

        return [
            $joins,
            'WHERE ' . implode(' AND ', $where),
            $joinTypes . $whereTypes,
            array_merge($joinBinds, $whereBinds),
        ];
    }
}
