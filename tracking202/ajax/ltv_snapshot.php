<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

/**
 * Compact all-time customer-LTV strip for the account overview page, as a
 * panel of tiles on the v2 shell. Renders nothing when the LTV schema is
 * missing or no customers are tracked yet, so the overview stays clean for
 * accounts not using the feature; 202-js/p202-overview.js removes the empty
 * placeholder when it gets nothing back.
 */

$userId = (int) $_SESSION['user_id'];
require_once __DIR__ . '/ltv_helpers.php';
$money = p202_ltv_money(...);

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
$tiles = [
    ['Customers', number_format((int) ($summary['customers'] ?? 0)), 'all time'],
    ['Revenue', '$' . $money($summary['total_revenue'] ?? 0), 'all time'],
    ['Avg LTV', '$' . $money($summary['avg_ltv'] ?? 0), 'per customer'],
    ['Repeat rate', number_format(((float) ($summary['repeat_rate'] ?? 0)) * 100, 1) . '%', 'bought more than once'],
    ['MRR', '$' . $money($mrr['mrr'] ?? 0), 'monthly recurring'],
    ['Monthly churn', number_format(((float) ($mrr['monthly_churn_rate'] ?? 0)) * 100, 2) . '%', 'of subscribers'],
];
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<section class="p202-panel" aria-labelledby="ltv-snapshot-title">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title" id="ltv-snapshot-title">Customer lifetime value</h2>
        <span class="p202-panel__sub">all time</span>
        <div class="p202-panel__aside"><a class="btn btn-secondary btn-sm" href="<?php echo $e(get_absolute_url() . 'tracking202/analyze/ltv.php'); ?>">Full report</a></div>
    </div>
    <div class="p202-panel__body">
        <div class="p202-tiles mb-0">
            <?php foreach ($tiles as [$label, $value, $sub]) { ?>
                <div class="p202-tile"><div class="p202-tile__label"><?php echo $e($label); ?></div><div class="p202-tile__value"><?php echo $e($value); ?></div><div class="p202-tile__sub"><?php echo $e($sub); ?></div></div>
            <?php } ?>
        </div>
    </div>
</section>
