<?php

declare(strict_types=1);

use Prosper202\Click\ClickRecord;
use Prosper202\Click\ClickRequest;
use Prosper202\Click\DynamicBid;
use Prosper202\Click\TrackingBaseUrl;

#only allow numeric t202ids, reject 0 as invalid
$t202id = $_GET['t202id'] ?? '';
if (!is_numeric($t202id) || (int)$t202id <= 0) die();

# check to see if mysql connection works, if not fail over to cached stored redirect urls
include_once(substr(__DIR__, 0, -21) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0, -21) . '/202-config/class-dataengine-slim.php');

// Speculative requests must NOT record a click (browser prefetch/prerender +
// the real navigation would otherwise both count the same visit — see
// p202IsSpeculativeRequest). Answer with 204 and record nothing; the real
// navigation re-requests and is counted exactly once.
if (p202IsSpeculativeRequest()) {
	p202DeclineSpeculativeRequest();
}

$locationRepo = \Prosper202\Repository\LookupRepositoryFactory::location($db);
$trackingRepo = \Prosper202\Repository\LookupRepositoryFactory::tracking($db);

// Enable processing to continue even if the client disconnects.
// This is necessary to ensure that critical operations, such as database updates
// or logging, are completed even if the user closes their browser or loses connection.
// Note: The behavior of ignore_user_abort(true) can vary depending on the PHP SAPI environment.
// For example, in CLI mode, this function has no effect, while in Apache or CGI contexts,
// it ensures that the script continues execution regardless of client disconnection.
ignore_user_abort(true);

function renderErrorPage(int $code, string $title, string $message, string $accentColor, string $svgIcon): void
{
	http_response_code($code);
	die('<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-card {
            background: white;
            border-radius: 16px;
            padding: 48px;
            max-width: 480px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }
        .error-icon {
            width: 80px;
            height: 80px;
            background: #f3f4f6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .error-icon svg {
            width: 40px;
            height: 40px;
            color: ' . htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') . ';
        }
        h1 { color: #1f2937; font-size: 24px; margin-bottom: 12px; }
        p { color: #6b7280; font-size: 16px; line-height: 1.6; }
        .error-code {
            display: inline-block;
            background: #f3f4f6;
            color: #6b7280;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 13px;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                ' . $svgIcon . '
            </svg>
        </div>
        <h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>
        <p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
        <span class="error-code">Error ' . $code . '</span>
    </div>
</body>
</html>');
}

$usedCachedRedirect = false;
if (!$db) $usedCachedRedirect = true;

#the mysql server is down, use the cached redirect
if ($usedCachedRedirect == true) {

	$t202id = $_GET['t202id'];

	//if a cached key is found for this t202id, redirect to that url
	if ($memcacheWorking) {
		$getUrl = $memcache->get(md5('url_' . $t202id . systemHash()));
		if ($getUrl) {

			$new_url = str_replace("[[subid]]", "p202", $getUrl);
			// No click is recorded while MySQL is down: nothing to sign, so
			// the install token expands empty (plan §5.1).
			$new_url = str_ireplace('[[p202_install_token]]', '', $new_url);

			// c1-c4, gclid and the UTM values as sent, or p202<name> for one not sent
			$cachedTokens = [
				'c1', 'c2', 'c3', 'c4', 'gclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
			];
			foreach ($cachedTokens as $token) {
				$sent = ClickRequest::text($_GET, $token);
				$new_url = str_replace("[[$token]]", $sent !== '' ? rawurlencode($sent) : "p202$token", $new_url);
			}

			p202NoStore();
			header('location: ' . $new_url);
			die();
		}
	}

	renderErrorPage(
		503,
		'Service Temporarily Unavailable',
		'We are experiencing technical difficulties. Please try again in a few moments.',
		'#d97706',
		'<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>'
	);
}

//grab tracker data
$mysql['tracker_id_public'] = $db->real_escape_string($t202id);
$tracker_sql = "SELECT 202_trackers.user_id,
						202_trackers.aff_campaign_id,
						text_ad_id,
						ppc_account_id,
						click_cpc,
						click_cpa,
						click_cloaking,
						aff_campaign_rotate,
						aff_campaign_url,
						aff_campaign_url_2,
						aff_campaign_url_3,
						aff_campaign_url_4,
						aff_campaign_url_5,
						aff_campaign_payout,
						aff_campaign_cloaking,
						202_aff_campaigns.identity_signals,
						2cv.ppc_variable_ids,
						2cv.parameters,
                        user_timezone, 
		                user_keyword_searched_or_bidded,
                        user_pref_referer_data,
                        user_pref_dynamic_bid,
		                maxmind_isp,
                        user_public_publisher_id
                        FROM 202_trackers 
                        LEFT JOIN 202_users_pref USING (user_id) 
            LEFT JOIN 202_users USING (user_id) 
    			LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
				LEFT JOIN 202_ppc_accounts USING (ppc_account_id)
				LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(ppc_variable_id ORDER BY ppc_variable_id) AS ppc_variable_ids, GROUP_CONCAT(parameter ORDER BY ppc_variable_id) AS parameters FROM 202_ppc_network_variables WHERE deleted = 0 GROUP BY ppc_network_id) AS 2cv USING (ppc_network_id)					                 
				WHERE tracker_id_public='" . $mysql['tracker_id_public'] . "'";

$tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);

// Check if tracker exists BEFORE using its data
if (!$tracker_row) {
	renderErrorPage(
		404,
		'Tracking Link Not Found',
		'The tracking link you requested does not exist or has been removed. Please check the URL and try again.',
		'#dc2626',
		'<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>'
	);
}

// The privacy setting in force for this visitor: the stricter of the
// install's and this tracker's account's (p202ApplyOwnerPrivacy()), before
// the address is stored or a cookie set.
p202ApplyOwnerPrivacy($tracker_row['user_id'] ?? null);

// The URL a MySQL outage redirects to (read above): kept equal to the
// campaign's current URL, not the one it had at its first click.
if ($memcacheWorking) {
	\Prosper202\Click\FallbackRedirectUrl::refresh(
		md5('url_' . $t202id . systemHash()),
		(string) ($tracker_row['aff_campaign_url'] ?? ''),
		static fn(string $key): mixed => $memcache->get($key),
		static fn(string $key, string $url): mixed => setCache($key, $url, 0),
	);
}


//set the timezone to the users timezone
$mysql['user_id'] = $db->real_escape_string((string)($tracker_row['user_id'] ?? '0'));

//now this sets timezone
date_default_timezone_set($tracker_row['user_timezone']);

//get mysql variables 
$mysql['aff_campaign_id'] = $db->real_escape_string((string)($tracker_row['aff_campaign_id'] ?? '0'));
$mysql['ppc_account_id'] = $db->real_escape_string((string)($tracker_row['ppc_account_id'] ?? '0'));
$mysql['user_pref_dynamic_bid'] = $db->real_escape_string((string)($tracker_row['user_pref_dynamic_bid'] ?? '0'));
// The bid the link names when the account takes dynamic bids, else the tracker's CPC.
$bid = $mysql['user_pref_dynamic_bid'] == '1' ? DynamicBid::fromRequest($_GET['t202b'] ?? null) : null;
$mysql['click_cpc'] = $bid ?? $db->real_escape_string((string)($tracker_row['click_cpc'] ?? ''));

$mysql['click_cpa'] = $db->real_escape_string((string)($tracker_row['click_cpa'] ?? ''));
$mysql['click_payout'] = $db->real_escape_string((string)($tracker_row['aff_campaign_payout'] ?? ''));
$mysql['click_time'] = time();
$mysql['text_ad_id'] = $db->real_escape_string((string)($tracker_row['text_ad_id'] ?? '0'));

// The referring page's query: its q is a searched keyword (ClickRequest::keyword()), its url the referer.
$referer_url_parsed = [];
$referer_url_query = '';
$referer_query = [];

if (!empty($_SERVER['HTTP_REFERER'])) {
	$referer_url_parsed = @parse_url((string) $_SERVER['HTTP_REFERER']);
	$referer_url_query = $referer_url_parsed['query'] ?? '';
	@parse_str($referer_url_query, $referer_query);
}

$keyword = ClickRequest::keyword($_GET, $referer_query, $tracker_row['user_keyword_searched_or_bidded'] ?? null);
$keyword_id = $trackingRepo->findOrCreateKeyword($keyword);
$mysql['keyword_id'] = $db->real_escape_string((string)$keyword_id);

$_lGET = array_change_key_case($_GET, CASE_LOWER); //make lowercase copy of get 
$clickCustom = [];
//Get C1-C4 IDs
for ($i = 1; $i <= 4; $i++) {
	$custom = "c" . $i; //create dynamic variable
	// Raw value: findOrCreateCustomVar() binds it as a parameter, so escaping
	// here would store literal backslashes.
	$custom_val = ClickRequest::text($_lGET, $custom);
	$custom_val = str_replace('%20', ' ', $custom_val);
    $clickCustom[$custom] = \Prosper202\Repository\Mysql\MysqlTrackingRepository::storedValue($custom_val);
	$custom_id = $trackingRepo->findOrCreateCustomVar($custom, $custom_val); //get the id
	$mysql[$custom . '_id'] = $db->real_escape_string((string)$custom_id); //save it
}

// Raw: the writer binds it, so an escaped value was stored with its backslashes.
$gclid = ClickRequest::text($_GET, 'gclid');
$mysql['gclid'] = $gclid;

$custom_var_ids = [];

foreach (\Prosper202\Click\TrackerVariables::pairs($tracker_row) as [$value, $ppcVariableId]) {
	$variable = ClickRequest::text($_GET, $value);

	if (isset($variable) && $variable != '') {
		$variable = str_replace('%20', ' ', $variable);
		$variable_id = $trackingRepo->findOrCreateVariable($variable, $ppcVariableId);
		$custom_var_ids[] = $variable_id;
	}
}

//utm_source
$utm_source = ClickRequest::text($_GET, 'utm_source');
if (isset($utm_source) && $utm_source != '') {
	$utm_source = str_replace('%20', ' ', $utm_source);
	$utm_source_id = $trackingRepo->findOrCreateUtm($utm_source, 'utm_source');
} else {
	$utm_source_id = 0;
}
$mysql['utm_source_id'] = $db->real_escape_string((string)$utm_source_id);

//utm_medium
$utm_medium = ClickRequest::text($_GET, 'utm_medium');
if (isset($utm_medium) && $utm_medium != '') {
	$utm_medium = str_replace('%20', ' ', $utm_medium);
	$utm_medium_id = $trackingRepo->findOrCreateUtm($utm_medium, 'utm_medium');
} else {
	$utm_medium_id = 0;
}
$mysql['utm_medium_id'] = $db->real_escape_string((string)$utm_medium_id);

//utm_campaign
$utm_campaign = ClickRequest::text($_GET, 'utm_campaign');
if (isset($utm_campaign) && $utm_campaign != '') {
	$utm_campaign = str_replace('%20', ' ', $utm_campaign);
	$utm_campaign_id = $trackingRepo->findOrCreateUtm($utm_campaign, 'utm_campaign');
} else {
	$utm_campaign_id = 0;
}
$mysql['utm_campaign_id'] = $db->real_escape_string((string)$utm_campaign_id);

//utm_term
$utm_term = ClickRequest::text($_GET, 'utm_term');
if (isset($utm_term) && $utm_term != '') {
	$utm_term = str_replace('%20', ' ', $utm_term);
	$utm_term_id = $trackingRepo->findOrCreateUtm($utm_term, 'utm_term');
} else {
	$utm_term_id = 0;
}
$mysql['utm_term_id'] = $db->real_escape_string((string)$utm_term_id);

//utm_content
$utm_content = ClickRequest::text($_GET, 'utm_content');
if (isset($utm_content) && $utm_content != '') {
	$utm_content = str_replace('%20', ' ', $utm_content);
	$utm_content_id = $trackingRepo->findOrCreateUtm($utm_content, 'utm_content');
} else {
	$utm_content_id = 0;
}
$mysql['utm_content_id'] = $db->real_escape_string((string)$utm_content_id);


// Initialize DeviceDetect if not already done
if (!isset($detect)) {
	$detect = new DeviceDetect();
}

$device_id = PLATFORMS::get_device_info($db, $detect, ClickRequest::text($_GET, 'ua'));
$mysql['platform_id'] = $db->real_escape_string((string)($device_id['platform'] ?? '0'));
$mysql['browser_id'] = $db->real_escape_string((string)($device_id['browser'] ?? '0'));
$mysql['device_id'] = $db->real_escape_string((string)($device_id['device'] ?? '0'));

// Bots are redirected like anyone else; only the recorded flags differ.
$clickIsBot = \Prosper202\Click\ClickBotDetector::isBot($detect->getUserAgent(), $device_id);
$mysql['click_bot'] = $clickIsBot ? '1' : '0';

$mysql['click_in'] = 1;
$mysql['click_out'] = 1;


$ip = \Prosper202\Http\VisitorIp::fromServer($_SERVER);
// Stored masked under the owner's privacy setting (p202StoredVisitorIp).
$ip_id = $locationRepo->findOrCreateIp(p202StoredVisitorIp());
$mysql['ip_id'] = $db->real_escape_string((string)$ip_id);

//before we finish filter this click
$ip_address = $ip;
$user_id = $tracker_row['user_id'];

// GEO/ISP (MaxMind) lookups are deferred into $computeAndRecordClick so the slow
// database reads stay off the redirect critical path for non-cloaked clicks.

if ($clickIsBot) {
	$mysql['click_filtered'] = '1';
} else {
	// Initialize click_id as 0 for the filter (will be updated after insert)
	$click_id_temp = 0;
	$click_filtered = FILTER::startFilter($db, $click_id_temp, $ip_id, $user_id);
	$mysql['click_filtered'] = $db->real_escape_string((string)$click_filtered);
}


// Pre-allocate click_id (single DB write before redirect for minimal latency)
$conn = \Prosper202\Repository\LookupRepositoryFactory::connection($db);
$clickRepo = new \Prosper202\Click\MysqlClickRepository($conn);
$click_id = $clickRepo->allocateClickId();
$mysql['click_id'] = (string) $click_id;
$mysql['click_alp'] = 0;

// Opt-in tracer (no-op unless 202-config/.click-debug exists): this hit passed
// the speculative guard and just reserved a click_id, so it WILL count. Pair
// this line with any 'declined-speculative' line for the same click to see what
// a double-counting second hit actually looks like at the header level.
p202LogRedirectHit('dl.php', 'recorded click_id=' . $click_id);

// Generate click_id_public only when cloaking is active (needed for PCI-based lookups via cl.php)
$click_id_public = '';
$mysql['click_id_public'] = '';

// Determine cloaking (needed for redirect decision)
$cloaking_on = false;
if (\Prosper202\Click\ClickCloaking::isOn($tracker_row)) {
	$cloaking_on = true;
	$mysql['click_cloaking'] = 1;
	$click_id_public = random_int(1, 9) . $click_id . random_int(1, 9);
	$mysql['click_id_public'] = (string) $click_id_public;
} else {
	$mysql['click_cloaking'] = 0;
}

// Compute redirect URL (needed before redirect)
$redirect_site_url = rotateTrackerUrl($db, $tracker_row);
// The click's rows are written after the redirect, so its URL's tokens come
// from the values in hand: read back, they are not there yet. The geo lookup,
// kept off the redirect, runs before it only for a URL that names a place.
$earlyGeoData = preg_match('/\[\[(country|country_code|region|city)\]\]/i', (string) $redirect_site_url)
    ? getGeoData($ip_address)
    : null;
$redirect_site_url = replaceClickPlaceholders($redirect_site_url, (string) $click_id, [
    'keyword' => \Prosper202\Repository\Mysql\MysqlTrackingRepository::storedKeyword($keyword),
    't202pubid' => $tracker_row['user_public_publisher_id'] ?? '',
    'c1' => $clickCustom['c1'],
    'c2' => $clickCustom['c2'],
    'c3' => $clickCustom['c3'],
    'c4' => $clickCustom['c4'],
    'gclid' => ClickRecord::storedGclid($gclid),
    // As stored: a value past the columns' 350 bytes is sent as cut.
    'utm_source' => \Prosper202\Repository\Mysql\MysqlTrackingRepository::storedValue($utm_source),
    'utm_medium' => \Prosper202\Repository\Mysql\MysqlTrackingRepository::storedValue($utm_medium),
    'utm_campaign' => \Prosper202\Repository\Mysql\MysqlTrackingRepository::storedValue($utm_campaign),
    'utm_term' => \Prosper202\Repository\Mysql\MysqlTrackingRepository::storedValue($utm_term),
    'utm_content' => \Prosper202\Repository\Mysql\MysqlTrackingRepository::storedValue($utm_content),
    'country' => $earlyGeoData['country'] ?? '',
    'country_code' => $earlyGeoData['country_code'] ?? '',
    'region' => $earlyGeoData['region'] ?? '',
    'city' => $earlyGeoData['city'] ?? '',
    // As stored: a tracker with no CPC stores 0.00000.
    'cpc' => number_format((float) $mysql['click_cpc'], 5, '.', ''),
    'cpa' => $tracker_row['click_cpa'] ?? '',
    'payout' => $tracker_row['aff_campaign_payout'] ?? '',
    'ppc_account_id' => $tracker_row['ppc_account_id'] ?? '',
]);

$cloaking_site_url = '';
if ($cloaking_on === true) {
	$cloaking_site_url = TrackingBaseUrl::forRequest($_SERVER) . 'tracking202/redirect/cl.php?pci=' . $click_id_public;
}

// Identity signals (the p202vid cookie, the landing page's p202lpid, a
// signed customer id): captured now, because the cookie has to go out with
// the redirect, and linked after the click is stored. Consent withheld —
// p202_consent=0, or the campaign's identity capture off — captures nothing.
// A tracker with no campaign has NULL there, which leaves capture on (the
// column's default); anything but '1' or '0' reads as off.
$clickIdentity = p202ClickIdentity(
	$_GET,
	\Prosper202\Identity\RequestSignals::campaignAllows(
		array_key_exists('identity_signals', $tracker_row) ? $tracker_row['identity_signals'] : null
	)
);

// Helper: compute remaining click data and record
$computeAndRecordClick = function () use (&$mysql, $custom_var_ids, $trackingRepo, $locationRepo, $tracker_row, $referer_query, $redirect_site_url, $click_id, $clickRepo, $cloaking_on, $cloaking_site_url, $ip_address, $clickIdentity, $earlyGeoData): void {
	// GEO lookup (deferred here so MaxMind reads stay off the redirect hot path)
    $GeoData = $earlyGeoData ?? getGeoData($ip_address);
	$country_id = $locationRepo->findOrCreateCountry($GeoData['country'] ?? '', $GeoData['country_code'] ?? '');
	$mysql['country_id'] = (string) $country_id;
	$region_id = $locationRepo->findOrCreateRegion($GeoData['region'] ?? '', $country_id);
	$mysql['region_id'] = (string) $region_id;
	$city_id = $locationRepo->findOrCreateCity($GeoData['city'] ?? '', $country_id);
	$mysql['city_id'] = (string) $city_id;

	// ISP lookup (MaxMind; only when the tracker enables it)
	$mysql['isp_id'] = '0';
	if ($tracker_row['maxmind_isp'] == '1') {
		$IspData = getIspData($ip_address);
		if (is_string($IspData)) {
			$IspDataParts = explode(',', $IspData);
			$IspData = $IspDataParts[0];
		}
		$mysql['isp_id'] = (string) $locationRepo->findOrCreateIsp($IspData);
	}

	// Compute variable_set_id
	$total_vars = count($custom_var_ids);
	if ($total_vars > 0) {
		$variables = implode(",", $custom_var_ids);
		$variable_set_id = $trackingRepo->findOrCreateVariableSet($variables);
		$mysql['variable_set_id'] = (string) $variable_set_id;
	} else {
		$mysql['variable_set_id'] = '0';
	}

	// Compute site URLs
	if ($tracker_row['user_pref_referer_data'] == 't202ref') {
		if (is_string($_GET['t202ref'] ?? null) && $_GET['t202ref'] !== '') {
			$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($_GET['t202ref']);
		} else {
			if (is_string($referer_query['url'] ?? null) && $referer_query['url'] !== '') {
				$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($referer_query['url']);
			} else {
				$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($_SERVER['HTTP_REFERER'] ?? '');
			}
		}
	} else {
		if (is_string($referer_query['url'] ?? null) && $referer_query['url'] !== '') {
			$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($referer_query['url']);
		} else {
			$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($_SERVER['HTTP_REFERER'] ?? '');
		}
	}
	$mysql['click_referer_site_url_id'] = (string) $click_referer_site_url_id;

	$outbound_site_url = TrackingBaseUrl::requestUrl($_SERVER);
	$click_outbound_site_url_id = $locationRepo->findOrCreateSiteUrl($outbound_site_url);
	$mysql['click_outbound_site_url_id'] = (string) $click_outbound_site_url_id;

	if ($cloaking_on && $cloaking_site_url !== '') {
		$click_cloaking_site_url_id = $locationRepo->findOrCreateClickSiteUrl($cloaking_site_url);
		$mysql['click_cloaking_site_url_id'] = (string) $click_cloaking_site_url_id;
	} else {
		$mysql['click_cloaking_site_url_id'] = '0';
	}
	$click_redirect_site_url_id = $locationRepo->findOrCreateClickSiteUrl($redirect_site_url);
	$mysql['click_redirect_site_url_id'] = (string) $click_redirect_site_url_id;

	// Every row in one transaction (MysqlClickRepository)
	$clickRepo->recordClick(\Prosper202\Click\ClickRecordBuilder::fromLegacyArray($mysql, $click_id, $clickIdentity));
};

$urlvars = getPrePopVars($_GET);
setClickIdCookie($mysql['click_id'], $mysql['aff_campaign_id']);
$clickIdentity->sendCookie($_SERVER);
if ($cloaking_on === true) {
	// Cloaked: cl.php needs click rows to exist, so record BEFORE redirect
	$computeAndRecordClick();
	$redirectLocation = setPrePopVars($urlvars, $cloaking_site_url, true);
} else {
	$redirectLocation = setPrePopVars($urlvars, $redirect_site_url, false);
}

// Never send an empty Location header — Safari retries the same URL in a loop,
// generating dozens of duplicate clicks per single page load.
if ($redirectLocation === null || $redirectLocation === '') {
	renderErrorPage(
		502,
		'Redirect Error',
		'This tracking link has no destination URL configured. Please check your campaign settings.',
		'#dc2626',
		'<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>'
	);
}

p202NoStore();
header('Location: ' . $redirectLocation);
if (function_exists('fastcgi_finish_request')) {
	fastcgi_finish_request();
}

// --- Everything below runs after user has been redirected ---

// Non-cloaked: record click after redirect for lower latency
if ($cloaking_on !== true) {
	$computeAndRecordClick();
}


//update the click summary table

$now = time();

$today_day = date('j', time());
$today_month = date('n', time());
$today_year = date('Y', time());

//the click_time is recorded in the middle of the day
$click_time = mktime(12, 0, 0, (int)$today_month, (int)$today_day, (int)$today_year);
$mysql['click_time'] = $db->real_escape_string((string)$click_time);


if ($mysql['click_cpa'] != NULL) {
	$insert_sql = "INSERT INTO 202_cpa_trackers
                                   SET         click_id='" . $mysql['click_id'] . "',
                                                           tracker_id_public='" . $mysql['tracker_id_public'] . "'";
	// Post-redirect: the visitor is already gone, so log a failure instead of
	// dying (which would abandon the remaining bookkeeping below).
	if ($db->query($insert_sql) === false) {
		error_log('dl.php: failed to insert cpa_tracker for click ' . $mysql['click_id'] . ': ' . $db->error);
	}
}

//set dirty hour
$de = new DataEngine();
$data = ($de->setDirtyHour($mysql['click_id']));
