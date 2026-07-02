<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect2.php');

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

/**
 * ABM account drill-down: one company's contacts with their individual
 * engagement, value and per-contact score.
 */

$userId = (int) $_SESSION['user_id'];
$company = isset($_POST['company']) && is_scalar($_POST['company']) ? trim((string) $_POST['company']) : '';

$money = static fn (mixed $v): string => number_format((float) $v, 2);
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when = static fn (mixed $ts): string => ((int) $ts) > 0 ? date('M j, Y', (int) $ts) : '—';

$backUrl = get_absolute_url() . 'tracking202/ajax/sort_ltv.php';

$contacts = [];
try {
    if ($company !== '') {
        $conn = new \Prosper202\Database\Connection($db);
        $engagementRepo = new \Prosper202\Ltv\MysqlEngagementRepository($conn);
        $contacts = $engagementRepo->abmCompanyDetail($userId, $company, 90);
        foreach ($contacts as &$contact) {
            $aggregates = $engagementRepo->customerEngagementAggregates($userId, (int) $contact['customer_id'], 90);
            $contact['engagement_score'] = \Prosper202\Ltv\MysqlEngagementRepository::engagementScore($aggregates);
        }
        unset($contact);
    }
} catch (\Throwable $e) {
    error_log('ltv_company: ' . $e->getMessage());
    $contacts = [];
}
?>

<div class="row" style="margin-bottom: 10px;">
    <div class="col-xs-12">
        <a href="#" onclick="loadContent('<?php echo $backUrl; ?>', null); return false;">&laquo; Back to Customer LTV</a>
    </div>
</div>

<?php if ($company === '' || $contacts === []) { ?>
    <div class="alert alert-warning">No contacts found for this company.</div>
    <?php return; ?>
<?php } ?>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-xs-12">
        <h6><?php echo $esc($company); ?> <small>account view &mdash; <?php echo count($contacts); ?> contact(s), last 90 days</small></h6>
    </div>
</div>

<div class="row">
    <div class="col-xs-12">
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th>Contact</th>
                    <th>Email</th>
                    <th>Score</th>
                    <th>Engagements</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>MRR</th>
                    <th>Last Activity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contacts as $contact) {
                    $name = trim(((string) ($contact['first_name'] ?? '')) . ' ' . ((string) ($contact['last_name'] ?? '')));
                ?>
                    <tr style="cursor: pointer;" onclick="ltvCompanyCustomer(<?php echo (int) $contact['customer_id']; ?>);" title="View customer detail">
                        <td><?php echo $esc($name !== '' ? $name : ('#' . $contact['customer_id'])); ?></td>
                        <td><?php echo $esc($contact['email'] ?? '') ?: '—'; ?></td>
                        <td><strong><?php echo (int) ($contact['engagement_score'] ?? 0); ?></strong>/100</td>
                        <td><?php echo number_format((int) ($contact['engagements'] ?? 0)); ?></td>
                        <td><?php echo number_format((int) ($contact['order_count'] ?? 0)); ?></td>
                        <td>$<?php echo $money($contact['total_revenue'] ?? 0); ?></td>
                        <td>$<?php echo $money($contact['mrr'] ?? 0); ?></td>
                        <td><?php echo $when($contact['last_activity_time'] ?? 0); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<script type="text/javascript">
    function ltvCompanyCustomer(customerId) {
        var element = $('#m-content');
        $.post('<?php echo get_absolute_url(); ?>tracking202/ajax/ltv_customer.php', {
            customer_id: customerId
        }).done(function(data) {
            element.html(data);
            element.css('opacity', '1');
        });
    }
</script>
