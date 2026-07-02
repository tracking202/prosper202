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

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when = static fn (mixed $ts): string => ((int) $ts) > 0 ? date('M j, Y', (int) $ts) : '—';

$backUrl = get_absolute_url() . 'tracking202/ajax/sort_ltv.php';
$selfUrl = get_absolute_url() . 'tracking202/ajax/ltv_settings.php';

$notice = null;
$error = null;
$newWebhookSecret = null;

/**
 * Validate the personalization-allowlist pref the same way the redeem path
 * parses it (MysqlPersonalizationRepository::allowedFields), but REJECT
 * unknown entries instead of silently dropping them — a typo the user never
 * sees would otherwise just make personalization mysteriously not work.
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
        if (in_array($entry, \Prosper202\Ltv\MysqlPersonalizationRepository::ALLOWED_CRM_FIELDS, true)
            || $entry === 'rec:next_offer'
            || (str_starts_with($entry, 'cf:') && preg_match('/^cf:[a-z0-9_]{1,64}$/', $entry) === 1)) {
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

                        $stmt = $conn->prepareWrite(
                            'UPDATE 202_users_pref
                             SET user_ltv_customer_cparam = ?, user_ltv_personalization_fields = ?
                             WHERE user_id = ?'
                        );
                        $conn->bind($stmt, 'isi', [$cparam, $p13nFields, $userId]);
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
                        $provider = strtolower(trim((string) ($_POST['integration_provider'] ?? '')));
                        if ($provider === '' || preg_match('/^[a-z0-9_\-]{1,50}$/', $provider) !== 1) {
                            throw new \RuntimeException('Provider is required (a-z, 0-9, dash/underscore, max 50 chars).');
                        }
                        $name = trim((string) ($_POST['integration_name'] ?? ''));
                        if ($name === '') {
                            $name = $provider;
                        }
                        $now = time();
                        $stmt = $conn->prepareWrite(
                            "INSERT INTO 202_ltv_integrations (user_id, provider, name, config, api_key_id, status, created_at, updated_at)
                             VALUES (?, ?, ?, NULL, NULL, 'active', ?, ?)"
                        );
                        $conn->bind($stmt, 'issii', [$userId, $provider, $name, $now, $now]);
                        $conn->executeInsert($stmt);
                        $notice = 'Integration added.';
                        break;

                    case 'delete_integration':
                        $stmt = $conn->prepareWrite(
                            'DELETE FROM 202_ltv_integrations WHERE integration_id = ? AND user_id = ?'
                        );
                        $conn->bind($stmt, 'ii', [(int) ($_POST['integration_id'] ?? 0), $userId]);
                        if ($conn->executeUpdate($stmt) === 0) {
                            throw new \RuntimeException('Integration not found.');
                        }
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
        'SELECT user_ltv_customer_cparam, user_ltv_personalization_fields
         FROM 202_users_pref WHERE user_id = ? LIMIT 1'
    );
    $conn->bind($stmt, 'i', [$userId]);
    $prefs = $conn->fetchOne($stmt) ?? [];
    $cparamValue = (int) ($prefs['user_ltv_customer_cparam'] ?? 0);
    $p13nValue = (string) ($prefs['user_ltv_personalization_fields'] ?? '');

    $fieldDefinitions = $fieldsRepo->list($userId);
    $webhooks = $webhooksRepo->list($userId);

    $stmt = $conn->prepareRead(
        'SELECT integration_id, provider, name, status, created_at
         FROM 202_ltv_integrations WHERE user_id = ? ORDER BY integration_id ASC'
    );
    $conn->bind($stmt, 'i', [$userId]);
    $integrations = $conn->fetchAll($stmt);
} catch (\Throwable $e) {
    error_log('ltv_settings: ' . $e->getMessage());
    echo '<div class="alert alert-danger">LTV settings could not be loaded. '
        . 'Run the LTV migration (202-config/migrations/run_ltv_migration.php) if you have not yet.</div>';
    return;
}

$csrfToken = (string) ($_SESSION['token'] ?? '');
?>

<div class="row" style="margin-bottom: 10px;">
    <div class="col-xs-12">
        <a href="#" onclick="loadContent('<?php echo $backUrl; ?>', null); return false;">&laquo; Back to Customer LTV</a>
    </div>
</div>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-xs-12">
        <h6>LTV Settings</h6>
    </div>
</div>

<?php if ($notice !== null && $error === null) { ?>
    <div class="alert alert-success"><?php echo $esc($notice); ?></div>
<?php } ?>
<?php if ($newWebhookSecret !== null) { ?>
    <div class="alert alert-warning">
        <strong>Webhook signing secret (shown once — store it now):</strong>
        <code><?php echo $esc($newWebhookSecret); ?></code><br>
        <small>Deliveries are signed with <code>X-P202-Signature: sha256=HMAC-SHA256(body, secret)</code>.</small>
    </div>
<?php } ?>
<?php if ($error !== null) { ?>
    <div class="alert alert-danger"><?php echo $esc($error); ?></div>
<?php } ?>

<!-- ================= Tracking & personalization prefs ================= -->
<div class="row">
    <div class="col-xs-12">
        <h6>Identity &amp; Personalization</h6>
        <form id="ltv-prefs-form" onsubmit="return false;">
            <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
            <input type="hidden" name="action" value="save_prefs" />
            <table class="table table-bordered">
                <tbody>
                    <tr>
                        <th style="width: 30%;">Customer ID from c-param
                            <br><small class="text-muted">When a conversion has no explicit customer id, resolve it from this tracking token. Run the backfill script after enabling.</small></th>
                        <td>
                            <select class="form-control" name="cparam" style="width: auto;">
                                <option value="0" <?php if ($cparamValue === 0) { echo 'selected'; } ?>>Off</option>
                                <?php for ($i = 1; $i <= 4; $i++) { ?>
                                    <option value="<?php echo $i; ?>" <?php if ($cparamValue === $i) { echo 'selected'; } ?>>c<?php echo $i; ?></option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Landing page personalization fields
                            <br><small class="text-muted">Comma-separated. Empty = personalization off.
                            Allowed: <?php echo $esc(implode(', ', \Prosper202\Ltv\MysqlPersonalizationRepository::ALLOWED_CRM_FIELDS)); ?>,
                            <code>cf:&lt;field_key&gt;</code>, <code>rec:next_offer</code>.
                            Email, phone and address are never eligible.</small></th>
                        <td><input type="text" class="form-control" name="p13n_fields" maxlength="500"
                            value="<?php echo $esc($p13nValue); ?>" placeholder="e.g. first_name, rec:next_offer"></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="btn btn-primary" onclick="ltvSettingsSubmit('ltv-prefs-form');">Save Settings</button>
        </form>
    </div>
</div>

<!-- ================= Custom field definitions ================= -->
<div class="row" style="margin-top: 20px;">
    <div class="col-xs-12">
        <h6>Custom Fields <small>typed fields available on every customer record</small></h6>
        <table class="table table-bordered table-hover">
            <thead>
                <tr><th>Key</th><th>Label</th><th>Type</th><th>Required</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                <?php if ($fieldDefinitions === []) { ?>
                    <tr><td colspan="6"><em>No custom fields defined yet.</em></td></tr>
                <?php } ?>
                <?php foreach ($fieldDefinitions as $field) { ?>
                    <tr>
                        <td><code><?php echo $esc($field['field_key']); ?></code></td>
                        <td><?php echo $esc($field['label'] ?? ''); ?></td>
                        <td><?php echo $esc($field['field_type']); ?><?php
                            if ((string) $field['field_type'] === 'select') {
                                $options = is_string($field['options'] ?? null) ? json_decode((string) $field['options'], true) : null;
                                if (is_array($options) && $options !== []) {
                                    echo ' <small class="text-muted">(' . $esc(implode(', ', array_map(strval(...), $options))) . ')</small>';
                                }
                            }
                        ?></td>
                        <td><?php echo !empty($field['is_required']) ? 'Yes' : 'No'; ?></td>
                        <td><?php echo $when($field['created_at'] ?? 0); ?></td>
                        <td class="text-right">
                            <button type="button" class="btn btn-xs btn-danger"
                                onclick="ltvSettingsDelete('delete_field', 'field_id', <?php echo (int) $field['field_id']; ?>, 'Delete this field AND every value stored on customers? This cannot be undone.');">Delete</button>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <form id="ltv-field-form" class="form-inline" onsubmit="return false;">
            <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
            <input type="hidden" name="action" value="add_field" />
            <input type="text" class="form-control" name="field_key" maxlength="64" placeholder="field_key (a-z, 0-9, _)">
            <input type="text" class="form-control" name="field_label" maxlength="255" placeholder="Label">
            <select class="form-control" name="field_type" id="ltv-field-type">
                <?php foreach (\Prosper202\Ltv\MysqlCustomerFieldRepository::FIELD_TYPES as $type) { ?>
                    <option value="<?php echo $esc($type); ?>"><?php echo $esc($type); ?></option>
                <?php } ?>
            </select>
            <input type="text" class="form-control" name="field_options" maxlength="1000" placeholder="Options, comma-separated (select only)">
            <label class="checkbox-inline"><input type="checkbox" name="field_required" value="1"> Required</label>
            <button type="button" class="btn btn-default" onclick="ltvSettingsSubmit('ltv-field-form');">Add Field</button>
        </form>
    </div>
</div>

<!-- ================= Outbound webhooks ================= -->
<div class="row" style="margin-top: 20px;">
    <div class="col-xs-12">
        <h6>Outbound Webhooks <small>signed HMAC-SHA256 pushes for customer / revenue / subscription changes</small></h6>
        <table class="table table-bordered table-hover">
            <thead>
                <tr><th>#</th><th>URL</th><th>Events</th><th>Status</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                <?php if ($webhooks === []) { ?>
                    <tr><td colspan="6"><em>No webhooks registered.</em></td></tr>
                <?php } ?>
                <?php foreach ($webhooks as $webhook) {
                    $status = (string) ($webhook['status'] ?? '');
                ?>
                    <tr>
                        <td><?php echo (int) $webhook['webhook_id']; ?></td>
                        <td><?php echo $esc(mb_strimwidth((string) ($webhook['webhook_url'] ?? ''), 0, 70, '…')); ?></td>
                        <td><small><?php echo $esc(str_replace(',', ', ', (string) ($webhook['subscribed_events'] ?? ''))); ?></small></td>
                        <td>
                            <?php if ($status === 'dead') { ?>
                                <span class="text-danger" title="Deliveries exhausted their retries; fix the endpoint and re-register."><strong>dead</strong></span>
                            <?php } else { ?>
                                <?php echo $esc($status); ?>
                            <?php } ?>
                        </td>
                        <td><?php echo $when($webhook['created_at'] ?? 0); ?></td>
                        <td class="text-right">
                            <button type="button" class="btn btn-xs btn-danger"
                                onclick="ltvSettingsDelete('delete_webhook', 'webhook_id', <?php echo (int) $webhook['webhook_id']; ?>, 'Delete this webhook and its delivery history?');">Delete</button>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <form id="ltv-webhook-form" class="form-inline" onsubmit="return false;">
            <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
            <input type="hidden" name="action" value="add_webhook" />
            <input type="text" class="form-control" name="webhook_url" maxlength="500" size="40" placeholder="https://example.com/hooks/p202">
            <?php foreach (\Prosper202\Ltv\MysqlWebhookRepository::EVENTS as $eventName) { ?>
                <label class="checkbox-inline">
                    <input type="checkbox" name="webhook_events[]" value="<?php echo $esc($eventName); ?>" checked> <?php echo $esc($eventName); ?>
                </label>
            <?php } ?>
            <button type="button" class="btn btn-default" onclick="ltvSettingsSubmit('ltv-webhook-form');">Register Webhook</button>
        </form>
        <small class="text-muted">HTTPS only; hosts resolving to private or reserved addresses are rejected. The signing secret is shown once after registration.</small>
    </div>
</div>

<!-- ================= Integrations ================= -->
<div class="row" style="margin-top: 20px; margin-bottom: 20px;">
    <div class="col-xs-12">
        <h6>Integrations <small>label inbound pushes from ESPs, membership and billing platforms</small></h6>
        <table class="table table-bordered table-hover">
            <thead>
                <tr><th>#</th><th>Provider</th><th>Name</th><th>Status</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                <?php if ($integrations === []) { ?>
                    <tr><td colspan="6"><em>No integrations configured. Inbound pushes use the API with an <code>ltv:write</code> key.</em></td></tr>
                <?php } ?>
                <?php foreach ($integrations as $integration) { ?>
                    <tr>
                        <td><?php echo (int) $integration['integration_id']; ?></td>
                        <td><code><?php echo $esc($integration['provider']); ?></code></td>
                        <td><?php echo $esc($integration['name'] ?? ''); ?></td>
                        <td><?php echo $esc($integration['status'] ?? ''); ?></td>
                        <td><?php echo $when($integration['created_at'] ?? 0); ?></td>
                        <td class="text-right">
                            <button type="button" class="btn btn-xs btn-danger"
                                onclick="ltvSettingsDelete('delete_integration', 'integration_id', <?php echo (int) $integration['integration_id']; ?>, 'Delete this integration record?');">Delete</button>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <form id="ltv-integration-form" class="form-inline" onsubmit="return false;">
            <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
            <input type="hidden" name="action" value="add_integration" />
            <input type="text" class="form-control" name="integration_provider" maxlength="50" placeholder="provider (e.g. shopify, aweber)">
            <input type="text" class="form-control" name="integration_name" maxlength="255" placeholder="Display name">
            <button type="button" class="btn btn-default" onclick="ltvSettingsSubmit('ltv-integration-form');">Add Integration</button>
        </form>
    </div>
</div>

<script type="text/javascript">
    function ltvSettingsSubmit(formId) {
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', $('#' + formId).serialize())
            .done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvSettingsDelete(action, idField, id, message) {
        if (!window.confirm(message)) { return; }
        var payload = {
            action: action,
            token: <?php echo json_encode($csrfToken); ?>
        };
        payload[idField] = id;
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', payload)
            .done(function(data) { element.html(data).css('opacity', '1'); });
    }
</script>
