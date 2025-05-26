<?php

declare(strict_types=1);
#only allow numeric t202ids
$t202id = $_GET['t202id'];
if (!is_numeric($t202id)) die();

# check to see if mysql connection works, if not fail over to cached stored redirect urls
include_once(substr(dirname(__FILE__), 0, -21) . '/202-config/connect2.php');
include_once(substr(dirname(__FILE__), 0, -21) . '/202-config/class-dataengine-slim.php');

// Enable processing to continue even if the client disconnects.
// This is necessary to ensure that critical operations, such as database updates
// or logging, are completed even if the user closes their browser or loses connection.
// Note: The behavior of ignore_user_abort(true) can vary depending on the PHP SAPI environment.
// For example, in CLI mode, this function has no effect, while in Apache or CGI contexts,
// it ensures that the script continues execution regardless of client disconnection.
ignore_user_abort(true);

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

	die("<h2>Error establishing a database connection - please contact the webhost</h2>");
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


if (!$tracker_row) {
	die();
}

//get mysql variables 
$mysql['aff_campaign_id'] = $db->real_escape_string((string)($tracker_row['aff_campaign_id'] ?? '0'));
$mysql['ppc_account_id'] = $db->real_escape_string((string)($tracker_row['ppc_account_id'] ?? '0'));
$mysql['user_pref_dynamic_bid'] = $db->real_escape_string((string)($tracker_row['user_pref_dynamic_bid'] ?? '0'));
// set cpc use dynamic variable if set or the default if not
if (isset($_GET['t202b']) && $mysql['user_pref_dynamic_bid'] == '1') {
	$_GET['t202b'] = ltrim($_GET['t202b'], '$');
	if (is_numeric($_GET['t202b'])) {
		$bid = number_format($_GET['t202b'], 5, '.', '');
		$mysql['click_cpc'] = $db->real_escape_string($bid);
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
$referer_url_parsed = array();
$referer_url_query = '';
$referer_query = array();

if (!empty($_SERVER['HTTP_REFERER'])) {
	$referer_url_parsed = @parse_url($_SERVER['HTTP_REFERER']);
	$referer_url_query = isset($referer_url_parsed['query']) ? $referer_url_parsed['query'] : '';
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

if (substr($keyword, 0, 8) == 't202var_') {
	$t202var = substr($keyword, strpos($keyword, "_") + 1);

	if (isset($_GET[$t202var])) {
		$keyword = $_GET[$t202var];
	}
}

$keyword = str_replace('%20', ' ', $keyword);
$keyword_id = INDEXES::get_keyword_id($db, $keyword);
$mysql['keyword_id'] = $db->real_escape_string((string)$keyword_id);

$_lGET = array_change_key_case($_GET, CASE_LOWER); //make lowercase copy of get 
//Get C1-C4 IDs
for ($i = 1; $i <= 4; $i++) {
	$custom = "c" . $i; //create dynamic variable
	$custom_val = isset($_lGET[$custom]) ? $_lGET[$custom] : '';
	$custom_val = $db->real_escape_string($custom_val); // get the value
	$custom_val = str_replace('%20', ' ', $custom_val);
	$custom_id = INDEXES::get_custom_var_id($db, $custom, $custom_val); //get the id
	$mysql[$custom . '_id'] = $db->real_escape_string((string)$custom_id); //save it
}

$mysql['gclid'] = $db->real_escape_string((string)($_GET['gclid'] ?? ''));

$custom_var_ids = array();

$ppc_variable_ids = !empty($tracker_row['ppc_variable_ids']) ? explode(',', $tracker_row['ppc_variable_ids']) : array();
$parameters = !empty($tracker_row['parameters']) ? explode(',', $tracker_row['parameters']) : array();

foreach ($parameters as $key => $value) {
	$variable = $db->real_escape_string((string)($_GET[$value] ?? ''));

	if (isset($variable) && $variable != '') {
		$variable = str_replace('%20', ' ', $variable);
		$variable_id = INDEXES::get_variable_id($db, $variable, $ppc_variable_ids[$key] ?? '');
		$custom_var_ids[] = $variable_id;
	}
}

//utm_source
$utm_source = $db->real_escape_string((string)($_GET['utm_source'] ?? ''));
if (isset($utm_source) && $utm_source != '') {
	$utm_source = str_replace('%20', ' ', $utm_source);
	$utm_source_id = INDEXES::get_utm_id($db, $utm_source, 'utm_source');
} else {
	$utm_source_id = 0;
}
$mysql['utm_source_id'] = $db->real_escape_string((string)$utm_source_id);

//utm_medium
$utm_medium = $db->real_escape_string((string)($_GET['utm_medium'] ?? ''));
if (isset($utm_medium) && $utm_medium != '') {
	$utm_medium = str_replace('%20', ' ', $utm_medium);
	$utm_medium_id = INDEXES::get_utm_id($db, $utm_medium, 'utm_medium');
} else {
	$utm_medium_id = 0;
}
$mysql['utm_medium_id'] = $db->real_escape_string((string)$utm_medium_id);

//utm_campaign
$utm_campaign = $db->real_escape_string((string)($_GET['utm_campaign'] ?? ''));
if (isset($utm_campaign) && $utm_campaign != '') {
	$utm_campaign = str_replace('%20', ' ', $utm_campaign);
	$utm_campaign_id = INDEXES::get_utm_id($db, $utm_campaign, 'utm_campaign');
} else {
	$utm_campaign_id = 0;
}
$mysql['utm_campaign_id'] = $db->real_escape_string((string)$utm_campaign_id);

//utm_term
$utm_term = $db->real_escape_string((string)($_GET['utm_term'] ?? ''));
if (isset($utm_term) && $utm_term != '') {
	$utm_term = str_replace('%20', ' ', $utm_term);
	$utm_term_id = INDEXES::get_utm_id($db, $utm_term, 'utm_term');
} else {
	$utm_term_id = 0;
}
$mysql['utm_term_id'] = $db->real_escape_string((string)$utm_term_id);

//utm_content
$utm_content = $db->real_escape_string((string)($_GET['utm_content'] ?? ''));
if (isset($utm_content) && $utm_content != '') {
	$utm_content = str_replace('%20', ' ', $utm_content);
	$utm_content_id = INDEXES::get_utm_id($db, $utm_content, 'utm_content');
} else {
	$utm_content_id = 0;
}
$mysql['utm_content_id'] = $db->real_escape_string((string)$utm_content_id);


$device_id = PLATFORMS::get_device_info($db, $detect, $_GET['ua']);
$mysql['platform_id'] = $db->real_escape_string($device_id['platform']);
$mysql['browser_id'] = $db->real_escape_string($device_id['browser']);
$mysql['device_id'] = $db->real_escape_string($device_id['device']);

if ($device_id['type'] == '4') {
	$mysql['click_bot'] = '1';
}

$mysql['click_in'] = 1;
$mysql['click_out'] = 1;


$ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '0.0.0.0';
$ip_id = INDEXES::get_ip_id($db, $ip);
$mysql['ip_id'] = $db->real_escape_string($ip_id);

//before we finish filter this click
$ip_address = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '0.0.0.0';
$user_id = $tracker_row['user_id'];

//GEO Lookup
$GeoData = getGeoData($ip_address);

$country_id = INDEXES::get_country_id($db, $GeoData['country'], $GeoData['country_code']);
$mysql['country_id'] = $db->real_escape_string($country_id);

$region_id = INDEXES::get_region_id($db, $GeoData['region'], $mysql['country_id']);
$mysql['region_id'] = $db->real_escape_string($region_id);

$city_id = INDEXES::get_city_id($db, $GeoData['city'], $mysql['country_id']);
$mysql['city_id'] = $db->real_escape_string($city_id);


if ($tracker_row['maxmind_isp'] == '1') {
	$IspData = getIspData($ip_address);
	$isp_id = INDEXES::get_isp_id($db, $IspData);
	$mysql['isp_id'] = $db->real_escape_string($isp_id);
}

if ($device_id['type'] == '4') {
	$mysql['click_filtered'] = '1';
} else {
	$click_filtered = FILTER::startFilter($db, $click_id, $ip_id, $ip_address, $user_id);
	$mysql['click_filtered'] = $db->real_escape_string($click_filtered);
}


//ok we have the main data, now insert this row
$click_sql = "INSERT INTO  202_clicks_counter SET click_id=DEFAULT";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);



//now gather the info for the advance click insert
$click_id = $db->insert_id;
$mysql['click_id'] = $db->real_escape_string($click_id);

//because this is a simple landing page, set click_alp (which stands for click advanced landing page, equal to 0)
$mysql['click_alp'] = 0;


//ok we have the main data, now insert this row
$click_sql = "INSERT INTO   202_clicks
			  SET           	click_id='" . $mysql['click_id'] . "',
							user_id = '" . $mysql['user_id'] . "',   
							aff_campaign_id = '" . $mysql['aff_campaign_id'] . "',   
							ppc_account_id = '" . $mysql['ppc_account_id'] . "',   
							click_cpc = '" . $mysql['click_cpc'] . "',   
							click_payout = '" . $mysql['click_payout'] . "',   
							click_alp = '" . $mysql['click_alp'] . "',
							click_filtered = '" . $mysql['click_filtered'] . "',
							click_bot = '" . $mysql['click_bot'] . "',
							click_time = '" . $mysql['click_time'] . "'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);

$total_vars = count($custom_var_ids);

if ($total_vars > 0) {

	$variables = implode(",", $custom_var_ids);
	$variable_set_id = INDEXES::get_variable_set_id($db, $variables);

	$mysql['variable_set_id'] = $db->real_escape_string($variable_set_id);

	$var_sql = "INSERT INTO 202_clicks_variable (click_id, variable_set_id) VALUES ('" . $mysql['click_id'] . "', '" . $mysql['variable_set_id'] . "')";
	$var_result = $db->query($var_sql) or record_mysql_error($db, $var_sql);
} else {
	$var_sql = "INSERT INTO 202_clicks_variable (click_id, variable_set_id) VALUES ('" . $mysql['click_id'] . "',0)";
	$var_result = $db->query($var_sql) or record_mysql_error($db, $var_sql);
}

// insert gclid and utm vars
$click_sql = "INSERT INTO   202_google
			  SET           	click_id='" . $mysql['click_id'] . "',
							gclid = '" . $mysql['gclid'] . "',
                            utm_source_id = '" . $mysql['utm_source_id'] . "',
                            utm_medium_id = '" . $mysql['utm_medium_id'] . "',
utm_campaign_id = '" . $mysql['utm_campaign_id'] . "',
utm_term_id = '" . $mysql['utm_term_id'] . "',
utm_content_id = '" . $mysql['utm_content_id'] . "'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);

//ok we have the main data, now insert this row
$click_sql = "INSERT INTO   202_clicks_spy
			  SET           	click_id='" . $mysql['click_id'] . "',
							user_id = '" . $mysql['user_id'] . "',   
							aff_campaign_id = '" . $mysql['aff_campaign_id'] . "',   
							ppc_account_id = '" . $mysql['ppc_account_id'] . "',   
							click_cpc = '" . $mysql['click_cpc'] . "',   
							click_payout = '" . $mysql['click_payout'] . "',   
							click_filtered = '" . $mysql['click_filtered'] . "',
							click_bot = '" . $mysql['click_bot'] . "',
							click_alp = '" . $mysql['click_alp'] . "',
							click_time = '" . $mysql['click_time'] . "'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);



//now we have the click's advance data, now insert this row
$click_sql = "INSERT INTO   202_clicks_advance
			  SET           click_id='" . $mysql['click_id'] . "',
							text_ad_id='" . $mysql['text_ad_id'] . "',
							keyword_id='" . $mysql['keyword_id'] . "',
							ip_id='" . $mysql['ip_id'] . "',
							country_id='" . $mysql['country_id'] . "',
							region_id='" . $mysql['region_id'] . "',
							isp_id='" . $mysql['isp_id'] . "',
							city_id='" . $mysql['city_id'] . "',
							platform_id='" . $mysql['platform_id'] . "',
							browser_id='" . $mysql['browser_id'] . "',
							device_id='" . $mysql['device_id'] . "'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);

//insert the tracking data
$click_sql = "
	INSERT INTO
		202_clicks_tracking
	SET
		click_id='" . $mysql['click_id'] . "',
		c1_id = '" . $mysql['c1_id'] . "',
		c2_id = '" . $mysql['c2_id'] . "',
		c3_id = '" . $mysql['c3_id'] . "',
		c4_id = '" . $mysql['c4_id'] . "'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);

//now gather variables for the clicks record db
//lets determine if cloaking is on
if (($tracker_row['click_cloaking'] == 1) or //if tracker has overrided cloaking on                                                             
	(($tracker_row['click_cloaking'] == -1) and ($tracker_row['aff_campaign_cloaking'] == 1)) or
	((!isset($tracker_row['click_cloaking'])) and ($tracker_row['aff_campaign_cloaking'] == 1)) //if no tracker but but by default campaign has cloaking on
) {
	$cloaking_on = true;
	$mysql['click_cloaking'] = 1;
	//if cloaking is on, add in a click_id_public, because we will be forwarding them to a cloaked /cl/xxxx link
	$click_id_public = rand(1, 9) . $click_id . rand(1, 9);
	$mysql['click_id_public'] = $db->real_escape_string($click_id_public);
} else {
	$mysql['click_cloaking'] = 0;
}

//ok we have our click recorded table, now lets insert theses
$click_sql = "INSERT INTO   202_clicks_record
			  SET           click_id='" . $mysql['click_id'] . "',
							click_id_public='" . $mysql['click_id_public'] . "',
							click_cloaking='" . $mysql['click_cloaking'] . "',
							click_in='" . $mysql['click_in'] . "',
							click_out='" . $mysql['click_out'] . "'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);

// if user wants to use t202ref from url variable use that first if it's not set try and get it from the ref url
if ($tracker_row['user_pref_referer_data'] == 't202ref') {
	if (isset($_GET['t202ref']) && $_GET['t202ref'] != '') { //check for t202ref value
		$mysql['t202ref'] = $db->real_escape_string((string)$_GET['t202ref']);
		$click_referer_site_url_id = INDEXES::get_site_url_id($db, $mysql['t202ref']);
	} else { //if not found revert to what we usually do
		if ($referer_query['url']) {
			$click_referer_site_url_id = INDEXES::get_site_url_id($db, $referer_query['url']);
		} else {
			$click_referer_site_url_id = INDEXES::get_site_url_id($db, $_SERVER['HTTP_REFERER']);
		}
	}
} else { //user wants the real referer first

	// now lets get variables for clicks site
	// so this is going to check the REFERER URL, for a ?url=, which is the ACUTAL URL, instead of the google content, pagead2.google....
	if ($referer_query['url']) {
		$click_referer_site_url_id = INDEXES::get_site_url_id($db, $referer_query['url']);
	} else {
		$click_referer_site_url_id = INDEXES::get_site_url_id($db, $_SERVER['HTTP_REFERER']);
	}
}

$mysql['click_referer_site_url_id'] = $db->real_escape_string($click_referer_site_url_id);

$outbound_site_url = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
$click_outbound_site_url_id = INDEXES::get_site_url_id($db, $outbound_site_url);
$mysql['click_outbound_site_url_id'] = $db->real_escape_string($click_outbound_site_url_id);

if ($cloaking_on == true) {
	$cloaking_site_url = 'http://' . $_SERVER['SERVER_NAME'] . '/tracking202/redirect/cl.php?pci=' . $click_id_public;
}


//rotate the urls
$redirect_site_url = rotateTrackerUrl($db, $tracker_row);

$redirect_site_url = replaceTrackerPlaceholders($db, $redirect_site_url, $click_id);


$urlvars = getPrePopVars($_GET);
setClickIdCookie($mysql['click_id'], $mysql['aff_campaign_id']);
if ($cloaking_on === true) {
	$redirectLocation = setPrePopVars($urlvars, $cloaking_site_url, true);
} else {
	$redirectLocation = setPrePopVars($urlvars, $redirect_site_url, false);
}
header('Location: ' . $redirectLocation);
if (function_exists('fastcgi_finish_request')) {
	fastcgi_finish_request();
}

$click_redirect_site_url_id = INDEXES::get_site_url_id($db, $redirect_site_url);
$mysql['click_redirect_site_url_id'] = $db->real_escape_string($click_redirect_site_url_id);

//insert this
$click_sql = "INSERT INTO   202_clicks_site
                          SET           click_id='" . $mysql['click_id'] . "',
                                                        click_referer_site_url_id='" . $mysql['click_referer_site_url_id'] . "',
                                                        click_outbound_site_url_id='" . $mysql['click_outbound_site_url_id'] . "',
                                                        click_redirect_site_url_id='" . $mysql['click_redirect_site_url_id'] . "'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);


//update the click summary table

$now = time();

$today_day = date('j', time());
$today_month = date('n', time());
$today_year = date('Y', time());

//the click_time is recorded in the middle of the day
$click_time = mktime(12, 0, 0, $today_month, $today_day, $today_year);
$mysql['click_time'] = $db->real_escape_string($click_time);


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
