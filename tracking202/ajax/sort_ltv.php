<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

//grab user time range preference
$time = grab_timeframe();
$timeFrom = (int) $time['from'];
$timeTo = (int) $time['to'];
$userId = (int) $_SESSION['user_id'];

$offset = max(0, (int) ($_POST['offset'] ?? 0));
$limit = 50;

$allowedDimensions = [
    'campaign' => 'Campaign',
    'ppc_account' => 'Traffic Source',
    'landing_page' => 'Landing Page',
    'product' => 'Product',
    'abm' => 'Company (ABM)',
];
$by = isset($_POST['ltv_by']) && isset($allowedDimensions[(string) $_POST['ltv_by']])
    ? (string) $_POST['ltv_by']
    : 'campaign';

$money = static fn (mixed $v): string => number_format((float) $v, 2);
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

try {
    $conn = new \Prosper202\Database\Connection($db);
    $ltv = new \Prosper202\Ltv\MysqlLtvRepository($conn);
    $query = new \Prosper202\Ltv\LtvQuery($userId, $timeFrom, $timeTo);

    $summary = $ltv->summary($query);
    $mrr = $ltv->mrr($userId);
    if ($by === 'abm') {
        // ABM: engagement-based account rollup over the last 90 days.
        $breakdown = (new \Prosper202\Ltv\MysqlEngagementRepository($conn))->abmBreakdown($userId, 90, 25, 0);
    } else {
        $breakdown = $ltv->breakdown($query, $by, 25, 0);
    }
    $customers = $ltv->customers($query, 'total_revenue', 'DESC', $limit, $offset);
} catch (\Throwable $e) {
    // Most likely cause: the LTV schema has not been installed yet.
    error_log('sort_ltv: ' . $e->getMessage());
    echo '<div class="alert alert-warning">Customer LTV data is unavailable. '
        . 'If you just upgraded, run the database upgrade (202-config/upgrade.php) or '
        . '<code>202-config/migrations/run_ltv_migration.php</code> to install the LTV tables.</div>';
    return;
}

$totalCustomers = (int) ($summary['customers'] ?? 0);
?>

<?php if ($totalCustomers === 0) { ?>
    <div class="alert alert-info">
        <strong>No customers tracked in this date range yet.</strong><br>
        Link conversions to customers by adding <code>&amp;cust=CUSTOMER_ID</code> to your conversion
        pixel/postback, by pushing orders to the <code>/api/v3/ltv</code> endpoints, or by designating
        one of your c1&ndash;c4 tracking params as the customer reference
        (then run <code>202-config/migrations/run_ltv_backfill.php</code> to import history).
    </div>
<?php } ?>

<!-- Summary -->
<div class="row">
    <div class="col-xs-12">
        <table class="table table-bordered" id="ltv-summary-table">
            <thead>
                <tr>
                    <th>Customers</th>
                    <th>Purchasing</th>
                    <th>Repeat Rate</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>Refunded</th>
                    <th>Avg LTV</th>
                    <th>AOV</th>
                    <th>MRR</th>
                    <th>Monthly Churn</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo number_format($totalCustomers); ?></td>
                    <td><?php echo number_format((int) ($summary['purchasing_customers'] ?? 0)); ?></td>
                    <td><?php echo number_format(((float) ($summary['repeat_rate'] ?? 0)) * 100, 1); ?>%</td>
                    <td><?php echo number_format((int) ($summary['total_orders'] ?? 0)); ?></td>
                    <td>$<?php echo $money($summary['total_revenue'] ?? 0); ?></td>
                    <td>$<?php echo $money($summary['refunded_amount'] ?? 0); ?></td>
                    <td>$<?php echo $money($summary['avg_ltv'] ?? 0); ?></td>
                    <td>$<?php echo $money($summary['aov'] ?? 0); ?></td>
                    <td>$<?php echo $money($mrr['mrr'] ?? 0); ?>
                        <small>(<?php echo number_format((int) ($mrr['active_subscriptions'] ?? 0)); ?> active subs)</small></td>
                    <td><?php echo number_format(((float) ($mrr['monthly_churn_rate'] ?? 0)) * 100, 2); ?>%</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- LTV by acquisition dimension / product -->
<div class="row" style="margin-top: 10px;">
    <div class="col-xs-8">
        <h6 style="display: inline-block;">LTV by
            <select id="ltv-by-select" style="width: auto; display: inline-block;">
                <?php foreach ($allowedDimensions as $key => $label) { ?>
                    <option value="<?php echo $esc($key); ?>" <?php if ($key === $by) { echo 'selected'; } ?>><?php echo $esc($label); ?></option>
                <?php } ?>
            </select>
        </h6>
    </div>
    <div class="col-xs-4 text-right">
        <a href="<?php echo get_absolute_url(); ?>tracking202/analyze/ltv_download.php" target="_blank">
            <i class="fa fa-download"></i> Download Customers
        </a>
    </div>
</div>
<div class="row">
    <div class="col-xs-12">
        <table class="table table-bordered table-hover" id="ltv-breakdown-table">
            <thead>
                <tr>
                    <?php if ($by === 'abm') { ?>
                        <th>Company</th>
                        <th>Contacts</th>
                        <th>Engagements (90d)</th>
                        <th>Top Interest</th>
                        <th>Top Event</th>
                        <th>Revenue</th>
                        <th>MRR</th>
                        <th>Last Activity</th>
                    <?php } elseif ($by === 'product') { ?>
                        <th><?php echo $esc($allowedDimensions[$by]); ?></th>
                        <th>Customers</th>
                        <th>Orders</th>
                        <th>Units</th>
                        <th>Revenue</th>
                        <th>Revenue / Customer</th>
                    <?php } else { ?>
                        <th><?php echo $esc($allowedDimensions[$by]); ?></th>
                        <th>Customers</th>
                        <th>Orders</th>
                        <th>Revenue</th>
                        <th>Avg LTV</th>
                        <th>AOV</th>
                        <th>Repeat Rate</th>
                        <th>MRR</th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($breakdown === []) { ?>
                    <tr><td colspan="8"><em>No data for this range.</em></td></tr>
                <?php } ?>
                <?php foreach ($breakdown as $row) { ?>
                    <tr>
                        <?php if ($by === 'abm') { ?>
                            <td><?php echo $esc($row['company'] ?? ''); ?></td>
                            <td><?php echo number_format((int) ($row['contacts'] ?? 0)); ?></td>
                            <td><?php echo number_format((int) ($row['engagements'] ?? 0)); ?></td>
                            <td><?php echo $esc($row['top_campaign_name'] ?? '') ?: '—'; ?></td>
                            <td><?php echo $esc($row['top_event_name'] ?? '') ?: '—'; ?></td>
                            <td>$<?php echo $money($row['total_revenue'] ?? 0); ?></td>
                            <td>$<?php echo $money($row['mrr'] ?? 0); ?></td>
                            <td data-sort="<?php echo (int) ($row['last_activity'] ?? 0); ?>"><?php echo ((int) ($row['last_activity'] ?? 0)) > 0 ? date('M j, Y', (int) $row['last_activity']) : '—'; ?></td>
                        <?php } elseif ($by === 'product') { ?>
                            <td><?php echo $esc($row['name'] ?? ('#' . ($row['id'] ?? ''))); ?></td>
                            <td><?php echo number_format((int) ($row['customers'] ?? 0)); ?></td>
                            <td><?php echo number_format((int) ($row['orders'] ?? 0)); ?></td>
                            <td><?php echo number_format((float) ($row['units'] ?? 0), 1); ?></td>
                            <td>$<?php echo $money($row['total_revenue'] ?? 0); ?></td>
                            <td>$<?php echo $money($row['avg_revenue_per_customer'] ?? 0); ?></td>
                        <?php } else { ?>
                            <td><?php echo $esc($row['name'] ?? ('#' . ($row['id'] ?? ''))); ?></td>
                            <td><?php echo number_format((int) ($row['customers'] ?? 0)); ?></td>
                            <td><?php echo number_format((int) ($row['total_orders'] ?? 0)); ?></td>
                            <td>$<?php echo $money($row['total_revenue'] ?? 0); ?></td>
                            <td>$<?php echo $money($row['avg_ltv'] ?? 0); ?></td>
                            <td>$<?php echo $money($row['aov'] ?? 0); ?></td>
                            <td><?php echo number_format(((float) ($row['repeat_rate'] ?? 0)) * 100, 1); ?>%</td>
                            <td>$<?php echo $money($row['mrr'] ?? 0); ?></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Top customers -->
<div class="row" style="margin-top: 10px;">
    <div class="col-xs-12">
        <h6>Customers by Lifetime Value
            <small>(<?php echo number_format((int) $customers['total']); ?> total; showing
            <?php echo number_format(min($offset + 1, (int) $customers['total'])); ?>&ndash;<?php echo number_format(min($offset + $limit, (int) $customers['total'])); ?>)</small>
        </h6>
        <table class="table table-bordered table-hover" id="ltv-customers-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Name / Company</th>
                    <th>First Seen</th>
                    <th>Last Activity</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>Refunded</th>
                    <th>Active Subs</th>
                    <th>MRR</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($customers['rows'] === []) { ?>
                    <tr><td colspan="9"><em>No customers in this range.</em></td></tr>
                <?php } ?>
                <?php foreach ($customers['rows'] as $c) {
                    $displayName = trim(((string) ($c['first_name'] ?? '')) . ' ' . ((string) ($c['last_name'] ?? '')));
                    if ($displayName === '' && !empty($c['company'])) {
                        $displayName = (string) $c['company'];
                    }
                ?>
                    <tr style="cursor: pointer;" onclick="ltvCustomer(<?php echo (int) $c['customer_id']; ?>);"
                        title="View customer detail">
                        <td title="<?php echo $esc($c['primary_ref'] ?? ''); ?>">
                            <?php echo $esc(mb_strimwidth((string) ($c['primary_ref'] ?? ('#' . $c['customer_id'])), 0, 40, '…')); ?>
                        </td>
                        <td><?php echo $esc($displayName !== '' ? $displayName : '—'); ?></td>
                        <td data-sort="<?php echo (int) ($c['first_seen_time'] ?? 0); ?>"><?php echo date('M j, Y', (int) ($c['first_seen_time'] ?? 0)); ?></td>
                        <td data-sort="<?php echo (int) ($c['last_activity_time'] ?? 0); ?>"><?php echo date('M j, Y', (int) ($c['last_activity_time'] ?? 0)); ?></td>
                        <td><?php echo number_format((int) ($c['order_count'] ?? 0)); ?></td>
                        <td>$<?php echo $money($c['total_revenue'] ?? 0); ?></td>
                        <td>$<?php echo $money($c['refunded_amount'] ?? 0); ?></td>
                        <td><?php echo number_format((int) ($c['active_subscription_count'] ?? 0)); ?></td>
                        <td>$<?php echo $money($c['mrr'] ?? 0); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <div class="text-center">
            <?php if ($offset > 0) { ?>
                <a href="#" onclick="ltvLoad(<?php echo max(0, $offset - $limit); ?>); return false;">&laquo; Previous</a>
            <?php } ?>
            <?php if ($offset + $limit < (int) $customers['total']) { ?>
                &nbsp;<a href="#" onclick="ltvLoad(<?php echo $offset + $limit; ?>); return false;">Next &raquo;</a>
            <?php } ?>
        </div>
    </div>
</div>

<script type="text/javascript">
    function ltvLoad(offset) {
        var element = $('#m-content');
        $.post('<?php echo get_absolute_url(); ?>tracking202/ajax/sort_ltv.php', {
            offset: offset,
            ltv_by: $('#ltv-by-select').val()
        }).done(function(data) {
            element.html(data);
            element.css('opacity', '1');
        });
    }
    function ltvCustomer(customerId) {
        var element = $('#m-content');
        $.post('<?php echo get_absolute_url(); ?>tracking202/ajax/ltv_customer.php', {
            customer_id: customerId
        }).done(function(data) {
            element.html(data);
            element.css('opacity', '1');
        });
    }
    $('#ltv-by-select').on('change', function() { ltvLoad(0); });

    new Tablesort(document.getElementById('ltv-breakdown-table'), { descending: true });
    new Tablesort(document.getElementById('ltv-customers-table'), { descending: true });
</script>
