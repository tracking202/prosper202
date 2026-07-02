#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * LTV maintenance cronjob. Run every 15-60 minutes.
 *
 * 1. Subscription lifecycle sweep: 'active' subscriptions past
 *    current_period_end + grace_days with no renewal become 'past_due';
 *    'past_due' subscriptions 30 days past the period end become 'canceled'.
 *    These windows are what makes churn a computable, documented number.
 *
 * 2. Rollup reconciliation: recomputes the cached customer rollups
 *    (order_count, total_revenue, refunded_amount, mrr,
 *    active_subscription_count) from the 202_revenue_events ledger and
 *    202_subscriptions — the sources of truth. The ingest path bumps these
 *    caches for freshness; this job makes any drift (crashes, bugs,
 *    backfills) self-healing instead of permanent.
 *
 * Ledger-derived definitions (must stay in sync with
 * MysqlCustomerRepository::applyEventToRollups):
 *   total_revenue   = SUM(amount) over all events (voids/refunds are negative)
 *   refunded_amount = SUM(-amount) over refund/chargeback events
 *   order_count     = COUNT(purchase|renewal|one_time)
 *                   - COUNT(adjustment with external_ref 'void:%')
 *   mrr / active_subscription_count = from subscriptions with status='active'
 *
 * Options:
 *   --full            reconcile every customer (default: customers active in
 *                     the last 48 hours)
 *   --batch-size=N    customers per UPDATE chunk (default 500)
 */

error_reporting(E_ALL);

include_once(str_repeat("../", 1) . '202-config/connect.php');

set_time_limit(0);

$isCli = PHP_SAPI === 'cli';
$options = $isCli ? getopt('', ['full', 'batch-size::']) : [];
$fullSweep = isset($options['full']);
$batchSize = isset($options['batch-size']) ? max(50, (int) $options['batch-size']) : 500;

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "ltv_maintenance: database connection unavailable\n");
    exit(1);
}

$now = time();

/**
 * Run a query, throwing on failure (CLAUDE.md: no unchecked query results).
 */
$run = function (string $sql) use ($db): mysqli_result|bool {
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException('ltv_maintenance query failed: ' . $db->error . ' -- ' . substr($sql, 0, 120));
    }
    return $result;
};

try {
    // ---------- 1. Subscription lifecycle sweep ----------
    // active -> past_due once the paid-through period plus grace has lapsed
    // with no renewal (a renewal event pushes current_period_end forward).
    $stmt = $db->prepare(
        "UPDATE 202_subscriptions
         SET status = 'past_due', updated_at = ?
         WHERE status = 'active' AND (current_period_end + grace_days * 86400) < ?"
    );
    if ($stmt === false) {
        throw new RuntimeException('prepare failed: ' . $db->error);
    }
    $stmt->bind_param('ii', $now, $now);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('past_due sweep failed: ' . $db->error);
    }
    $pastDue = $stmt->affected_rows;
    $stmt->close();

    // past_due -> canceled after a further 30-day recovery window.
    $stmt = $db->prepare(
        "UPDATE 202_subscriptions
         SET status = 'canceled', canceled_at = ?, updated_at = ?
         WHERE status = 'past_due' AND (current_period_end + (grace_days + 30) * 86400) < ?"
    );
    if ($stmt === false) {
        throw new RuntimeException('prepare failed: ' . $db->error);
    }
    $stmt->bind_param('iii', $now, $now, $now);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('cancel sweep failed: ' . $db->error);
    }
    $canceled = $stmt->affected_rows;
    $stmt->close();

    echo "subscription sweep: {$pastDue} -> past_due, {$canceled} -> canceled\n";

    // ---------- 2. Rollup reconciliation ----------
    // Chunk over customer_id so the UPDATE ... JOIN never scans the whole
    // ledger at once. Dirty window by default; --full sweeps everything.
    $dirtyCutoff = $now - 172800;
    $where = $fullSweep ? '1=1' : "last_activity_time >= {$dirtyCutoff}";
    $whereC = $fullSweep ? '1=1' : "c.last_activity_time >= {$dirtyCutoff}";

    $minMax = $run("SELECT MIN(customer_id) AS lo, MAX(customer_id) AS hi FROM 202_customers WHERE {$where}");
    $range = $minMax instanceof mysqli_result ? $minMax->fetch_assoc() : null;
    $lo = (int) ($range['lo'] ?? 0);
    $hi = (int) ($range['hi'] ?? 0);

    $reconciled = 0;
    for ($start = $lo; $start > 0 && $start <= $hi; $start += $batchSize) {
        $end = $start + $batchSize - 1;

        $sql = "UPDATE 202_customers c
            LEFT JOIN (
                SELECT customer_id,
                    SUM(amount) AS revenue,
                    SUM(CASE WHEN event_type IN ('refund','chargeback') THEN -amount ELSE 0 END) AS refunded,
                    SUM(CASE WHEN event_type IN ('purchase','renewal','one_time') THEN 1
                             WHEN event_type = 'adjustment' AND external_ref LIKE 'void:%' THEN -1
                             ELSE 0 END) AS orders
                FROM 202_revenue_events
                WHERE customer_id BETWEEN {$start} AND {$end}
                GROUP BY customer_id
            ) e ON e.customer_id = c.customer_id
            LEFT JOIN (
                SELECT customer_id,
                    SUM(CASE WHEN status = 'active' THEN mrr ELSE 0 END) AS mrr,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_subs
                FROM 202_subscriptions
                WHERE customer_id BETWEEN {$start} AND {$end}
                GROUP BY customer_id
            ) s ON s.customer_id = c.customer_id
            SET c.order_count = GREATEST(0, COALESCE(e.orders, 0)),
                c.total_revenue = COALESCE(e.revenue, 0),
                c.refunded_amount = GREATEST(0, COALESCE(e.refunded, 0)),
                c.mrr = COALESCE(s.mrr, 0),
                c.active_subscription_count = COALESCE(s.active_subs, 0),
                c.updated_at = {$now}
            WHERE c.customer_id BETWEEN {$start} AND {$end} AND {$whereC}";

        $run($sql);
        $reconciled += $db->affected_rows;
    }

    echo "rollup reconcile: {$reconciled} customer rows corrected"
        . ($fullSweep ? ' (full sweep)' : ' (48h dirty window)') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ltv_maintenance failed: ' . $e->getMessage() . "\n");
    error_log('ltv_maintenance failed: ' . $e->getMessage());
    exit(1);
}

echo "ltv_maintenance completed\n";
