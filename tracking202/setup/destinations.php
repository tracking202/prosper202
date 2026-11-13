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
require_once __DIR__ . '/_includes/destination_panels.php';
require_once __DIR__ . '/_includes/ad_connection_panel.php';

use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\AdPlatformUnavailable;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;
use Prosper202\AdPlatforms\OpenAiAds\OpenAiAdsConnections;
use Prosper202\AdPlatforms\OpenAiAds\OpenAiAdsTestEvent;
use Prosper202\Destinations\DestinationForm;
use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\Ga4Destination;
use Prosper202\Destinations\GoogleAdsAccounts;
use Prosper202\Destinations\GoogleAdsDestination;
use Prosper202\Destinations\GoogleAdsOAuth;
use Prosper202\Destinations\OpenAiAdsDestination;

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
$adStore = new AdConnectionStore($conn);
$advertiserApi = static fn (): AdvertiserApi => AdvertiserApi::fromEnvironment(\Api\V3\Support\ServerStateStore::forInstall($db));
$adConnections = static fn (): OpenAiAdsConnections => new OpenAiAdsConnections($adStore, $advertiserApi(), $store, \Prosper202\Database\InstallId::of($db));
$base = get_absolute_url();
$self = $base . 'tracking202/setup/destinations.php';
$redirectUri = GoogleAdsOAuth::redirectUri($_SERVER);
$canSeeLog = $userObj->hasPermission('access_to_campaign_data');
// The full log of one destination (?log=<id>), for a role that may see it.
$logId = \Prosper202\Http\PostedId::read($_GET['log'] ?? null);
$logStatus = is_string($_GET['status'] ?? null) && in_array($_GET['status'], DestinationQueue::STATUSES, true) ? $_GET['status'] : null;
$logPage = \Prosper202\Http\PostedId::read($_GET['page'] ?? null) ?? 1;
$logQuery = $logId === null ? '' : '?log=' . $logId . ($logStatus !== null ? '&status=' . $logStatus : '') . ($logPage > 1 ? '&page=' . $logPage : '');
$errors = [];
$tokenRefused = false;
$posted = null;
$choose = null;
$connectionId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = \Prosper202\Http\PostedId::read($_POST['destination_id'] ?? null) ?? 0;
    $posted = $_POST;
    if (!AUTH::check_csrf_token()) {
        $tokenRefused = true;
    } else {
        try {
            $connectionId = \Prosper202\Http\PostedId::read($_POST['connection_id'] ?? null) ?? 0;
            $postedText = static fn (string $k): ?string => is_string($_POST[$k] ?? null) && trim($_POST[$k]) !== '' ? trim($_POST[$k]) : null;
            if ($action === 'ad_connect') {
                $ppcAccountId = $postedText('ppc_account_id') === null ? null : \Prosper202\Http\PostedId::read($_POST['ppc_account_id']);
                if ($postedText('ppc_account_id') !== null && $ppcAccountId === null) {
                    throw new AdConnectionInputException('ppc_account_id', 'The form named no traffic source account; pick one or None.');
                }
                $made = $adConnections()->connect($userId, OpenAiAdsConnections::advertiserKey($_POST['api_key'] ?? null), $ppcAccountId, time());
                header('location: ' . $self . '?done=ad_connected#ac' . (int) $made['connection_id']);
                die();
            } elseif ($action === 'ad_check') {
                $checked = $adConnections()->check($userId, $connectionId, time());
                if ($checked === null) {
                    $errors['destination'] = 'That connection is not one of yours, or was deleted.';
                } elseif (is_string($checked['last_error'] ?? null) && $checked['last_error'] !== '') {
                    // Recorded on the connection too; said in its block rather than as "read again".
                    $errors['check'] = $checked['last_error'];
                } else {
                    header('location: ' . $self . '?done=ad_checked#ac' . $connectionId);
                    die();
                }
            } elseif ($action === 'ad_replace_key') {
                // The key is read by replaceKey() itself, after it has found the connection.
                $newKey = is_string($_POST['api_key'] ?? null) ? $_POST['api_key'] : '';
                if ($adConnections()->replaceKey($userId, $connectionId, $newKey, time()) === null) {
                    $errors['destination'] = 'That connection is not one of yours, or was deleted.';
                } else {
                    header('location: ' . $self . '?done=ad_key_replaced#ac' . $connectionId);
                    die();
                }
            } elseif ($action === 'ad_setup') {
                $steps = is_array($_POST['steps'] ?? null) ? array_values(array_filter($_POST['steps'], 'is_string')) : [];
                $events = is_array($_POST['event_types'] ?? null) ? array_values(array_filter($_POST['event_types'], 'is_string')) : [];
                $options = array_filter([
                    'skip' => array_values(array_diff(OpenAiAdsConnections::STEPS, $steps)),
                    'event_types' => $events,
                    'pixel_source_id' => $postedText('pixel_source_id'),
                    'pixel_id' => $postedText('pixel_id'),
                    'source_id' => $postedText('source_id'),
                    'conversions_api_key' => $postedText('conversions_api_key'),
                ], static fn (mixed $v): bool => $v !== null && $v !== []);
                $ran = $adConnections()->setup($userId, $connectionId, $options, time());
                if ($ran === null) {
                    $errors['destination'] = 'That connection is not one of yours, or was deleted.';
                } else {
                    $_SESSION['p202_ad_setup'] = ['id' => $connectionId, 'steps' => array_map(static fn (array $step): array => array_intersect_key($step, array_flip(['step', 'outcome', 'detail'])), $ran['steps'])];
                    header('location: ' . $self . '?done=ad_setup#ac' . $connectionId);
                    die();
                }
            } elseif ($action === 'ad_delete') {
                if ($adStore->delete($userId, $connectionId, time())) {
                    header('location: ' . $self . '?done=ad_deleted#chatgpt-ads');
                    die();
                }
                $errors['destination'] = 'That connection is not one of yours, or was deleted.';
            } elseif ($action === 'create_ga4') {
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
            } elseif ($action === 'create_openai_ads') {
                $str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
                $settings = array_filter([
                    'pixel_id' => $str('oai_pixel_id'),
                    'event_type' => $str('oai_event_type'),
                    'custom_event_name' => $str('oai_custom_event_name'),
                    'scope' => $str('oai_scope'),
                ], static fn (string $v): bool => $v !== '') + ['send_user_data' => ($_POST['oai_send_user_data'] ?? '') === '1'];
                if ($str('oai_ppc_account_id') !== '') {
                    $settings['ppc_account_id'] = \Prosper202\Http\PostedId::read($_POST['oai_ppc_account_id'])
                        ?? throw new DestinationInputException('settings.ppc_account_id', 'Pick the traffic source account from the list.');
                }
                $created = $store->create($userId, OpenAiAdsDestination::TYPE, $str('oai_name'), $settings, ['conversions_api_key' => $str('oai_conversions_api_key')], time());
                header('location: ' . $self . '?done=created&id=' . (int) $created['destination_id'] . '#d' . (int) $created['destination_id']);
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
            } elseif ($action === 'test_real') {
                $real = (new OpenAiAdsTestEvent($conn, $adStore, $store, $advertiserApi(), new OpenAiAdsDestination(AdvertiserApi::eventsSender())))->send($userId, $id, time());
                if ($real === null) {
                    $errors['destination'] = 'That destination is not one of yours, or was deleted.';
                } else {
                    $_SESSION['p202_destination_real_test'] = ['id' => $id] + $real;
                    header('location: ' . $self . '?done=tested#d' . $id);
                    die();
                }
            } elseif ($action === 'test') {
                $result = (new DestinationQueue($conn, $store))->testFire($userId, $id, time());
                if ($result === null) {
                    $errors['destination'] = 'That destination is not one of yours, or was deleted.';
                } else {
                    $_SESSION['p202_destination_test'] = ['id' => $id] + $result;
                    header('location: ' . $self . '?done=tested#d' . $id);
                    die();
                }
            } elseif ($action === 'update') {
                $current = $store->get($userId, $id);
                if ($current === null || $store->update($userId, $id, DestinationForm::patch($_POST, $current, $store->type((string) $current['type'])), time(), true) === null) {
                    $errors['destination'] = 'That destination is not one of yours, or was deleted.';
                } else {
                    header('location: ' . $self . '?done=updated#d' . $id);
                    die();
                }
            } elseif ($action === 'resend' || $action === 'resend_all') {
                $deliveryId = $action === 'resend' ? \Prosper202\Http\PostedId::read($_POST['delivery_id'] ?? null) : null;
                $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
                if ($action === 'resend' && $deliveryId === null) {
                    $errors['delivery'] = 'The form did not name a delivery; nothing was changed.';
                } else {
                    $again = (new DestinationQueue($conn, $store))->resend($userId, $id, $deliveryId, $status, time());
                    if ($again === null) {
                        $errors['destination'] = 'That destination is not one of yours, or was deleted.';
                    } elseif ($again['queued'] === 0) {
                        $errors['delivery'] = $action === 'resend'
                            ? 'That delivery is not a failed or withheld conversion of this destination (it may have been sent again already); nothing was changed.'
                            : 'No ' . $status . ' conversion of the last ' . DestinationQueue::RESEND_DAYS . ' days was found to send again.';
                    } else {
                        $_SESSION['p202_destination_queued'] = ['id' => $id, 'what' => 'again'] + $again;
                        header('location: ' . $self . ($action === 'resend' && $logQuery !== '' ? $logQuery . '&done=queued' : '?done=queued#d' . $id));
                        die();
                    }
                }
            } elseif ($action === 'backfill') {
                $days = \Prosper202\Http\PostedId::read($_POST['days'] ?? null);
                $filled = $days === null ? null : (new DestinationQueue($conn, $store))->backfill($userId, $id, $days, time());
                if ($days === null) {
                    $errors['days'] = 'Pick how many days back to send; nothing was changed.';
                } elseif ($filled === null) {
                    $errors['destination'] = 'That destination is not one of yours, or was deleted.';
                } else {
                    $_SESSION['p202_destination_queued'] = ['id' => $id, 'what' => 'earlier', 'days' => $days] + $filled;
                    header('location: ' . $self . '?done=queued#d' . $id);
                    die();
                }
            } else {
                $errors['action'] = 'The form did not say what to do; nothing was changed.';
            }
        } catch (DestinationInputException | AdConnectionInputException $e) {
            $errors[$e->field] = $e->getMessage();
        } catch (AdPlatformUnavailable $e) {
            // OpenAI was not reached, or is limiting requests: its own sentence, which is not about the key.
            $errors['openai'] = $e->getMessage();
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
$realTest = null;
if (isset($_SESSION['p202_destination_real_test']) && is_array($_SESSION['p202_destination_real_test'])) {
    $realTest = $_SESSION['p202_destination_real_test'];
    unset($_SESSION['p202_destination_real_test']);
}
if (isset($_SESSION['p202_destination_test']) && is_array($_SESSION['p202_destination_test'])) {
    $test = $_SESSION['p202_destination_test'];
    unset($_SESSION['p202_destination_test']);
}
$adSetup = null;
if (isset($_SESSION['p202_ad_setup']) && is_array($_SESSION['p202_ad_setup'])) {
    $adSetup = $_SESSION['p202_ad_setup'];
    unset($_SESSION['p202_ad_setup']);
}
$queuedNotice = null;
if (isset($_SESSION['p202_destination_queued']) && is_array($_SESSION['p202_destination_queued'])) {
    $queuedNotice = $_SESSION['p202_destination_queued'];
    unset($_SESSION['p202_destination_queued']);
}
$destinations = $store->list($userId);
$adConnectionList = $adStore->list($userId);
$ppcAccountNames = [];
$ppcAccountSql = 'SELECT a.ppc_account_id, a.ppc_account_name, n.ppc_network_name FROM 202_ppc_accounts a JOIN 202_ppc_networks n ON n.ppc_network_id = a.ppc_network_id AND n.user_id = a.user_id'
    . ' WHERE a.user_id = ' . $userId . ' AND a.ppc_account_deleted = 0 AND n.ppc_network_deleted = 0 ORDER BY n.ppc_network_name, a.ppc_account_name';
foreach (p202_setup_rows($db, $ppcAccountSql) as $row) {
    $ppcAccountNames[(int) $row['ppc_account_id']] = $row['ppc_network_name'] . ' › ' . $row['ppc_account_name'];
}
// The destination whose account or conversion action is being changed.
$choose ??= \Prosper202\Http\PostedId::read($_GET['choose'] ?? null) ?? 0;
$googleAccounts = new GoogleAdsAccounts();
$now = time();
$queue = new DestinationQueue($conn, $store);
$health = $queue->health($userId, array_map(static fn (array $d): int => (int) $d['destination_id'], $destinations), $now - 7 * 86400, $now);
$privacy = \Prosper202\Http\PrivacySetting::forAccount($conn, $userId);
$campaignLabels = [];
foreach (p202_setup_campaign_options($db, $userId) as $group) {
    foreach ($group['options'] as $campaignId => $option) {
        $campaignLabels[(int) $campaignId] = $group['label'] . ' › ' . $option['label'];
    }
}
$logDestination = null;
if ($logId !== null && $canSeeLog) {
    foreach ($destinations as $d) {
        $logDestination = (int) $d['destination_id'] === $logId ? $d : $logDestination;
    }
    if ($logDestination === null) {
        $errors['destination'] = 'That destination is not one of yours, or was deleted.';
    }
}
// The latest deliveries of each destination, read per destination so a busy one cannot crowd out a quiet one's.
$recent = [];
if ($canSeeLog) {
    foreach ($destinations as $d) {
        $rows = array_slice($queue->log($userId, (int) $d['destination_id'], null, 10, 0), 0, 10);
        if ($rows !== []) {
            $recent[(int) $d['destination_id']] = $rows;
        }
    }
}
$value = static fn (string $key, string $default = ''): string => is_string($posted[$key] ?? null) ? (string) $posted[$key] : $default;
// A refused form's errors and values belong to the form that was posted, not to both.
$postedAction = is_string($posted['action'] ?? null) ? $posted['action'] : '';
$formErrors = static fn (string $form): array => $postedAction === $form ? $errors : [];
// A refused edit is shown again in its destination's own form, where the page lands.
$editedId = $postedAction === 'update' && $errors !== [] && in_array($id ?? 0, array_map(static fn (array $d): int => (int) $d['destination_id'], $destinations), true) ? $id : null;
// A refused action on one connection is said in that connection's block, where the page lands.
$adRefused = $errors !== [] && isset(P202_AD_CONNECTION_REFUSALS[$postedAction])
    && $logId === null && in_array($connectionId, array_column($adConnectionList, 'connection_id'), true)
    ? ['id' => $connectionId, 'action' => $postedAction, 'errors' => $errors] : null;
$ga4Errors = $formErrors('create_ga4');
$gadsErrors = $formErrors('create_google_ads');
$oaiErrors = $formErrors('create_openai_ads');
$oaiFields = [
    ['oai_name', 'name', 'Name', 'text', 'ChatGPT Ads', ''],
    ['oai_pixel_id', 'settings.pixel_id', 'Pixel ID', 'text', '', 'Ads Manager › Conversions › your web pixel.'],
    ['oai_conversions_api_key', 'credentials.conversions_api_key', 'Conversions API key', 'password', '', 'Ads Manager › Conversions › Conversions API keys › Create. Stored encrypted, never shown again.'],
];
$oaiEventTypes = array_combine(array_keys(OpenAiAdsDestination::EVENT_TYPES), array_keys(OpenAiAdsDestination::EVENT_TYPES));
$trafficSources = [];
foreach (p202_destination_traffic_sources($conn, $userId) as $accountId => $accountLabel) {
    $trafficSources[$accountId] = $accountLabel;
}
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
$formFields = ['api_key', 'ppc_account_id', 'settings.pixel_id', 'settings.event_type', 'settings.custom_event_name', 'settings.scope', 'settings.ppc_account_id', 'credentials.conversions_api_key', 'name', 'settings.measurement_id', 'settings.event_name', 'settings.client_id_source', 'credentials.api_secret', 'settings.consent.ad_user_data', 'settings.consent.ad_personalization',
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
    'ad_connected' => 'ChatGPT Ads connected: OpenAI accepted the key. Run setup to make the pixel, Conversions API key and event settings.',
    'ad_checked' => 'OpenAI accepted the stored key; the account\'s name, currency and time zone were read again.',
    'ad_key_replaced' => 'Advertiser API key replaced: OpenAI accepted the new key for this ad account.',
    'ad_setup' => 'Setup ran; what each step did is under the connection.',
    'ad_deleted' => 'ChatGPT Ads connection deleted. Its keys are erased here; nothing was removed at OpenAI.',
    'updated' => 'Changes saved. Conversions that were waiting for this destination go out with the next run; send a test to check it. Conversions that failed before the change stay failed until you send them again, below.',
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
if ($queuedNotice !== null && ($_GET['done'] ?? '') === 'queued') {
    $n = (int) ($queuedNotice['queued'] ?? 0);
    echo p202_flash($n > 0 ? 'ok' : 'info', (($queuedNotice['what'] ?? '') === 'earlier'
        ? ($n > 0 ? number_format($n) . ' earlier conversion(s) queued for this destination.' : 'No paid conversion of the last ' . (int) ($queuedNotice['days'] ?? 0) . ' day(s) was left to queue: each one already has a row in its log.')
        : number_format($n) . ' conversion(s) queued to be sent again, with all their tries.')
        . ($n > 0 ? ' Sending them takes ' . p202_destination_send_time($n) . ' at the cron job\'s rate (about ' . DestinationQueue::SENT_A_MINUTE . ' a minute); the destination\'s counts below show how many are still to send, sent and failed.' : '')
        . (($queuedNotice['more'] ?? false) === true ? ' More remain: press the button again for the next ' . number_format(DestinationQueue::RESEND_BATCH) . '.' : ''));
}
echo p202_setup_error_flashes($editedId !== null || $adRefused !== null ? [] : $errors, $formFields);
if ($logDestination !== null) {
    echo p202_destination_log($logDestination, $queue->log($userId, $logId, $logStatus, 50, ($logPage - 1) * 50), $logStatus, $logPage, 50, $self, $base, (string) ($_SESSION['token'] ?? ''), $when);
    echo p202_setup_script_tag($base);
    template_bottom();

    return;
}
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
                        <?php $isAds = $d['type'] === GoogleAdsDestination::TYPE;
                        $adapter = $store->types()[$d['type']] ?? null; ?>
                        <span class="p202-pill"><?php echo p202_setup_e($adapter !== null ? $adapter->label($settings) : strtoupper($d['type'])); ?></span>
                        <span class="p202-pill<?php echo $d['enabled'] ? ' p202-pill--accent' : ''; ?>"><?php echo $d['enabled'] ? 'on' : 'off'; ?></span>
                        <?php if (($d['status'] ?? 'ready') !== 'ready') { ?>
                        <span class="p202-pill"><?php echo ['needs_authorisation' => 'not connected', 'needs_account' => 'pick its account'][$d['status']] ?? 'credentials unreadable'; ?></span>
                        <?php } ?>
                        <span class="p202-list__actions">
                            <?php $acts = ($isAds ? ['connect' => ($d['status'] ?? '') === 'ready' ? 'reconnect Google' : 'Connect Google Ads'] : []) + ['test' => 'send test']
                                + (p202_ad_connection_reaches($adConnectionList, $d) ? ['test_real' => 'send a real test event'] : [])
                                + [$d['enabled'] ? 'disable' : 'enable' => $d['enabled'] ? 'turn off' : 'turn on', 'delete' => 'delete']; ?>
                            <?php foreach ($acts as $act => $label) {
                                $confirm = ['delete' => 'Delete this destination? What it has not sent is cancelled.', 'test_real' => 'Send a real test event to ChatGPT Ads? OpenAI saves it and counts it as a conversion of the pixel; then its recent events are read back.'][$act] ?? ''; ?>
                            <form method="post" action="<?php echo p202_setup_e($self); ?>" class="d-inline"<?php echo $confirm !== '' ? ' data-p202-confirm="' . p202_setup_e($confirm) . '"' : ''; ?>>
                                <?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
                                <input type="hidden" name="action" value="<?php echo $act; ?>"><input type="hidden" name="destination_id" value="<?php echo $id; ?>">
                                <button type="submit" class="p202-list__action<?php echo $act === 'delete' ? ' p202-list__action--danger' : ''; ?>"><?php echo $label; ?></button>
                            </form>
                            <?php } ?>
                            <?php if ($isAds && ($d['status'] ?? '') === 'ready' && $choose !== $id) { ?>
                            <a class="p202-list__action" href="<?php echo p202_setup_e($self . '?choose=' . $id . '#d' . $id); ?>">change account or action</a>
                            <?php } ?>
                        </span>
                        <?php $typeMeta = $d['type'] === OpenAiAdsDestination::TYPE
                            ? 'event ' . p202_setup_e((string) (($settings['event_type'] ?? '') === 'custom' ? ($settings['custom_event_name'] ?? '') : ($settings['event_type'] ?? '')))
                                . (empty($settings['event_types']) ? '' : ' (+' . count((array) $settings['event_types']) . ' per campaign)')
                                . ' · ' . p202_setup_e(p202_destination_openai_scope((string) ($settings['scope'] ?? 'oppref')))
                            : ($isAds
                            ? 'conversion action ' . p202_setup_e(isset($settings['conversion_action']) ? p202_gads_named($settings, 'conversion_action', 'conversion_action') : 'not picked yet')
                                . (empty($settings['campaign_conversion_actions']) ? '' : ' (+' . count((array) $settings['campaign_conversion_actions']) . ' per campaign)')
                            : 'event ' . p202_setup_e((string) ($settings['event_name'] ?? '')) . ' · client id from ' . p202_setup_e((string) ($settings['client_id_source'] ?? ''))); ?>
                        <span class="p202-list__meta"><?php echo $typeMeta; ?> · <?php echo empty($settings['campaign_ids']) ? 'every campaign' : count($settings['campaign_ids']) . ' campaign(s)'; ?> · hashed user data <?php echo !empty($settings['send_user_data']) ? 'on' : 'off'; ?></span>
                    </div>
                    <?php if ($isAds && !isset($settings['settings_invalid']) && (($d['status'] ?? '') === 'needs_account' || ($choose === $id && ($d['status'] ?? '') === 'ready'))) {
                        // Read from Google for this one destination, now.
                        echo p202_gads_picker_html($self, (string) ($_SESSION['token'] ?? ''), $id, p202_gads_picker($store, $googleAccounts, $userId, $id, $settings, $choose === $id && isset($_GET['account'])), ($d['status'] ?? '') === 'ready');
                    } ?>
                    <?php if (is_array($d['waiting'] ?? null)) {
                        echo p202_flash('bad', 'Conversions are waiting for you, using none of their tries: ' . rtrim((string) $d['waiting']['reason'], '. ')
                            . '. They are tried again at ' . $when((int) $d['waiting']['until']) . ', or at once when you connect it again or save its settings or credentials (Edit, below).');
                    } ?>
                    <?php echo p202_destination_privacy_note($privacy, $settings, $base, $adapter?->takesConsent() ?? true); ?>
                    <?php if ($realTest !== null && (int) $realTest['id'] === $id) {
                        echo p202_flash(($realTest['arrived'] ?? false) === true ? 'ok' : 'bad', (string) $realTest['message']);
                    } ?>
                    <?php if ($test !== null && (int) $test['id'] === $id) {
                        echo p202_flash($test['valid'] ? 'ok' : 'bad', $test['valid']
                            ? ($d['type'] === OpenAiAdsDestination::TYPE
                                ? 'OpenAI checked the test event (validate only, nothing saved): the pixel id and Conversions API key are accepted. Confirm the first real conversions in Ads Manager › Conversions.'
                                : ($isAds
                                ? 'Google Ads checked the test upload (validate only, nothing recorded): the authorisation, developer token, account and conversion action are accepted. Only its made-up click id was refused, as expected.'
                                : 'GA4\'s validation endpoint accepted the test event. Nothing was recorded in your property. It does not check the API secret: confirm the first real conversions in GA4\'s Realtime report.'))
                            : 'The test was refused: ' . ($test['messages'] !== [] ? implode(' ', $test['messages']) : (string) ($test['reason'] ?? 'no reason given')));
                    } ?>
                    <?php echo p202_destination_health($d, $health[$id], $self, (string) ($_SESSION['token'] ?? ''), $canSeeLog); ?>
                    <?php echo p202_destination_edit($d, $campaignLabels, $editedId === $id ? $posted : null, $editedId === $id ? $errors : [], $self, (string) ($_SESSION['token'] ?? ''), p202_destination_privacy_sentence($privacy), $base, $trafficSources); ?>
                    <?php if ($canSeeLog && isset($recent[$id])) { ?>
                    <div class="p202-table-wrap">
                        <table class="table p202-table">
                            <thead><tr><th>When</th><th>Conversion</th><th>Status</th><th class="num">Tries</th><th>Last answer</th></tr></thead>
                            <tbody>
                                <?php foreach ($recent[$id] as $row) { ?>
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
                <div class="form-text">Google Ads takes a conversion 6 hours after its click, so those wait until then, uncounted as tries. Conversions go out within a minute or two, sent by the minutely cron job and retried with backoff (8 tries over about a day). A conversion whose click was guessed is never sent (withheld). <?php echo p202_setup_e(p202_destination_privacy_sentence($privacy)); ?> Change the setting in <?php echo p202_destination_privacy_link($base); ?>. The same from a script: <code>p202 destination</code> or <code>/api/v3/destinations</code>.</div>
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
                <div class="form-text mb-3">Prosper202 does not record a visitor's consent: state what your consent banner collected, or leave it unstated. <?php echo p202_setup_e(p202_destination_privacy_sentence($privacy)); ?></div>
                <div class="p202-form-actions">
                    <button type="submit" class="btn btn-primary">Add destination</button>
                </div>
            </div>
        </form>

        <?php echo p202_ad_connections_panel($adConnectionList, $ppcAccountNames, $adSetup, $formErrors('ad_connect'), $self, (string) ($_SESSION['token'] ?? ''), $adRefused); ?>

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
                <div class="form-text mb-3"><?php echo p202_setup_e(p202_destination_privacy_sentence($privacy)); ?> A conversion is sent with the click's gclid (or wbraid, gbraid), or with hashed email and phone when you turn that on; one with neither is withheld.</div>
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
                        <div class="form-text">Left empty, they are picked after you connect. A conversion action for one campaign is set after adding, by editing the destination or with <code>p202 destination update --campaign-conversion-action</code>.</div>
                    </div>
                </details>
                <div class="p202-form-actions">
                    <button type="submit" class="btn btn-primary">Add and connect Google Ads</button>
                </div>
            </div>
        </form>

        <form class="p202-panel mt-4" id="add-openai-ads" method="post" action="<?php echo p202_setup_e($self); ?>#add-openai-ads" autocomplete="off">
            <?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
            <input type="hidden" name="action" value="create_openai_ads">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title">Add ChatGPT Ads</h2>
                <span class="p202-panel__sub">OpenAI Conversions API</span>
            </div>
            <div class="p202-panel__body">
                <?php foreach ($oaiFields as [$field, $key, $label, $type, $placeholder, $help]) { ?>
                <div class="mb-3">
                    <label class="form-label" for="<?php echo $field; ?>"><?php echo p202_setup_e($label); ?></label>
                    <input type="<?php echo $type; ?>" class="form-control<?php echo p202_setup_invalid($oaiErrors, $key); ?>" id="<?php echo $field; ?>" name="<?php echo $field; ?>" placeholder="<?php echo p202_setup_e($placeholder); ?>" value="<?php echo $type === 'password' || $postedAction !== 'create_openai_ads' ? '' : p202_setup_e($value($field)); ?>">
                    <?php echo p202_setup_feedback($oaiErrors, $key); ?>
                    <?php if ($help !== '') {
                        ?><div class="form-text"><?php echo p202_setup_e($help); ?></div><?php
                    } ?>
                </div>
                <?php } ?>
                <div class="form-text mb-3">Sends conversions whose click came from a ChatGPT ad (it carries OpenAI's click id, oppref) as <code>order_created</code> events. Each is sent once: OpenAI keeps the first event it gets for a conversion and takes no refund or change afterwards. Conversions older than 7 days are not taken. <?php echo p202_setup_e(p202_destination_openai_privacy_sentence($privacy)); ?></div>
                <details class="p202-disclosure mb-3" data-p202-remember="setup-destinations-openai"<?php echo array_intersect_key($oaiErrors, array_flip(['settings.event_type', 'settings.custom_event_name', 'settings.scope', 'settings.ppc_account_id'])) !== [] ? ' open' : ''; ?>>
                    <summary>Advanced <span class="p202-disclosure__hint">event type, which conversions, hashed user data</span></summary>
                    <div class="p202-disclosure__body">
                        <div class="mb-3">
                            <label class="form-label" for="oai_event_type">Event type</label>
                            <select class="form-select<?php echo p202_setup_invalid($oaiErrors, 'settings.event_type'); ?>" id="oai_event_type" name="oai_event_type">
                                <?php echo p202_setup_options($oaiEventTypes, $postedAction === 'create_openai_ads' ? $value('oai_event_type', 'order_created') : 'order_created'); ?>
                            </select>
                            <?php echo p202_setup_feedback($oaiErrors, 'settings.event_type'); ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="oai_custom_event_name">Custom event name</label>
                            <input type="text" class="form-control<?php echo p202_setup_invalid($oaiErrors, 'settings.custom_event_name'); ?>" id="oai_custom_event_name" name="oai_custom_event_name" value="<?php echo $postedAction !== 'create_openai_ads' ? '' : p202_setup_e($value('oai_custom_event_name')); ?>">
                            <?php echo p202_setup_feedback($oaiErrors, 'settings.custom_event_name'); ?>
                            <div class="form-text">Only with the event type custom: lowercase letters, digits, - and _, starting and ending with a letter or digit.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="oai_scope">Which conversions</label>
                            <select class="form-select<?php echo p202_setup_invalid($oaiErrors, 'settings.scope'); ?>" id="oai_scope" name="oai_scope">
                                <?php echo p202_setup_options(array_combine(OpenAiAdsDestination::SCOPES, array_map('p202_destination_openai_scope', OpenAiAdsDestination::SCOPES)), $postedAction === 'create_openai_ads' ? $value('oai_scope', 'oppref') : 'oppref'); ?>
                            </select>
                            <?php echo p202_setup_feedback($oaiErrors, 'settings.scope'); ?>
                            <div class="form-text">Every conversion lets OpenAI credit people who saw an ad without clicking it, and sends OpenAI conversions from your other traffic too.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="oai_ppc_account_id">Traffic source account</label>
                            <select class="form-select<?php echo p202_setup_invalid($oaiErrors, 'settings.ppc_account_id'); ?>" id="oai_ppc_account_id" name="oai_ppc_account_id">
                                <?php echo p202_setup_options($trafficSources, $postedAction === 'create_openai_ads' ? $value('oai_ppc_account_id') : '', 'Only with one traffic source…'); ?>
                            </select>
                            <?php echo p202_setup_feedback($oaiErrors, 'settings.ppc_account_id'); ?>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="oai_send_user_data" name="oai_send_user_data" value="1"<?php echo $postedAction === 'create_openai_ads' && $value('oai_send_user_data') === '1' ? ' checked' : ''; ?>>
                            <label class="form-check-label" for="oai_send_user_data">Send hashed email and phone, and the visitor's IP address</label>
                        </div>
                    </div>
                </details>
                <div class="p202-form-actions">
                    <button type="submit" class="btn btn-primary">Add destination</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
