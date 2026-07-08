<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

/**
 * Customer detail partial for the LTV report. Three modes:
 *   default          — read-only detail view with an Edit button
 *   mode=edit        — edit form (CRM fields + all defined custom fields)
 *   action=save      — CSRF-checked save via MysqlCustomerCrmRepository::upsert(),
 *                      then the detail view again (or the form + error message
 *                      with the entered values preserved on validation failure)
 */

$userId = (int) $_SESSION['user_id'];
$customerId = (int) ($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);
$mode = (string) ($_POST['mode'] ?? '');
$action = (string) ($_POST['action'] ?? '');

require_once __DIR__ . '/ltv_ui.php';
$money = p202_ltv_money(...);
$esc = p202_ltv_esc(...);
$when = static fn (mixed $ts): string => p202_ltv_when($ts, true);

$selfUrl = get_absolute_url() . 'tracking202/ajax/ltv_customer.php';

$saveError = null;
$saved = false;

try {
    $conn = new \Prosper202\Database\Connection($db);
    $customersRepo = new \Prosper202\Ltv\MysqlCustomerRepository($conn);
    $fieldsRepo = new \Prosper202\Ltv\MysqlCustomerFieldRepository($conn);
    $crm = new \Prosper202\Ltv\MysqlCustomerCrmRepository($conn, $customersRepo, $fieldsRepo);

    // ---- Erase (CSRF-gated, destructive: PII removed, ledger kept) ----
    if ($action === 'erase' && $customerId > 0) {
        if (!AUTH::check_csrf_token()) {
            $saveError = 'Your session token was invalid — please try again.';
        } else {
            try {
                $crm->erase($userId, $customerId);
                echo p202_ltv_ui_styles()
                    . '<a href="#" class="ltv-back" onclick="ltvNav(\'report\'); return false;">'
                    . '<i class="fa fa-angle-left"></i> Back to Customer LTV</a>'
                    . p202_ltv_flash('success', 'Customer erased: personal data, aliases, custom fields and '
                        . 'personalization tokens removed. Revenue totals were kept for reporting integrity.');
                return;
            } catch (\RuntimeException $eraseError) {
                $saveError = $eraseError->getMessage();
            }
        }
    }

    // ---- Merge (CSRF-gated: another customer merges INTO this one) ----
    if ($action === 'merge' && $customerId > 0) {
        if (!AUTH::check_csrf_token()) {
            $saveError = 'Your session token was invalid — please try again.';
        } else {
            $sourceId = (int) ($_POST['source_customer_id'] ?? 0);
            try {
                if ($sourceId <= 0) {
                    throw new \RuntimeException('Enter the customer # to merge into this record.');
                }
                $crm->merge($userId, $sourceId, $customerId);
                $saved = true; // fall through to render the refreshed detail
            } catch (\RuntimeException $mergeError) {
                $saveError = $mergeError->getMessage();
            }
        }
    }

    // ---- Alias add/remove (CSRF-gated identity edits) ----
    if ($action === 'add_alias' && $customerId > 0) {
        if (!AUTH::check_csrf_token()) {
            $saveError = 'Your session token was invalid — please try again.';
        } else {
            try {
                $owner = $conn->transaction(fn (): int => $customersRepo->addAlias(
                    $userId,
                    $customerId,
                    (string) ($_POST['alias_type'] ?? 'custom'),
                    (string) ($_POST['alias_value'] ?? ''),
                    time()
                ));
                if ($owner !== $customerId) {
                    $saveError = 'That identity already belongs to customer #' . $owner
                        . ' — merge the two records instead of re-pointing the alias.';
                } else {
                    $saved = true;
                }
            } catch (\RuntimeException $aliasError) {
                $saveError = $aliasError->getMessage();
            }
        }
    }
    if ($action === 'delete_alias' && $customerId > 0) {
        if (!AUTH::check_csrf_token()) {
            $saveError = 'Your session token was invalid — please try again.';
        } else {
            try {
                $customersRepo->deleteAlias($userId, $customerId, (int) ($_POST['alias_id'] ?? 0));
                $saved = true;
            } catch (\RuntimeException $aliasError) {
                $saveError = $aliasError->getMessage();
            }
        }
    }

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
    $nextOfferShown = null;
    $engagementScore = 0;
    if ($customer !== null) {
        $engagementRepo = new \Prosper202\Ltv\MysqlEngagementRepository($conn);
        $engagement = $engagementRepo->customerEngagement($userId, $customerId, 90);
        $engagementEvents = $engagementRepo->customerEvents($userId, $customerId, 90, 25);
        $nextOffer = (new \Prosper202\Ltv\MysqlRecommendationRepository($conn))->nextOffer($userId, $customerId);
        $nextOfferShown = null;
        if ($nextOffer !== null) {
            // Exposure so far, from the recommendation decision log.
            $shownStmt = $conn->prepareRead(
                'SELECT SUM(times_shown) AS shown, MIN(first_shown_at) AS first_at
                 FROM 202_offer_recommendations
                 WHERE user_id = ? AND customer_id = ? AND campaign_id = ?'
            );
            $conn->bind($shownStmt, 'iii', [$userId, $customerId, (int) $nextOffer['campaign_id']]);
            $shownRow = $conn->fetchOne($shownStmt);
            if ($shownRow !== null && (int) ($shownRow['shown'] ?? 0) > 0) {
                $nextOfferShown = ['shown' => (int) $shownRow['shown'], 'first_at' => (int) $shownRow['first_at']];
            }
        }
        $engagementScore = \Prosper202\Ltv\MysqlEngagementRepository::engagementScore(
            $engagementRepo->customerEngagementAggregates($userId, $customerId, 90),
            null,
            $engagementRepo->scoreWeights($userId)
        );
    }
} catch (\Throwable $e) {
    error_log('ltv_customer: ' . $e->getMessage());
    $customer = null;
    $fieldDefinitions = [];
    $engagement = [];
    $engagementEvents = [];
    $nextOffer = null;
    $nextOfferShown = null;
    $engagementScore = 0;
}
?>

<?php echo p202_ltv_ui_styles(); ?>

<a href="#" class="ltv-back" onclick="ltvNav('report'); return false;"><i class="fa fa-angle-left"></i> Back to Customer LTV</a>

<?php if ($customer === null) { ?>
    <?php echo p202_ltv_flash('warn', 'Customer not found.'); ?>
    <?php return; ?>
<?php } ?>

<?php if ($saved) { ?>
    <?php echo p202_ltv_flash('success', 'Customer updated.'); ?>
<?php } ?>
<?php if ($saveError !== null) { ?>
    <?php echo p202_ltv_flash('error', $esc($saveError)); ?>
<?php } ?>

<?php
$displayName = trim(((string) ($customer['first_name'] ?? '')) . ' ' . ((string) ($customer['last_name'] ?? '')));
if ($displayName === '') {
    $displayName = (string) ($customer['company'] ?? '');
}
if ($displayName === '') {
    $displayName = (string) ($customer['primary_ref'] ?? ('Customer #' . $customerId));
}
$avatarInitial = mb_strtoupper(mb_substr($displayName, 0, 1));

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
    <div class="ltv-page-head">
        <span class="ltv-avatar"><?php echo $esc($avatarInitial); ?></span>
        <div>
            <div class="ltv-page-title">Edit <?php echo $esc($displayName); ?></div>
            <div class="ltv-page-sub">Customer #<?php echo (int) $customer['customer_id']; ?></div>
        </div>
    </div>

    <form id="ltv-customer-edit-form" onsubmit="return false;">
        <input type="hidden" name="token" value="<?php echo $esc($_SESSION['token'] ?? ''); ?>" />
        <input type="hidden" name="customer_id" value="<?php echo (int) $customer['customer_id']; ?>" />
        <input type="hidden" name="action" value="save" />

        <div class="ltv-cols">
            <?php echo p202_ltv_card_open('Profile'); ?>
                <div class="ltv-card-body">
                    <div class="ltv-def"><div class="ltv-def-label">Customer Ref
                            <small>Identity key — managed via aliases, not editable.</small></div>
                        <div class="ltv-def-value"><?php echo $esc($customer['primary_ref'] ?? ''); ?></div></div>
                    <div class="ltv-def"><div class="ltv-def-label">First Name</div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="crm[first_name]" maxlength="100" value="<?php echo $esc($crmValue('first_name')); ?>"></div></div>
                    <div class="ltv-def"><div class="ltv-def-label">Last Name</div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="crm[last_name]" maxlength="100" value="<?php echo $esc($crmValue('last_name')); ?>"></div></div>
                    <div class="ltv-def"><div class="ltv-def-label">Email</div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="email" maxlength="255" value="<?php echo $esc($emailValue); ?>"></div></div>
                    <div class="ltv-def"><div class="ltv-def-label">Phone</div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="crm[phone]" maxlength="50" value="<?php echo $esc($crmValue('phone')); ?>"></div></div>
                    <div class="ltv-def"><div class="ltv-def-label">Company</div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="crm[company]" maxlength="255" value="<?php echo $esc($crmValue('company')); ?>"></div></div>
                </div>
            <?php echo p202_ltv_card_close(); ?>
            <?php echo p202_ltv_card_open('Address'); ?>
                <div class="ltv-card-body">
                    <div class="ltv-def"><div class="ltv-def-label">Address Line 1</div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="crm[address_line1]" maxlength="255" value="<?php echo $esc($crmValue('address_line1')); ?>"></div></div>
                    <div class="ltv-def"><div class="ltv-def-label">Address Line 2</div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="crm[address_line2]" maxlength="255" value="<?php echo $esc($crmValue('address_line2')); ?>"></div></div>
                    <div class="ltv-def"><div class="ltv-def-label">City</div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="crm[city]" maxlength="100" value="<?php echo $esc($crmValue('city')); ?>"></div></div>
                    <div class="ltv-def"><div class="ltv-def-label">Region / State</div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="crm[region]" maxlength="100" value="<?php echo $esc($crmValue('region')); ?>"></div></div>
                    <div class="ltv-def"><div class="ltv-def-label">Postal Code</div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="crm[postal_code]" maxlength="20" value="<?php echo $esc($crmValue('postal_code')); ?>"></div></div>
                    <div class="ltv-def"><div class="ltv-def-label">Country <small>2-letter code</small></div>
                        <div class="ltv-def-value"><input type="text" class="ltv-input" name="crm[country]" maxlength="2" value="<?php echo $esc($crmValue('country')); ?>"></div></div>
                </div>
            <?php echo p202_ltv_card_close(); ?>
        </div>

        <?php if ($fieldDefinitions !== []) { ?>
            <?php echo p202_ltv_card_open('Custom Fields'); ?>
                <div class="ltv-card-body">
                    <?php foreach ($fieldDefinitions as $field) {
                        $key = (string) $field['field_key'];
                        $label = (string) ($field['label'] ?? $key);
                        $type = (string) $field['field_type'];
                        $value = $cfValue($field);
                    ?>
                        <div class="ltv-def">
                            <div class="ltv-def-label"><?php echo $esc($label); ?>
                                <small><?php echo $esc($type); ?></small></div>
                            <div class="ltv-def-value">
                                <?php if ($type === 'boolean') { ?>
                                    <select class="ltv-select" name="cf[<?php echo $esc($key); ?>]">
                                        <option value="" <?php if ($value === '') { echo 'selected'; } ?>>&mdash;</option>
                                        <option value="1" <?php if ($value === '1') { echo 'selected'; } ?>>Yes</option>
                                        <option value="0" <?php if ($value === '0') { echo 'selected'; } ?>>No</option>
                                    </select>
                                <?php } elseif ($type === 'select') {
                                    $options = is_string($field['options'] ?? null) ? json_decode((string) $field['options'], true) : ($field['options'] ?? []);
                                    $options = is_array($options) ? $options : [];
                                ?>
                                    <select class="ltv-select" name="cf[<?php echo $esc($key); ?>]">
                                        <option value="" <?php if ($value === '') { echo 'selected'; } ?>>&mdash;</option>
                                        <?php foreach ($options as $option) { ?>
                                            <option value="<?php echo $esc($option); ?>" <?php if ($value === (string) $option) { echo 'selected'; } ?>><?php echo $esc($option); ?></option>
                                        <?php } ?>
                                    </select>
                                <?php } else { ?>
                                    <input type="text" class="ltv-input" name="cf[<?php echo $esc($key); ?>]"
                                        value="<?php echo $esc($value); ?>"
                                        <?php if ($type === 'date') { echo 'placeholder="YYYY-MM-DD"'; } ?>
                                        <?php if ($type === 'number') { echo 'placeholder="e.g. 42.5"'; } ?>>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            <?php echo p202_ltv_card_close(); ?>
        <?php } ?>

        <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 16px;">
            <button type="button" class="ltv-btn ltv-btn-primary" onclick="ltvCustomerSave();">Save Changes</button>
            <button type="button" class="ltv-btn" onclick="ltvCustomerView(<?php echo (int) $customer['customer_id']; ?>);">Cancel</button>
            <span class="ltv-note">Emptying a field clears its stored value.</span>
        </div>
    </form>

    <script type="text/javascript">
        function ltvCustomerView(customerId) {
            ltvNav('customer', { customer_id: customerId });
        }
        function ltvCustomerSave() {
            loadContentPost('<?php echo $selfUrl; ?>', $('#ltv-customer-edit-form').serialize());
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
$customerStatus = (string) ($customer['status'] ?? 'active');
?>

<div class="ltv-page-head">
    <span class="ltv-avatar"><?php echo $esc($avatarInitial); ?></span>
    <div>
        <div class="ltv-page-title"><?php echo $esc($displayName); ?>
            <?php if ($customerStatus !== 'active') { ?>
                <?php echo p202_ltv_status_pill($customerStatus); ?>
            <?php } ?>
            <?php echo p202_ltv_pill('Engagement ' . (int) $engagementScore . '/100',
                $engagementScore >= 70 ? 'green' : ($engagementScore >= 40 ? 'blue' : 'gray')); ?>
        </div>
        <div class="ltv-page-sub">Customer #<?php echo (int) $customer['customer_id']; ?></div>
    </div>
    <div class="ltv-page-actions">
        <button type="button" class="ltv-btn" onclick="ltvCustomerEdit(<?php echo (int) $customer['customer_id']; ?>);">
            <i class="fa fa-pencil"></i> Edit
        </button>
        <button type="button" class="ltv-btn" onclick="ltvCustomerMerge(<?php echo (int) $customer['customer_id']; ?>);" title="Merge another customer record into this one">
            <i class="fa fa-compress"></i> Merge
        </button>
        <button type="button" class="ltv-btn ltv-btn-danger" onclick="ltvCustomerErase(<?php echo (int) $customer['customer_id']; ?>);" title="Erase personal data (GDPR); revenue totals are kept">
            <i class="fa fa-eraser"></i> Erase
        </button>
    </div>
</div>

<!-- Lifetime rollups -->
<div class="ltv-stats">
    <?php echo p202_ltv_stat('Lifetime Revenue', '$' . $money($customer['total_revenue'] ?? 0),
        ((float) ($customer['refunded_amount'] ?? 0)) > 0 ? '$' . $money($customer['refunded_amount']) . ' refunded' : ''); ?>
    <?php echo p202_ltv_stat('Orders', number_format((int) ($customer['order_count'] ?? 0))); ?>
    <?php echo p202_ltv_stat('Avg Order', '$' . $money(((int) ($customer['order_count'] ?? 0)) > 0
        ? ((float) ($customer['total_revenue'] ?? 0)) / (int) $customer['order_count']
        : 0)); ?>
    <?php echo p202_ltv_stat('Active Subs', number_format((int) ($customer['active_subscription_count'] ?? 0))); ?>
    <?php echo p202_ltv_stat('MRR', '$' . $money($customer['mrr'] ?? 0)); ?>
    <?php echo p202_ltv_stat('First Seen', p202_ltv_when($customer['first_seen_time'] ?? 0)); ?>
    <?php echo p202_ltv_stat('Last Activity', p202_ltv_when($customer['last_activity_time'] ?? 0)); ?>
</div>

<!-- Profile + identity -->
<div class="ltv-cols">
    <?php echo p202_ltv_card_open('Profile'); ?>
        <div class="ltv-card-body">
            <div class="ltv-def"><div class="ltv-def-label">Customer Ref</div>
                <div class="ltv-def-value"><?php echo $esc($customer['primary_ref'] ?? ''); ?></div></div>
            <div class="ltv-def"><div class="ltv-def-label">Email</div>
                <div class="ltv-def-value"><?php echo $esc($customer['email'] ?? '') ?: '<span class="ltv-dim">—</span>'; ?></div></div>
            <div class="ltv-def"><div class="ltv-def-label">Phone</div>
                <div class="ltv-def-value"><?php echo $esc($customer['phone'] ?? '') ?: '<span class="ltv-dim">—</span>'; ?></div></div>
            <div class="ltv-def"><div class="ltv-def-label">Company</div>
                <div class="ltv-def-value"><?php echo $esc($customer['company'] ?? '') ?: '<span class="ltv-dim">—</span>'; ?></div></div>
            <div class="ltv-def"><div class="ltv-def-label">Address</div>
                <div class="ltv-def-value"><?php echo $addressParts !== [] ? $esc(implode(', ', $addressParts)) : '<span class="ltv-dim">—</span>'; ?></div></div>
            <div class="ltv-def"><div class="ltv-def-label">Acquisition Click</div>
                <div class="ltv-def-value"><?php echo !empty($customer['first_click_id']) ? '#' . (int) $customer['first_click_id'] : '<span class="ltv-dim">—</span>'; ?></div></div>
            <?php foreach (($customer['custom_fields'] ?? []) as $key => $value) { ?>
                <div class="ltv-def">
                    <div class="ltv-def-label"><?php echo $esc($key); ?></div>
                    <div class="ltv-def-value"><?php
                        if (is_bool($value)) {
                            echo $value ? 'Yes' : 'No';
                        } else {
                            echo $esc($value ?? '') ?: '<span class="ltv-dim">—</span>';
                        }
                    ?></div>
                </div>
            <?php } ?>
        </div>
    <?php echo p202_ltv_card_close(); ?>
    <?php echo p202_ltv_card_open('Linked Identities'); ?>
        <div class="ltv-table-wrap">
            <table class="ltv-table">
                <thead>
                    <tr><th>Type</th><th>Value</th><th>Linked</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if (($customer['aliases'] ?? []) === []) { ?>
                        <tr><td colspan="4">
                            <?php echo p202_ltv_empty('fa-link', 'No aliases recorded'); ?>
                        </td></tr>
                    <?php } ?>
                    <?php foreach (($customer['aliases'] ?? []) as $alias) { ?>
                        <tr>
                            <td><?php echo p202_ltv_pill((string) ($alias['alias_type'] ?? ''), 'gray'); ?></td>
                            <td><?php echo $esc(mb_strimwidth((string) ($alias['alias_value'] ?? ''), 0, 60, '…')); ?></td>
                            <td><?php echo $when($alias['created_at'] ?? 0); ?></td>
                            <td class="num">
                                <button type="button" class="ltv-btn ltv-btn-xs ltv-btn-danger"
                                    onclick="ltvAliasDelete(<?php echo (int) $customer['customer_id']; ?>, <?php echo (int) ($alias['alias_id'] ?? 0); ?>);"
                                    title="Unlink this identity">&times;</button>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <form id="ltv-alias-form" onsubmit="return false;">
            <div class="ltv-toolbar" style="padding-bottom: 12px; border-top: 1px solid #f0f1f2; padding-top: 12px;">
                <select class="ltv-select ltv-input-sm" id="ltv-alias-type">
                    <?php
                    // From the repo constant so new alias types appear here
                    // automatically; subid stays excluded — those are minted by
                    // the click pipeline, not typed by hand.
                    foreach (array_merge(
                        ['custom'],
                        array_diff(\Prosper202\Ltv\MysqlCustomerRepository::ALIAS_TYPES, ['subid', 'custom'])
                    ) as $aliasType) { ?>
                        <option value="<?php echo $esc($aliasType); ?>"><?php echo $esc($aliasType); ?></option>
                    <?php } ?>
                </select>
                <input type="text" class="ltv-input ltv-input-sm ltv-grow" id="ltv-alias-value" maxlength="255" placeholder="Identity value (hash, id, ...)">
                <button type="button" class="ltv-btn ltv-btn-xs" onclick="ltvAliasAdd(<?php echo (int) $customer['customer_id']; ?>);"><i class="fa fa-plus"></i> Link Identity</button>
            </div>
        </form>
    <?php echo p202_ltv_card_close(); ?>
</div>

<!-- Suggested next offer + engagement -->
<div class="ltv-cols">
    <?php echo p202_ltv_card_open('Suggested Next Offer'); ?>
        <div class="ltv-card-body">
        <?php if ($nextOffer === null) { ?>
            <?php echo p202_ltv_empty('fa-lightbulb-o', 'Not enough data yet to suggest an offer',
                'No usable purchase history, tracked browsing, or recent account conversions.'); ?>
        <?php } else { ?>
            <p style="margin: 2px 0 0;">
                <span class="ltv-strong" style="font-size: 14px;"><?php echo $esc($nextOffer['name']); ?></span>
                <span class="ltv-dim" style="font-size: 12px;">campaign #<?php echo (int) $nextOffer['campaign_id']; ?></span><br>
                <?php $why = $nextOffer['why'] ?? null; ?>
                <?php if (is_array($why) && ($why['basis'] ?? '') === 'transition') { ?>
                    <span class="ltv-note">
                        Based on <?php echo (int) $why['direct_transitions']; ?> direct
                        and <?php echo (int) $why['eventual_transitions']; ?> eventual follow-on purchase(s)
                        from campaign(s) #<?php echo $esc(implode(', #', array_map(strval(...), (array) $why['based_on_campaigns']))); ?>
                        &middot; score <?php echo number_format((float) $why['score'], 3); ?><?php
                        if ((float) ($why['avg_order_value'] ?? 0) > 0) { ?>
                            &middot; avg order $<?php echo $money($why['avg_order_value']); ?><?php
                        } ?>
                    </span>
                <?php } elseif (is_array($why) && ($why['basis'] ?? '') === 'engagement') { ?>
                    <span class="ltv-note">
                        They've been browsing this offer but haven't bought:
                        <?php echo (int) ($why['clicks'] ?? 0); ?> tracked visit(s), last seen
                        <?php echo $esc(date('M j, Y', (int) ($why['last_engaged_at'] ?? 0))); ?>.
                    </span>
                <?php } else { ?>
                    <span class="ltv-note">No purchase or browsing signal for this customer yet &mdash; showing the account's top-converting live campaign of the last <?php echo (int) (is_array($why) ? ($why['window_days'] ?? 180) : 180); ?> days they haven't bought.</span>
                <?php } ?>
                <?php if ($nextOfferShown !== null) { ?>
                    <br><span class="ltv-note">Shown to this customer <?php echo (int) $nextOfferShown['shown']; ?> time(s)
                    since <?php echo $esc(date('M j, Y', $nextOfferShown['first_at'])); ?>.</span>
                <?php } ?>
                <?php if (is_array($why) && !empty($why['suppressed_campaigns'])) { ?>
                    <br><span class="ltv-note">Paused after repeated exposure without purchase:
                    campaign #<?php echo $esc(implode(', #', array_map(strval(...), (array) $why['suppressed_campaigns']))); ?>.</span>
                <?php } ?>
            </p>
        <?php } ?>
        </div>
    <?php echo p202_ltv_card_close(); ?>
    <?php echo p202_ltv_card_open('Engagement', 'last 90 days'); ?>
        <div class="ltv-table-wrap">
            <table class="ltv-table ltv-table-hover">
                <thead>
                    <tr><th>Campaign</th><th>Landing Page</th><th class="num">Views</th><th class="num">Conv.</th><th>Last Seen</th></tr>
                </thead>
                <tbody>
                    <?php if ($engagement === []) { ?>
                        <tr><td colspan="5">
                            <?php echo p202_ltv_empty('fa-mouse-pointer', 'No tracked browsing in this window'); ?>
                        </td></tr>
                    <?php } ?>
                    <?php foreach ($engagement as $row) { ?>
                        <tr>
                            <td><?php echo $esc($row['campaign_name'] ?? ('#' . ($row['campaign_id'] ?? ''))); ?></td>
                            <td><?php echo $esc($row['landing_page'] ?? '') ?: '<span class="ltv-dim">—</span>'; ?></td>
                            <td class="num"><?php echo number_format((int) ($row['clicks'] ?? 0)); ?></td>
                            <td class="num"><?php echo number_format((int) ($row['conversions'] ?? 0)); ?></td>
                            <td><?php echo $when($row['last_seen'] ?? 0); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <?php if ($engagementEvents !== []) { ?>
            <div class="ltv-card-head" style="padding-top: 6px;"><span class="ltv-card-title">Instrumented Events</span>
                <span class="ltv-card-sub">last 90 days</span></div>
            <div class="ltv-table-wrap">
                <table class="ltv-table ltv-table-hover">
                    <thead>
                        <tr><th>Event</th><th class="num">Value</th><th>Source</th><th>When</th></tr>
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
                                <td class="num"><?php echo $esc($valueLabel); ?></td>
                                <td><?php echo $esc($event['source'] ?? ''); ?></td>
                                <td><?php echo $when($event['occurred_at'] ?? 0); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    <?php echo p202_ltv_card_close(); ?>
</div>

<!-- Subscriptions -->
<?php if (($customer['subscriptions'] ?? []) !== []) { ?>
<?php echo p202_ltv_card_open('Subscriptions'); ?>
    <div class="ltv-table-wrap">
        <table class="ltv-table ltv-table-hover">
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Status</th>
                    <th class="num">Amount</th>
                    <th class="num">MRR</th>
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
                        <td><?php echo p202_ltv_status_pill((string) ($sub['status'] ?? '')); ?></td>
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
<?php echo p202_ltv_card_close(); ?>
<?php } ?>

<!-- Purchase history -->
<?php echo p202_ltv_card_open('Purchase History',
    'most recent ' . count($customer['recent_events'] ?? []) . ' events'); ?>
    <div class="ltv-table-wrap">
        <table class="ltv-table ltv-table-hover">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th class="num">Amount</th>
                    <th>Products</th>
                    <th>Transaction</th>
                </tr>
            </thead>
            <tbody>
                <?php if (($customer['recent_events'] ?? []) === []) { ?>
                    <tr><td colspan="6">
                        <?php echo p202_ltv_empty('fa-shopping-bag', 'No revenue recorded yet'); ?>
                    </td></tr>
                <?php } ?>
                <?php foreach (($customer['recent_events'] ?? []) as $event) {
                    $amount = (float) ($event['amount'] ?? 0);
                    $eventType = (string) ($event['event_type'] ?? '');
                ?>
                    <tr>
                        <td><?php echo $when($event['occurred_at'] ?? 0); ?></td>
                        <td><?php echo p202_ltv_pill($eventType, match ($eventType) {
                            'refund', 'chargeback' => 'red',
                            'adjustment' => 'amber',
                            'renewal' => 'blue',
                            default => 'green', // purchase, one_time
                        }); ?></td>
                        <td><?php echo $esc($event['source'] ?? ''); ?>
                            <?php if (!empty($event['conv_id'])) { ?><span class="ltv-dim" style="font-size: 12px;">(conv #<?php echo (int) $event['conv_id']; ?>)</span><?php } ?>
                        </td>
                        <td class="num <?php echo $amount < 0 ? 'ltv-neg' : ''; ?>">
                            <?php echo ($amount < 0 ? '-$' : '$') . $money(abs($amount)); ?>
                        </td>
                        <td>
                            <?php if (($event['items'] ?? []) === []) { ?>
                                <?php // No SKU line items — a subscription-sourced event shows its plan. ?>
                                <?php echo ($event['plan_name'] ?? null) !== null && $event['plan_name'] !== ''
                                    ? $esc($event['plan_name'])
                                    : '<span class="ltv-dim">—</span>'; ?>
                            <?php } else { ?>
                                <?php foreach ($event['items'] as $item) { ?>
                                    <div>
                                        <?php echo $esc(($item['product_name'] ?? '') !== '' && $item['product_name'] !== null
                                            ? $item['product_name']
                                            : ($item['sku'] ?? 'product #' . ($item['product_id'] ?? '?'))); ?>
                                        <span class="ltv-dim">&times;<?php echo number_format((float) ($item['quantity'] ?? 1), (fmod((float) ($item['quantity'] ?? 1), 1.0) === 0.0) ? 0 : 2); ?></span>
                                        &mdash; $<?php echo $money($item['amount'] ?? 0); ?>
                                    </div>
                                <?php } ?>
                            <?php } ?>
                        </td>
                        <td><?php echo $esc(mb_strimwidth((string) ($event['transaction_id'] ?? ''), 0, 30, '…')) ?: '<span class="ltv-dim">—</span>'; ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
<?php echo p202_ltv_card_close(); ?>

<script type="text/javascript">
    function ltvCustomerEdit(customerId) {
        ltvNav('customer', { customer_id: customerId, mode: 'edit' });
    }
    // Merge picker (ltv_merge_modal.php): type-ahead search instead of a
    // raw-id prompt, with a confirm step and a keep-direction swap.
    var ltvMergeSelf = <?php echo json_encode($customer !== null ? [
        'id' => (int) $customer['customer_id'],
        'label' => trim(((string) ($customer['first_name'] ?? '')) . ' ' . ((string) ($customer['last_name'] ?? ''))) !== ''
            ? trim(((string) ($customer['first_name'] ?? '')) . ' ' . ((string) ($customer['last_name'] ?? '')))
            : (string) ($customer['primary_ref'] ?? ('#' . (int) $customer['customer_id'])),
        'sub' => trim(implode(' · ', array_filter([
            (string) ($customer['email'] ?? ''),
            (string) ($customer['company'] ?? ''),
            '#' . (int) $customer['customer_id'],
        ]))),
        'meta' => '$' . number_format((float) ($customer['total_revenue'] ?? 0), 2)
            . ' · ' . (int) ($customer['order_count'] ?? 0) . ' order' . (((int) ($customer['order_count'] ?? 0)) === 1 ? '' : 's'),
    ] : null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    function ltvCustomerMerge(customerId) {
        if (!ltvMergeSelf) { return; }
        ltvMergeOpen({
            entity: 'customer',
            noun: 'customer',
            placeholder: 'Search by name, email, ref or company…',
            moves: 'aliases, revenue history, conversions, subscriptions and engagement',
            target: ltvMergeSelf,
            confirm: function(keptId, goneId) {
                loadContentPost('<?php echo $selfUrl; ?>', {
                    customer_id: keptId,
                    action: 'merge',
                    source_customer_id: goneId,
                    token: <?php echo json_encode((string) ($_SESSION['token'] ?? '')); ?>
                });
                // When the direction was swapped, this page's URL points at
                // the record that just merged away — move it to the survivor.
                if (String(keptId) !== String(ltvMergeSelf.id) && window.history && window.history.replaceState) {
                    window.history.replaceState(
                        { ltvView: 'customer', ltvParams: { customer_id: keptId } }, '',
                        ltvUrl('customer', { customer_id: keptId })
                    );
                }
            }
        });
    }
    function ltvCustomerErase(customerId) {
        if (!window.confirm('Erase this customer\'s personal data?\n\nName, contact info, custom fields, aliases and personalization tokens will be deleted. Revenue totals are kept for reporting. This cannot be undone.')) { return; }
        loadContentPost('<?php echo $selfUrl; ?>', {
            customer_id: customerId,
            action: 'erase',
            token: <?php echo json_encode((string) ($_SESSION['token'] ?? '')); ?>
        });
    }
    function ltvAliasAdd(customerId) {
        loadContentPost('<?php echo $selfUrl; ?>', {
            customer_id: customerId,
            action: 'add_alias',
            alias_type: $('#ltv-alias-type').val(),
            alias_value: $('#ltv-alias-value').val(),
            token: <?php echo json_encode((string) ($_SESSION['token'] ?? '')); ?>
        });
    }
    function ltvAliasDelete(customerId, aliasId) {
        if (!window.confirm('Unlink this identity? Future events using it will create or match a different customer.')) { return; }
        loadContentPost('<?php echo $selfUrl; ?>', {
            customer_id: customerId,
            action: 'delete_alias',
            alias_id: aliasId,
            token: <?php echo json_encode((string) ($_SESSION['token'] ?? '')); ?>
        });
    }
</script>

<?php require __DIR__ . '/ltv_merge_modal.php'; ?>
