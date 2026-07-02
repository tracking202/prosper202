<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

/**
 * Customer detail partial for the LTV report. Three modes:
 *   default          — read-only detail view with an Edit button
 *   view=edit        — edit form (CRM fields + all defined custom fields)
 *   action=save      — CSRF-checked save via MysqlCustomerCrmRepository::upsert(),
 *                      then the detail view again (or the form + error message
 *                      with the entered values preserved on validation failure)
 */

$userId = (int) $_SESSION['user_id'];
$customerId = (int) ($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);
$mode = (string) ($_POST['view'] ?? '');
$action = (string) ($_POST['action'] ?? '');

$money = static fn (mixed $v): string => number_format((float) $v, 2);
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when = static fn (mixed $ts): string => ((int) $ts) > 0 ? date('M j, Y g:ia', (int) $ts) : '—';

$backUrl = get_absolute_url() . 'tracking202/ajax/sort_ltv.php';
$selfUrl = get_absolute_url() . 'tracking202/ajax/ltv_customer.php';

$saveError = null;
$saved = false;

try {
    $conn = new \Prosper202\Database\Connection($db);
    $customersRepo = new \Prosper202\Ltv\MysqlCustomerRepository($conn);
    $fieldsRepo = new \Prosper202\Ltv\MysqlCustomerFieldRepository($conn);
    $crm = new \Prosper202\Ltv\MysqlCustomerCrmRepository($conn, $customersRepo, $fieldsRepo);

    // ---- Save (CSRF-gated write) ----
    if ($action === 'save' && $customerId > 0) {
        if (!AUTH::check_csrf_token()) {
            $saveError = 'Your session token was invalid — please try again.';
            $mode = 'edit';
        } else {
            $crmInput = isset($_POST['crm']) && is_array($_POST['crm']) ? $_POST['crm'] : [];
            $cfInput = isset($_POST['cf']) && is_array($_POST['cf']) ? $_POST['cf'] : [];

            $payload = ['customer_id' => $customerId];
            foreach (['first_name', 'last_name', 'phone', 'company',
                'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country'] as $column) {
                // Always present from the form: an emptied input deliberately
                // clears the stored value.
                $payload[$column] = trim((string) ($crmInput[$column] ?? ''));
            }
            $payload['email'] = trim((string) ($_POST['email'] ?? ''));
            if ($cfInput !== []) {
                $payload['custom_fields'] = array_map(
                    static fn ($v): string => trim((string) $v),
                    $cfInput
                );
            }

            try {
                $crm->upsert($userId, $payload);
                $saved = true;
            } catch (\RuntimeException $validation) {
                $saveError = $validation->getMessage();
                $mode = 'edit'; // re-render the form with the entered values
            }
        }
    }

    $customer = $customerId > 0 ? $crm->get($userId, $customerId, 50) : null;
    $fieldDefinitions = $customer !== null ? $fieldsRepo->list($userId) : [];

    $engagement = [];
    $engagementEvents = [];
    $nextOffer = null;
    if ($customer !== null) {
        $engagementRepo = new \Prosper202\Ltv\MysqlEngagementRepository($conn);
        $engagement = $engagementRepo->customerEngagement($userId, $customerId, 90);
        $engagementEvents = $engagementRepo->customerEvents($userId, $customerId, 90, 25);
        $nextOffer = (new \Prosper202\Ltv\MysqlRecommendationRepository($conn))->nextOffer($userId, $customerId);
    }
} catch (\Throwable $e) {
    error_log('ltv_customer: ' . $e->getMessage());
    $customer = null;
    $fieldDefinitions = [];
    $engagement = [];
    $engagementEvents = [];
    $nextOffer = null;
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

<?php if ($saved) { ?>
    <div class="alert alert-success">Customer updated.</div>
<?php } ?>
<?php if ($saveError !== null) { ?>
    <div class="alert alert-danger"><?php echo $esc($saveError); ?></div>
<?php } ?>

<?php
$displayName = trim(((string) ($customer['first_name'] ?? '')) . ' ' . ((string) ($customer['last_name'] ?? '')));
if ($displayName === '') {
    $displayName = (string) ($customer['company'] ?? '');
}
if ($displayName === '') {
    $displayName = (string) ($customer['primary_ref'] ?? ('Customer #' . $customerId));
}

// When re-rendering the edit form after a failed save, show what the user
// typed, not the stored values, so nothing they entered is lost.
$fromPost = $saveError !== null && $action === 'save';
$crmValue = static function (string $column) use ($fromPost, $customer): string {
    if ($fromPost) {
        $crmInput = isset($_POST['crm']) && is_array($_POST['crm']) ? $_POST['crm'] : [];
        return trim((string) ($crmInput[$column] ?? ''));
    }
    return (string) ($customer[$column] ?? '');
};
$emailValue = $fromPost ? trim((string) ($_POST['email'] ?? '')) : (string) ($customer['email'] ?? '');
$cfValue = static function (array $field) use ($fromPost, $customer): string {
    $key = (string) $field['field_key'];
    if ($fromPost) {
        $cfInput = isset($_POST['cf']) && is_array($_POST['cf']) ? $_POST['cf'] : [];
        return trim((string) ($cfInput[$key] ?? ''));
    }
    $value = $customer['custom_fields'][$key] ?? null;
    if ($value === null) {
        return '';
    }
    return match ((string) $field['field_type']) {
        'boolean' => $value ? '1' : '0',
        'date' => date('Y-m-d', (int) $value),
        default => (string) $value,
    };
};
?>

<?php if ($mode === 'edit') { ?>
    <!-- ================= EDIT MODE ================= -->
    <div class="row" style="margin-bottom: 15px;">
        <div class="col-xs-12">
            <h6>Edit <?php echo $esc($displayName); ?> <small>customer #<?php echo (int) $customer['customer_id']; ?></small></h6>
        </div>
    </div>

    <form id="ltv-customer-edit-form" onsubmit="return false;">
        <input type="hidden" name="token" value="<?php echo $esc($_SESSION['token'] ?? ''); ?>" />
        <input type="hidden" name="customer_id" value="<?php echo (int) $customer['customer_id']; ?>" />
        <input type="hidden" name="action" value="save" />

        <div class="row">
            <div class="col-sm-6">
                <h6>Profile</h6>
                <table class="table table-bordered">
                    <tbody>
                        <tr><th style="width: 35%;">Customer Ref</th>
                            <td><?php echo $esc($customer['primary_ref'] ?? ''); ?>
                                <br><small class="text-muted">Identity key — managed via aliases, not editable.</small></td></tr>
                        <tr><th>First Name</th>
                            <td><input type="text" class="form-control" name="crm[first_name]" maxlength="100" value="<?php echo $esc($crmValue('first_name')); ?>"></td></tr>
                        <tr><th>Last Name</th>
                            <td><input type="text" class="form-control" name="crm[last_name]" maxlength="100" value="<?php echo $esc($crmValue('last_name')); ?>"></td></tr>
                        <tr><th>Email</th>
                            <td><input type="text" class="form-control" name="email" maxlength="255" value="<?php echo $esc($emailValue); ?>"></td></tr>
                        <tr><th>Phone</th>
                            <td><input type="text" class="form-control" name="crm[phone]" maxlength="50" value="<?php echo $esc($crmValue('phone')); ?>"></td></tr>
                        <tr><th>Company</th>
                            <td><input type="text" class="form-control" name="crm[company]" maxlength="255" value="<?php echo $esc($crmValue('company')); ?>"></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-sm-6">
                <h6>Address</h6>
                <table class="table table-bordered">
                    <tbody>
                        <tr><th style="width: 35%;">Address Line 1</th>
                            <td><input type="text" class="form-control" name="crm[address_line1]" maxlength="255" value="<?php echo $esc($crmValue('address_line1')); ?>"></td></tr>
                        <tr><th>Address Line 2</th>
                            <td><input type="text" class="form-control" name="crm[address_line2]" maxlength="255" value="<?php echo $esc($crmValue('address_line2')); ?>"></td></tr>
                        <tr><th>City</th>
                            <td><input type="text" class="form-control" name="crm[city]" maxlength="100" value="<?php echo $esc($crmValue('city')); ?>"></td></tr>
                        <tr><th>Region / State</th>
                            <td><input type="text" class="form-control" name="crm[region]" maxlength="100" value="<?php echo $esc($crmValue('region')); ?>"></td></tr>
                        <tr><th>Postal Code</th>
                            <td><input type="text" class="form-control" name="crm[postal_code]" maxlength="20" value="<?php echo $esc($crmValue('postal_code')); ?>"></td></tr>
                        <tr><th>Country <small>(2-letter code)</small></th>
                            <td><input type="text" class="form-control" name="crm[country]" maxlength="2" value="<?php echo $esc($crmValue('country')); ?>"></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($fieldDefinitions !== []) { ?>
            <div class="row">
                <div class="col-sm-6">
                    <h6>Custom Fields</h6>
                    <table class="table table-bordered">
                        <tbody>
                            <?php foreach ($fieldDefinitions as $field) {
                                $key = (string) $field['field_key'];
                                $label = (string) ($field['label'] ?? $key);
                                $type = (string) $field['field_type'];
                                $value = $cfValue($field);
                            ?>
                                <tr>
                                    <th style="width: 35%;"><?php echo $esc($label); ?>
                                        <br><small class="text-muted"><?php echo $esc($type); ?></small></th>
                                    <td>
                                        <?php if ($type === 'boolean') { ?>
                                            <select class="form-control" name="cf[<?php echo $esc($key); ?>]">
                                                <option value="" <?php if ($value === '') { echo 'selected'; } ?>>&mdash;</option>
                                                <option value="1" <?php if ($value === '1') { echo 'selected'; } ?>>Yes</option>
                                                <option value="0" <?php if ($value === '0') { echo 'selected'; } ?>>No</option>
                                            </select>
                                        <?php } elseif ($type === 'select') {
                                            $options = is_string($field['options'] ?? null) ? json_decode((string) $field['options'], true) : ($field['options'] ?? []);
                                            $options = is_array($options) ? $options : [];
                                        ?>
                                            <select class="form-control" name="cf[<?php echo $esc($key); ?>]">
                                                <option value="" <?php if ($value === '') { echo 'selected'; } ?>>&mdash;</option>
                                                <?php foreach ($options as $option) { ?>
                                                    <option value="<?php echo $esc($option); ?>" <?php if ($value === (string) $option) { echo 'selected'; } ?>><?php echo $esc($option); ?></option>
                                                <?php } ?>
                                            </select>
                                        <?php } else { ?>
                                            <input type="text" class="form-control" name="cf[<?php echo $esc($key); ?>]"
                                                value="<?php echo $esc($value); ?>"
                                                <?php if ($type === 'date') { echo 'placeholder="YYYY-MM-DD"'; } ?>
                                                <?php if ($type === 'number') { echo 'placeholder="e.g. 42.5"'; } ?>>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php } ?>

        <div class="row" style="margin-bottom: 15px;">
            <div class="col-xs-12">
                <button type="button" class="btn btn-primary" onclick="ltvCustomerSave();">Save Changes</button>
                <button type="button" class="btn btn-default" onclick="ltvCustomerView(<?php echo (int) $customer['customer_id']; ?>);">Cancel</button>
                <small class="text-muted" style="margin-left: 10px;">Emptying a field clears its stored value.</small>
            </div>
        </div>
    </form>

    <script type="text/javascript">
        function ltvCustomerView(customerId) {
            var element = $('#m-content');
            $.post('<?php echo $selfUrl; ?>', { customer_id: customerId })
                .done(function(data) { element.html(data).css('opacity', '1'); });
        }
        function ltvCustomerSave() {
            var element = $('#m-content');
            $.post('<?php echo $selfUrl; ?>', $('#ltv-customer-edit-form').serialize())
                .done(function(data) { element.html(data).css('opacity', '1'); });
        }
    </script>

    <?php return; ?>
<?php } ?>

<!-- ================= VIEW MODE ================= -->
<?php
$addressParts = array_filter([
    (string) ($customer['address_line1'] ?? ''),
    (string) ($customer['address_line2'] ?? ''),
    trim(((string) ($customer['city'] ?? '')) . ' ' . ((string) ($customer['region'] ?? ''))),
    (string) ($customer['postal_code'] ?? ''),
    (string) ($customer['country'] ?? ''),
], static fn (string $part): bool => trim($part) !== '');
?>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-xs-8">
        <h6><?php echo $esc($displayName); ?>
            <small>customer #<?php echo (int) $customer['customer_id']; ?>
                <?php if ((string) ($customer['status'] ?? 'active') !== 'active') { ?>
                    &mdash; <?php echo $esc($customer['status']); ?>
                <?php } ?>
            </small>
        </h6>
    </div>
    <div class="col-xs-4 text-right">
        <button type="button" class="btn btn-sm btn-default" onclick="ltvCustomerEdit(<?php echo (int) $customer['customer_id']; ?>);">
            <i class="fa fa-pencil"></i> Edit Customer
        </button>
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

<!-- Suggested next offer + engagement -->
<div class="row">
    <div class="col-sm-6">
        <h6>Suggested Next Offer</h6>
        <?php if ($nextOffer === null) { ?>
            <p class="text-muted"><em>Not enough conversion history yet to suggest an offer.</em></p>
        <?php } else { ?>
            <p>
                <strong><?php echo $esc($nextOffer['name']); ?></strong>
                <small>(campaign #<?php echo (int) $nextOffer['campaign_id']; ?>)</small><br>
                <small class="text-muted">Based on what customers with similar purchases converted on next.</small>
            </p>
        <?php } ?>
    </div>
    <div class="col-sm-6">
        <h6>Engagement <small>(last 90 days)</small></h6>
        <table class="table table-bordered table-hover">
            <thead>
                <tr><th>Campaign</th><th>Landing Page</th><th>Views</th><th>Conv.</th><th>Last Seen</th></tr>
            </thead>
            <tbody>
                <?php if ($engagement === []) { ?>
                    <tr><td colspan="5"><em>No tracked browsing in this window.</em></td></tr>
                <?php } ?>
                <?php foreach ($engagement as $row) { ?>
                    <tr>
                        <td><?php echo $esc($row['campaign_name'] ?? ('#' . ($row['campaign_id'] ?? ''))); ?></td>
                        <td><?php echo $esc($row['landing_page'] ?? '') ?: '—'; ?></td>
                        <td><?php echo number_format((int) ($row['clicks'] ?? 0)); ?></td>
                        <td><?php echo number_format((int) ($row['conversions'] ?? 0)); ?></td>
                        <td><?php echo $when($row['last_seen'] ?? 0); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <?php if ($engagementEvents !== []) { ?>
            <h6>Instrumented Events <small>(last 90 days)</small></h6>
            <table class="table table-bordered table-hover">
                <thead>
                    <tr><th>Event</th><th>Value</th><th>Source</th><th>When</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($engagementEvents as $event) {
                        $eventName = (string) ($event['event_name'] ?? '');
                        $eventValue = $event['event_value'] ?? null;
                        // Friendly units for the auto-instrumented depth metrics.
                        $valueLabel = '—';
                        if ($eventValue !== null) {
                            $valueLabel = match ($eventName) {
                                'time_on_page' => number_format((float) $eventValue) . 's',
                                'scroll_depth', 'video_viewed' => number_format((float) $eventValue) . '%',
                                default => rtrim(rtrim(number_format((float) $eventValue, 3), '0'), '.'),
                            };
                        }
                    ?>
                        <tr>
                            <td><?php echo $esc($eventName); ?></td>
                            <td><?php echo $esc($valueLabel); ?></td>
                            <td><?php echo $esc($event['source'] ?? ''); ?></td>
                            <td><?php echo $when($event['occurred_at'] ?? 0); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        <?php } ?>
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

<script type="text/javascript">
    function ltvCustomerEdit(customerId) {
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', { customer_id: customerId, view: 'edit' })
            .done(function(data) { element.html(data).css('opacity', '1'); });
    }
</script>
