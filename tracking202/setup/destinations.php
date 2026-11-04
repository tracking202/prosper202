<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission('access_to_setup_section')) {
    header('location: ' . get_absolute_url() . 'tracking202/');
    die();
}

require_once __DIR__ . '/_includes/setup_ui.php';

use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\Ga4Destination;

/*
 * Setup › Destinations: the ad platforms this account's conversions are sent
 * to from this server (Prosper202\Destinations), the same store and queue
 * as /api/v3/destinations. GA4 first. The API secret is taken and never shown.
 */

$userId = (int) $_SESSION['user_id'];
$conn = new \Prosper202\Database\Connection($db);
$store = new DestinationStore($conn);
$base = get_absolute_url();
$self = $base . 'tracking202/setup/destinations.php';
$canSeeLog = $userObj->hasPermission('access_to_campaign_data');
$errors = [];
$tokenRefused = false;
$posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = \Prosper202\Http\PostedId::read($_POST['destination_id'] ?? null) ?? 0;
    $posted = $_POST;
    if (!AUTH::check_csrf_token()) {
        $tokenRefused = true;
    } else {
        try {
            if ($action === 'create_ga4') {
                $str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
                $settings = [
                    'measurement_id' => $str('measurement_id'),
                    'event_name' => $str('event_name') !== '' ? $str('event_name') : 'purchase',
                    'client_id_source' => $str('client_id_source') !== '' ? $str('client_id_source') : 'click',
                    'send_user_data' => ($_POST['send_user_data'] ?? '') === '1',
                    'consent' => array_filter(['ad_user_data' => $str('ad_user_data'), 'ad_personalization' => $str('ad_personalization')], static fn (string $v): bool => $v !== ''),
                ];
                $created = $store->create($userId, Ga4Destination::TYPE, $str('name'), $settings, ['api_secret' => $str('api_secret')], time());
                header('location: ' . $self . '?done=created&id=' . (int) $created['destination_id'] . '#d' . (int) $created['destination_id']);
                die();
            } elseif ($action === 'enable' || $action === 'disable') {
                if ($store->update($userId, $id, ['enabled' => $action === 'enable'], time()) === null) {
                    $errors['destination'] = 'That destination is not one of yours, or was deleted.';
                } else {
                    header('location: ' . $self . '?done=' . $action . 'd#d' . $id);
                    die();
                }
            } elseif ($action === 'delete') {
                if ($store->delete($userId, $id, time())) {
                    header('location: ' . $self . '?done=deleted');
                    die();
                }
                $errors['destination'] = 'That destination is not one of yours, or was deleted.';
            } elseif ($action === 'test') {
                $result = (new DestinationQueue($conn, $store))->testFire($userId, $id, time());
                if ($result === null) {
                    $errors['destination'] = 'That destination is not one of yours, or was deleted.';
                } else {
                    $_SESSION['p202_destination_test'] = ['id' => $id] + $result;
                    header('location: ' . $self . '?done=tested#d' . $id);
                    die();
                }
            } else {
                $errors['action'] = 'The form did not say what to do; nothing was changed.';
            }
        } catch (DestinationInputException $e) {
            $errors[$e->field] = $e->getMessage();
        } catch (\Throwable $e) {
            error_log(p202UncaughtLogLine('Setup › Destinations: ' . $action . ' failed', $e));
            $errors['action'] = 'That could not be done. Try again; if it keeps failing, the server\'s error log says why.';
        }
    }
}

$test = null;
if (isset($_SESSION['p202_destination_test']) && is_array($_SESSION['p202_destination_test'])) {
    $test = $_SESSION['p202_destination_test'];
    unset($_SESSION['p202_destination_test']);
}
$destinations = $store->list($userId);
// The latest deliveries of each, for a role that may see conversion values.
$recent = [];
if ($canSeeLog && $destinations !== []) {
    $stmt = $conn->prepareRead('SELECT destination_id, conv_id, kind, status, attempts, last_status_code, last_error, created_at FROM 202_destination_deliveries
        WHERE user_id = ? ORDER BY delivery_id DESC LIMIT 200');
    $conn->bind($stmt, 'i', [$userId]);
    foreach ($conn->fetchAll($stmt) as $row) {
        $recent[(int) $row['destination_id']][] = $row;
    }
}
$value = static fn (string $key, string $default = ''): string => is_string($posted[$key] ?? null) ? (string) $posted[$key] : $default;
$when = static fn (int $ts): string => date('Y-m-d H:i', $ts);
$consentChoices = ['' => 'Not stated', 'granted' => 'Granted', 'denied' => 'Denied'];
$gaFields = [
    ['name', 'name', 'Name', 'text', 'Online store GA4', ''],
    ['measurement_id', 'settings.measurement_id', 'Measurement ID', 'text', 'G-XXXXXXXXXX', 'GA4 Admin › Data streams › your web stream.'],
    ['api_secret', 'credentials.api_secret', 'API secret', 'password', '', 'Same stream › Measurement Protocol API secrets › Create. Stored encrypted, never shown again.'],
    ['event_name', 'settings.event_name', 'Event name', 'text', 'purchase', 'Sent with value, currency and transaction_id.'],
];
$formFields = ['name', 'settings.measurement_id', 'settings.event_name', 'settings.client_id_source', 'credentials.api_secret', 'settings.consent.ad_user_data', 'settings.consent.ad_personalization'];

AUTH::set_timezone($_SESSION['user_timezone'] ?? date_default_timezone_get());
template_top('Destinations');
?>

<div class="p202-page-header">
    <div class="p202-page-header__icon"><i class="bi bi-send"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Destinations</h1>
        <p class="p202-page-header__desc">Send each conversion to your ad platforms from this server, as it is recorded. Raw personal data stays here: at most a SHA-256 hash of a customer's email or phone leaves, and only if you turn that on.</p>
    </div>
</div>

<?php
$doneText = [
    'created' => 'Destination added. Send a test to check it.',
    'enabled' => 'Destination on: new conversions are queued for it.',
    'disabled' => 'Destination off: it queues nothing, and what it queued waits.',
    'deleted' => 'Destination deleted. What it had not sent is cancelled.',
][is_string($_GET['done'] ?? null) ? $_GET['done'] : ''] ?? null;
if ($doneText !== null) {
    echo p202_flash('ok', $doneText);
}
if ($tokenRefused) {
    echo p202_flash('bad', 'Your session\'s security token did not match, so nothing was changed. Reload the page and try again.');
}
echo p202_setup_error_flashes($errors, $formFields);
?>

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <section class="p202-panel" id="list">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title">Your destinations</h2>
                <span class="p202-pill"><?php echo count($destinations); ?></span>
            </div>
            <div class="p202-panel__body">
                <?php if ($destinations === []) { ?>
                <div class="p202-empty">
                    <i class="bi bi-send p202-empty__icon"></i>
                    <strong class="p202-empty__title">No destinations yet</strong>
                    <div>Add Google Analytics 4 to send each conversion as a purchase event.</div>
                </div>
                <?php } ?>
                <?php foreach ($destinations as $d) {
                    $id = (int) $d['destination_id'];
                    $settings = (array) $d['settings']; ?>
                <div class="mb-4" id="d<?php echo $id; ?>">
                    <div class="p202-list__item">
                        <span class="p202-list__name"><?php echo p202_setup_e($d['name']); ?></span>
                        <span class="p202-pill"><?php echo p202_setup_e(strtoupper($d['type'])); ?> <?php echo p202_setup_e((string) ($settings['measurement_id'] ?? '')); ?></span>
                        <span class="p202-pill<?php echo $d['enabled'] ? ' p202-pill--accent' : ''; ?>"><?php echo $d['enabled'] ? 'on' : 'off'; ?></span>
                        <span class="p202-list__actions">
                            <?php foreach (['test' => 'send test', $d['enabled'] ? 'disable' : 'enable' => $d['enabled'] ? 'turn off' : 'turn on', 'delete' => 'delete'] as $act => $label) { ?>
                            <form method="post" action="<?php echo p202_setup_e($self); ?>" class="d-inline"<?php echo $act === 'delete' ? ' data-p202-confirm="Delete this destination? What it has not sent is cancelled."' : ''; ?>>
                                <?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
                                <input type="hidden" name="action" value="<?php echo $act; ?>"><input type="hidden" name="destination_id" value="<?php echo $id; ?>">
                                <button type="submit" class="p202-list__action<?php echo $act === 'delete' ? ' p202-list__action--danger' : ''; ?>"><?php echo $label; ?></button>
                            </form>
                            <?php } ?>
                        </span>
                        <span class="p202-list__meta">event <?php echo p202_setup_e((string) ($settings['event_name'] ?? '')); ?> · client id from <?php echo p202_setup_e((string) ($settings['client_id_source'] ?? '')); ?> · <?php echo empty($settings['campaign_ids']) ? 'every campaign' : count($settings['campaign_ids']) . ' campaign(s)'; ?> · hashed user data <?php echo !empty($settings['send_user_data']) ? 'on' : 'off'; ?></span>
                    </div>
                    <?php if ($test !== null && (int) $test['id'] === $id) {
                        echo p202_flash($test['valid'] ? 'ok' : 'bad', $test['valid']
                            ? 'GA4\'s validation endpoint accepted the test event. Nothing was recorded in your property.'
                            : 'The test was refused: ' . ($test['messages'] !== [] ? implode(' ', $test['messages']) : (string) ($test['reason'] ?? 'no reason given')));
                    } ?>
                    <?php if ($canSeeLog && isset($recent[$id])) { ?>
                    <div class="p202-table-wrap">
                        <table class="table p202-table">
                            <thead><tr><th>When</th><th>Conversion</th><th>Status</th><th class="num">Tries</th><th>Last answer</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($recent[$id], 0, 10) as $row) { ?>
                                <tr>
                                    <td><?php echo p202_setup_e($when((int) $row['created_at'])); ?></td>
                                    <td><?php echo $row['conv_id'] === null ? 'test' : (int) $row['conv_id']; ?></td>
                                    <td><?php echo p202_setup_e((string) $row['status']); ?></td>
                                    <td class="num"><?php echo (int) $row['attempts']; ?></td>
                                    <td><?php echo p202_setup_e(trim(($row['last_status_code'] !== null ? $row['last_status_code'] . ' ' : '') . (string) ($row['last_error'] ?? ''))); ?></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <?php } ?>
                </div>
                <?php } ?>
                <div class="form-text">Conversions go out within a minute or two, sent by the minutely cron job and retried with backoff (8 tries over about a day). A visitor your privacy setting holds back (Personal settings) is never sent: their row says withheld. Field mapping, campaigns and the full log: <code>p202 destination</code> or <code>/api/v3/destinations</code>.</div>
            </div>
        </section>
    </div>

    <div class="col-12 col-lg-5">
        <form class="p202-panel" id="add" method="post" action="<?php echo p202_setup_e($self); ?>#add" autocomplete="off">
            <?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
            <input type="hidden" name="action" value="create_ga4">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title">Add Google Analytics 4</h2>
                <span class="p202-panel__sub">Measurement Protocol</span>
            </div>
            <div class="p202-panel__body">
                <?php foreach ($gaFields as [$field, $key, $label, $type, $placeholder, $help]) { ?>
                <div class="mb-3">
                    <label class="form-label" for="ga4_<?php echo $field; ?>"><?php echo p202_setup_e($label); ?></label>
                    <input type="<?php echo $type; ?>" class="form-control<?php echo p202_setup_invalid($errors, $key); ?>" id="ga4_<?php echo $field; ?>" name="<?php echo $field; ?>" placeholder="<?php echo p202_setup_e($placeholder); ?>" value="<?php echo $type === 'password' ? '' : p202_setup_e($value($field)); ?>">
                    <?php echo p202_setup_feedback($errors, $key); ?>
                    <?php if ($help !== '') {
                        ?><div class="form-text"><?php echo p202_setup_e($help); ?></div><?php
                    } ?>
                </div>
                <?php } ?>
                <div class="mb-3">
                    <label class="form-label" for="ga4_client_id_source">GA client id</label>
                    <select class="form-select<?php echo p202_setup_invalid($errors, 'settings.client_id_source'); ?>" id="ga4_client_id_source" name="client_id_source">
                        <?php echo p202_setup_options(['click' => 'Made from the click (p202.<click id>)', 'c1' => 'In c1', 'c2' => 'In c2', 'c3' => 'In c3', 'c4' => 'In c4'], $value('client_id_source', 'click')); ?>
                    </select>
                    <div class="form-text">GA4 ties an event to a browser session only by its _ga client id. If your landing page passes it on a c variable, pick that one.</div>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="ga4_send_user_data" name="send_user_data" value="1"<?php echo $value('send_user_data') === '1' ? ' checked' : ''; ?>>
                    <label class="form-check-label" for="ga4_send_user_data">Send hashed email and phone (from the conversion's customer)</label>
                </div>
                <?php foreach (['ad_user_data' => 'Ad User Data', 'ad_personalization' => 'Ad Personalization'] as $key => $label) { ?>
                <div class="mb-3">
                    <label class="form-label" for="ga4_<?php echo $key; ?>"><?php echo p202_setup_e($label); ?> consent</label>
                    <select class="form-select<?php echo p202_setup_invalid($errors, 'settings.consent.' . $key); ?>" id="ga4_<?php echo $key; ?>" name="<?php echo $key; ?>">
                        <?php echo p202_setup_options($consentChoices, $value($key)); ?>
                    </select>
                </div>
                <?php } ?>
                <div class="form-text mb-3">Prosper202 does not record a visitor's consent: state what your consent banner collected, or leave it unstated.</div>
                <div class="p202-form-actions">
                    <button type="submit" class="btn btn-primary">Add destination</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
