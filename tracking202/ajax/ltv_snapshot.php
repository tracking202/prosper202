<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

/**
 * Compact all-time customer-LTV strip for the account overview page. Renders
 * nothing when the LTV schema is missing or no customers are tracked yet, so
 * the overview stays clean for accounts not using the feature.
 */

$userId = (int) $_SESSION['user_id'];
$money = static fn (mixed $v): string => number_format((float) $v, 2);

try {
    $conn = new \Prosper202\Database\Connection($db);
    $ltv = new \Prosper202\Ltv\MysqlLtvRepository($conn);

    $summary = $ltv->summary(new \Prosper202\Ltv\LtvQuery($userId));
    $mrr = $ltv->mrr($userId);
} catch (\Throwable $e) {
    error_log('ltv_snapshot: ' . $e->getMessage());
    return;
}

if ((int) ($summary['customers'] ?? 0) === 0) {
    return;
}
?>
<div class="row" style="margin-bottom: 15px;">
    <div class="col-xs-12">
        <table class="table table-bordered" style="margin-bottom: 5px;">
            <thead>
                <tr>
                    <th colspan="6">
                        Customer Lifetime Value <small>(all time)</small>
                        <span class="pull-right">
                            <a href="<?php echo get_absolute_url(); ?>tracking202/analyze/ltv.php">Full report &raquo;</a>
                        </span>
                    </th>
                </tr>
                <tr>
                    <th>Customers</th>
                    <th>Revenue</th>
                    <th>Avg LTV</th>
                    <th>Repeat Rate</th>
                    <th>MRR</th>
                    <th>Monthly Churn</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo number_format((int) ($summary['customers'] ?? 0)); ?></td>
                    <td>$<?php echo $money($summary['total_revenue'] ?? 0); ?></td>
                    <td>$<?php echo $money($summary['avg_ltv'] ?? 0); ?></td>
                    <td><?php echo number_format(((float) ($summary['repeat_rate'] ?? 0)) * 100, 1); ?>%</td>
                    <td>$<?php echo $money($mrr['mrr'] ?? 0); ?></td>
                    <td><?php echo number_format(((float) ($mrr['monthly_churn_rate'] ?? 0)) * 100, 2); ?>%</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
