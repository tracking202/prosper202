<?php
declare(strict_types=1);
use UAParser\Parser;

#only allow numeric id, reject 0 as invalid
$tracker_id = $_GET['t202id'] ?? '';
if (!is_numeric($tracker_id) || (int)$tracker_id <= 0) die();

# check to see if mysql connection works, if not fail over to cached stored redirect urls
include_once(substr(__DIR__, 0,-21) . '/202-config/connect2.php'); 

$usedCachedRedirect = false;
if (!$db) $usedCachedRedirect = true;

#the mysql server is down, use the cached redirect
if ($usedCachedRedirect==true) { 

		//if a cached key is found for this id, redirect to that url
		if ($memcacheWorking) {
			$getUrl = $memcache->get(md5("default_url" . $tracker_id . systemHash()));
			if ($getUrl) {			
				header('location: '. $getUrl); 
				die();
			}
		}

	die("<h2>Error establishing a database connection - please contact the webhost</h2>");
}

include_once(substr(__DIR__, 0,-21) . '/202-config/class-dataengine-slim.php');

//grab tracker data
$mysql['tracker_id_public'] = $db->real_escape_string($tracker_id);
$rotator_sql = "SELECT  tr.user_id,
						tr.ppc_account_id,
						tr.rotator_id,
						tr.click_cpc,
						rt.default_url,
						rt.default_campaign,
						rt.default_lp,
						rt.auto_monetizer,
						ca.aff_campaign_id,
						ca.aff_campaign_rotate,
					    ca.aff_campaign_url,
					    ca.aff_campaign_url_2,
					    ca.aff_campaign_url_3,
					    ca.aff_campaign_url_4,
					    ca.aff_campaign_url_5,
					    ca.aff_campaign_payout,
					    ca.aff_campaign_cloaking,
						ur.user_timezone,
					   	up.user_keyword_searched_or_bidded,
                        up.user_pref_dynamic_bid,
					   	up.maxmind_isp,
					   	lp.landing_page_url
				FROM    202_trackers AS tr
				LEFT JOIN 202_rotators AS rt ON rt.id = tr.rotator_id
				LEFT JOIN 202_aff_campaigns AS ca ON ca.aff_campaign_id = rt.default_campaign
				LEFT JOIN 202_landing_pages AS lp ON lp.landing_page_id = rt.default_lp
				LEFT JOIN 202_users AS ur ON ur.user_id = tr.user_id
				LEFT JOIN 202_users_pref AS up ON up.user_id = tr.user_id
				WHERE   tracker_id_public='".$mysql['tracker_id_public']."'"; 
$rotator_row = memcache_mysql_fetch_assoc($db, $rotator_sql);
$user_id = $db->real_escape_string((string)$rotator_row['user_id']);
$user_keyword_searched_or_bidded = $db->real_escape_string($rotator_row['user_keyword_searched_or_bidded']);

//grab rules data
$mysql['rotator_id'] = $db->real_escape_string((string)$rotator_row['rotator_id']);
$rule_sql = "SELECT ru.id as rule_id
			 FROM 202_rotator_rules AS ru
			 WHERE rotator_id='".$mysql['rotator_id']."' AND status='1'"; 
$rule_row = foreach_memcache_mysql_fetch_assoc($db, $rule_sql);
if (!$rotator_row) die();

AUTH::set_timezone($rotator_row['user_timezone']);

$ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($rotator_row['maxmind_isp'] == '1') {
	$IspData = getIspData($ip_address);
	$IspData = explode(',', (string) $IspData);
	$IspData = $IspData[0];
} else {
	$IspData = null;
}

//GEO Lookup
$GeoData = getGeoData($ip_address);

//User-agent parser
$parser = Parser::create();

//Device type
$detect = new Mobile_Detect;
$ua = $detect->getUserAgent();
$result = $parser->parse($ua);

if( !$detect->isMobile() && !$detect->isTablet() ){

	switch ($result->device->family) {
		//Is Bot
		case 'Bot':
			$result->device->family = "Bot";
		break;
		//Is Desktop
		case 'Other':
			$result->device->family = "Desktop";
		break;
	}
} else {
	if ($detect->isTablet()) {
		$result->device->family = "Tablet";
		//If mobile	
	} else {
		$result->device->family = "Mobile";
	}
}

// set cpc use dynamic variable if set or the default if not
if (isset ( $_GET ['t202b'] ) && $rotator_row['user_pref_dynamic_bid'] == '1') {
    $_GET ['t202b']=ltrim((string) $_GET ['t202b'],'$');
    if(is_numeric ( $_GET ['t202b'] )){
        $bid = number_format ( $_GET ['t202b'], 5, '.', '' );
        $rotator_row ['click_cpc'] = $db->real_escape_string ( $bid );
    }
} 

$default = false;

foreach ($rule_row as $rule) {
	
	$rotate = [];
	$count = 0;

	$mysql['rule_id'] = $db->real_escape_string((string)$rule['rule_id']);
	$criteria_sql = "SELECT type, statement, value
				 FROM 202_rotator_rules_criteria
				 WHERE rule_id='".$mysql['rule_id']."'"; 
	$criteria_row = foreach_memcache_mysql_fetch_assoc($db, $criteria_sql);

	foreach ($criteria_row as $criteria) {
		switch ($criteria['statement']) {
			case 'is':
				$statement = true;		
			break;
					
			case 'is_not':
				$statement = false;	
			break;
		}

		$values = explode(',', (string) $criteria['value']);

		if (in_array('ALL', $values) || in_array('all', $values)) {
			
			$rotate[] = true;

		} else {

			switch ($criteria['type']) {
				case 'country':
					$country = $GeoData['country']."(".$GeoData['country_code'].")";

					if ($statement) {
						if (in_array($country, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($country, $values)) {
							$rotate[] = true;
						}
					}
						
				break;
				
				case 'region':
					$region = $GeoData['region']."(".$GeoData['country_code'].")";
					
					if ($statement) {
						if (in_array($region, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($region, $values)) {
							$rotate[] = true;
						}
					}

				break;

				case 'city':
					$city = $GeoData['city']."(".$GeoData['country_code'].")";
					
					if ($statement) {
						if (in_array($city, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($city, $values)) {
							$rotate[] = true;
						}
					}
				break;

				case 'isp':
					
					if ($statement) {
						if (in_array($IspData, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($IspData, $values)) {
							$rotate[] = true;
						}
					}
				break;

				case 'ip':
					if ($statement) {
						if (in_array($ip_address, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($ip_address, $values)) {
							$rotate[] = true;
						}
					}

				break;

				case 'platform':
					if ($statement) {
						if (in_array($result->os->family, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($result->os->family, $values)) {
							$rotate[] = true;
						}
					}
				break;

				case 'device':
					if ($statement) {
						if (in_array(strtolower($result->device->family), $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array(strtolower($result->device->family), $values)) {
							$rotate[] = true;
						}
					}
				break;

				case 'browser':
					if ($statement) {
						if (in_array($result->ua->family, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($result->ua->family, $values)) {
							$rotate[] = true;
						}
					}
				break;
			}
		}

		$count++;

	}
	
	//If any of the rules maches, redirect to the redirect type.
	if ($count == count($rotate)) {
		$rule_redirects_sql = "SELECT rur.id,
					   rur.redirect_url,
					   rur.redirect_campaign,
					   rur.redirect_lp,
					   rur.auto_monetizer,
					   rur.weight,
					   ca.aff_campaign_id,
					   ca.aff_campaign_rotate,
					   ca.aff_campaign_url,
					   ca.aff_campaign_url_2,
					   ca.aff_campaign_url_3,
					   ca.aff_campaign_url_4,
					   ca.aff_campaign_url_5,
					   ca.aff_campaign_payout,
					   ca.aff_campaign_cloaking,
					   lp.landing_page_url,
		               lp.landing_page_id
				FROM 202_rotator_rules_redirects AS rur
				LEFT JOIN 202_aff_campaigns AS ca ON ca.aff_campaign_id = rur.redirect_campaign
				LEFT JOIN 202_landing_pages AS lp ON lp.landing_page_id = rur.redirect_lp
				WHERE rule_id='".$mysql['rule_id']."'"; 
		$rule_redirects_row = foreach_memcache_mysql_fetch_assoc($db, $rule_redirects_sql);
		$redirects = [];
		$redirect_values = [];

		foreach ($rule_redirects_row as $rule_redirect_row) {
			
			if ($rule_redirect_row['redirect_campaign'] != null) {
				$redirects[] = ['rule_id' => $mysql['rule_id'], 'redirect_id' => $rule_redirect_row['id'], 'type' => 'campaign', 'aff_campaign_url' => $rule_redirect_row['aff_campaign_url'], 'aff_campaign_url_2' => $rule_redirect_row['aff_campaign_url_2'], 'aff_campaign_url_3' => $rule_redirect_row['aff_campaign_url_3'], 'aff_campaign_url_4' => $rule_redirect_row['aff_campaign_url_4'], 'aff_campaign_url_5' => $rule_redirect_row['aff_campaign_url_5'], 'weight' => $rule_redirect_row['weight'], 'aff_campaign_id' => $rule_redirect_row['aff_campaign_id'], 'aff_campaign_payout' => $rule_redirect_row['aff_campaign_payout'], 'aff_campaign_cloaking' => $rule_redirect_row['aff_campaign_cloaking']];
			} else if($rule_redirect_row['redirect_url'] != null) {
				$redirects[] = ['rule_id' => $mysql['rule_id'], 'redirect_id' => $rule_redirect_row['id'], 'type' => 'url', 'redirect_url' => $rule_redirect_row['redirect_url'], 'weight' => $rule_redirect_row['weight'], 'aff_campaign_id' => $rule_redirect_row['aff_campaign_id'], 'aff_campaign_payout' => $rule_redirect_row['aff_campaign_payout'], 'aff_campaign_cloaking' => $rule_redirect_row['aff_campaign_cloaking']];
			} else if ($rule_redirect_row['redirect_lp'] != null) {
				$redirects[] = ['rule_id' => $mysql['rule_id'], 'redirect_id' => $rule_redirect_row['id'], 'type' => 'lp', 'landing_page_id' => $rule_redirect_row['landing_page_id'],'landing_page_url' => $rule_redirect_row['landing_page_url'], 'weight' => $rule_redirect_row['weight'], 'aff_campaign_id' => $rule_redirect_row['aff_campaign_id'], 'aff_campaign_payout' => $rule_redirect_row['aff_campaign_payout'], 'aff_campaign_cloaking' => $rule_redirect_row['aff_campaign_cloaking']];
			} else if ($rule_redirect_row['auto_monetizer'] != null) {
				$redirects[] = ['rule_id' => $mysql['rule_id'], 'redirect_id' => $rule_redirect_row['id'], 'type' => 'monetizer', 'monetizer_url' => 'http://prosper202.com', 'weight' => $rule_redirect_row['weight'], 'aff_campaign_id' => $rule_redirect_row['aff_campaign_id'], 'aff_campaign_payout' => $rule_redirect_row['aff_campaign_payout'], 'aff_campaign_cloaking' => $rule_redirect_row['aff_campaign_cloaking']];
			}
		}

		if (count($rule_redirects_row) > 1) {
			$redirect_array = $redirects[getSplitTestValue($redirects)];
		} elseif (!empty($redirects)) {
			$redirect_array = $redirects[0];
		} else {
			$redirect_array = null;
		}

		$default = false;
		if ($redirect_array !== null) {
			$redirect = redirect_process($db, $redirect_array, $rotator_row['ppc_account_id'], $rotator_row['click_cpc'], $rotator_row['rotator_id'], $GeoData, $ip_address, $user_id, $IspData, $user_keyword_searched_or_bidded);
			header('location: '.$redirect);
			die();
		}
	} else {
		$default = true;
	}
	
}

if ($default == true) {
	$default = redirect_process($db, $rotator_row, $rotator_row['ppc_account_id'], $rotator_row['click_cpc'], $rotator_row['rotator_id'], $GeoData, $ip_address, $user_id, $IspData, $user_keyword_searched_or_bidded);
	
		if ($usedCachedRedirect==true) { 

			if ($memcacheWorking) {
				$getUrl = $memcache->get(md5("default_url" . $tracker_id . systemHash()));
				if (!$getUrl) {
						setCache(md5('default_url' . $tracker_id . systemHash()), $default, 0);			
				}
			}
		}
	header('location: '.$default);
	die();
}



//Redirect process function
function redirect_process($db, $rule, $ppc_account, $cpc, $rotator_id, $GeoData, $ip_address, $user_id, $IspData, $keyword_type){
$ruleDefaults = [
	'aff_campaign_id' => 0,
	'aff_campaign_url' => '',
	'aff_campaign_url_2' => '',
	'aff_campaign_url_3' => '',
	'aff_campaign_url_4' => '',
	'aff_campaign_url_5' => '',
	'aff_campaign_payout' => '0',
	'aff_campaign_cloaking' => 0,
	'landing_page_id' => 0,
	'landing_page_url' => '',
	'text_ad_id' => 0,
	'weight' => 0,
	'redirect_url' => '',
	'default_url' => '',
	'default_lp' => null,
	'click_cpc' => $cpc,
	'rule_id' => 0,
	'redirect_id' => 0,
	'auto_monetizer' => null,
	'type' => 'url',
	'monetizer_url' => 'http://prosper202.com'
];

$rule = array_merge($ruleDefaults, is_array($rule) ? $rule : []);

$escape = static fn($value) => $db->real_escape_string((string)($value ?? ''));

$uaParam = (string)($_GET['ua'] ?? '');
$detectInstance = new Mobile_Detect();
if ($uaParam !== '') {
	$detectInstance->setUserAgent($uaParam);
}
$device_id = PLATFORMS::get_device_info($db, $detectInstance, $uaParam);
if (!is_array($device_id)) {
	$device_id = ['platform' => '', 'browser' => '', 'device' => '', 'type' => '1'];
}

$mysql['click_time'] = time();
$mysql['aff_campaign_id'] = $escape($rule['aff_campaign_id']);
$mysql['click_cpc'] = $escape($rule['click_cpc']);
$mysql['click_payout'] = $escape($rule['aff_campaign_payout']);
$mysql['rule_id'] = $escape($rule['rule_id']);
$mysql['rule_redirect_id'] = $escape($rule['redirect_id']);
$mysql['ppc_account'] = $escape($ppc_account);
$mysql['cpc'] = $escape($cpc);
$mysql['landing_page_id'] = $escape($rule['landing_page_id']);

/* ok, if $_GET['OVRAW'] that is a yahoo keyword, if on the REFER, there is a $_GET['q], that is a GOOGLE keyword... */
//so this is going to check the REFERER URL, for a ?q=, which is the ACUTAL KEYWORD searched.
$referer_url_parsed = @parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
$referer_url_query = $referer_url_parsed['query'] ?? '';
$referer_query = [];
if ($referer_url_query !== '') {
    @parse_str($referer_url_query, $referer_query);
}

switch ($keyword_type) { 

	case "bidded":
	      #try to get the bidded keyword first
		if (isset($_GET['OVKEY']) && $_GET['OVKEY'] != '') { //if this is a Y! keyword
			$keyword = $db->real_escape_string((string)$_GET['OVKEY']);   
		}  elseif (isset($_GET['t202kw']) && $_GET['t202kw'] != '') { 
			$keyword = $db->real_escape_string((string)$_GET['t202kw']);  
		} elseif (isset($_GET['target_passthrough']) && $_GET['target_passthrough'] != '') { //if this is a mediatraffic! keyword
			$keyword = $db->real_escape_string((string)$_GET['target_passthrough']);   
		} else { //if this is a zango, or more keyword
			$keyword = $db->real_escape_string((string)($_GET['keyword'] ?? ''));   
		} 
		break;
		case "searched":
		#try to get the searched keyword
		if (isset($referer_query['q']) && $referer_query['q'] != '') { 
			$keyword = $db->real_escape_string($referer_query['q']);
		} elseif (isset($_GET['OVRAW']) && $_GET['OVRAW'] != '') { //if this is a Y! keyword
			$keyword = $db->real_escape_string((string)$_GET['OVRAW']);   
		} elseif (isset($_GET['target_passthrough']) && $_GET['target_passthrough'] != '') { //if this is a mediatraffic! keyword
			$keyword = $db->real_escape_string((string)$_GET['target_passthrough']);   
		} elseif (isset($_GET['keyword']) && $_GET['keyword'] != '') { //if this is a zango, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['keyword']);   
		} elseif (isset($_GET['search_word']) && $_GET['search_word'] != '') { //if this is a eniro, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['search_word']);   
		} elseif (isset($_GET['query']) && $_GET['query'] != '') { //if this is a naver, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['query']);   
		} elseif (isset($_GET['encquery']) && $_GET['encquery'] != '') { //if this is a aol, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['encquery']);   
		} elseif (isset($_GET['terms']) && $_GET['terms'] != '') { //if this is a about.com, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['terms']);   
		} elseif (isset($_GET['rdata']) && $_GET['rdata'] != '') { //if this is a viola, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['rdata']);   
		} elseif (isset($_GET['qs']) && $_GET['qs'] != '') { //if this is a virgilio, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['qs']);   
		} elseif (isset($_GET['wd']) && $_GET['wd'] != '') { //if this is a baidu, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['wd']);   
		} elseif (isset($_GET['text']) && $_GET['text'] != '') { //if this is a yandex, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['text']);   
		} elseif (isset($_GET['szukaj']) && $_GET['szukaj'] != '') { //if this is a wp.pl, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['szukaj']);   
		} elseif (isset($_GET['qt']) && $_GET['qt'] != '') { //if this is a O*net, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['qt']);   
		} elseif (isset($_GET['k']) && $_GET['k'] != '') { //if this is a yam, or more keyword
			$keyword = $db->real_escape_string((string)$_GET['k']);   
		} elseif (isset($_GET['words']) && $_GET['words'] != '') { //if this is a Rambler, or more keyword
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

$keyword = str_replace('%20',' ',$keyword);      
$keyword_id = INDEXES::get_keyword_id($db, $keyword); 
$mysql['keyword_id'] = $db->real_escape_string((string)$keyword_id); 		  

$c1 = $db->real_escape_string((string)($_GET['c1'] ?? ''));
$c1 = str_replace('%20',' ',$c1);  
$c1_id = INDEXES::get_c1_id($db, $c1); 
$mysql['c1_id'] = $db->real_escape_string((string)$c1_id);

$c2 = $db->real_escape_string((string)($_GET['c2'] ?? ''));
$c2 = str_replace('%20',' ',$c2);
$c2_id = INDEXES::get_c2_id($db, $c2);
$mysql['c2_id'] = $db->real_escape_string((string)$c2_id);

$c3 = $db->real_escape_string((string)($_GET['c3'] ?? ''));
$c3 = str_replace('%20',' ',$c3);  
$c3_id = INDEXES::get_c3_id($db, $c3); 
$mysql['c3_id'] = $db->real_escape_string((string)$c3_id);

$c4 = $db->real_escape_string((string)($_GET['c4'] ?? ''));
$c4 = str_replace('%20',' ',$c4);
$c4_id = INDEXES::get_c4_id($db, $c4);
$mysql['c4_id'] = $db->real_escape_string((string)$c4_id);

$mysql['platform_id'] = $db->real_escape_string((string)($device_id['platform'] ?? ''));
$mysql['browser_id'] = $db->real_escape_string((string)($device_id['browser'] ?? ''));
$mysql['device_id'] = $db->real_escape_string((string)($device_id['device'] ?? ''));
$mysql['text_ad_id'] = $db->real_escape_string((string)($rule['text_ad_id'] ?? 0));
$mysql['isp_id'] = $db->real_escape_string('0');

$mysql['click_bot'] = '0';
if (($device_id['type'] ?? '') == '4') {
	$mysql['click_bot'] = '1';
}

$mysql['click_in'] = 1;
$mysql['click_out'] = 1; 

$ip_id = INDEXES::get_ip_id($db, $ip_address);
$mysql['ip_id'] = $db->real_escape_string((string)$ip_id);

$countryName = $GeoData['country'] ?? '';
$countryCode = $GeoData['country_code'] ?? '';
$country_id = INDEXES::get_country_id($db, $countryName, $countryCode);
$mysql['country_id'] = $db->real_escape_string((string)$country_id);

$regionName = $GeoData['region'] ?? '';
$region_id = INDEXES::get_region_id($db, $regionName, $country_id);
$mysql['region_id'] = $db->real_escape_string((string)$region_id);

$cityName = $GeoData['city'] ?? '';
$city_id = INDEXES::get_city_id($db, $cityName, $country_id);
$mysql['city_id'] = $db->real_escape_string((string)$city_id);

if ($IspData != null) {
	$isp_id = INDEXES::get_isp_id($db, $IspData);
	$mysql['isp_id'] = $db->real_escape_string((string)$isp_id);
}

if ($device_id['type'] == '4') {
	$mysql['click_filtered'] = '1';
} else {
	// Initialize click_id as 0 for the filter (will be updated after insert)
	$click_id_temp = 0;
	$click_filtered = FILTER::startFilter($db, $click_id_temp,$ip_id,$ip_address,$user_id);
$mysql['click_filtered'] = $db->real_escape_string((string)$click_filtered);
}

if(isset($_GET['lpr']) && $_GET['lpr'] != '') {
	$click_sql1 = "	SELECT 	202_clicks.click_id,keyword,keyword_id
					FROM 		202_clicks
					LEFT JOIN	202_clicks_advance USING (click_id)
					LEFT JOIN 	202_ips USING (ip_id) 
					LEFT JOIN 	202_keywords USING (keyword_id) 
					WHERE 	202_ips.ip_address='".$ip_address."'
					AND		202_clicks.user_id='".$user_id."'  
					AND		202_clicks.click_time >= '30'
					ORDER BY 	202_clicks.click_id DESC 
					LIMIT 		1";
	$click_result1 = $db->query($click_sql1) or record_mysql_error($click_sql1);
	$click_row1 = $click_result1->fetch_assoc();
	$mysql['click_id'] = $db->real_escape_string((string)$click_row1['click_id']);
	$keyword = $db->real_escape_string($keyword);
	$keyword_id = $db->real_escape_string((string)$click_row1['keyword_id']);
	$mysql['keyword_id'] = $db->real_escape_string((string)$keyword_id);
}
else{
//ok we have the main data, now insert this row
$click_sql = "INSERT INTO  202_clicks_counter SET click_id=DEFAULT";
$click_result = $db->query($click_sql) or record_mysql_error($db); 

//now gather the info for the advance click insert
$click_id = $db->insert_id;
$mysql['click_id'] = $db->real_escape_string((string)$click_id); 
}
$mysql['click_alp'] = 0;

$mysql['rotator_id'] = $db->real_escape_string((string)$rotator_id); 
$mysql['user_id'] = $db->real_escape_string((string)$user_id);

//ok we have the main data, now insert this row
$click_sql = "REPLACE INTO   202_clicks
			  SET           	click_id='".$mysql['click_id']."',
							user_id = '".$mysql['user_id']."',   
							aff_campaign_id = '".$mysql['aff_campaign_id']."',
							 landing_page_id = '".$mysql['landing_page_id']."',   
							ppc_account_id = '".$mysql['ppc_account']."',   
							click_cpc = '".$mysql['cpc']."',   
							click_payout = '".$mysql['click_payout']."',   
							click_alp = '".$mysql['click_alp']."',
							click_filtered = '".$mysql['click_filtered']."',
							click_bot = '".$mysql['click_bot']."',
							click_time = '".$mysql['click_time']."',
                            rotator_id = '".$mysql['rotator_id']."',
                            rule_id = '".$mysql['rule_redirect_id']."'"; 

$click_result = $db->query($click_sql) or record_mysql_error($db);   

	//ok we have the main data, now insert this row
	$click_sql = "REPLACE INTO   202_clicks_spy
				  SET           	click_id='".$mysql['click_id']."',
								user_id = '".$mysql['user_id']."',   
								aff_campaign_id = '".$mysql['aff_campaign_id']."',   
								ppc_account_id = '".$mysql['ppc_account']."',   
								click_cpc = '".$mysql['cpc']."',   
								click_payout = '".$mysql['click_payout']."',   
								click_filtered = '".$mysql['click_filtered']."',
								click_bot = '".$mysql['click_bot']."',
								click_alp = '".$mysql['click_alp']."',
								click_time = '".$mysql['click_time']."'"; 
	$click_result = $db->query($click_sql) or record_mysql_error($db);   
	
//now we have the click's advance data, now insert this row
$click_sql = "REPLACE INTO   202_clicks_advance
			  SET           click_id='".$mysql['click_id']."',
							text_ad_id='".$mysql['text_ad_id']."',
							keyword_id='".$mysql['keyword_id']."',
							ip_id='".$mysql['ip_id']."',
							country_id='".$mysql['country_id']."',
							region_id='".$mysql['region_id']."',
							isp_id='".$mysql['isp_id']."',
							city_id='".$mysql['city_id']."',
							platform_id='".$mysql['platform_id']."',
							browser_id='".$mysql['browser_id']."',
							device_id='".$mysql['device_id']."'";
$click_result = $db->query($click_sql) or record_mysql_error($db);

//insert the tracking data
$click_sql = "
	REPLACE INTO
		202_clicks_tracking
	SET
		click_id='".$mysql['click_id']."',
		c1_id = '".$mysql['c1_id']."',
		c2_id = '".$mysql['c2_id']."',
		c3_id = '".$mysql['c3_id']."',
		c4_id = '".$mysql['c4_id']."'";
$click_result = $db->query($click_sql) or record_mysql_error($db);

$click_sql = "
	REPLACE INTO
		202_clicks_rotator
	SET
		click_id='".$mysql['click_id']."',
		rotator_id='".$mysql['rotator_id']."',
		rule_id='".$mysql['rule_id']."',
		rule_redirect_id = '".$mysql['rule_redirect_id']."'";
$click_result = $db->query($click_sql) or record_mysql_error($db);


//now gather variables for the clicks record db
//lets determine if cloaking is on
$mysql['click_id_public'] = '';
if ($rule['aff_campaign_cloaking'] == 1) {
	$cloaking_on = true;
	$mysql['click_cloaking'] = 1;
	//if cloaking is on, add in a click_id_public, because we will be forwarding them to a cloaked /cl/xxxx link
	$click_id_public = random_int(1,9) . $click_id . random_int(1,9);
	$mysql['click_id_public'] = $db->real_escape_string($click_id_public); 
} else { 
	$mysql['click_cloaking'] = 0; 
}

//ok we have our click recorded table, now lets insert theses
$click_sql = "REPLACE INTO   202_clicks_record
			  SET           click_id='".$mysql['click_id']."',
							click_id_public='".$mysql['click_id_public']."',
							click_cloaking='".$mysql['click_cloaking']."',
							click_in='".$mysql['click_in']."',
							click_out='".$mysql['click_out']."'"; 
$click_result = $db->query($click_sql) or record_mysql_error($db); 

//now lets get variables for clicks site
//so this is going to check the REFERER URL, for a ?url=, which is the ACUTAL URL, instead of the google content, pagead2.google.... 
if (!empty($referer_query['url'])) { 
    $click_referer_site_url_id = INDEXES::get_site_url_id($db, $referer_query['url']);
} else {
    $click_referer_site_url_id = INDEXES::get_site_url_id($db, $_SERVER['HTTP_REFERER'] ?? '');
}

$mysql['click_referer_site_url_id'] = $db->real_escape_string((string)$click_referer_site_url_id); 

$outbound_site_url = 'http://'.$_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
$click_outbound_site_url_id = INDEXES::get_site_url_id($db, $outbound_site_url); 
$mysql['click_outbound_site_url_id'] = $db->real_escape_string((string)$click_outbound_site_url_id); 

if ($cloaking_on == true) {
	$cloaking_site_url = 'http://'.$_SERVER['SERVER_NAME'] . '/tracking202/redirect/cl.php?pci=' . $click_id_public;      
}
if ($rule['aff_campaign_id'] != null) {
	//rotate the urls
	$redirect_site_url = rotateTrackerUrl($db, $rule);
} else {
	if ($rule['type'] == 'url') {
		$redirect_site_url = $rule['redirect_url'];
	} else if($rule['type'] == 'campaign') {
		$redirect_site_url = $rule['aff_campaign_url'];
	} else if ($rule['type'] == 'lp') {
		$redirect_site_url = $rule['landing_page_url'];
	} else if ($rule['type'] == 'auto_monetizer') {
		$redirect_site_url = "http://prosper202.com";
	} else if ($rule['default_url'] != null) {
		$redirect_site_url = $rule['default_url'];
	} else if ($rule['default_lp'] != null) {
		$redirect_site_url = $rule['landing_page_url'];
	}
}

$redirect_site_url = replaceTrackerPlaceholders($db, $redirect_site_url,$click_id);

$click_redirect_site_url_id = INDEXES::get_site_url_id($db, $redirect_site_url); 
$mysql['click_redirect_site_url_id'] = $db->real_escape_string((string)$click_redirect_site_url_id);

//insert this
$click_sql = "REPLACE INTO   202_clicks_site
			  SET           click_id='".$mysql['click_id']."',
							click_referer_site_url_id='".$mysql['click_referer_site_url_id']."',
							click_outbound_site_url_id='".$mysql['click_outbound_site_url_id']."',
							click_redirect_site_url_id='".$mysql['click_redirect_site_url_id']."'";
$click_result = $db->query($click_sql) or record_mysql_error($db);   

	if ($rule['aff_campaign_id'] != null) {
		//set the cookie
		setClickIdCookie($mysql['click_id'],$rule['aff_campaign_id']);
	}

	//set dirty hour
	$de = new DataEngine();
	$data = $de->setDirtyHour($mysql['click_id']);

	$urlvars = getPrePopVars($_GET);
	
	//now we've recorded, now lets redirect them
	if ($cloaking_on == true) {
		//if cloaked, redirect them to the cloaked site. 
		return setPrePopVars($urlvars,$cloaking_site_url,true);  
	} else {
		return setPrePopVars($urlvars,$redirect_site_url,false);       
	} 

}

?>
