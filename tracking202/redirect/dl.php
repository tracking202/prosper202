<?php

declare(strict_types=1);
#only allow numeric t202ids, reject 0 as invalid
$t202id = $_GET['t202id'] ?? '';
if (!is_numeric($t202id) || (int)$t202id <= 0) die();

# check to see if mysql connection works, if not fail over to cached stored redirect urls
include_once(substr(__DIR__, 0, -21) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0, -21) . '/202-config/class-dataengine-slim.php');

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

			//c1 string replace for cached redirect
			if (isset($_GET['c1']) && $_GET['c1'] != '') {
				$new_url = str_replace("[[c1]]", $db->real_escape_string((string)$_GET['c1']), $new_url);
			} else {
				$new_url = str_replace("[[c1]]", "p202c1", $new_url);
			}

			//c2 string replace for cached redirect
			if (isset($_GET['c2']) && $_GET['c2'] != '') {
				$new_url = str_replace("[[c2]]", $db->real_escape_string((string)$_GET['c2']), $new_url);
			} else {
				$new_url = str_replace("[[c2]]", "p202c2", $new_url);
			}

			//c3 string replace for cached redirect
			if (isset($_GET['c3']) && $_GET['c3'] != '') {
				$new_url = str_replace("[[c3]]", $db->real_escape_string((string)$_GET['c3']), $new_url);
			} else {
				$new_url = str_replace("[[c3]]", "p202c3", $new_url);
			}

			//c4 string replace for cached redirect
			if (isset($_GET['c4']) && $_GET['c4'] != '') {
				$new_url = str_replace("[[c4]]", $db->real_escape_string((string)$_GET['c4']), $new_url);
			} else {
				$new_url = str_replace("[[c4]]", "p202c4", $new_url);
			}

			//gclid string replace for cached redirect
			if (isset($_GET['gclid']) && $_GET['gclid'] != '') {
				$new_url = str_replace("[[gclid]]", $db->real_escape_string((string)$_GET['gclid']), $new_url);
			} else {
				$new_url = str_replace("[[gclid]]", "p202gclid", $new_url);
			}

			//utm_source string replace for cached redirect
			if (isset($_GET['utm_source']) && $_GET['utm_source'] != '') {
				$new_url = str_replace("[[utm_source]]", $db->real_escape_string((string)$_GET['utm_source']), $new_url);
			} else {
				$new_url = str_replace("[[utm_source]]", "p202utm_source", $new_url);
			}

			//utm_medium string replace for cached redirect
			if (isset($_GET['utm_medium']) && $_GET['utm_medium'] != '') {
				$new_url = str_replace("[[utm_medium]]", $db->real_escape_string((string)$_GET['utm_medium']), $new_url);
			} else {
				$new_url = str_replace("[[utm_medium]]", "p202utm_medium", $new_url);
			}

			//utm_campaign string replace for cached redirect
			if (isset($_GET['utm_campaign']) && $_GET['utm_campaign'] != '') {
				$new_url = str_replace("[[utm_campaign]]", $db->real_escape_string((string)$_GET['utm_campaign']), $new_url);
			} else {
				$new_url = str_replace("[[utm_campaign]]", "p202utm_campaign", $new_url);
			}

			//utm_term string replace for cached redirect
			if (isset($_GET['utm_term']) && $_GET['utm_term'] != '') {
				$new_url = str_replace("[[utm_term]]", $db->real_escape_string((string)$_GET['utm_term']), $new_url);
			} else {
				$new_url = str_replace("[[utm_term]]", "p202utm_term", $new_url);
			}

			//utm_content string replace for cached redirect
			if (isset($_GET['utm_content']) && $_GET['utm_content'] != '') {
				$new_url = str_replace("[[utm_content]]", $db->real_escape_string((string)$_GET['utm_content']), $new_url);
			} else {
				$new_url = str_replace("[[utm_content]]", "p202utm_content", $new_url);
			}

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
						2cv.ppc_variable_ids,
						2cv.parameters,
                        user_timezone, 
		                user_keyword_searched_or_bidded,
                        user_pref_referer_data,
                        user_pref_dynamic_bid,
		                maxmind_isp 
                        FROM 202_trackers 
                        LEFT JOIN 202_users_pref USING (user_id) 
            LEFT JOIN 202_users USING (user_id) 
    			LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
				LEFT JOIN 202_ppc_accounts USING (ppc_account_id)
				LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(ppc_variable_id) AS ppc_variable_ids, GROUP_CONCAT(parameter) AS parameters FROM 202_ppc_network_variables GROUP BY ppc_network_id) AS 2cv USING (ppc_network_id)					                 
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

if ($memcacheWorking) {

	$url = $tracker_row['aff_campaign_url'];
	$tid = $t202id;

	$getKey = $memcache->get(md5('url_' . $tid . systemHash()));
	if ($getKey === false) {
		$setUrl = setCache(md5('url_' . $tid . systemHash()), $url, 0);
	}
}


//set the timezone to the users timezone
$mysql['user_id'] = $db->real_escape_string((string)($tracker_row['user_id'] ?? '0'));

//now this sets timezone
date_default_timezone_set($tracker_row['user_timezone']);

//get mysql variables 
$mysql['aff_campaign_id'] = $db->real_escape_string((string)($tracker_row['aff_campaign_id'] ?? '0'));
$mysql['ppc_account_id'] = $db->real_escape_string((string)($tracker_row['ppc_account_id'] ?? '0'));
$mysql['user_pref_dynamic_bid'] = $db->real_escape_string((string)($tracker_row['user_pref_dynamic_bid'] ?? '0'));
// set cpc use dynamic variable if set or the default if not
if (isset($_GET['t202b']) && $mysql['user_pref_dynamic_bid'] == '1') {
	$_GET['t202b'] = ltrim((string) $_GET['t202b'], '$');
	if (is_numeric($_GET['t202b'])) {
		$bid = number_format($_GET['t202b'], 5, '.', '');
		$mysql['click_cpc'] = $db->real_escape_string((string)$bid);
	} else {
		$mysql['click_cpc'] = $db->real_escape_string((string)($tracker_row['click_cpc'] ?? ''));
	}
} else
	$mysql['click_cpc'] = $db->real_escape_string((string)($tracker_row['click_cpc'] ?? ''));

$mysql['click_cpa'] = $db->real_escape_string((string)($tracker_row['click_cpa'] ?? ''));
$mysql['click_payout'] = $db->real_escape_string((string)($tracker_row['aff_campaign_payout'] ?? ''));
$mysql['click_time'] = time();
$mysql['text_ad_id'] = $db->real_escape_string((string)($tracker_row['text_ad_id'] ?? '0'));

/* ok, if $_GET['OVRAW'] that is a yahoo keyword, if on the REFER, there is a $_GET['q], that is a GOOGLE keyword... */
//so this is going to check the REFERER URL, for a ?q=, which is the ACUTAL KEYWORD searched.
$referer_url_parsed = [];
$referer_url_query = '';
$referer_query = [];

if (!empty($_SERVER['HTTP_REFERER'])) {
	$referer_url_parsed = @parse_url((string) $_SERVER['HTTP_REFERER']);
	$referer_url_query = $referer_url_parsed['query'] ?? '';
	@parse_str($referer_url_query, $referer_query);
}

switch ($tracker_row['user_keyword_searched_or_bidded']) {

	case "bidded":
		#try to get the bidded keyword first
		if (isset($_GET['OVKEY'])) { //if this is a Y! keyword
			$keyword = $db->real_escape_string((string)$_GET['OVKEY']);
		} elseif (isset($_GET['t202kw'])) {
			$keyword = $db->real_escape_string((string)$_GET['t202kw']);
		} elseif (isset($_GET['target_passthrough'])) { //if this is a mediatraffic! keyword
			$keyword = $db->real_escape_string((string)$_GET['target_passthrough']);
		} else { //if this is a zango, or more keyword
			$keyword = $db->real_escape_string((string)($_GET['keyword'] ?? ''));
		}
		break;
	case "searched":
		#try to get the searched keyword
		if (isset($referer_query['q'])) {
			$keyword = $db->real_escape_string($referer_query['q']);
		} elseif (isset($_GET['OVRAW'])) { //if this is a Y! keyword
			$keyword = $db->real_escape_string((string)$_GET['OVRAW']);
		} elseif (isset($_GET['target_passthrough'])) { //if this is a mediatraffic! keyword
			$keyword = $db->real_escape_string((string)$_GET['target_passthrough']);
		} elseif (isset($_GET['keyword'])) { //if this is a zango, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['keyword']);
		} elseif (isset($_GET['search_word'])) { //if this is a eniro, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['search_word']);
		} elseif (isset($_GET['query'])) { //if this is a naver, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['query']);
		} elseif (isset($_GET['encquery'])) { //if this is a aol, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['encquery']);
		} elseif (isset($_GET['terms'])) { //if this is a about.com, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['terms']);
		} elseif (isset($_GET['rdata'])) { //if this is a viola, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['rdata']);
		} elseif (isset($_GET['qs'])) { //if this is a virgilio, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['qs']);
		} elseif (isset($_GET['wd'])) { //if this is a baidu, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['wd']);
		} elseif (isset($_GET['text'])) { //if this is a yandex, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['text']);
		} elseif (isset($_GET['szukaj'])) { //if this is a wp.pl, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['szukaj']);
		} elseif (isset($_GET['qt'])) { //if this is a O*net, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['qt']);
		} elseif (isset($_GET['k'])) { //if this is a yam, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['k']);
		} elseif (isset($_GET['words'])) { //if this is a Rambler, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['words']);
		} else {
			$keyword = $db->real_escape_string((string)($_GET['t202kw'] ?? ''));
		}
		break;
}

if (str_starts_with((string) $keyword, 't202var_')) {
	$t202var = substr((string) $keyword, strpos((string) $keyword, "_") + 1);

	if (isset($_GET[$t202var])) {
		$keyword = $_GET[$t202var];
	}
}

$keyword = str_replace('%20', ' ', $keyword);
$keyword_id = $trackingRepo->findOrCreateKeyword($keyword);
$mysql['keyword_id'] = $db->real_escape_string((string)$keyword_id);

$_lGET = array_change_key_case($_GET, CASE_LOWER); //make lowercase copy of get 
//Get C1-C4 IDs
for ($i = 1; $i <= 4; $i++) {
	$custom = "c" . $i; //create dynamic variable
	$custom_val = $_lGET[$custom] ?? '';
	$custom_val = $db->real_escape_string($custom_val); // get the value
	$custom_val = str_replace('%20', ' ', $custom_val);
	$custom_id = $trackingRepo->findOrCreateCustomVar($custom, $custom_val); //get the id
	$mysql[$custom . '_id'] = $db->real_escape_string((string)$custom_id); //save it
}

$mysql['gclid'] = $db->real_escape_string((string)($_GET['gclid'] ?? ''));

$custom_var_ids = [];

$ppc_variable_ids = !empty($tracker_row['ppc_variable_ids']) ? explode(',', (string) $tracker_row['ppc_variable_ids']) : [];
$parameters = !empty($tracker_row['parameters']) ? explode(',', (string) $tracker_row['parameters']) : [];

foreach ($parameters as $key => $value) {
	$variable = $db->real_escape_string((string)($_GET[$value] ?? ''));

	if (isset($variable) && $variable != '') {
		$variable = str_replace('%20', ' ', $variable);
		$variable_id = $trackingRepo->findOrCreateVariable($variable, (int) ($ppc_variable_ids[$key] ?? 0));
		$custom_var_ids[] = $variable_id;
	}
}

//utm_source
$utm_source = $db->real_escape_string((string)($_GET['utm_source'] ?? ''));
if (isset($utm_source) && $utm_source != '') {
	$utm_source = str_replace('%20', ' ', $utm_source);
	$utm_source_id = $trackingRepo->findOrCreateUtm($utm_source, 'utm_source');
} else {
	$utm_source_id = 0;
}
$mysql['utm_source_id'] = $db->real_escape_string((string)$utm_source_id);

//utm_medium
$utm_medium = $db->real_escape_string((string)($_GET['utm_medium'] ?? ''));
if (isset($utm_medium) && $utm_medium != '') {
	$utm_medium = str_replace('%20', ' ', $utm_medium);
	$utm_medium_id = $trackingRepo->findOrCreateUtm($utm_medium, 'utm_medium');
} else {
	$utm_medium_id = 0;
}
$mysql['utm_medium_id'] = $db->real_escape_string((string)$utm_medium_id);

//utm_campaign
$utm_campaign = $db->real_escape_string((string)($_GET['utm_campaign'] ?? ''));
if (isset($utm_campaign) && $utm_campaign != '') {
	$utm_campaign = str_replace('%20', ' ', $utm_campaign);
	$utm_campaign_id = $trackingRepo->findOrCreateUtm($utm_campaign, 'utm_campaign');
} else {
	$utm_campaign_id = 0;
}
$mysql['utm_campaign_id'] = $db->real_escape_string((string)$utm_campaign_id);

//utm_term
$utm_term = $db->real_escape_string((string)($_GET['utm_term'] ?? ''));
if (isset($utm_term) && $utm_term != '') {
	$utm_term = str_replace('%20', ' ', $utm_term);
	$utm_term_id = $trackingRepo->findOrCreateUtm($utm_term, 'utm_term');
} else {
	$utm_term_id = 0;
}
$mysql['utm_term_id'] = $db->real_escape_string((string)$utm_term_id);

//utm_content
$utm_content = $db->real_escape_string((string)($_GET['utm_content'] ?? ''));
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

$device_id = PLATFORMS::get_device_info($db, $detect, $_GET['ua'] ?? '');
$mysql['platform_id'] = $db->real_escape_string((string)($device_id['platform'] ?? '0'));
$mysql['browser_id'] = $db->real_escape_string((string)($device_id['browser'] ?? '0'));
$mysql['device_id'] = $db->real_escape_string((string)($device_id['device'] ?? '0'));

// Initialize click_bot with default value
$mysql['click_bot'] = '0';
if (isset($device_id['type']) && $device_id['type'] == '4') {
	$mysql['click_bot'] = '1';
}

$mysql['click_in'] = 1;
$mysql['click_out'] = 1;


$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip_id = $locationRepo->findOrCreateIp($ip);
$mysql['ip_id'] = $db->real_escape_string((string)$ip_id);

//before we finish filter this click
$ip_address = $ip;
$user_id = $tracker_row['user_id'];

//GEO Lookup
$GeoData = getGeoData($ip_address);
$countryName = $GeoData['country'] ?? '';
$countryCode = $GeoData['country_code'] ?? '';
$country_id = $locationRepo->findOrCreateCountry($countryName, $countryCode);
$mysql['country_id'] = $db->real_escape_string((string)$country_id);

$regionName = $GeoData['region'] ?? '';
$region_id = $locationRepo->findOrCreateRegion($regionName, $country_id);
$mysql['region_id'] = $db->real_escape_string((string)$region_id);

$cityName = $GeoData['city'] ?? '';
$city_id = $locationRepo->findOrCreateCity($cityName, $country_id);
$mysql['city_id'] = $db->real_escape_string((string)$city_id);


// Initialize isp_id with default value
$mysql['isp_id'] = '0';
if ($tracker_row['maxmind_isp'] == '1') {
	$IspData = getIspData($ip_address);
	if (is_string($IspData)) {
		$IspDataParts = explode(',', $IspData);
		$IspData = $IspDataParts[0];
	}
	$isp_id = $locationRepo->findOrCreateIsp($IspData);
	$mysql['isp_id'] = $db->real_escape_string((string)$isp_id);
}

if ($device_id['type'] == '4') {
	$mysql['click_filtered'] = '1';
} else {
	// Initialize click_id as 0 for the filter (will be updated after insert)
	$click_id_temp = 0;
	$click_filtered = FILTER::startFilter($db, $click_id_temp, $ip_id, $ip_address, $user_id);
	$mysql['click_filtered'] = $db->real_escape_string((string)$click_filtered);
}


// Pre-allocate click_id (single DB write before redirect for minimal latency)
$conn = \Prosper202\Repository\LookupRepositoryFactory::connection($db);
$clickRepo = new \Prosper202\Click\MysqlClickRepository($conn);
$click_id = $clickRepo->allocateClickId();
$mysql['click_id'] = (string) $click_id;
$mysql['click_alp'] = 0;

// Generate click_id_public only when cloaking is active (needed for PCI-based lookups via cl.php)
$click_id_public = '';
$mysql['click_id_public'] = '';

// Determine cloaking (needed for redirect decision)
$cloaking_on = false;
if (($tracker_row['click_cloaking'] == 1) or
	(($tracker_row['click_cloaking'] == -1) and ($tracker_row['aff_campaign_cloaking'] == 1)) or
	((!isset($tracker_row['click_cloaking'])) and ($tracker_row['aff_campaign_cloaking'] == 1))
) {
	$cloaking_on = true;
	$mysql['click_cloaking'] = 1;
	$click_id_public = random_int(1, 9) . $click_id . random_int(1, 9);
	$mysql['click_id_public'] = (string) $click_id_public;
} else {
	$mysql['click_cloaking'] = 0;
}

// Compute redirect URL (needed before redirect)
$redirect_site_url = rotateTrackerUrl($db, $tracker_row);
$redirect_site_url = replaceTrackerPlaceholders($db, $redirect_site_url, $click_id);

$cloaking_site_url = '';
if ($cloaking_on === true) {
	$cloaking_site_url = 'http://' . $_SERVER['SERVER_NAME'] . '/tracking202/redirect/cl.php?pci=' . $click_id_public;
}

// Helper: compute remaining click data and record
$computeAndRecordClick = function () use (&$mysql, $custom_var_ids, $trackingRepo, $locationRepo, $tracker_row, $referer_query, $redirect_site_url, $click_id, $clickRepo, $cloaking_on, $cloaking_site_url): void {
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
		if (isset($_GET['t202ref']) && $_GET['t202ref'] != '') {
			$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($_GET['t202ref']);
		} else {
			if (isset($referer_query['url'])) {
				$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($referer_query['url']);
			} else {
				$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($_SERVER['HTTP_REFERER'] ?? '');
			}
		}
	} else {
		if (isset($referer_query['url'])) {
			$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($referer_query['url']);
		} else {
			$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($_SERVER['HTTP_REFERER'] ?? '');
		}
	}
	$mysql['click_referer_site_url_id'] = (string) $click_referer_site_url_id;

	$outbound_site_url = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
	$click_outbound_site_url_id = $locationRepo->findOrCreateSiteUrl($outbound_site_url);
	$mysql['click_outbound_site_url_id'] = (string) $click_outbound_site_url_id;

	if ($cloaking_on && $cloaking_site_url !== '') {
		$click_cloaking_site_url_id = $locationRepo->findOrCreateSiteUrl($cloaking_site_url);
		$mysql['click_cloaking_site_url_id'] = (string) $click_cloaking_site_url_id;
	} else {
		$mysql['click_cloaking_site_url_id'] = '0';
	}
	$click_redirect_site_url_id = $locationRepo->findOrCreateSiteUrl($redirect_site_url);
	$mysql['click_redirect_site_url_id'] = (string) $click_redirect_site_url_id;

	// Record click via repository (all 9 tables in one atomic transaction)
	$clickRecord = \Prosper202\Click\ClickRecordBuilder::fromLegacyArray($mysql);
	$clickRecord->clickId = $click_id;
	$clickRepo->recordClick($clickRecord);
};

$urlvars = getPrePopVars($_GET);
setClickIdCookie($mysql['click_id'], $mysql['aff_campaign_id']);
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
	$insert_result = $db->query($insert_sql);
}

//set dirty hour
$de = new DataEngine();
$data = ($de->setDirtyHour($mysql['click_id']));

if (isset($_COOKIE['p202_ipx'])) {
	$mysql['p202_ipx'] = $db->real_escape_string($_COOKIE['p202_ipx']);
	$db->query("UPDATE 202_clicks_impressions SET click_id = '" . $mysql['click_id'] . "' WHERE impression_id = '" . $mysql['p202_ipx'] . "'");
}
