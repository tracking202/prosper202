<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission('access_to_setup_section')) {
    header('location: ' . get_absolute_url() . 'tracking202/');
    die();
}

require_once __DIR__ . '/_includes/setup_ui.php';
require_once __DIR__ . '/_includes/google_ads_picker.php';

use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\Ga4Destination;
use Prosper202\Destinations\GoogleAdsAccounts;
use Prosper202\Destinations\GoogleAdsDestination;
use Prosper202\Destinations\GoogleAdsOAuth;

/*
 * Setup › Destinations: the ad platforms this account's conversions are sent
 * to from this server (Prosper202\Destinations), the same store and queue
 * as /api/v3/destinations: GA4 and Google Ads. Secrets are taken and never
 * shown. A Google Ads destination is connected to the operator's Google
 * account through Google's consent screen; destination-oauth.php is where
 * Google sends the browser back. Its account and conversion action are then
 * picked from what Google lists (_includes/google_ads_picker.php).
 */

$userId = (int) $_SESSION['user_id'];
$conn = new \Prosper202\Database\Connection($db);
$store = new DestinationStore($conn);
$base = get_absolute_url();
$self = $base . 'tracking202/setup/destinations.php';
$redirectUri = GoogleAdsOAuth::redirectUri($_SERVER);
$canSeeLog = $userObj->hasPermission('access_to_campaign_data');
$errors = [];
$tokenRefused = false;
$posted = null;
$choose = null;

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
            } elseif ($action === 'create_google_ads') {
                $str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
                // The IDs are optional: left out, they are picked from Google's lists once connected.
                $settings = array_filter([
                    'customer_id' => $str('gads_customer_id'),
                    'login_customer_id' => $str('gads_login_customer_id'),
                    'conversion_action' => $str('gads_conversion_action'),
                ], static fn (string $v): bool => $v !== '') + [
                    'send_user_data' => ($_POST['gads_send_user_data'] ?? '') === '1',
                    'consent' => array_filter(['ad_user_data' => $str('gads_ad_user_data'), 'ad_personalization' => $str('gads_ad_personalization')], static fn (string $v): bool => $v !== ''),
                ];
                $credentials = [
                    'developer_token' => $str('gads_developer_token'),
                    'oauth_client_id' => $str('gads_oauth_client_id'),
                    'oauth_client_secret' => $str('gads_oauth_client_secret'),
                ];
                $created = $store->create($userId, GoogleAdsDestination::TYPE, $str('gads_name'), $settings, $credentials, time());
                // Straight on to Google's consent screen; the destination's Connect button is there if that fails.
                $next = $self . '?done=created_google_ads&id=' . (int) $created['destination_id'] . '#d' . (int) $created['destination_id'];
                try {
                    $next = GoogleAdsOAuth::start($store, $userId, (int) $created['destination_id'], $redirectUri, time())['authorisation_url'] ?? $next;
                } catch (\Throwable $e) {
                    error_log(p202UncaughtLogLine('Setup › Destinations: the new destination was not sent on to Google', $e));
                }
                header('location: ' . $next);
                die();
            } elseif ($action === 'pick_google_ads') {
                try {
                    $picked = p202_gads_pick($store, $userId, $id, $_POST, time());
                } catch (DestinationInputException $e) {
                    // The picker's form has no field of the add forms' to say it under.
                    $picked = false;
                    $errors['pick'] = $e->getMessage();
                }
                if (isset($errors['pick'])) {
                    $choose = $id;
                } elseif ($picked === null) {
                    $errors['destination'] = 'That destination is not one of yours, or was deleted.';
                } else {
                    header('location: ' . $self . ($picked ? '?done=picked' : '?choose=' . $id) . '#d' . $id);
                    die();
                }
            } elseif ($action === 'connect') {
                $start = GoogleAdsOAuth::start($store, $userId, $id, $redirectUri, time());
                if ($start === null) {
                    $errors['destination'] = 'That destination is not one of yours, or was deleted.';
                } else {
                    header('location: ' . $start['authorisation_url']);
                    die();
                }
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

$oauthNotice = null;
if (isset($_SESSION['p202_destination_oauth']) && is_array($_SESSION['p202_destination_oauth'])) {
    $oauthNotice = $_SESSION['p202_destination_oauth'];
    unset($_SESSION['p202_destination_oauth']);
}
$test = null;
if (isset($_SESSION['p202_destination_test']) && is_array($_SESSION['p202_destination_test'])) {
    $test = $_SESSION['p202_destination_test'];
    unset($_SESSION['p202_destination_test']);
}
$destinations = $store->list($userId);
// The destination whose account or conversion action is being changed.
$choose ??= \Prosper202\Http\PostedId::read($_GET['choose'] ?? null) ?? 0;
$googleAccounts = new GoogleAdsAccounts();
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
// A refused form's errors and values belong to the form that was posted, not to both.
$postedAction = is_string($posted['action'] ?? null) ? $posted['action'] : '';
$formErrors = static fn (string $form): array => $postedAction === $form ? $errors : [];
$ga4Errors = $formErrors('create_ga4');
$gadsErrors = $formErrors('create_google_ads');
$when = static fn (int $ts): string => date('Y-m-d H:i', $ts);
$consentChoices = ['' => 'Not stated', 'granted' => 'Granted', 'denied' => 'Denied'];
$gaFields = [
    ['name', 'name', 'Name', 'text', 'Online store GA4', ''],
    ['measurement_id', 'settings.measurement_id', 'Measurement ID', 'text', 'G-XXXXXXXXXX', 'GA4 Admin › Data streams › your web stream.'],
    ['api_secret', 'credentials.api_secret', 'API secret', 'password', '', 'Same stream › Measurement Protocol API secrets › Create. Stored encrypted, never shown again.'],
    ['event_name', 'settings.event_name', 'Event name', 'text', 'purchase', 'Sent with value, currency and transaction_id.'],
];
$gadsFields = [
    ['gads_name', 'name', 'Name', 'text', 'Search campaigns', ''],
    ['gads_developer_token', 'credentials.developer_token', 'Developer token', 'password', '', 'Your manager account\'s API Center. Stored encrypted, never shown again.'],
    ['gads_oauth_client_id', 'credentials.oauth_client_id', 'OAuth client ID', 'text', '….apps.googleusercontent.com', 'Google Cloud Console › Credentials › your OAuth client (Web application).'],
    ['gads_oauth_client_secret', 'credentials.oauth_client_secret', 'OAuth client secret', 'password', '', 'Stored encrypted, never shown again.'],
];
// Only for typing the IDs by hand: they are picked from Google's lists after connecting.
$gadsIdFields = [
    ['gads_customer_id', 'settings.customer_id', 'Customer ID', '123-456-7890', 'The Google Ads account the conversions go to, shown at the top of Google Ads.'],
    ['gads_login_customer_id', 'settings.login_customer_id', 'Manager account ID', '', 'Only when your access to that account is through a manager account.'],
    ['gads_conversion_action', 'settings.conversion_action', 'Conversion action ID', '', 'Goals › Conversions › your action (source Import, clicks): the number after ctId= in its address.'],
];
$formFields = ['name', 'settings.measurement_id', 'settings.event_name', 'settings.client_id_source', 'credentials.api_secret', 'settings.consent.ad_user_data', 'settings.consent.ad_personalization',
    'settings.customer_id', 'settings.login_customer_id', 'settings.conversion_action', 'credentials.developer_token', 'credentials.oauth_client_id', 'credentials.oauth_client_secret'];

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
    'created_google_ads' => 'Destination added. Press Connect Google Ads to authorise it; until then its conversions wait.',
    'connected' => 'Google Ads connected. Once its account and conversion action are set, conversions waiting for it go out with the next run; send a test to check it.',
    'picked' => 'Account and conversion action saved. Conversions waiting for this destination go out with the next run; send a test to check it.',
    'enabled' => 'Destination on: new conversions are queued for it.',
    'disabled' => 'Destination off: it queues nothing, and what it queued waits.',
    'deleted' => 'Destination deleted. What it had not sent is cancelled.',
][is_string($_GET['done'] ?? null) ? $_GET['done'] : ''] ?? null;
if ($doneText !== null) {
    echo p202_flash('ok', $doneText);
}
if ($oauthNotice !== null) {
    echo p202_flash(($oauthNotice['ok'] ?? false) === true ? 'ok' : 'bad', (string) ($oauthNotice['text'] ?? ''));
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
                    <div>Add Google Analytics 4 to send each conversion as a purchase event, or Google Ads to upload it as a click conversion.</div>
                </div>
                <?php } ?>
                <?php foreach ($destinations as $d) {
                    $id = (int) $d['destination_id'];
                    $settings = (array) $d['settings']; ?>
                <div class="mb-4" id="d<?php echo $id; ?>">
                    <div class="p202-list__item">
                        <span class="p202-list__name"><?php echo p202_setup_e($d['name']); ?></span>
                        <?php $isAds = $d['type'] === GoogleAdsDestination::TYPE; ?>
                        <span class="p202-pill"><?php echo $isAds ? 'Google Ads ' . p202_setup_e((string) ($settings['customer_id'] ?? '')) : p202_setup_e(strtoupper($d['type'])) . ' ' . p202_setup_e((string) ($settings['measurement_id'] ?? '')); ?></span>
                        <span class="p202-pill<?php echo $d['enabled'] ? ' p202-pill--accent' : ''; ?>"><?php echo $d['enabled'] ? 'on' : 'off'; ?></span>
                        <?php if (($d['status'] ?? 'ready') !== 'ready') { ?>
                        <span class="p202-pill"><?php echo ['needs_authorisation' => 'not connected', 'needs_account' => 'pick its account'][$d['status']] ?? 'credentials unreadable'; ?></span>
                        <?php } ?>
                        <span class="p202-list__actions">
                            <?php $acts = ($isAds ? ['connect' => ($d['status'] ?? '') === 'ready' ? 'reconnect Google' : 'Connect Google Ads'] : []) + ['test' => 'send test', $d['enabled'] ? 'disable' : 'enable' => $d['enabled'] ? 'turn off' : 'turn on', 'delete' => 'delete']; ?>
                            <?php foreach ($acts as $act => $label) { ?>
                            <form method="post" action="<?php echo p202_setup_e($self); ?>" class="d-inline"<?php echo $act === 'delete' ? ' data-p202-confirm="Delete this destination? What it has not sent is cancelled."' : ''; ?>>
                                <?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
                                <input type="hidden" name="action" value="<?php echo $act; ?>"><input type="hidden" name="destination_id" value="<?php echo $id; ?>">
                                <button type="submit" class="p202-list__action<?php echo $act === 'delete' ? ' p202-list__action--danger' : ''; ?>"><?php echo $label; ?></button>
                            </form>
                            <?php } ?>
                            <?php if ($isAds && ($d['status'] ?? '') === 'ready' && $choose !== $id) { ?>
                            <a class="p202-list__action" href="<?php echo p202_setup_e($self . '?choose=' . $id . '#d' . $id); ?>">change account or action</a>
                            <?php } ?>
                        </span>
                        <?php $typeMeta = $isAds
                            ? 'conversion action ' . p202_setup_e((string) ($settings['conversion_action'] ?? 'not picked yet'))
                                . (empty($settings['campaign_conversion_actions']) ? '' : ' (+' . count((array) $settings['campaign_conversion_actions']) . ' per campaign)')
                            : 'event ' . p202_setup_e((string) ($settings['event_name'] ?? '')) . ' · client id from ' . p202_setup_e((string) ($settings['client_id_source'] ?? '')); ?>
                        <span class="p202-list__meta"><?php echo $typeMeta; ?> · <?php echo empty($settings['campaign_ids']) ? 'every campaign' : count($settings['campaign_ids']) . ' campaign(s)'; ?> · hashed user data <?php echo !empty($settings['send_user_data']) ? 'on' : 'off'; ?></span>
                    </div>
                    <?php if ($isAds && !isset($settings['settings_invalid']) && (($d['status'] ?? '') === 'needs_account' || ($choose === $id && ($d['status'] ?? '') === 'ready'))) {
                        // Read from Google for this one destination, now.
                        echo p202_gads_picker_html($self, (string) ($_SESSION['token'] ?? ''), $id, p202_gads_picker($store, $googleAccounts, $userId, $id, $settings, $choose === $id && isset($_GET['account'])), ($d['status'] ?? '') === 'ready');
                    } ?>
                    <?php if (is_array($d['waiting'] ?? null)) {
                        echo p202_flash('bad', 'Conversions are waiting for you, using none of their tries: ' . rtrim((string) $d['waiting']['reason'], '. ')
                            . '. They are tried again at ' . $when((int) $d['waiting']['until']) . ', or at once when you connect it again or save its settings or credentials (p202 destination update).');
                    } ?>
                    <?php if ($test !== null && (int) $test['id'] === $id) {
                        echo p202_flash($test['valid'] ? 'ok' : 'bad', $test['valid']
                            ? ($isAds
                                ? 'Google Ads checked the test upload (validate only, nothing recorded): the authorisation, developer token, account and conversion action are accepted. Only its made-up click id was refused, as expected.'
                                : 'GA4\'s validation endpoint accepted the test event. Nothing was recorded in your property. It does not check the API secret: confirm the first real conversions in GA4\'s Realtime report.')
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
                <div class="form-text">Google Ads takes a conversion 6 hours after its click, so those wait until then, uncounted as tries. Conversions go out within a minute or two, sent by the minutely cron job and retried with backoff (8 tries over about a day). A guessed click, a visitor your privacy setting holds back, and a visitor who may be in the EEA or UK are never sent (withheld) unless, for the last, both consent signals below are Granted. Field mapping, campaigns and the full log: <code>p202 destination</code> or <code>/api/v3/destinations</code>.</div>
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
                    <input type="<?php echo $type; ?>" class="form-control<?php echo p202_setup_invalid($ga4Errors, $key); ?>" id="ga4_<?php echo $field; ?>" name="<?php echo $field; ?>" placeholder="<?php echo p202_setup_e($placeholder); ?>" value="<?php echo $type === 'password' || $postedAction !== 'create_ga4' ? '' : p202_setup_e($value($field)); ?>">
                    <?php echo p202_setup_feedback($ga4Errors, $key); ?>
                    <?php if ($help !== '') {
                        ?><div class="form-text"><?php echo p202_setup_e($help); ?></div><?php
                    } ?>
                </div>
                <?php } ?>
                <div class="mb-3">
                    <label class="form-label" for="ga4_client_id_source">GA client id</label>
                    <select class="form-select<?php echo p202_setup_invalid($ga4Errors, 'settings.client_id_source'); ?>" id="ga4_client_id_source" name="client_id_source">
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
                    <select class="form-select<?php echo p202_setup_invalid($ga4Errors, 'settings.consent.' . $key); ?>" id="ga4_<?php echo $key; ?>" name="<?php echo $key; ?>">
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

        <form class="p202-panel mt-4" id="add-google-ads" method="post" action="<?php echo p202_setup_e($self); ?>#add-google-ads" autocomplete="off">
            <?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
            <input type="hidden" name="action" value="create_google_ads">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title">Add Google Ads</h2>
                <span class="p202-panel__sub">Click conversions, your own developer token</span>
            </div>
            <div class="p202-panel__body">
                <div class="form-text mb-3">Add your OAuth client's authorised redirect URI first, exactly: <code><?php echo p202_setup_e($redirectUri); ?></code>. Adding the destination takes you to Google to connect it; you then pick the account and conversion action from your Google Ads account. The setup guide (Destinations in the documentation) walks through the developer token and the OAuth client.</div>
                <?php foreach ($gadsFields as [$field, $key, $label, $type, $placeholder, $help]) { ?>
                <div class="mb-3">
                    <label class="form-label" for="<?php echo $field; ?>"><?php echo p202_setup_e($label); ?></label>
                    <input type="<?php echo $type; ?>" class="form-control<?php echo p202_setup_invalid($gadsErrors, $key); ?>" id="<?php echo $field; ?>" name="<?php echo $field; ?>" placeholder="<?php echo p202_setup_e($placeholder); ?>" value="<?php echo $type === 'password' || $postedAction !== 'create_google_ads' ? '' : p202_setup_e($value($field)); ?>">
                    <?php echo p202_setup_feedback($gadsErrors, $key); ?>
                    <?php if ($help !== '') {
                        ?><div class="form-text"><?php echo p202_setup_e($help); ?></div><?php
                    } ?>
                </div>
                <?php } ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="gads_send_user_data" name="gads_send_user_data" value="1"<?php echo $postedAction === 'create_google_ads' && $value('gads_send_user_data') === '1' ? ' checked' : ''; ?>>
                    <label class="form-check-label" for="gads_send_user_data">Send hashed email and phone (enhanced conversions for leads)</label>
                </div>
                <?php foreach (['ad_user_data' => 'Ad User Data', 'ad_personalization' => 'Ad Personalization'] as $key => $label) { ?>
                <div class="mb-3">
                    <label class="form-label" for="gads_<?php echo $key; ?>"><?php echo p202_setup_e($label); ?> consent</label>
                    <select class="form-select<?php echo p202_setup_invalid($gadsErrors, 'settings.consent.' . $key); ?>" id="gads_<?php echo $key; ?>" name="gads_<?php echo $key; ?>">
                        <?php echo p202_setup_options($consentChoices, $postedAction === 'create_google_ads' ? $value('gads_' . $key) : ''); ?>
                    </select>
                </div>
                <?php } ?>
                <div class="form-text mb-3">A conversion is sent with the click's gclid (or wbraid, gbraid), or with hashed email and phone when you turn that on; one with neither is withheld.</div>
                <details class="p202-disclosure mb-3" data-p202-remember="setup-destinations-gads-ids"<?php echo array_intersect_key($gadsErrors, array_flip(array_column($gadsIdFields, 1))) !== [] ? ' open' : ''; ?>>
                    <summary>Advanced <span class="p202-disclosure__hint">type the account and conversion action IDs instead of picking them</span></summary>
                    <div class="p202-disclosure__body">
                        <?php foreach ($gadsIdFields as [$field, $key, $label, $placeholder, $help]) { ?>
                        <div class="mb-3">
                            <label class="form-label" for="<?php echo $field; ?>"><?php echo p202_setup_e($label); ?></label>
                            <input type="text" class="form-control<?php echo p202_setup_invalid($gadsErrors, $key); ?>" id="<?php echo $field; ?>" name="<?php echo $field; ?>" placeholder="<?php echo p202_setup_e($placeholder); ?>" value="<?php echo $postedAction !== 'create_google_ads' ? '' : p202_setup_e($value($field)); ?>">
                            <?php echo p202_setup_feedback($gadsErrors, $key); ?>
                            <div class="form-text"><?php echo p202_setup_e($help); ?></div>
                        </div>
                        <?php } ?>
                        <div class="form-text">Left empty, they are picked after you connect. Per-campaign actions: <code>p202 destination update --campaign-conversion-action</code>.</div>
                    </div>
                </details>
                <div class="p202-form-actions">
                    <button type="submit" class="btn btn-primary">Add and connect Google Ads</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
