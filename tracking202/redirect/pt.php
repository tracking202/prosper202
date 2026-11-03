<?php

declare(strict_types=1);

// Google Ads tracking template (paid 1.9.56's parallel tracking URL): records the
// landing page's click, then redirects; the page's script takes it by gclid.

use Prosper202\Click\ClickRequest;

$t202id = $_GET['t202id'] ?? '';
if (!is_string($t202id) || !ctype_digit($t202id) || (int) $t202id <= 0) {
    http_response_code(404);
    die();
}

include_once(substr(__DIR__, 0, -21) . '/202-config/connect2.php');

if (p202IsSpeculativeRequest()) {
    p202DeclineSpeculativeRequest();
}
// Background calls: the click is recorded whether or not the caller waits.
ignore_user_abort(true);

$ptTrackerId = \Prosper202\Click\PublicId::parse($t202id);
$ptTracker = null;
if ($ptTrackerId !== null) {
    try {
        $ptTracker = \Prosper202\Repository\LookupRepositoryFactory::reads($db)->trackerLandingPage($ptTrackerId);
    } catch (\Throwable $ptReadError) {
        record_mysql_error($db, 'reading tracker ' . $ptTrackerId, $ptReadError->getMessage());
    }
}
if ($ptTracker === null) {
    p202NoStore();
    http_response_code(404);
    die();
}

// A tracker with no landing page is a direct link: dl.php records and redirects it.
if ($ptTracker['landing_page_id_public'] === null) {
    p202NoStore();
    header('Location: dl.php?' . (string) ($_SERVER['QUERY_STRING'] ?? ''), true, 302);
    die();
}

// Where the caller goes: Google's stand-in for {lpurl}, or the page with the
// ad's click ids, so its script finds this click.
$ptLocation = \Prosper202\Click\GoogleAdsParallelTracking::redirectTarget(
    (string) $ptTracker['landing_page_url'],
    ClickRequest::string($_GET, 'lpurl'),
    ClickRequest::adClickIds($_GET),
    (string) getPrePopVars(array_diff_key($_GET, ['lpurl' => true, 'gb' => true]), true)
);

// A page with no address: nowhere to send the caller, so nothing is recorded.
if ($ptLocation === '') {
    p202NoStore();
    http_response_code(502);
    die();
}

// The request the page's script sends (landing.php): the page, the tracker,
// the visitor's referrer, and the page's own address as the script's referer.
$ptLandingPageId = (int) $ptTracker['landing_page_id_public'];
$_GET['lpip'] = (string) $ptLandingPageId;
$_GET['referer'] = ClickRequest::string($_SERVER, 'HTTP_REFERER');
$_SERVER['HTTP_REFERER'] = (string) $ptTracker['landing_page_url'];

// The recorder record.php would pick; a simple page whose campaign is not its
// account's records nothing there either.
$ptRecorder = match ((string) $ptTracker['landing_page_type']) {
    '0' => 'record_simple.php',
    '1' => 'record_adv.php',
    default => null,
};
if ($ptRecorder === 'record_simple.php') {
    try {
        if (\Prosper202\Repository\LookupRepositoryFactory::reads($db)->landingPageOffer($ptLandingPageId) === null) {
            $ptRecorder = null;
        }
    } catch (\Throwable $ptReadError) {
        record_mysql_error($db, 'reading landing page ' . $ptLandingPageId, $ptReadError->getMessage());
    }
}

if ($ptRecorder !== null) {
    // The recorder answers with the page's script; nobody here runs it.
    ob_start();
    include substr(__DIR__, 0, -21) . '/tracking202/static/' . $ptRecorder;
    ob_end_clean();
}

p202NoStore();
header('Content-Type: text/html; charset=UTF-8');
header('Location: ' . $ptLocation, true, 302);
die();
