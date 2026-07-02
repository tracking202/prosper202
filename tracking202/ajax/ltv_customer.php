<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

$userId = (int) $_SESSION['user_id'];
$customerId = (int) ($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);

$money = static fn (mixed $v): string => number_format((float) $v, 2);
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when = static fn (mixed $ts): string => ((int) $ts) > 0 ? date('M j, Y g:ia', (int) $ts) : '—';

$backUrl = get_absolute_url() . 'tracking202/ajax/sort_ltv.php';

try {
    $conn = new \Prosper202\Database\Connection($db);
    $customers = new \Prosper202\Ltv\MysqlCustomerRepository($conn);
    $fields = new \Prosper202\Ltv\MysqlCustomerFieldRepository($conn);
    $crm = new \Prosper202\Ltv\MysqlCustomerCrmRepository($conn, $customers, $fields);

    $customer = $customerId > 0 ? $crm->get($userId, $customerId, 50) : null;
} catch (\Throwable $e) {
    error_log('ltv_customer: ' . $e->getMessage());
    $customer = null;
}
?>

<div class="row" style="margin-bottom: 10px;">
    <div class="col-xs-12">
        <a href="#" onclick="loadContent('<?php echo $backUrl; ?>', null); return false;">&laquo; Back to Customer LTV</a>
    </div>
</div>

<?php if ($customer === null) { ?>
    <div class="alert alert-warning">Customer not found.</div>
    <?php return; ?>
<?php } ?>

<?php
$displayName = trim(((string) ($customer['first_name'] ?? '')) . ' ' . ((string) ($customer['last_name'] ?? '')));
if ($displayName === '') {
    $displayName = (string) ($customer['company'] ?? '');
}
if ($displayName === '') {
    $displayName = (string) ($customer['primary_ref'] ?? ('Customer #' . $customerId));
}
$addressParts = array_filter([
    (string) ($customer['address_line1'] ?? ''),
    (string) ($customer['address_line2'] ?? ''),
    trim(((string) ($customer['city'] ?? '')) . ' ' . ((string) ($customer['region'] ?? ''))),
    (string) ($customer['postal_code'] ?? ''),
    (string) ($customer['country'] ?? ''),
], static fn (string $part): bool => trim($part) !== '');
?>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-xs-12">
        <h6><?php echo $esc($displayName); ?>
            <small>customer #<?php echo (int) $customer['customer_id']; ?>
                <?php if ((string) ($customer['status'] ?? 'active') !== 'active') { ?>
                    &mdash; <?php echo $esc($customer['status']); ?>
                <?php } ?>
            </small>
        </h6>
    </div>
</div>

<!-- Lifetime rollups -->
<div class="row">
    <div class="col-xs-12">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Orders</th>
                    <th>Lifetime Revenue</th>
                    <th>Refunded</th>
                    <th>Avg Order</th>
                    <th>Active Subs</th>
                    <th>MRR</th>
                    <th>First Seen</th>
                    <th>Last Activity</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo number_format((int) ($customer['order_count'] ?? 0)); ?></td>
                    <td>$<?php echo $money($customer['total_revenue'] ?? 0); ?></td>
                    <td>$<?php echo $money($customer['refunded_amount'] ?? 0); ?></td>
                    <td>$<?php echo $money(((int) ($customer['order_count'] ?? 0)) > 0
                        ? ((float) ($customer['total_revenue'] ?? 0)) / (int) $customer['order_count']
                        : 0); ?></td>
                    <td><?php echo number_format((int) ($customer['active_subscription_count'] ?? 0)); ?></td>
                    <td>$<?php echo $money($customer['mrr'] ?? 0); ?></td>
                    <td><?php echo $when($customer['first_seen_time'] ?? 0); ?></td>
                    <td><?php echo $when($customer['last_activity_time'] ?? 0); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Profile + identity -->
<div class="row">
    <div class="col-sm-6">
        <h6>Profile</h6>
        <table class="table table-bordered">
            <tbody>
                <tr><th style="width: 35%;">Customer Ref</th><td><?php echo $esc($customer['primary_ref'] ?? ''); ?></td></tr>
                <tr><th>Email</th><td><?php echo $esc($customer['email'] ?? '') ?: '—'; ?></td></tr>
                <tr><th>Phone</th><td><?php echo $esc($customer['phone'] ?? '') ?: '—'; ?></td></tr>
                <tr><th>Company</th><td><?php echo $esc($customer['company'] ?? '') ?: '—'; ?></td></tr>
                <tr><th>Address</th><td><?php echo $addressParts !== [] ? $esc(implode(', ', $addressParts)) : '—'; ?></td></tr>
                <tr><th>Acquisition Click</th>
                    <td><?php echo !empty($customer['first_click_id']) ? '#' . (int) $customer['first_click_id'] : '—'; ?></td></tr>
                <?php foreach (($customer['custom_fields'] ?? []) as $key => $value) { ?>
                    <tr>
                        <th><?php echo $esc($key); ?></th>
                        <td><?php
                            if (is_bool($value)) {
                                echo $value ? 'Yes' : 'No';
                            } else {
                                echo $esc($value ?? '') ?: '—';
                            }
                        ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <div class="col-sm-6">
        <h6>Linked Identities</h6>
        <table class="table table-bordered">
            <thead>
                <tr><th>Type</th><th>Value</th><th>Linked</th></tr>
            </thead>
            <tbody>
                <?php if (($customer['aliases'] ?? []) === []) { ?>
                    <tr><td colspan="3"><em>No aliases recorded.</em></td></tr>
                <?php } ?>
                <?php foreach (($customer['aliases'] ?? []) as $alias) { ?>
                    <tr>
                        <td><?php echo $esc($alias['alias_type'] ?? ''); ?></td>
                        <td><?php echo $esc(mb_strimwidth((string) ($alias['alias_value'] ?? ''), 0, 60, '…')); ?></td>
                        <td><?php echo $when($alias['created_at'] ?? 0); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Subscriptions -->
<?php if (($customer['subscriptions'] ?? []) !== []) { ?>
<div class="row">
    <div class="col-xs-12">
        <h6>Subscriptions</h6>
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Amount</th>
                    <th>MRR</th>
                    <th>Started</th>
                    <th>Paid Through</th>
                    <th>Canceled</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customer['subscriptions'] as $sub) { ?>
                    <tr>
                        <td title="<?php echo $esc($sub['external_sub_id'] ?? ''); ?>">
                            <?php echo $esc(($sub['plan_name'] ?? '') !== '' && $sub['plan_name'] !== null ? $sub['plan_name'] : ($sub['external_sub_id'] ?? '')); ?>
                        </td>
                        <td><?php echo $esc($sub['status'] ?? ''); ?></td>
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
    </div>
</div>
<?php } ?>

<!-- Purchase history -->
<div class="row">
    <div class="col-xs-12">
        <h6>Purchase History <small>(most recent <?php echo count($customer['recent_events'] ?? []); ?> events)</small></h6>
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th>Amount</th>
                    <th>Products</th>
                    <th>Transaction</th>
                </tr>
            </thead>
            <tbody>
                <?php if (($customer['recent_events'] ?? []) === []) { ?>
                    <tr><td colspan="6"><em>No revenue recorded yet.</em></td></tr>
                <?php } ?>
                <?php foreach (($customer['recent_events'] ?? []) as $event) {
                    $amount = (float) ($event['amount'] ?? 0);
                ?>
                    <tr>
                        <td><?php echo $when($event['occurred_at'] ?? 0); ?></td>
                        <td><?php echo $esc($event['event_type'] ?? ''); ?></td>
                        <td><?php echo $esc($event['source'] ?? ''); ?>
                            <?php if (!empty($event['conv_id'])) { ?><small>(conv #<?php echo (int) $event['conv_id']; ?>)</small><?php } ?>
                        </td>
                        <td style="<?php echo $amount < 0 ? 'color: #d9534f;' : ''; ?>">
                            <?php echo ($amount < 0 ? '-$' : '$') . $money(abs($amount)); ?>
                        </td>
                        <td>
                            <?php if (($event['items'] ?? []) === []) { ?>
                                —
                            <?php } else { ?>
                                <?php foreach ($event['items'] as $item) { ?>
                                    <div>
                                        <?php echo $esc(($item['product_name'] ?? '') !== '' && $item['product_name'] !== null
                                            ? $item['product_name']
                                            : ($item['sku'] ?? 'product #' . ($item['product_id'] ?? '?'))); ?>
                                        &times;<?php echo number_format((float) ($item['quantity'] ?? 1), (fmod((float) ($item['quantity'] ?? 1), 1.0) === 0.0) ? 0 : 2); ?>
                                        &mdash; $<?php echo $money($item['amount'] ?? 0); ?>
                                    </div>
                                <?php } ?>
                            <?php } ?>
                        </td>
                        <td><?php echo $esc(mb_strimwidth((string) ($event['transaction_id'] ?? ''), 0, 30, '…')) ?: '—'; ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
