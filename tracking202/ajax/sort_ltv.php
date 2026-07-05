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

$search = trim((string) ($_POST['q'] ?? ''));
$segments = ['' => 'All customers', 'repeat' => 'Repeat buyers', 'subscribers' => 'Active subscribers', 'at_risk' => 'At risk (past-due subs)'];
$segment = isset($_POST['segment']) && array_key_exists((string) $_POST['segment'], $segments)
    ? (string) $_POST['segment']
    : '';

require_once __DIR__ . '/ltv_ui.php';
$money = p202_ltv_money(...);
$esc = p202_ltv_esc(...);

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
    $customers = $ltv->customers($query, 'total_revenue', 'DESC', $limit, $offset, $search, $segment !== '' ? $segment : null);
    $cohorts = $ltv->cohorts($userId, 6);
    // Reuse the aggregates computed above — predict($query) would re-run
    // the same summary and MRR queries in the hottest LTV render.
    $predict = $ltv->predictFromComputed($summary, $mrr);
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

<?php echo p202_ltv_ui_styles(); ?>
<?php echo p202_ltv_tabs('report'); ?>

<?php if ($totalCustomers === 0) { ?>
    <?php echo p202_ltv_flash(
        'info',
        '<strong>No customers tracked in this date range yet.</strong><br>'
        . 'Link conversions to customers by adding <code>&amp;cust=CUSTOMER_ID</code> to your conversion '
        . 'pixel/postback, by pushing orders to the <code>/api/v3/ltv</code> endpoints, or by designating '
        . 'one of your c1&ndash;c4 tracking params as the customer reference '
        . '(then run <code>202-config/migrations/run_ltv_backfill.php</code> to import history).'
    ); ?>
<?php } ?>

<!-- Summary KPIs -->
<div class="ltv-stats" id="ltv-summary">
    <?php echo p202_ltv_stat('Customers', number_format($totalCustomers),
        number_format((int) ($summary['purchasing_customers'] ?? 0)) . ' purchasing'); ?>
    <?php echo p202_ltv_stat('Revenue', '$' . $money($summary['total_revenue'] ?? 0),
        '$' . $money($summary['refunded_amount'] ?? 0) . ' refunded'); ?>
    <?php echo p202_ltv_stat('Orders', number_format((int) ($summary['total_orders'] ?? 0))); ?>
    <?php echo p202_ltv_stat('Avg LTV', '$' . $money($summary['avg_ltv'] ?? 0)); ?>
    <?php echo p202_ltv_stat('AOV', '$' . $money($summary['aov'] ?? 0)); ?>
    <?php echo p202_ltv_stat('Repeat Rate', number_format(((float) ($summary['repeat_rate'] ?? 0)) * 100, 1) . '%'); ?>
    <?php echo p202_ltv_stat('MRR', '$' . $money($mrr['mrr'] ?? 0),
        number_format((int) ($mrr['active_subscriptions'] ?? 0)) . ' active subs'); ?>
    <?php echo p202_ltv_stat('Monthly Churn', number_format(((float) ($mrr['monthly_churn_rate'] ?? 0)) * 100, 2) . '%'); ?>
</div>

<!-- Predictive LTV (deterministic projection; inputs + caps shown) -->
<?php echo p202_ltv_card_open('Predicted LTV', 'deterministic projection from this range\'s realized data'); ?>
    <div class="ltv-card-body">
        <div class="ltv-stats" style="margin-bottom: 8px;">
            <?php echo p202_ltv_stat('Predicted LTV / Customer', '$' . $money($predict['predicted_ltv_per_customer'] ?? 0)); ?>
            <?php echo p202_ltv_stat('Subscriber Pool Value', '$' . $money($predict['predicted_subscriber_pool_value'] ?? 0)); ?>
            <?php echo p202_ltv_stat('AOV Input', '$' . $money($predict['inputs']['aov'] ?? 0)); ?>
            <?php echo p202_ltv_stat('Repeat Rate Input', number_format(((float) ($predict['inputs']['repeat_rate'] ?? 0)) * 100, 1) . '%'); ?>
            <?php echo p202_ltv_stat('Monthly Churn Input', number_format(((float) ($predict['inputs']['monthly_churn_rate'] ?? 0)) * 100, 2) . '%'); ?>
        </div>
        <div class="ltv-note">Guards applied:
            <?php echo ($predict['caps_applied'] ?? []) !== []
                ? $esc(implode(', ', array_map(strval(...), (array) $predict['caps_applied'])))
                : 'none'; ?></div>
    </div>
<?php echo p202_ltv_card_close(); ?>

<!-- LTV by acquisition dimension / product / company -->
<?php echo p202_ltv_card_open('LTV by', '', '<a href="' . $esc(get_absolute_url()) . 'tracking202/analyze/ltv_download.php" target="_blank">'
    . '<i class="fa fa-download"></i> Export customers</a>'); ?>
    <div class="ltv-toolbar" style="padding-top: 4px;">
        <?php echo p202_ltv_chips('ltv-by-select', $allowedDimensions, $by, 'ltvLoad(0);'); ?>
    </div>
    <div class="ltv-table-wrap">
        <table class="ltv-table ltv-table-hover" id="ltv-breakdown-table">
            <thead>
                <tr>
                    <?php if ($by === 'abm') { ?>
                        <th>Company</th>
                        <th class="num">Score</th>
                        <th class="num">Contacts</th>
                        <th class="num">Engagements (90d)</th>
                        <th class="num">Avg Time</th>
                        <th class="num">Avg Scroll</th>
                        <th>Top Interest</th>
                        <th>Top Event</th>
                        <th class="num">Revenue</th>
                        <th class="num">MRR</th>
                        <th>Last Activity</th>
                    <?php } elseif ($by === 'product') { ?>
                        <th><?php echo $esc($allowedDimensions[$by]); ?></th>
                        <th class="num">Customers</th>
                        <th class="num">Orders</th>
                        <th class="num">Units</th>
                        <th class="num">Revenue</th>
                        <th class="num">Revenue / Customer</th>
                    <?php } else { ?>
                        <th><?php echo $esc($allowedDimensions[$by]); ?></th>
                        <th class="num">Customers</th>
                        <th class="num">Orders</th>
                        <th class="num">Revenue</th>
                        <th class="num">Avg LTV</th>
                        <th class="num">AOV</th>
                        <th class="num">Repeat Rate</th>
                        <th class="num">MRR</th>
                        <th class="num">Spend</th>
                        <th class="num">CAC</th>
                        <th class="num" title="Lifetime revenue returned per ad dollar spent in this range">LTV:CAC</th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($breakdown === []) { ?>
                    <tr><td colspan="<?php echo $by === 'abm' ? 11 : ($by === 'product' ? 6 : 11); ?>">
                        <?php echo p202_ltv_empty('fa-bar-chart', 'No data for this range',
                            'Try a wider date range, or a different dimension.'); ?>
                    </td></tr>
                <?php } ?>
                <?php foreach ($breakdown as $row) { ?>
                    <tr>
                        <?php if ($by === 'abm') { ?>
                            <td class="ltv-row-link" onclick="ltvCompany(this.getAttribute('data-company'));"
                                data-company="<?php echo $esc($row['company'] ?? ''); ?>" title="View account detail">
                                <a href="#" onclick="return false;"><?php echo $esc($row['company'] ?? ''); ?></a>
                            </td>
                            <td class="num"><span class="ltv-strong"><?php echo (int) ($row['engagement_score'] ?? 0); ?></span><span class="ltv-dim">/100</span></td>
                            <td class="num"><?php echo number_format((int) ($row['contacts'] ?? 0)); ?></td>
                            <td class="num"><?php echo number_format((int) ($row['engagements'] ?? 0)); ?></td>
                            <td class="num"><?php echo ((float) ($row['avg_time_on_page'] ?? 0)) > 0 ? number_format((float) $row['avg_time_on_page']) . 's' : '—'; ?></td>
                            <td class="num"><?php echo ((float) ($row['avg_scroll_depth'] ?? 0)) > 0 ? number_format((float) $row['avg_scroll_depth']) . '%' : '—'; ?></td>
                            <td><?php echo $esc($row['top_campaign_name'] ?? '') ?: '—'; ?></td>
                            <td><?php echo $esc($row['top_event_name'] ?? '') ?: '—'; ?></td>
                            <td class="num">$<?php echo $money($row['total_revenue'] ?? 0); ?></td>
                            <td class="num">$<?php echo $money($row['mrr'] ?? 0); ?></td>
                            <td data-sort="<?php echo (int) ($row['last_activity'] ?? 0); ?>"><?php echo ((int) ($row['last_activity'] ?? 0)) > 0 ? date('M j, Y', (int) $row['last_activity']) : '—'; ?></td>
                        <?php } elseif ($by === 'product') { ?>
                            <td><?php echo $esc($row['name'] ?? ('#' . ($row['id'] ?? ''))); ?></td>
                            <td class="num"><?php echo number_format((int) ($row['customers'] ?? 0)); ?></td>
                            <td class="num"><?php echo number_format((int) ($row['orders'] ?? 0)); ?></td>
                            <td class="num"><?php echo number_format((float) ($row['units'] ?? 0), 1); ?></td>
                            <td class="num">$<?php echo $money($row['total_revenue'] ?? 0); ?></td>
                            <td class="num">$<?php echo $money($row['avg_revenue_per_customer'] ?? 0); ?></td>
                        <?php } else { ?>
                            <td><?php echo $esc($row['name'] ?? ('#' . ($row['id'] ?? ''))); ?></td>
                            <td class="num"><?php echo number_format((int) ($row['customers'] ?? 0)); ?></td>
                            <td class="num"><?php echo number_format((int) ($row['total_orders'] ?? 0)); ?></td>
                            <td class="num">$<?php echo $money($row['total_revenue'] ?? 0); ?></td>
                            <td class="num">$<?php echo $money($row['avg_ltv'] ?? 0); ?></td>
                            <td class="num">$<?php echo $money($row['aov'] ?? 0); ?></td>
                            <td class="num"><?php echo number_format(((float) ($row['repeat_rate'] ?? 0)) * 100, 1); ?>%</td>
                            <td class="num">$<?php echo $money($row['mrr'] ?? 0); ?></td>
                            <td class="num">$<?php echo $money($row['spend'] ?? 0); ?></td>
                            <td class="num"><?php echo ((float) ($row['spend'] ?? 0)) > 0 ? '$' . $money($row['cac'] ?? 0) : '—'; ?></td>
                            <?php $ltvCac = (float) ($row['ltv_cac'] ?? 0); ?>
                            <td class="num <?php echo ((float) ($row['spend'] ?? 0)) > 0 ? ($ltvCac >= 3 ? 'ltv-pos ltv-strong' : ($ltvCac < 1 ? 'ltv-neg' : '')) : ''; ?>">
                                <?php echo ((float) ($row['spend'] ?? 0)) > 0 ? number_format($ltvCac, 2) . 'x' : '—'; ?>
                            </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
<?php echo p202_ltv_card_close(); ?>

<!-- LTV maturation by acquisition cohort -->
<?php echo p202_ltv_card_open('LTV Maturation by Acquisition Cohort',
    'revenue by months since first seen (last 6 months, all time ranges)'); ?>
    <div class="ltv-table-wrap">
        <table class="ltv-table">
            <thead>
                <tr>
                    <th>Cohort</th><th class="num">Customers</th>
                    <th class="num">Month 0</th><th class="num">Month 1</th><th class="num">Month 2</th>
                    <th class="num">Month 3</th><th class="num">Month 4</th><th class="num">Month 5+</th>
                    <th class="num">Total</th><th class="num">LTV / Customer</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($cohorts === []) { ?>
                    <tr><td colspan="10">
                        <?php echo p202_ltv_empty('fa-calendar-o', 'No customers acquired in the last 6 months'); ?>
                    </td></tr>
                <?php } ?>
                <?php foreach ($cohorts as $cohort) { ?>
                    <tr>
                        <td class="ltv-strong"><?php echo $esc($cohort['cohort_month'] ?? ''); ?></td>
                        <td class="num"><?php echo number_format((int) ($cohort['customers'] ?? 0)); ?></td>
                        <?php foreach (['m0', 'm1', 'm2', 'm3', 'm4', 'm5_plus'] as $bucket) { ?>
                            <td class="num"><?php echo ((float) ($cohort[$bucket] ?? 0)) != 0.0 ? '$' . $money($cohort[$bucket]) : '<span class="ltv-dim">—</span>'; ?></td>
                        <?php } ?>
                        <td class="num">$<?php echo $money($cohort['total_revenue'] ?? 0); ?></td>
                        <td class="num ltv-strong">$<?php echo $money($cohort['ltv_per_customer'] ?? 0); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
<?php echo p202_ltv_card_close(); ?>

<!-- Top customers -->
<?php echo p202_ltv_card_open('Customers by Lifetime Value',
    number_format((int) $customers['total']) . ' total'); ?>
    <div class="ltv-toolbar">
        <input type="text" class="ltv-input ltv-grow" id="ltv-customer-search" maxlength="255"
               placeholder="Search ref, name, email or company&hellip;" value="<?php echo $esc($search); ?>"
               onkeydown="if (event.key === 'Enter') { ltvLoad(0); return false; }">
        <?php echo p202_ltv_chips('ltv-segment-select', $segments, $segment, 'ltvLoad(0);'); ?>
        <button type="button" class="ltv-btn" onclick="ltvLoad(0);"><i class="fa fa-search"></i> Search</button>
        <?php if ($search !== '' || $segment !== '') { ?>
            <a href="#" style="font-size: 12px;" onclick="$('#ltv-customer-search').val(''); $('#ltv-segment-select').val(''); ltvLoad(0); return false;">clear</a>
        <?php } ?>
    </div>
    <div class="ltv-table-wrap">
        <table class="ltv-table ltv-table-hover" id="ltv-customers-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Name / Company</th>
                    <th>First Seen</th>
                    <th>Last Activity</th>
                    <th class="num">Orders</th>
                    <th class="num">Revenue</th>
                    <th class="num">Refunded</th>
                    <th class="num">Active Subs</th>
                    <th class="num">MRR</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($customers['rows'] === []) { ?>
                    <tr><td colspan="9">
                        <?php echo p202_ltv_empty('fa-users', 'No customers in this range',
                            $search !== '' || $segment !== '' ? 'Try clearing the search or segment filter.' : ''); ?>
                    </td></tr>
                <?php } ?>
                <?php foreach ($customers['rows'] as $c) {
                    $displayName = trim(((string) ($c['first_name'] ?? '')) . ' ' . ((string) ($c['last_name'] ?? '')));
                    if ($displayName === '' && !empty($c['company'])) {
                        $displayName = (string) $c['company'];
                    }
                ?>
                    <tr class="ltv-row-link" onclick="ltvCustomer(<?php echo (int) $c['customer_id']; ?>);"
                        title="View customer detail">
                        <td title="<?php echo $esc($c['primary_ref'] ?? ''); ?>">
                            <?php echo $esc(mb_strimwidth((string) ($c['primary_ref'] ?? ('#' . $c['customer_id'])), 0, 40, '…')); ?>
                        </td>
                        <td><?php echo $displayName !== '' ? $esc($displayName) : '<span class="ltv-dim">—</span>'; ?></td>
                        <td data-sort="<?php echo (int) ($c['first_seen_time'] ?? 0); ?>"><?php echo date('M j, Y', (int) ($c['first_seen_time'] ?? 0)); ?></td>
                        <td data-sort="<?php echo (int) ($c['last_activity_time'] ?? 0); ?>"><?php echo date('M j, Y', (int) ($c['last_activity_time'] ?? 0)); ?></td>
                        <td class="num"><?php echo number_format((int) ($c['order_count'] ?? 0)); ?></td>
                        <td class="num ltv-strong">$<?php echo $money($c['total_revenue'] ?? 0); ?></td>
                        <td class="num"><?php echo ((float) ($c['refunded_amount'] ?? 0)) > 0 ? '$' . $money($c['refunded_amount']) : '<span class="ltv-dim">—</span>'; ?></td>
                        <td class="num"><?php echo number_format((int) ($c['active_subscription_count'] ?? 0)); ?></td>
                        <td class="num">$<?php echo $money($c['mrr'] ?? 0); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <?php echo p202_ltv_pager($offset, $limit, (int) $customers['total'], 'ltvLoad'); ?>
<?php echo p202_ltv_card_close(); ?>

<script type="text/javascript">
    function ltvLoad(offset) {
        ltvNav('report', {
            offset: offset,
            ltv_by: $('#ltv-by-select').val(),
            q: $('#ltv-customer-search').val(),
            segment: $('#ltv-segment-select').val()
        });
    }
    function ltvCustomer(customerId) {
        ltvNav('customer', { customer_id: customerId });
    }
    function ltvCompany(company) {
        ltvNav('company', { company: company });
    }

    new Tablesort(document.getElementById('ltv-breakdown-table'), { descending: true });
    new Tablesort(document.getElementById('ltv-customers-table'), { descending: true });
</script>
