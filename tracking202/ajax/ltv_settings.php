<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

/**
 * LTV settings partial: everything that configures the LTV feature set lives
 * here — the two account prefs (c-param identity fallback, personalization
 * field allowlist), custom field definitions, outbound webhooks, and
 * integration records. Every write is CSRF-gated and re-renders this page.
 */

$userId = (int) $_SESSION['user_id'];
$action = (string) ($_POST['action'] ?? '');

require_once __DIR__ . '/ltv_ui.php';
$esc = p202_ltv_esc(...);
$when = p202_ltv_when(...);

$selfUrl = get_absolute_url() . 'tracking202/ajax/ltv_settings.php';

$notice = null;
$error = null;
$newWebhookSecret = null;

/**
 * Validate the personalization-allowlist pref against the SAME grammar the
 * redeem path uses (MysqlPersonalizationRepository::isAllowedEntry — one
 * shared source of truth), but REJECT unknown entries instead of silently
 * dropping them — a typo the user never sees would otherwise just make
 * personalization mysteriously not work.
 *
 * @return string normalized comma-separated list
 */
function ltv_settings_validate_p13n_fields(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (strlen($raw) > 500) {
        throw new \RuntimeException('Personalization fields list exceeds 500 characters.');
    }

    $valid = [];
    $invalid = [];
    foreach (explode(',', $raw) as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }
        if (\Prosper202\Ltv\MysqlPersonalizationRepository::isAllowedEntry($entry)) {
            $valid[] = $entry;
        } else {
            $invalid[] = $entry;
        }
    }
    if ($invalid !== []) {
        throw new \RuntimeException(
            'Invalid personalization field(s): ' . implode(', ', $invalid)
            . '. Allowed: ' . implode(', ', \Prosper202\Ltv\MysqlPersonalizationRepository::ALLOWED_CRM_FIELDS)
            . ', cf:<field_key>, rec:next_offer.'
        );
    }

    return implode(',', array_values(array_unique($valid)));
}

try {
    $conn = new \Prosper202\Database\Connection($db);
    $fieldsRepo = new \Prosper202\Ltv\MysqlCustomerFieldRepository($conn);
    $webhooksRepo = new \Prosper202\Ltv\MysqlWebhookRepository($conn);
    $integrationsRepo = new \Prosper202\Ltv\MysqlIntegrationRepository($conn);

    if ($action !== '') {
        if (!AUTH::check_csrf_token()) {
            $error = 'Your session token was invalid — please try again.';
        } else {
            try {
                switch ($action) {
                    case 'save_prefs':
                        $cparam = (int) ($_POST['cparam'] ?? 0);
                        if ($cparam < 0 || $cparam > 4) {
                            throw new \RuntimeException('Customer c-param must be Off or c1–c4.');
                        }
                        $p13nFields = ltv_settings_validate_p13n_fields((string) ($_POST['p13n_fields'] ?? ''));

                        // Engagement-score weights: five inputs -> canonical
                        // pref string, validated by the same parser the read
                        // path uses. All-defaults stores '' (follow future
                        // default changes automatically).
                        $weightInput = [];
                        foreach (array_keys(\Prosper202\Ltv\MysqlEngagementRepository::DEFAULT_SCORE_WEIGHTS) as $component) {
                            $value = trim((string) ($_POST['weight_' . $component] ?? ''));
                            if (preg_match('/^\d{1,3}$/', $value) !== 1) {
                                throw new \RuntimeException('Score weight "' . $component . '" must be an integer 0-100.');
                            }
                            $weightInput[$component] = (int) $value;
                        }
                        $weightsPref = '';
                        if ($weightInput !== \Prosper202\Ltv\MysqlEngagementRepository::DEFAULT_SCORE_WEIGHTS) {
                            $pairs = [];
                            foreach ($weightInput as $component => $value) {
                                $pairs[] = $component . ':' . $value;
                            }
                            $weightsPref = implode(',', $pairs);
                            \Prosper202\Ltv\MysqlEngagementRepository::parseScoreWeights($weightsPref);
                        }

                        // Recommendation fatigue: '' = defaults, '0' = off,
                        // otherwise "shown,days" — validated by the same
                        // parser the serving path uses.
                        $fatiguePref = trim((string) ($_POST['rec_fatigue'] ?? ''));
                        if ($fatiguePref !== '' && preg_match('/^\d{1,3}(,\d{1,4})?$/', $fatiguePref) !== 1) {
                            throw new \RuntimeException('Offer fatigue must be empty (defaults), 0 (off), or "times,days" — e.g. 3,21.');
                        }

                        $stmt = $conn->prepareWrite(
                            'UPDATE 202_users_pref
                             SET user_ltv_customer_cparam = ?, user_ltv_personalization_fields = ?, user_ltv_score_weights = ?,
                                 user_ltv_rec_fatigue = ?
                             WHERE user_id = ?'
                        );
                        $conn->bind($stmt, 'isssi', [$cparam, $p13nFields, $weightsPref, $fatiguePref, $userId]);
                        $conn->executeUpdate($stmt);
                        $notice = 'Settings saved.';
                        break;

                    case 'add_field':
                        $payload = [
                            'field_key' => (string) ($_POST['field_key'] ?? ''),
                            'label' => (string) ($_POST['field_label'] ?? ''),
                            'field_type' => (string) ($_POST['field_type'] ?? 'text'),
                            'is_required' => !empty($_POST['field_required']),
                        ];
                        if ($payload['field_type'] === 'select') {
                            $options = array_values(array_filter(array_map(
                                trim(...),
                                explode(',', (string) ($_POST['field_options'] ?? ''))
                            ), static fn (string $o): bool => $o !== ''));
                            $payload['options'] = $options;
                        }
                        $fieldsRepo->create($userId, $payload);
                        $notice = 'Custom field created.';
                        break;

                    case 'delete_field':
                        $fieldsRepo->delete($userId, (int) ($_POST['field_id'] ?? 0));
                        $notice = 'Custom field and all its stored values deleted.';
                        break;

                    case 'add_webhook':
                        $events = isset($_POST['webhook_events']) && is_array($_POST['webhook_events'])
                            ? array_map(strval(...), $_POST['webhook_events'])
                            : [];
                        $created = $webhooksRepo->create($userId, trim((string) ($_POST['webhook_url'] ?? '')), $events);
                        $newWebhookSecret = $created['secret'];
                        $notice = 'Webhook #' . $created['webhookId'] . ' registered.';
                        break;

                    case 'delete_webhook':
                        $webhooksRepo->delete($userId, (int) ($_POST['webhook_id'] ?? 0));
                        $notice = 'Webhook deleted.';
                        break;

                    case 'add_integration':
                        $integrationsRepo->create(
                            $userId,
                            (string) ($_POST['integration_provider'] ?? ''),
                            (string) ($_POST['integration_name'] ?? '')
                        );
                        $notice = 'Integration added.';
                        break;

                    case 'delete_integration':
                        $integrationsRepo->delete($userId, (int) ($_POST['integration_id'] ?? 0));
                        $notice = 'Integration deleted.';
                        break;

                    default:
                        throw new \RuntimeException('Unknown action.');
                }
            } catch (\RuntimeException $actionError) {
                $error = $actionError->getMessage();
            }
        }
    }

    // ---- Current state (always re-read after any write) ----
    $stmt = $conn->prepareRead(
        'SELECT user_ltv_customer_cparam, user_ltv_personalization_fields, user_ltv_score_weights, user_ltv_rec_fatigue
         FROM 202_users_pref WHERE user_id = ? LIMIT 1'
    );
    $conn->bind($stmt, 'i', [$userId]);
    $prefs = $conn->fetchOne($stmt) ?? [];
    $cparamValue = (int) ($prefs['user_ltv_customer_cparam'] ?? 0);
    $p13nValue = (string) ($prefs['user_ltv_personalization_fields'] ?? '');
    $fatigueValue = (string) ($prefs['user_ltv_rec_fatigue'] ?? '');
    $fatigueDefaults = \Prosper202\Ltv\MysqlRecommendationRepository::DEFAULT_FATIGUE;
    try {
        $weightValues = \Prosper202\Ltv\MysqlEngagementRepository::parseScoreWeights(
            (string) ($prefs['user_ltv_score_weights'] ?? '')
        );
    } catch (\RuntimeException) {
        $weightValues = \Prosper202\Ltv\MysqlEngagementRepository::DEFAULT_SCORE_WEIGHTS;
    }

    $fieldDefinitions = $fieldsRepo->list($userId);
    $webhooks = $webhooksRepo->list($userId);

    // Read-only delivery log for one endpoint (no CSRF needed — no write).
    $deliveryLogWebhookId = (int) ($_POST['show_deliveries'] ?? 0);
    $deliveryLog = $deliveryLogWebhookId > 0
        ? $webhooksRepo->recentDeliveries($userId, $deliveryLogWebhookId, 25)
        : [];

    $integrations = $integrationsRepo->list($userId);
} catch (\Throwable $e) {
    error_log('ltv_settings: ' . $e->getMessage());
    echo '<div class="alert alert-danger">LTV settings could not be loaded. '
        . 'Run the LTV migration (202-config/migrations/run_ltv_migration.php) if you have not yet.</div>';
    return;
}

$csrfToken = (string) ($_SESSION['token'] ?? '');
?>

<?php echo p202_ltv_ui_styles(); ?>
<?php echo p202_ltv_tabs('settings'); ?>

<?php if ($notice !== null && $error === null) { ?>
    <?php echo p202_ltv_flash('success', $esc($notice)); ?>
<?php } ?>
<?php if ($newWebhookSecret !== null) { ?>
    <?php echo p202_ltv_flash('warn',
        '<strong>Webhook signing secret (shown once — store it now):</strong> '
        . '<code>' . $esc($newWebhookSecret) . '</code><br>'
        . '<small>Deliveries are signed with <code>X-P202-Signature: sha256=HMAC-SHA256(body, secret)</code>.</small>'); ?>
<?php } ?>
<?php if ($error !== null) { ?>
    <?php echo p202_ltv_flash('error', $esc($error)); ?>
<?php } ?>

<!-- ================= Tracking & personalization prefs ================= -->
<?php echo p202_ltv_card_open('Identity & Personalization'); ?>
    <form id="ltv-prefs-form" onsubmit="return false;">
        <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
        <input type="hidden" name="action" value="save_prefs" />
        <div class="ltv-card-body">
            <div class="ltv-def">
                <div class="ltv-def-label">Customer ID from c-param
                    <small>When a conversion has no explicit customer id, resolve it from this tracking token. Run the backfill script after enabling.</small></div>
                <div class="ltv-def-value">
                    <select class="ltv-select" name="cparam" style="width: auto;">
                        <option value="0" <?php if ($cparamValue === 0) { echo 'selected'; } ?>>Off</option>
                        <?php for ($i = 1; $i <= 4; $i++) { ?>
                            <option value="<?php echo $i; ?>" <?php if ($cparamValue === $i) { echo 'selected'; } ?>>c<?php echo $i; ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <div class="ltv-def">
                <div class="ltv-def-label">Landing page personalization fields
                    <small>Comma-separated. Empty = personalization off.
                    Allowed: <?php echo $esc(implode(', ', \Prosper202\Ltv\MysqlPersonalizationRepository::ALLOWED_CRM_FIELDS)); ?>,
                    <code>cf:&lt;field_key&gt;</code>, <code>rec:next_offer</code>.
                    Email, phone and address are never eligible.</small></div>
                <div class="ltv-def-value"><input type="text" class="ltv-input" name="p13n_fields" maxlength="500"
                    value="<?php echo $esc($p13nValue); ?>" placeholder="e.g. first_name, rec:next_offer"></div>
            </div>
            <div class="ltv-def">
                <div class="ltv-def-label">Engagement score weights
                    <small>Points each component contributes; must total exactly 100.
                    Volume saturates at 10 engagements/contact, time at a 5-minute average.</small></div>
                <div class="ltv-def-value">
                    <?php foreach ($weightValues as $component => $value) { ?>
                        <label style="margin: 0 12px 6px 0; font-weight: 400; font-size: 12px; color: #5f6670;"><?php echo $esc(ucfirst((string) $component)); ?>
                            <input type="number" class="ltv-input ltv-input-sm" style="width: 64px;"
                                name="weight_<?php echo $esc($component); ?>" min="0" max="100"
                                value="<?php echo (int) $value; ?>">
                        </label>
                    <?php } ?>
                </div>
            </div>
            <div class="ltv-def">
                <div class="ltv-def-label">Offer fatigue
                    <small>Stop suggesting an offer to a customer after it has been shown
                    <em>times</em> visits over at least <em>days</em> days without a purchase (a fresh click on
                    the offer resets it). Format <code>times,days</code>; empty =
                    <?php echo (int) $fatigueDefaults['shown']; ?>,<?php echo (int) $fatigueDefaults['days']; ?>;
                    <code>0</code> disables.</small></div>
                <div class="ltv-def-value"><input type="text" class="ltv-input ltv-input-sm" style="width: 120px;" name="rec_fatigue"
                    maxlength="20" value="<?php echo $esc($fatigueValue); ?>"
                    placeholder="<?php echo (int) $fatigueDefaults['shown']; ?>,<?php echo (int) $fatigueDefaults['days']; ?>"></div>
            </div>
        </div>
        <div class="ltv-card-body">
            <button type="button" class="ltv-btn ltv-btn-primary" onclick="ltvSettingsSubmit('ltv-prefs-form');">Save Settings</button>
        </div>
    </form>
<?php echo p202_ltv_card_close(); ?>

<!-- ================= Custom field definitions ================= -->
<?php echo p202_ltv_card_open('Custom Fields', 'typed fields available on every customer record'); ?>
    <div class="ltv-table-wrap">
        <table class="ltv-table ltv-table-hover">
            <thead>
                <tr><th>Key</th><th>Label</th><th>Type</th><th>Required</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                <?php if ($fieldDefinitions === []) { ?>
                    <tr><td colspan="6">
                        <?php echo p202_ltv_empty('fa-list-alt', 'No custom fields defined yet',
                            'Typed fields (text, number, date, boolean, select) become editable on every customer record and filterable via the API.'); ?>
                    </td></tr>
                <?php } ?>
                <?php foreach ($fieldDefinitions as $field) { ?>
                    <tr>
                        <td><code><?php echo $esc($field['field_key']); ?></code></td>
                        <td><?php echo $esc($field['label'] ?? ''); ?></td>
                        <td><?php echo p202_ltv_pill((string) $field['field_type'], 'gray'); ?><?php
                            if ((string) $field['field_type'] === 'select') {
                                $options = is_string($field['options'] ?? null) ? json_decode((string) $field['options'], true) : null;
                                if (is_array($options) && $options !== []) {
                                    echo ' <span class="ltv-dim" style="font-size: 12px;">(' . $esc(implode(', ', array_map(strval(...), $options))) . ')</span>';
                                }
                            }
                        ?></td>
                        <td><?php echo !empty($field['is_required']) ? 'Yes' : '<span class="ltv-dim">No</span>'; ?></td>
                        <td><?php echo $when($field['created_at'] ?? 0); ?></td>
                        <td class="num">
                            <button type="button" class="ltv-btn ltv-btn-xs ltv-btn-danger"
                                onclick="ltvSettingsDelete('delete_field', 'field_id', <?php echo (int) $field['field_id']; ?>, 'Delete this field AND every value stored on customers? This cannot be undone.');">Delete</button>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <form id="ltv-field-form" onsubmit="return false;">
        <div class="ltv-toolbar" style="padding-bottom: 12px; border-top: 1px solid #f0f1f2; padding-top: 12px;">
            <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
            <input type="hidden" name="action" value="add_field" />
            <input type="text" class="ltv-input ltv-input-sm" name="field_key" maxlength="64" placeholder="field_key (a-z, 0-9, _)">
            <input type="text" class="ltv-input ltv-input-sm" name="field_label" maxlength="255" placeholder="Label">
            <select class="ltv-select ltv-input-sm" name="field_type" id="ltv-field-type">
                <?php foreach (\Prosper202\Ltv\MysqlCustomerFieldRepository::FIELD_TYPES as $type) { ?>
                    <option value="<?php echo $esc($type); ?>"><?php echo $esc($type); ?></option>
                <?php } ?>
            </select>
            <input type="text" class="ltv-input ltv-input-sm" name="field_options" maxlength="1000" placeholder="Options, comma-separated (select only)">
            <label style="font-weight: 400; font-size: 12px; color: #5f6670; margin: 0;"><input type="checkbox" name="field_required" value="1"> Required</label>
            <button type="button" class="ltv-btn ltv-btn-xs" onclick="ltvSettingsSubmit('ltv-field-form');"><i class="fa fa-plus"></i> Add Field</button>
        </div>
    </form>
<?php echo p202_ltv_card_close(); ?>

<!-- ================= Outbound webhooks ================= -->
<?php echo p202_ltv_card_open('Outbound Webhooks', 'signed HMAC-SHA256 pushes for customer / revenue / subscription changes'); ?>
    <div class="ltv-table-wrap">
        <table class="ltv-table ltv-table-hover">
            <thead>
                <tr><th>#</th><th>URL</th><th>Events</th><th>Status</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                <?php if ($webhooks === []) { ?>
                    <tr><td colspan="6">
                        <?php echo p202_ltv_empty('fa-paper-plane-o', 'No webhooks registered',
                            'Register an HTTPS endpoint below to receive signed pushes when customers, revenue or subscriptions change.'); ?>
                    </td></tr>
                <?php } ?>
                <?php foreach ($webhooks as $webhook) {
                    $status = (string) ($webhook['status'] ?? '');
                ?>
                    <tr>
                        <td class="ltv-dim">#<?php echo (int) $webhook['webhook_id']; ?></td>
                        <td><?php echo $esc(mb_strimwidth((string) ($webhook['webhook_url'] ?? ''), 0, 70, '…')); ?></td>
                        <td><span class="ltv-dim" style="font-size: 12px;"><?php echo $esc(str_replace(',', ', ', (string) ($webhook['subscribed_events'] ?? ''))); ?></span></td>
                        <td>
                            <?php if ($status === 'dead') { ?>
                                <span title="Deliveries exhausted their retries; fix the endpoint and re-register."><?php echo p202_ltv_status_pill('dead'); ?></span>
                            <?php } else { ?>
                                <?php echo p202_ltv_status_pill($status); ?>
                            <?php } ?>
                        </td>
                        <td><?php echo $when($webhook['created_at'] ?? 0); ?></td>
                        <td class="num" style="white-space: nowrap;">
                            <button type="button" class="ltv-btn ltv-btn-xs"
                                onclick="ltvWebhookLog(<?php echo (int) $webhook['webhook_id']; ?>);"><i class="fa fa-list"></i> Log</button>
                            <button type="button" class="ltv-btn ltv-btn-xs ltv-btn-danger"
                                onclick="ltvSettingsDelete('delete_webhook', 'webhook_id', <?php echo (int) $webhook['webhook_id']; ?>, 'Delete this webhook and its delivery history?');">Delete</button>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <form id="ltv-webhook-form" onsubmit="return false;">
        <div class="ltv-toolbar" style="border-top: 1px solid #f0f1f2; padding-top: 12px;">
            <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
            <input type="hidden" name="action" value="add_webhook" />
            <input type="text" class="ltv-input ltv-input-sm ltv-grow" name="webhook_url" maxlength="500" placeholder="https://example.com/hooks/p202">
            <?php foreach (\Prosper202\Ltv\MysqlWebhookRepository::KNOWN_EVENTS as $eventName) { ?>
                <label style="font-weight: 400; font-size: 12px; color: #5f6670; margin: 0; white-space: nowrap;">
                    <input type="checkbox" name="webhook_events[]" value="<?php echo $esc($eventName); ?>" checked> <?php echo $esc($eventName); ?>
                </label>
            <?php } ?>
            <button type="button" class="ltv-btn ltv-btn-xs" onclick="ltvSettingsSubmit('ltv-webhook-form');"><i class="fa fa-plus"></i> Register Webhook</button>
        </div>
        <div class="ltv-card-body ltv-note" style="padding-top: 4px;">HTTPS only; hosts resolving to private or reserved addresses are rejected. The signing secret is shown once after registration.</div>
    </form>

    <?php if ($deliveryLogWebhookId > 0) { ?>
        <div class="ltv-card-head" style="border-top: 1px solid #f0f1f2; padding-top: 12px;">
            <span class="ltv-card-title">Delivery Log</span>
            <span class="ltv-card-sub">webhook #<?php echo $deliveryLogWebhookId; ?>, most recent 25</span>
        </div>
        <div class="ltv-table-wrap">
            <table class="ltv-table">
                <thead>
                    <tr><th>#</th><th>Event</th><th>Status</th><th class="num">Attempts</th><th class="num">Last HTTP</th><th>Next Retry</th><th>Queued</th><th>Updated</th></tr>
                </thead>
                <tbody>
                    <?php if ($deliveryLog === []) { ?>
                        <tr><td colspan="8">
                            <?php echo p202_ltv_empty('fa-inbox', 'No deliveries recorded for this webhook yet'); ?>
                        </td></tr>
                    <?php } ?>
                    <?php foreach ($deliveryLog as $delivery) {
                        $deliveryStatus = (string) ($delivery['status'] ?? '');
                    ?>
                        <tr>
                            <td class="ltv-dim">#<?php echo (int) $delivery['delivery_id']; ?></td>
                            <td><?php echo $esc($delivery['event_name'] ?? ''); ?></td>
                            <td><?php echo p202_ltv_status_pill($deliveryStatus); ?></td>
                            <td class="num"><?php echo (int) ($delivery['attempts'] ?? 0); ?></td>
                            <td class="num"><?php echo ($delivery['last_status_code'] ?? null) !== null ? (int) $delivery['last_status_code'] : '<span class="ltv-dim">—</span>'; ?></td>
                            <td><?php echo $deliveryStatus === 'pending' ? date('M j, g:ia', (int) ($delivery['next_attempt_at'] ?? 0)) : '<span class="ltv-dim">—</span>'; ?></td>
                            <td><?php echo $when($delivery['created_at'] ?? 0); ?></td>
                            <td><?php echo $when($delivery['updated_at'] ?? 0); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
<?php echo p202_ltv_card_close(); ?>

<!-- ================= Integrations ================= -->
<?php echo p202_ltv_card_open('Integrations', 'label inbound pushes from ESPs, membership and billing platforms'); ?>
    <div class="ltv-table-wrap">
        <table class="ltv-table ltv-table-hover">
            <thead>
                <tr><th>#</th><th>Provider</th><th>Name</th><th>Status</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                <?php if ($integrations === []) { ?>
                    <tr><td colspan="6">
                        <?php echo p202_ltv_empty('fa-plug', 'No integrations configured',
                            'Inbound pushes use the API with an <code>ltv:write</code> key.'); ?>
                    </td></tr>
                <?php } ?>
                <?php foreach ($integrations as $integration) { ?>
                    <tr>
                        <td class="ltv-dim">#<?php echo (int) $integration['integration_id']; ?></td>
                        <td><code><?php echo $esc($integration['provider']); ?></code></td>
                        <td><?php echo $esc($integration['name'] ?? ''); ?></td>
                        <td><?php echo p202_ltv_status_pill((string) ($integration['status'] ?? '')); ?>
                            <?php if (!empty($integration['config_invalid'])) { ?>
                                <span class="ltv-neg" style="font-size: 12px;" title="The stored configuration is not valid JSON; re-save it via the API.">&#9888; config unreadable</span>
                            <?php } ?>
                        </td>
                        <td><?php echo $when($integration['created_at'] ?? 0); ?></td>
                        <td class="num">
                            <button type="button" class="ltv-btn ltv-btn-xs ltv-btn-danger"
                                onclick="ltvSettingsDelete('delete_integration', 'integration_id', <?php echo (int) $integration['integration_id']; ?>, 'Delete this integration record?');">Delete</button>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <form id="ltv-integration-form" onsubmit="return false;">
        <div class="ltv-toolbar" style="padding-bottom: 12px; border-top: 1px solid #f0f1f2; padding-top: 12px;">
            <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
            <input type="hidden" name="action" value="add_integration" />
            <input type="text" class="ltv-input ltv-input-sm" name="integration_provider" maxlength="50" placeholder="provider (e.g. shopify, aweber)">
            <input type="text" class="ltv-input ltv-input-sm" name="integration_name" maxlength="255" placeholder="Display name">
            <button type="button" class="ltv-btn ltv-btn-xs" onclick="ltvSettingsSubmit('ltv-integration-form');"><i class="fa fa-plus"></i> Add Integration</button>
        </div>
    </form>
<?php echo p202_ltv_card_close(); ?>

<script type="text/javascript">
    function ltvSettingsSubmit(formId) {
        loadContentPost('<?php echo $selfUrl; ?>', $('#' + formId).serialize());
    }
    function ltvWebhookLog(webhookId) {
        ltvNav('settings', { show_deliveries: webhookId });
    }
    function ltvSettingsDelete(action, idField, id, message) {
        if (!window.confirm(message)) { return; }
        var payload = {
            action: action,
            token: <?php echo json_encode($csrfToken); ?>
        };
        payload[idField] = id;
        loadContentPost('<?php echo $selfUrl; ?>', payload);
    }
</script>
