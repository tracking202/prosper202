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
            'spend_col' => 'aff_campaign_id',
        ],
        'ppc_account' => [
            'join' => 'INNER JOIN 202_clicks ck ON ck.click_id = c.first_click_id
                       INNER JOIN 202_ppc_accounts ref ON ref.ppc_account_id = ck.ppc_account_id',
            'id' => 'ref.ppc_account_id',
            'name' => 'ref.ppc_account_name',
            'spend_col' => 'ppc_account_id',
        ],
        'landing_page' => [
            'join' => 'INNER JOIN 202_clicks ck ON ck.click_id = c.first_click_id
                       INNER JOIN 202_landing_pages ref ON ref.landing_page_id = ck.landing_page_id',
            'id' => 'ref.landing_page_id',
            'name' => 'ref.landing_page_url',
            'spend_col' => 'landing_page_id',
        ],
    ];

    private const CUSTOMER_SORTS = [
        'total_revenue', 'order_count', 'last_activity_time', 'first_seen_time', 'mrr',
    ];

    /**
     * Actionable customer segments. Each is a self-contained WHERE fragment
     * against the `c` alias — code-owned SQL, never user input.
     */
    private const CUSTOMER_SEGMENTS = [
        'repeat' => 'c.order_count >= 2',
        'subscribers' => 'c.active_subscription_count > 0',
        'at_risk' => "EXISTS (SELECT 1 FROM 202_subscriptions s
                      WHERE s.customer_id = c.customer_id AND s.user_id = c.user_id
                        AND s.status = 'past_due')",
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

    public function customers(
        LtvQuery $query,
        string $sortBy,
        string $sortDir,
        int $limit,
        int $offset,
        ?string $search = null,
        ?string $segment = null
    ): array {
        if (!in_array($sortBy, self::CUSTOMER_SORTS, true)) {
            $sortBy = 'total_revenue';
        }
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        [$joins, $where, $types, $binds] = $this->buildCustomerScope($query);

        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            // Escape LIKE metacharacters so a literal "50%" searches for
            // "50%", then match ref, email, company or full name.
            $term = '%' . addcslashes($search, '%_\\') . '%';
            $where .= " AND (c.primary_ref LIKE ? OR c.email LIKE ? OR c.company LIKE ?
                        OR CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) LIKE ?)";
            $types .= 'ssss';
            array_push($binds, $term, $term, $term, $term);
        }

        $segment = $segment !== null ? trim($segment) : '';
        if ($segment !== '') {
            if (!isset(self::CUSTOMER_SEGMENTS[$segment])) {
                throw new RuntimeException(
                    'Invalid segment: ' . $segment . ' (expected ' . implode(', ', array_keys(self::CUSTOMER_SEGMENTS)) . ')'
                );
            }
            $where .= ' AND ' . self::CUSTOMER_SEGMENTS[$segment];
        }

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

        // Ad spend for the SAME dimension and window, from the click log —
        // this is what turns LTV into a scaling decision: cac = spend per
        // acquired customer, ltv_cac = revenue returned per ad dollar. The
        // spend subquery renders before the scope joins, so its binds come
        // first.
        $spendWhere = 'user_id = ?';
        $spendTypes = 'i';
        $spendBinds = [$query->userId];
        if ($query->timeFrom !== null) {
            $spendWhere .= ' AND click_time >= ?';
            $spendTypes .= 'i';
            $spendBinds[] = $query->timeFrom;
        }
        if ($query->timeTo !== null) {
            $spendWhere .= ' AND click_time <= ?';
            $spendTypes .= 'i';
            $spendBinds[] = $query->timeTo;
        }
        $spendJoin = "LEFT JOIN (
                SELECT {$bd['spend_col']} AS dim_id, SUM(click_cpc) AS spend
                FROM 202_clicks
                WHERE {$spendWhere}
                GROUP BY {$bd['spend_col']}
            ) sp ON sp.dim_id = {$bd['id']}";

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
                COALESCE(SUM(c.mrr), 0) AS mrr,
                COALESCE(sp.spend, 0) AS spend,
                CASE WHEN COUNT(*) > 0 THEN COALESCE(sp.spend, 0) / COUNT(*) ELSE 0 END AS cac,
                CASE WHEN COALESCE(sp.spend, 0) > 0 THEN SUM(c.total_revenue) / sp.spend ELSE 0 END AS ltv_cac
            FROM 202_customers c
            {$bd['join']}
            {$spendJoin}
            {$joins}
            {$where}
            GROUP BY {$bd['id']}, {$bd['name']}, sp.spend
            ORDER BY total_revenue DESC
            LIMIT ? OFFSET ?";

        $binds = array_merge($spendBinds, $binds, [$limit, $offset]);
        $types = $spendTypes . $types . 'ii';

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return $this->conn->fetchAll($stmt);
    }

    /**
     * LTV maturation by acquisition cohort: customers grouped by the month
     * they were first seen, with their lifetime revenue bucketed by months
     * since acquisition. Shows how fast LTV pays back — the number that
     * decides how aggressively acquisition can be financed.
     *
     * @return list<array<string, mixed>> newest cohort first; keys
     *         cohort_month (Y-m), customers, m0..m4, m5_plus,
     *         total_revenue, ltv_per_customer
     */
    public function cohorts(int $userId, int $months = 6, ?int $now = null): array
    {
        $months = max(1, min(24, $months));
        $now = $now ?? time();
        $windowStart = strtotime(date('Y-m-01', $now) . ' -' . ($months - 1) . ' months');

        // Bucket = whole months between acquisition and the revenue event
        // (clamped at 0: idempotent imports can carry occurred_at slightly
        // before first_seen). LEFT JOIN keeps zero-revenue cohorts visible.
        $bucket = 'GREATEST(TIMESTAMPDIFF(MONTH, FROM_UNIXTIME(c.first_seen_time), FROM_UNIXTIME(re.occurred_at)), 0)';
        $stmt = $this->conn->prepareRead(
            "SELECT DATE_FORMAT(FROM_UNIXTIME(c.first_seen_time), '%Y-%m') AS cohort_month,
                    COUNT(DISTINCT c.customer_id) AS customers,
                    COALESCE(SUM(CASE WHEN {$bucket} = 0 THEN re.amount END), 0) AS m0,
                    COALESCE(SUM(CASE WHEN {$bucket} = 1 THEN re.amount END), 0) AS m1,
                    COALESCE(SUM(CASE WHEN {$bucket} = 2 THEN re.amount END), 0) AS m2,
                    COALESCE(SUM(CASE WHEN {$bucket} = 3 THEN re.amount END), 0) AS m3,
                    COALESCE(SUM(CASE WHEN {$bucket} = 4 THEN re.amount END), 0) AS m4,
                    COALESCE(SUM(CASE WHEN {$bucket} >= 5 THEN re.amount END), 0) AS m5_plus,
                    COALESCE(SUM(re.amount), 0) AS total_revenue
             FROM 202_customers c
             LEFT JOIN 202_revenue_events re
                    ON re.customer_id = c.customer_id AND re.user_id = c.user_id
             WHERE c.user_id = ? AND c.merged_into_customer_id IS NULL
               AND c.first_seen_time >= ?
             GROUP BY cohort_month
             ORDER BY cohort_month DESC"
        );
        $this->conn->bind($stmt, 'ii', [$userId, (int) $windowStart]);

        $rows = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $customers = (int) $row['customers'];
            $row['ltv_per_customer'] = $customers > 0
                ? round(((float) $row['total_revenue']) / $customers, 5)
                : 0.0;
            $rows[] = $row;
        }

        return $rows;
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

    /**
     * Account projection from ALREADY-COMPUTED aggregates — for callers (the
     * dashboard) that just ran summary() and mrr() and must not pay for the
     * same aggregate queries twice in one render.
     *
     * @param array<string, mixed> $summary a summary() result
     * @param array<string, mixed> $mrr an mrr() result
     * @return array<string, mixed>
     */
    public function predictFromComputed(array $summary, array $mrr): array
    {
        // The subscriber pool must use the SUMMARY's scoped MRR (the sum over
        // the queried customers) with account churn — a narrow acquisition
        // window with no subscribers must not display the whole account's
        // pool value just because the churn inputs are account-wide.
        return $this->predictFromAggregates($summary, [
            'mrr' => (float) ($summary['mrr'] ?? 0),
            'monthly_churn_rate' => (float) ($mrr['monthly_churn_rate'] ?? 0),
        ], 'account');
    }

    public function predict(LtvQuery $query, ?string $breakdownType = null): array
    {
        $accountMrr = $this->mrr($query->userId);
        $account = $this->predictFromComputed($this->summary($query), $accountMrr);

        if ($breakdownType === null) {
            return $account;
        }

        $rows = [];
        foreach ($this->breakdown($query, $breakdownType, 100, 0) as $row) {
            $customers = (int) ($row['customers'] ?? 0);
            // Defensive net: every breakdown now supplies aov/repeat_rate/mrr
            // (product included), but a future breakdown that doesn't must fall
            // back to the account projection rather than project a bogus 0.0.
            $hasProjectionInputs = array_key_exists('aov', $row) && array_key_exists('repeat_rate', $row);
            if (!$hasProjectionInputs) {
                $prediction = $account;
                $prediction['basis'] = 'account_fallback';
                $prediction['fallback_reason'] = "'{$breakdownType}' breakdown does not supply per-cohort aov/repeat_rate";
                $rows[] = [
                    'id' => $row['id'] ?? null,
                    'name' => $row['name'] ?? null,
                    'customers' => $customers,
                    'prediction' => $prediction,
                ];
                continue;
            }
            if ($customers >= self::MIN_COHORT_SIZE) {
                // Cohort projection: the COHORT's own MRR (a campaign with no
                // subscribers must not display the account-wide subscriber
                // pool) combined with account churn — per-dimension churn is
                // not tracked.
                $prediction = $this->predictFromAggregates(
                    $row,
                    [
                        'mrr' => (float) ($row['mrr'] ?? 0),
                        'monthly_churn_rate' => (float) ($accountMrr['monthly_churn_rate'] ?? 0),
                    ],
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
        // Custom-field filters describe a CUSTOMER cohort; when present the
        // line items must be scoped to that cohort's (not-merged) customers,
        // not summed account-wide. Joins render before the WHERE clause, so
        // their binds come first.
        [$cfJoins, $cfTypes, $cfBinds] = $this->customFieldJoins($query);
        $customerJoin = $cfJoins !== ''
            ? "\n            INNER JOIN 202_customers c ON c.customer_id = re.customer_id AND c.user_id = li.user_id AND c.merged_into_customer_id IS NULL" . $cfJoins
            : '';

        $pcWhere = ['li.user_id = ?', 'li.product_id IS NOT NULL'];
        $pcTypes = $cfTypes . 'i';
        $pcBinds = array_merge($cfBinds, [$query->userId]);
        if ($query->timeFrom !== null) {
            $pcWhere[] = 're.occurred_at >= ?';
            $pcTypes .= 'i';
            $pcBinds[] = $query->timeFrom;
        }
        if ($query->timeTo !== null) {
            $pcWhere[] = 're.occurred_at <= ?';
            $pcTypes .= 'i';
            $pcBinds[] = $query->timeTo;
        }
        $pcWhereClause = 'WHERE ' . implode(' AND ', $pcWhere);

        // Per-product subscriber MRR. Subscriptions carry no product_id, so a
        // subscription's MRR is attributed to the product(s) it bills for (the
        // products on its events' line items), split EVENLY across the distinct
        // products of a multi-product (bundle) subscription. This is additive —
        // summing product mrr over all products reconciles to total active MRR
        // for subscriptions that have at least one product line item (a
        // subscription with no product line items cannot be attributed and is
        // omitted). Active subscriptions only, current-state (no report-window
        // filter), matching mrr() and the acquisition breakdown's SUM(c.mrr).
        // One placeholder: user_id.
        $mrrSubquery = "LEFT JOIN (
                    WITH sub_prod AS (
                        SELECT DISTINCT s.subscription_id, s.mrr, li_s.product_id
                        FROM 202_subscriptions s
                        INNER JOIN 202_revenue_events re_s
                            ON re_s.subscription_id = s.subscription_id AND re_s.user_id = s.user_id
                        INNER JOIN 202_revenue_line_items li_s
                            ON li_s.event_id = re_s.event_id AND li_s.product_id IS NOT NULL
                        WHERE s.user_id = ? AND s.status = 'active'
                    )
                    SELECT sp.product_id, SUM(sp.mrr / cnt.n) AS mrr
                    FROM sub_prod sp
                    INNER JOIN (
                        SELECT subscription_id, COUNT(*) AS n FROM sub_prod GROUP BY subscription_id
                    ) cnt ON cnt.subscription_id = sp.subscription_id
                    GROUP BY sp.product_id
                ) pm ON pm.product_id = pc.product_id";

        // pc is one row per (product, customer): grouping the events by
        // re.customer_id yields the per-customer distinct-order count that
        // repeat_rate needs. A merged customer's events were repointed to the
        // survivor at merge time, so the stored customer_id is already correct.
        $sql = "SELECT
                pc.product_id AS id,
                COALESCE(p.name, p.sku, p.external_product_id) AS name,
                p.sku,
                COUNT(*) AS customers,
                COALESCE(SUM(pc.cust_orders), 0) AS orders,
                COALESCE(SUM(pc.cust_units), 0) AS units,
                COALESCE(SUM(pc.cust_revenue), 0) AS total_revenue,
                CASE WHEN COUNT(*) > 0 THEN SUM(pc.cust_revenue) / COUNT(*) ELSE 0 END AS avg_revenue_per_customer,
                CASE WHEN SUM(pc.cust_orders) > 0 THEN SUM(pc.cust_revenue) / SUM(pc.cust_orders) ELSE 0 END AS aov,
                CASE WHEN SUM(CASE WHEN pc.cust_orders >= 1 THEN 1 ELSE 0 END) > 0
                     THEN SUM(CASE WHEN pc.cust_orders >= 2 THEN 1 ELSE 0 END)
                          / SUM(CASE WHEN pc.cust_orders >= 1 THEN 1 ELSE 0 END)
                     ELSE 0 END AS repeat_rate,
                COALESCE(MAX(pm.mrr), 0) AS mrr
            FROM (
                SELECT
                    li.product_id,
                    re.customer_id,
                    COUNT(DISTINCT CASE WHEN re.event_type IN ('purchase','renewal','one_time')
                                        THEN re.event_id END) AS cust_orders,
                    SUM(li.quantity) AS cust_units,
                    SUM(li.amount) AS cust_revenue
                FROM 202_revenue_line_items li
                INNER JOIN 202_revenue_events re ON re.event_id = li.event_id{$customerJoin}
                {$pcWhereClause}
                GROUP BY li.product_id, re.customer_id
            ) pc
            INNER JOIN 202_products p ON p.product_id = pc.product_id
            {$mrrSubquery}
            GROUP BY pc.product_id, name, p.sku
            ORDER BY total_revenue DESC
            LIMIT ? OFFSET ?";

        // Bind order follows SQL text: pc's cf-join + WHERE binds, then the MRR
        // subquery's user_id, then LIMIT/OFFSET.
        $binds = array_merge($pcBinds, [$query->userId, $limit, $offset]);
        $types = $pcTypes . 'iii';

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
        [$joins, $joinTypes, $joinBinds] = $this->customFieldJoins($query);

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

    /**
     * The up-to-3 custom-field filter joins against the `c` customers alias.
     * column/op are validated against allowlists in LtvQuery; only the value
     * is bound.
     *
     * @return array{0: string, 1: string, 2: list<mixed>} join SQL, bind types, binds
     */
    private function customFieldJoins(LtvQuery $query): array
    {
        $joins = '';
        $types = '';
        $binds = [];
        foreach ($query->customFieldFilters as $i => $filter) {
            $alias = 'cfv' . $i;
            $joins .= " INNER JOIN 202_customer_field_values {$alias}
                ON {$alias}.customer_id = c.customer_id AND {$alias}.field_id = ?
                AND {$alias}.{$filter['column']} {$filter['op']} ?";
            $types .= 'i' . ($filter['column'] === 'value_text' ? 's' : 'd');
            $binds[] = (int) $filter['fieldId'];
            $binds[] = $filter['column'] === 'value_text' ? (string) $filter['value'] : (float) $filter['value'];
        }

        return [$joins, $types, $binds];
    }
}
