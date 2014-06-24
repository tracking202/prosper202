<?php
use UAParser\Parser;

#only allow numeric id
$tracker_id = $_GET['t202id']; 
if (!is_numeric($tracker_id)) die();

# check to see if mysql connection works, if not fail over to cached stored redirect urls
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config.php'); 

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

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect2.php');

//grab tracker data
$mysql['tracker_id_public'] = $db->real_escape_string($tracker_id);
$rotator_sql = "SELECT  tr.user_id,
						tr.ppc_account_id,
						tr.rotator_id,
						tr.click_cpc,
						rt.default_url,
						rt.default_campaign,
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
					   	up.maxmind_isp
				FROM    202_trackers AS tr
				LEFT JOIN 202_rotators AS rt ON rt.id = tr.rotator_id
				LEFT JOIN 202_aff_campaigns AS ca ON ca.aff_campaign_id = rt.default_campaign
				LEFT JOIN 202_users AS ur ON ur.user_id = tr.user_id
				LEFT JOIN 202_users_pref AS up ON up.user_id = tr.user_id
				WHERE   tracker_id_public='".$mysql['tracker_id_public']."'"; 
$rotator_row = memcache_mysql_fetch_assoc($db, $rotator_sql);
$user_id = $db->real_escape_string($rotator_row['user_id']);
$user_keyword_searched_or_bidded = $db->real_escape_string($rotator_row['user_keyword_searched_or_bidded']);

//grab rules data
$mysql['rotator_id'] = $db->real_escape_string($rotator_row['rotator_id']);
$rule_sql = "SELECT ru.id as rule_id,
					   ru.redirect_url,
					   ru.redirect_campaign,
					   ca.aff_campaign_id,
					   ca.aff_campaign_rotate,
					   ca.aff_campaign_url,
					   ca.aff_campaign_url_2,
					   ca.aff_campaign_url_3,
					   ca.aff_campaign_url_4,
					   ca.aff_campaign_url_5,
					   ca.aff_campaign_payout,
					   ca.aff_campaign_cloaking
				FROM 202_rotator_rules AS ru
				LEFT JOIN 202_aff_campaigns AS ca ON ca.aff_campaign_id = ru.redirect_campaign
				WHERE rotator_id='".$mysql['rotator_id']."' AND status='1'"; 
$rule_row = foreach_memcache_mysql_fetch_assoc($db, $rule_sql);

if (!$rotator_row) die();

AUTH::set_timezone($rotator_row['user_timezone']);

$ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];

if ($rotator_row['maxmind'] == '1') {
	$IspData = getIspData($ip_address);
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

$default = false;

foreach ($rule_row as $rule) {
	
	$rotate = array();
	$count = 0;

	$mysql['rule_id'] = $db->real_escape_string($rule['rule_id']);
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

		$values = explode(',', $criteria['value']);

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

		$count++;

	}

	//If any of the rules maches, redirect to the redirect type (url or campaign)
	if ($count == count($rotate)) {
		$default = false;
		$redirect = redirect_process($db, $rule, $rotator_row['ppc_account_id'], $rotator_row['click_cpc'], $rotator_row['rotator_id'], $GeoData, $ip_address, $user_id, $IspData, $user_keyword_searched_or_bidded);
		header('location: '.$redirect);
		die();
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
						$memcache->set(md5('default_url' . $tracker_id . systemHash()), $default, false, 0);			
				}
			}
		}

	header('location: '.$default);
	die();
}



//Redirect process function
function redirect_process($db, $rule, $ppc_account, $cpc, $rotator_id, $GeoData, $ip_address, $user_id, $IspData, $keyword_type){

$mysql['aff_campaign_id'] = $db->real_escape_string($rule['aff_campaign_id']);
$mysql['click_cpc'] = $db->real_escape_string($rule['click_cpc']);
$mysql['click_payout'] = $db->real_escape_string($rule['aff_campaign_payout']);
$mysql['rule_id'] = $db->real_escape_string($rule['rule_id']);
$mysql['ppc_account'] = $db->real_escape_string($ppc_account);
$mysql['cpc'] = $db->real_escape_string($cpc);
$mysql['click_time'] = time();


/* ok, if $_GET['OVRAW'] that is a yahoo keyword, if on the REFER, there is a $_GET['q], that is a GOOGLE keyword... */
//so this is going to check the REFERER URL, for a ?q=, which is the ACUTAL KEYWORD searched.
$referer_url_parsed = @parse_url($_SERVER['HTTP_REFERER']);
$referer_url_query = $referer_url_parsed['query'];

@parse_str($referer_url_query, $referer_query);

switch ($keyword_type) { 

	case "bidded":
	      #try to get the bidded keyword first
		if ($_GET['OVKEY']) { //if this is a Y! keyword
			$keyword = $db->real_escape_string($_GET['OVKEY']);   
		} elseif ($_GET['utm_source']) { 
			$keyword = $db->real_escape_string($_GET['utm_source']);  
		} elseif ($_GET['t202kw']) { 
			$keyword = $db->real_escape_string($_GET['t202kw']);  
		} elseif ($referer_query['p']) { 
			$keyword = $db->real_escape_string($referer_query['p']);
		} elseif ($_GET['target_passthrough']) { //if this is a mediatraffic! keyword
			$keyword = $db->real_escape_string($_GET['target_passthrough']);   
		} else { //if this is a zango, or more keyword
			$keyword = $db->real_escape_string($_GET['keyword']);   
		} 
		break;
		case "searched":
		#try to get the searched keyword
		if ($referer_query['q']) { 
			$keyword = $db->real_escape_string($referer_query['q']);
		} elseif ($referer_query['p']) { 
			$keyword = $db->real_escape_string($referer_query['p']);
		} elseif ($_GET['OVRAW']) { //if this is a Y! keyword
			$keyword = $db->real_escape_string($_GET['OVRAW']);   
		} elseif ($_GET['target_passthrough']) { //if this is a mediatraffic! keyword
			$keyword = $db->real_escape_string($_GET['target_passthrough']);   
		} elseif ($_GET['keyword']) { //if this is a zango, or more keyword
			$keyword = $db->real_escape_string($_GET['keyword']);   
		} elseif ($_GET['search_word']) { //if this is a eniro, or more keyword
			$keyword = $db->real_escape_string($_GET['search_word']);   
		} elseif ($_GET['query']) { //if this is a naver, or more keyword
			$keyword = $db->real_escape_string($_GET['query']);   
		} elseif ($_GET['encquery']) { //if this is a aol, or more keyword
			$keyword = $db->real_escape_string($_GET['encquery']);   
		} elseif ($_GET['terms']) { //if this is a about.com, or more keyword
			$keyword = $db->real_escape_string($_GET['terms']);   
		} elseif ($_GET['rdata']) { //if this is a viola, or more keyword
			$keyword = $db->real_escape_string($_GET['rdata']);   
		} elseif ($_GET['qs']) { //if this is a virgilio, or more keyword
			$keyword = $db->real_escape_string($_GET['qs']);   
		} elseif ($_GET['wd']) { //if this is a baidu, or more keyword
			$keyword = $db->real_escape_string($_GET['wd']);   
		} elseif ($_GET['text']) { //if this is a yandex, or more keyword
			$keyword = $db->real_escape_string($_GET['text']);   
		} elseif ($_GET['szukaj']) { //if this is a wp.pl, or more keyword
			$keyword = $db->real_escape_string($_GET['szukaj']);   
		} elseif ($_GET['qt']) { //if this is a O*net, or more keyword
			$keyword = $db->real_escape_string($_GET['qt']);   
		} elseif ($_GET['k']) { //if this is a yam, or more keyword
			$keyword = $db->real_escape_string($_GET['k']);   
		} elseif ($_GET['words']) { //if this is a Rambler, or more keyword
			$keyword = $db->real_escape_string($_GET['words']);   
		} else { 
			$keyword = $db->real_escape_string($_GET['t202kw']);
		}
		break;
}

$keyword = str_replace('%20',' ',$keyword);      
$keyword_id = INDEXES::get_keyword_id($db, $keyword); 
$mysql['keyword_id'] = $db->real_escape_string($keyword_id); 		  

$c1 = $db->real_escape_string($_GET['c1']);
$c1 = str_replace('%20',' ',$c1);  
$c1_id = INDEXES::get_c1_id($db, $c1); 
$mysql['c1_id'] = $db->real_escape_string($c1_id);

$c2 = $db->real_escape_string($_GET['c2']);
$c2 = str_replace('%20',' ',$c2);
$c2_id = INDEXES::get_c2_id($db, $c2);
$mysql['c2_id'] = $db->real_escape_string($c2_id);

$c3 = $db->real_escape_string($_GET['c3']);
$c3 = str_replace('%20',' ',$c3);  
$c3_id = INDEXES::get_c3_id($db, $c3); 
$mysql['c3_id'] = $db->real_escape_string($c3_id);

$c4 = $db->real_escape_string($_GET['c4']);
$c4 = str_replace('%20',' ',$c4);
$c4_id = INDEXES::get_c4_id($db, $c4);
$mysql['c4_id'] = $db->real_escape_string($c4_id);

$device_id = PLATFORMS::get_device_info($db);
$mysql['platform_id'] = $db->real_escape_string($device_id['platform']); 
$mysql['browser_id'] = $db->real_escape_string($device_id['browser']);
$mysql['device_id'] = $db->real_escape_string($device_id['device']);

if ($device_id['type'] == '4') {
	$mysql['click_bot'] = '1';
}

$mysql['click_in'] = 1;
$mysql['click_out'] = 1; 

$ip_id = INDEXES::get_ip_id($db, $ip_address);
$mysql['ip_id'] = $db->real_escape_string($ip_id);

$country_id = INDEXES::get_country_id($db, $GeoData['country'], $GeoData['country_code']);
$mysql['country_id'] = $db->real_escape_string($country_id);

$region_id = INDEXES::get_region_id($db, $GeoData['region'], $mysql['country_id']);
$mysql['region_id'] = $db->real_escape_string($region_id);

$city_id = INDEXES::get_city_id($db, $GeoData['city'], $mysql['country_id']);
$mysql['city_id'] = $db->real_escape_string($city_id);

if ($IspData != null) {
	$isp_id = INDEXES::get_isp_id($db, $IspData);
	$mysql['isp_id'] = $db->real_escape_string($isp_id);
}

if ($device_id['type'] == '4') {
	$mysql['click_filtered'] = '1';
} else {
	$click_filtered = FILTER::startFilter($db, $click_id,$ip_id,$ip_address,$user_id);
	$mysql['click_filtered'] = $db->real_escape_string($click_filtered);
}

//ok we have the main data, now insert this row
$click_sql = "INSERT INTO  202_clicks_counter SET click_id=DEFAULT";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql); 

//now gather the info for the advance click insert
$click_id = $db->insert_id;
$mysql['click_id'] = $db->real_escape_string($click_id); 

$mysql['click_alp'] = 0;

$mysql['rotator_id'] = $db->real_escape_string($rotator_id); 
$mysql['user_id'] = $db->real_escape_string($user_id);

//ok we have the main data, now insert this row
$click_sql = "INSERT INTO   202_clicks
			  SET           	click_id='".$mysql['click_id']."',
							user_id = '".$mysql['user_id']."',   
							aff_campaign_id = '".$mysql['aff_campaign_id']."',   
							ppc_account_id = '".$mysql['ppc_account']."',   
							click_cpc = '".$mysql['cpc']."',   
							click_payout = '".$mysql['click_payout']."',   
							click_alp = '".$mysql['click_alp']."',
							click_filtered = '".$mysql['click_filtered']."',
							click_bot = '".$mysql['click_bot']."',
							click_time = '".$mysql['click_time']."',
							rotator_id = '".$mysql['rotator_id']."',
							rule_id = '".$mysql['rule_id']."'"; 
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);   

	//ok we have the main data, now insert this row
	$click_sql = "INSERT INTO   202_clicks_spy
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
	$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);   

//now we have the click's advance data, now insert this row
$click_sql = "INSERT INTO   202_clicks_advance
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
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);

//insert the tracking data
$click_sql = "
	INSERT INTO
		202_clicks_tracking
	SET
		click_id='".$mysql['click_id']."',
		c1_id = '".$mysql['c1_id']."',
		c2_id = '".$mysql['c2_id']."',
		c3_id = '".$mysql['c3_id']."',
		c4_id = '".$mysql['c4_id']."'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);


//now gather variables for the clicks record db
//lets determine if cloaking is on
if ($rule['aff_campaign_cloaking'] == 1) {
	$cloaking_on = true;
	$mysql['click_cloaking'] = 1;
	//if cloaking is on, add in a click_id_public, because we will be forwarding them to a cloaked /cl/xxxx link
	$click_id_public = rand(1,9) . $click_id . rand(1,9);
	$mysql['click_id_public'] = $db->real_escape_string($click_id_public); 
} else { 
	$mysql['click_cloaking'] = 0; 
}

//ok we have our click recorded table, now lets insert theses
$click_sql = "INSERT INTO   202_clicks_record
			  SET           click_id='".$mysql['click_id']."',
							click_id_public='".$mysql['click_id_public']."',
							click_cloaking='".$mysql['click_cloaking']."',
							click_in='".$mysql['click_in']."',
							click_out='".$mysql['click_out']."'"; 
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql); 

//now lets get variables for clicks site
//so this is going to check the REFERER URL, for a ?url=, which is the ACUTAL URL, instead of the google content, pagead2.google.... 
if ($referer_query['url']) { 
	$click_referer_site_url_id = INDEXES::get_site_url_id($db, $referer_query['url']);
} else {
	$click_referer_site_url_id = INDEXES::get_site_url_id($db, $_SERVER['HTTP_REFERER']);
}

$mysql['click_referer_site_url_id'] = $db->real_escape_string($click_referer_site_url_id); 

$outbound_site_url = 'http://'.$_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
$click_outbound_site_url_id = INDEXES::get_site_url_id($db, $outbound_site_url); 
$mysql['click_outbound_site_url_id'] = $db->real_escape_string($click_outbound_site_url_id); 

if ($cloaking_on == true) {
	$cloaking_site_url = 'http://'.$_SERVER['SERVER_NAME'] . '/tracking202/redirect/cl.php?pci=' . $click_id_public;      
}

if ($rule['aff_campaign_id'] != null) {
	//rotate the urls
	$redirect_site_url = rotateTrackerUrl($db, $rule);
} else {
	if ($rule['default_url'] != null) {
		$redirect_site_url = $rule['default_url'];
	} elseif($rule['redirect_url'] != null) {
		$redirect_site_url = $rule['redirect_url'];
	}
	
}


$redirect_site_url = replaceTrackerPlaceholders($db, $redirect_site_url,$click_id);


$click_redirect_site_url_id = INDEXES::get_site_url_id($db, $redirect_site_url); 
$mysql['click_redirect_site_url_id'] = $db->real_escape_string($click_redirect_site_url_id);

//insert this
$click_sql = "INSERT INTO   202_clicks_site
			  SET           click_id='".$mysql['click_id']."',
							click_referer_site_url_id='".$mysql['click_referer_site_url_id']."',
							click_outbound_site_url_id='".$mysql['click_outbound_site_url_id']."',
							click_redirect_site_url_id='".$mysql['click_redirect_site_url_id']."'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);   

//update the click summary table 

	$now = time();

	$today_day = date('j', time());
	$today_month = date('n', time());
	$today_year = date('Y', time());

	//the click_time is recorded in the middle of the day
	$click_time = mktime(12,0,0,$today_month,$today_day,$today_year);
	$mysql['click_time'] = $db->real_escape_string($click_time);

	//check to make sure this click_summary doesn't already exist
	$check_sql = "SELECT  *
				  FROM    202_summary_overview
				  WHERE   user_id='".$mysql['user_id']."'
				  AND     aff_campaign_id='".$mysql['aff_campaign_id']."'
				  AND     ppc_account_id='".$mysql['ppc_account']."'
				  AND     click_time='".$mysql['click_time']."'";
	$check_result = $db->query($check_sql) or record_mysql_error($db, $check_sql);
	$check_count = $check_result->num_rows;      


	//if this click summary hasn't been recorded do this now
	if ($check_count == 0 ) {

		$insert_sql = "INSERT INTO 202_summary_overview
					   SET         user_id='".$mysql['user_id']."',
								   aff_campaign_id='".$mysql['aff_campaign_id']."',
								   ppc_account_id='".$mysql['ppc_account']."',
								   click_time='".$mysql['click_time']."'";
		$insert_result = $db->query($insert_sql);
	}  
 

	if ($rule['aff_campaign_id'] != null) {
		//set the cookie
		setClickIdCookie($mysql['click_id'],$rule['aff_campaign_id']);
	}


	//now we've recorded, now lets redirect them
	if ($cloaking_on == true) {
		//if cloaked, redirect them to the cloaked site. 
		return $cloaking_site_url;  
	} else {
		return $redirect_site_url;       
	} 

}

?>