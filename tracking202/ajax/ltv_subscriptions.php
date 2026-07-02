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

$money = static fn (mixed $v): string => number_format((float) $v, 2);
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when = static fn (mixed $ts): string => ((int) $ts) > 0 ? date('M j, Y', (int) $ts) : '—';

$backUrl = get_absolute_url() . 'tracking202/ajax/sort_ltv.php';
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

<div class="row" style="margin-bottom: 10px;">
    <div class="col-xs-12">
        <a href="#" onclick="loadContent('<?php echo $backUrl; ?>', null); return false;">&laquo; Back to Customer LTV</a>
    </div>
</div>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-xs-12">
        <h6>Subscriptions <small><?php echo number_format((int) $list['total']); ?> record(s)</small></h6>
    </div>
</div>

<!-- Recurring revenue summary -->
<div class="row">
    <div class="col-xs-12">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>MRR</th>
                    <th>ARR</th>
                    <th>Active</th>
                    <th>Trialing</th>
                    <th>Past Due</th>
                    <th>Paused</th>
                    <th>Canceled (90d)</th>
                    <th>Monthly Churn</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>$<?php echo $money($mrr['mrr'] ?? 0); ?></td>
                    <td>$<?php echo $money(((float) ($mrr['mrr'] ?? 0)) * 12); ?></td>
                    <td><?php echo number_format((int) ($mrr['active_subscriptions'] ?? 0)); ?></td>
                    <td><?php echo number_format((int) ($mrr['trialing'] ?? 0)); ?></td>
                    <td><?php echo number_format((int) ($mrr['past_due'] ?? 0)); ?></td>
                    <td><?php echo number_format((int) ($mrr['paused'] ?? 0)); ?></td>
                    <td><?php echo number_format((int) ($mrr['churn_inputs']['canceled_in_window'] ?? 0)); ?></td>
                    <td><?php echo number_format(((float) ($mrr['monthly_churn_rate'] ?? 0)) * 100, 2); ?>%</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="row" style="margin-top: 10px; margin-bottom: 5px;">
    <div class="col-xs-12">
        Status:
        <select id="ltv-sub-status" style="width: auto; display: inline-block;">
            <?php foreach ($statuses as $key => $label) { ?>
                <option value="<?php echo $esc($key); ?>" <?php if ($key === $status) { echo 'selected'; } ?>><?php echo $esc($label); ?></option>
            <?php } ?>
        </select>
    </div>
</div>

<div class="row">
    <div class="col-xs-12">
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Amount</th>
                    <th>MRR</th>
                    <th>Started</th>
                    <th>Paid Through</th>
                    <th>Canceled</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($list['rows'] === []) { ?>
                    <tr><td colspan="8"><em>No subscriptions<?php echo $status !== '' ? ' with this status' : ' recorded yet — push them via POST /api/v3/ltv/subscriptions'; ?>.</em></td></tr>
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
                    <tr style="cursor: pointer;" onclick="ltvSubCustomer(<?php echo (int) ($sub['customer_id'] ?? 0); ?>);" title="View customer">
                        <td title="<?php echo $esc($sub['external_sub_id'] ?? ''); ?>">
                            <?php echo $esc(($sub['plan_name'] ?? '') !== '' && $sub['plan_name'] !== null ? $sub['plan_name'] : ($sub['external_sub_id'] ?? '')); ?>
                        </td>
                        <td><?php echo $esc($customerName); ?>
                            <?php if (($sub['company'] ?? '') !== '' && $sub['company'] !== null) { ?>
                                <small class="text-muted">(<?php echo $esc($sub['company']); ?>)</small>
                            <?php } ?>
                        </td>
                        <td class="<?php echo $subStatus === 'past_due' ? 'text-danger' : ($subStatus === 'active' ? 'text-success' : ''); ?>">
                            <?php echo $esc($subStatus); ?></td>
                        <td>$<?php echo $money($sub['amount'] ?? 0); ?> /
                            <?php echo ((int) ($sub['billing_interval_count'] ?? 1)) > 1 ? (int) $sub['billing_interval_count'] . ' ' : ''; ?><?php echo $esc($sub['billing_interval'] ?? 'month'); ?></td>
                        <td>$<?php echo $money($sub['mrr'] ?? 0); ?></td>
                        <td><?php echo $when($sub['started_at'] ?? 0); ?></td>
                        <td><?php echo $when($sub['current_period_end'] ?? 0); ?></td>
                        <td><?php echo !empty($sub['canceled_at']) ? $when($sub['canceled_at']) : '—'; ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <div class="text-center">
            <?php if ($offset > 0) { ?>
                <a href="#" onclick="ltvSubsLoad(<?php echo max(0, $offset - $limit); ?>); return false;">&laquo; Previous</a>
            <?php } ?>
            <?php if ($offset + $limit < (int) $list['total']) { ?>
                &nbsp;<a href="#" onclick="ltvSubsLoad(<?php echo $offset + $limit; ?>); return false;">Next &raquo;</a>
            <?php } ?>
        </div>
    </div>
</div>

<script type="text/javascript">
    function ltvSubsLoad(offset) {
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', {
            offset: offset,
            status: $('#ltv-sub-status').val()
        }).done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvSubCustomer(customerId) {
        var element = $('#m-content');
        $.post('<?php echo get_absolute_url(); ?>tracking202/ajax/ltv_customer.php', {
            customer_id: customerId
        }).done(function(data) { element.html(data).css('opacity', '1'); });
    }
    $('#ltv-sub-status').on('change', function() { ltvSubsLoad(0); });
</script>
