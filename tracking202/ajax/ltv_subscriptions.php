<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

/**
 * Account-wide subscriptions view: MRR/churn strip plus every subscription
 * across customers, filterable by lifecycle status, each row clickable
 * through to the owning customer.
 */

$userId = (int) $_SESSION['user_id'];
$offset = max(0, (int) ($_POST['offset'] ?? 0));
$limit = 50;

$statuses = ['' => 'All', 'trialing' => 'Trialing', 'active' => 'Active',
    'past_due' => 'Past Due', 'paused' => 'Paused', 'canceled' => 'Canceled'];
$status = isset($_POST['status']) && array_key_exists((string) $_POST['status'], $statuses)
    ? (string) $_POST['status']
    : '';

require_once __DIR__ . '/ltv_ui.php';
$money = p202_ltv_money(...);
$esc = p202_ltv_esc(...);
$when = p202_ltv_when(...);

$selfUrl = get_absolute_url() . 'tracking202/ajax/ltv_subscriptions.php';

try {
    $conn = new \Prosper202\Database\Connection($db);
    $customersRepo = new \Prosper202\Ltv\MysqlCustomerRepository($conn);
    $subsRepo = new \Prosper202\Ltv\MysqlSubscriptionRepository($conn, $customersRepo);
    $mrr = (new \Prosper202\Ltv\MysqlLtvRepository($conn))->mrr($userId);
    $list = $subsRepo->listForUser($userId, $status !== '' ? $status : null, $limit, $offset);
} catch (\Throwable $e) {
    error_log('ltv_subscriptions: ' . $e->getMessage());
    echo '<div class="alert alert-warning">Subscription data is unavailable. '
        . 'Run the LTV migration if you have not yet.</div>';
    return;
}
?>

<?php echo p202_ltv_ui_styles(); ?>
<?php echo p202_ltv_tabs('subscriptions'); ?>

<!-- Recurring revenue summary -->
<div class="ltv-stats">
    <?php echo p202_ltv_stat('MRR', '$' . $money($mrr['mrr'] ?? 0)); ?>
    <?php echo p202_ltv_stat('ARR', '$' . $money(((float) ($mrr['mrr'] ?? 0)) * 12)); ?>
    <?php echo p202_ltv_stat('Active', number_format((int) ($mrr['active_subscriptions'] ?? 0))); ?>
    <?php echo p202_ltv_stat('Trialing', number_format((int) ($mrr['trialing'] ?? 0))); ?>
    <?php echo p202_ltv_stat('Past Due', number_format((int) ($mrr['past_due'] ?? 0)),
        '', ((int) ($mrr['past_due'] ?? 0)) > 0 ? 'bad' : ''); ?>
    <?php echo p202_ltv_stat('Paused', number_format((int) ($mrr['paused'] ?? 0))); ?>
    <?php echo p202_ltv_stat('Canceled (90d)', number_format((int) ($mrr['churn_inputs']['canceled_in_window'] ?? 0))); ?>
    <?php echo p202_ltv_stat('Monthly Churn', number_format(((float) ($mrr['monthly_churn_rate'] ?? 0)) * 100, 2) . '%'); ?>
</div>

<?php echo p202_ltv_card_open('Subscriptions', number_format((int) $list['total']) . ' record(s)'); ?>
    <div class="ltv-toolbar" style="padding-top: 4px;">
        <?php echo p202_ltv_chips('ltv-sub-status', $statuses, $status, 'ltvSubsLoad(0);'); ?>
    </div>
    <div class="ltv-table-wrap">
        <table class="ltv-table ltv-table-hover">
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Customer</th>
                    <th class="num" title="The customer's lifetime revenue across ALL purchases and subscriptions, net of refunds">Customer LTV</th>
                    <th>Status</th>
                    <th class="num">Amount</th>
                    <th class="num">MRR</th>
                    <th>Started</th>
                    <th>Paid Through</th>
                    <th>Canceled</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($list['rows'] === []) { ?>
                    <tr><td colspan="9">
                        <?php echo p202_ltv_empty('fa-refresh',
                            $status !== '' ? 'No subscriptions with this status' : 'No subscriptions recorded yet',
                            $status !== '' ? '' : 'Push them via <code>POST /api/v3/ltv/subscriptions</code>.'); ?>
                    </td></tr>
                <?php } ?>
                <?php foreach ($list['rows'] as $sub) {
                    $customerName = trim(((string) ($sub['first_name'] ?? '')) . ' ' . ((string) ($sub['last_name'] ?? '')));
                    if ($customerName === '') {
                        $customerName = (string) ($sub['email'] ?? '');
                    }
                    if ($customerName === '') {
                        $customerName = '#' . (int) ($sub['customer_id'] ?? 0);
                    }
                    $subStatus = (string) ($sub['status'] ?? '');
                ?>
                    <tr class="ltv-row-link" onclick="ltvSubCustomer(<?php echo (int) ($sub['customer_id'] ?? 0); ?>);" title="View customer">
                        <td title="<?php echo $esc($sub['external_sub_id'] ?? ''); ?>">
                            <?php echo $esc(($sub['plan_name'] ?? '') !== '' && $sub['plan_name'] !== null ? $sub['plan_name'] : ($sub['external_sub_id'] ?? '')); ?>
                        </td>
                        <td><?php echo $esc($customerName); ?>
                            <?php if (($sub['company'] ?? '') !== '' && $sub['company'] !== null) { ?>
                                <span class="ltv-dim" style="font-size: 12px;">(<?php echo $esc($sub['company']); ?>)</span>
                            <?php } ?>
                        </td>
                        <td class="num ltv-strong">$<?php echo $money($sub['customer_ltv'] ?? 0); ?></td>
                        <td><?php echo p202_ltv_status_pill($subStatus); ?></td>
                        <td class="num">$<?php echo $money($sub['amount'] ?? 0); ?> <span class="ltv-dim">/
                            <?php echo ((int) ($sub['billing_interval_count'] ?? 1)) > 1 ? (int) $sub['billing_interval_count'] . ' ' : ''; ?><?php echo $esc($sub['billing_interval'] ?? 'month'); ?></span></td>
                        <td class="num">$<?php echo $money($sub['mrr'] ?? 0); ?></td>
                        <td><?php echo $when($sub['started_at'] ?? 0); ?></td>
                        <td><?php echo $when($sub['current_period_end'] ?? 0); ?></td>
                        <td><?php echo !empty($sub['canceled_at']) ? $when($sub['canceled_at']) : '<span class="ltv-dim">—</span>'; ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <?php echo p202_ltv_pager($offset, $limit, (int) $list['total'], 'ltvSubsLoad'); ?>
<?php echo p202_ltv_card_close(); ?>

<script type="text/javascript">
    function ltvSubsLoad(offset) {
        ltvNav('subscriptions', {
            offset: offset,
            status: $('#ltv-sub-status').val()
        });
    }
    function ltvSubCustomer(customerId) {
        ltvNav('customer', { customer_id: customerId });
    }
</script>
