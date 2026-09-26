<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();

// Draw the window ltv.php drew, not whatever the stored one says by now: a
// second tab may have stored another (ReportView). ltv.js sends the view
// with every request under tracking202/ajax/.
require_once(substr(__DIR__, 0, -17) . '/202-config/functions-report-prefs.php');
$reportView = p202_report_view_begin();
AUTH::set_timezone($_SESSION['user_timezone']);

/**
 * ABM account drill-down: one company's contacts with their individual
 * engagement, value and per-contact score.
 */

$userId = (int) $_SESSION['user_id'];
$company = isset($_POST['company']) && is_scalar($_POST['company']) ? trim((string) $_POST['company']) : '';

require_once __DIR__ . '/ltv_ui.php';
$money = p202_ltv_money(...);
$esc = p202_ltv_esc(...);
$when = p202_ltv_when(...);

$contacts = [];
try {
    if ($company !== '') {
        $conn = new \Prosper202\Database\Connection($db);
        $engagementRepo = new \Prosper202\Ltv\MysqlEngagementRepository($conn);
        // Rows arrive scored: abmCompanyDetail aggregates clicks + events per
        // contact set-based and attaches engagement_score itself.
        $contacts = $engagementRepo->abmCompanyDetail($userId, $company, 90);
    }
} catch (\Throwable $e) {
    error_log('ltv_company: ' . $e->getMessage());
    $contacts = [];
}
?>

<?php echo p202_ltv_ui_styles(); ?>

<a href="#" class="btn btn-link btn-sm px-0 mb-2" onclick="ltvNav('companies'); return false;"><i class="bi bi-chevron-left"></i> All companies</a>

<?php if ($company === '' || $contacts === []) { ?>
    <?php echo p202_ltv_flash('warn', 'No contacts found for this company.'); ?>
    <?php return; ?>
<?php } ?>

<div class="p202-page-header">
    <span class="p202-page-header__icon"><i class="bi bi-building"></i></span>
    <div class="p202-page-header__text">
        <div class="p202-page-header__title"><?php echo $esc($company); ?></div>
        <div class="p202-page-header__desc">Account view &middot; <?php echo count($contacts); ?> contact(s) &middot; last 90 days</div>
    </div>
</div>

<?php echo p202_ltv_card_open('Contacts'); ?>
    <div class="p202-table-wrap">
        <table class="table p202-table table-hover">
            <thead>
                <tr>
                    <th>Contact</th>
                    <th>Email</th>
                    <th class="num">Score</th>
                    <th class="num">Engagements</th>
                    <th class="num">Orders</th>
                    <th class="num">Revenue</th>
                    <th class="num">MRR</th>
                    <th>Last Activity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contacts as $contact) {
                    $name = trim(((string) ($contact['first_name'] ?? '')) . ' ' . ((string) ($contact['last_name'] ?? '')));
                    $score = (int) ($contact['engagement_score'] ?? 0);
                ?>
                    <tr class="p202-table__link-row" onclick="ltvCompanyCustomer(<?php echo (int) $contact['customer_id']; ?>);" title="View customer detail">
                        <td class="fw-bold"><?php echo $esc($name !== '' ? $name : ('#' . $contact['customer_id'])); ?></td>
                        <td><?php echo $esc($contact['email'] ?? '') ?: '<span class="text-secondary">—</span>'; ?></td>
                        <td class="num"><?php echo p202_ltv_pill($score . '/100', $score >= 70 ? 'green' : ($score >= 40 ? 'blue' : 'gray')); ?></td>
                        <td class="num"><?php echo number_format((int) ($contact['engagements'] ?? 0)); ?></td>
                        <td class="num"><?php echo number_format((int) ($contact['order_count'] ?? 0)); ?></td>
                        <td class="num fw-bold">$<?php echo $money($contact['total_revenue'] ?? 0); ?></td>
                        <td class="num">$<?php echo $money($contact['mrr'] ?? 0); ?></td>
                        <td><?php echo $when($contact['last_activity_time'] ?? 0); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
<?php echo p202_ltv_card_close(); ?>

<script type="text/javascript">
    function ltvCompanyCustomer(customerId) {
        ltvNav('customer', { customer_id: customerId });
    }
</script>
