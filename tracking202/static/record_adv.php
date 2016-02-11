<?php header("Pragma: no-cache");
header("Expires: -1"); 

include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/connect2.php'); 
include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/class-dataengine-slim.php');

$landing_page_id_public = $_GET['lpip'];
$mysql['landing_page_id_public'] = $db->real_escape_string($landing_page_id_public);
$tracker_sql = "SELECT  202_landing_pages.user_id,
						  202_landing_pages.landing_page_id
				FROM     202_landing_pages
				WHERE  202_landing_pages.landing_page_id_public='".$mysql['landing_page_id_public']."'";   
$tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);    

//set the timezone to the users timezone
$mysql['user_id'] = $db->real_escape_string($tracker_row['user_id']);
$user_sql = "SELECT 		user_timezone, 
							user_keyword_searched_or_bidded,
                            user_pref_referer_data,
                            user_pref_dynamic_bid,
							maxmind_isp 
			   FROM 		202_users 
			   LEFT JOIN	202_users_pref USING (user_id)
			   WHERE 		202_users.user_id='".$mysql['user_id']."'";
$user_row = memcache_mysql_fetch_assoc($db, $user_sql);
$mysql['user_pref_dynamic_bid'] = $db->real_escape_string($user_row['user_pref_dynamic_bid']);

//now this sets it
AUTH::set_timezone($user_row['user_timezone']);

if (!$tracker_row) { die(); }

if ($_GET['t202id']) { 
	//grab tracker data if avaliable
	$mysql['tracker_id_public'] = $db->real_escape_string($_GET['t202id']);

	$tracker_sql2 = "
		SELECT
			tr.text_ad_id,
			tr.ppc_account_id,
			tr.click_cpc,
			tr.click_cloaking,
			tr.aff_campaign_id,
			2cv.ppc_variable_ids,
			2cv.parameters
		FROM
			202_trackers AS tr
			LEFT JOIN 202_ppc_accounts AS 2ppc USING (ppc_account_id)
			LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(ppc_variable_id) AS ppc_variable_ids, GROUP_CONCAT(parameter) AS parameters FROM 202_ppc_network_variables GROUP BY ppc_network_id) AS 2cv USING (ppc_network_id)		
		WHERE
			tr.tracker_id_public='".$mysql['tracker_id_public']."'";   
	$tracker_row2 = memcache_mysql_fetch_assoc($db, $tracker_sql2);
	if ($tracker_row2) {
		$tracker_row = array_merge($tracker_row,$tracker_row2);
	}
}

 
//INSERT THIS CLICK BELOW, if this click doesn't already exisit

//get mysql variables 
$mysql['user_id'] = $db->real_escape_string($tracker_row['user_id']);
$mysql['aff_campaign_id'] = $db->real_escape_string($tracker_row['aff_campaign_id']);
$mysql['ppc_account_id'] = $db->real_escape_string($tracker_row['ppc_account_id']);
// set cpc use dynamic variable if set or the default if not
if (isset ( $_GET ['t202b'] ) && $mysql['user_pref_dynamic_bid'] == '1') {
    $_GET ['t202b']=ltrim($_GET ['t202b'],'$');
    if(is_numeric ( $_GET ['t202b'] )){
        $bid = number_format ( $_GET ['t202b'], 5, '.', '' );
        $mysql ['click_cpc'] = $db->real_escape_string ( $bid );
    }
    else{
        $mysql ['click_cpc'] = $db->real_escape_string ( $tracker_row ['click_cpc'] );
    }
} else
    $mysql ['click_cpc'] = $db->real_escape_string ( $tracker_row ['click_cpc'] );
$mysql['click_payout'] = $db->real_escape_string($tracker_row['aff_campaign_payout']);
$mysql['click_time'] = time();
$mysql['landing_page_id'] = $db->real_escape_string($tracker_row['landing_page_id']);
$mysql['text_ad_id'] = $db->real_escape_string($tracker_row['text_ad_id']);

/* ok, if $_GET['OVRAW'] that is a yahoo keyword, if on the REFER, there is a $_GET['q], that is a GOOGLE keyword... */
//so this is going to check the REFERER URL, for a ?q=, which is the ACUTAL KEYWORD searched.
$referer_url_parsed = @parse_url($_GET['referer']);
$referer_url_query = $referer_url_parsed['query'];
 
@parse_str($referer_url_query, $referer_query);

switch ($user_row['user_keyword_searched_or_bidded']) { 
	
	case "bidded":
	      #try to get the bidded keyword first
		if ($_GET['OVKEY']) { //if this is a Y! keyword
			$keyword = $db->real_escape_string($_GET['OVKEY']);   
		} elseif ($_GET['t202kw']) { 
			$keyword = $db->real_escape_string($_GET['t202kw']);  
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

if (substr($keyword, 0, 8) == 't202var_') {
	$t202var = substr($keyword, strpos($keyword, "_") + 1);

	if (isset($_GET[$t202var])) {
		$keyword = $_GET[$t202var];
	}
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

$mysql['gclid']= $db->real_escape_string($_GET['gclid']);


$custom_var_ids = array();

$ppc_variable_ids = explode(',', $tracker_row['ppc_variable_ids']);
$parameters = explode(',', $tracker_row['parameters']);

foreach ($parameters as $key => $value) {
	$variable = $db->real_escape_string($_GET[$value]);

	if (isset($variable) && $variable != '') {
		$variable = str_replace('%20',' ',$variable);
		$variable_id = INDEXES::get_variable_id($db, $variable, $ppc_variable_ids[$key]);
		$custom_var_ids[] = $variable_id;
	} 
}

//utm_source
$utm_source = $db->real_escape_string($_GET['utm_source']);
if(isset($utm_source) && $utm_source != '')
{
    $utm_source = str_replace('%20',' ',$utm_source);
    $utm_source_id = INDEXES::get_utm_id($db, $utm_source, 'utm_source');
}
else{
    $utm_source_id=0;
}
$mysql['utm_source_id']=$db->real_escape_string($utm_source_id);

//utm_medium
$utm_medium = $db->real_escape_string($_GET['utm_medium']);
if(isset($utm_medium) && $utm_medium != '')
{
    $utm_medium = str_replace('%20',' ',$utm_medium);
    $utm_medium_id = INDEXES::get_utm_id($db, $utm_medium, 'utm_medium');
}
else{
    $utm_medium_id=0;
}
$mysql['utm_medium_id']=$db->real_escape_string($utm_medium_id);

//utm_campaign
$utm_campaign = $db->real_escape_string($_GET['utm_campaign']);
if(isset($utm_campaign) && $utm_campaign != '')
{
    $utm_campaign = str_replace('%20',' ',$utm_campaign);
    $utm_campaign_id = INDEXES::get_utm_id($db, $utm_campaign, 'utm_campaign');
}
else{
    $utm_campaign_id=0;
}
$mysql['utm_campaign_id']=$db->real_escape_string($utm_campaign_id);

//utm_term
$utm_term = $db->real_escape_string($_GET['utm_term']);
if(isset($utm_term) && $utm_term != '')
{
    $utm_term = str_replace('%20',' ',$utm_term);
    $utm_term_id = INDEXES::get_utm_id($db, $utm_term, 'utm_term');
}
else{
    $utm_term_id=0;
}
$mysql['utm_term_id']=$db->real_escape_string($utm_term_id);

//utm_content
$utm_content = $db->real_escape_string($_GET['utm_content']);
if(isset($utm_content) && $utm_content != '')
{
    $utm_content = str_replace('%20',' ',$utm_content);
    $utm_content_id = INDEXES::get_utm_id($db, $utm_content, 'utm_content');
}
else{
    $utm_content_id=0;
}
$mysql['utm_content_id']=$db->real_escape_string($utm_content_id);

$ip_id = INDEXES::get_ip_id($db, $_SERVER['HTTP_X_FORWARDED_FOR']);
$mysql['ip_id'] = $db->real_escape_string($ip_id);     

//$device_id = PLATFORMS::get_device_info($db);
$device_id = PLATFORMS::get_device_info($db,$detect,$_GET['ua']);
$mysql['platform_id'] = $db->real_escape_string($device_id['platform']); 
$mysql['browser_id'] = $db->real_escape_string($device_id['browser']);
$mysql['device_id'] = $db->real_escape_string($device_id['device']);

if ($device_id['type'] == '4') {
	$mysql['click_bot'] = '1';
}

$mysql['click_in'] = 1;
$mysql['click_out'] = 0;

// if user wants to use t202ref from url variable use that first if it's not set try and get it from the ref url
if ($user_row['user_pref_referer_data'] == 't202ref') {
    if (isset($_GET['t202ref']) && $_GET['t202ref'] != '') { //check for t202ref value
        $mysql['t202ref']= $db->real_escape_string($_GET['t202ref']);
        $click_referer_site_url_id = INDEXES::get_site_url_id($db, $mysql['t202ref']);
    } else { //if not found revert to what we usually do
        if ($referer_query['url']) {
            $click_referer_site_url_id = INDEXES::get_site_url_id($db, $referer_query['url']);
        } else {
            $click_referer_site_url_id = INDEXES::get_site_url_id($db, $_GET['referer']);
        }
    }
} else { //user wants the real referer first

    // now lets get variables for clicks site
    // so this is going to check the REFERER URL, for a ?url=, which is the ACUTAL URL, instead of the google content, pagead2.google....
    if ($referer_query['url']) {
        $click_referer_site_url_id = INDEXES::get_site_url_id($db, $referer_query['url']);
    } else {
        $click_referer_site_url_id = INDEXES::get_site_url_id($db, $_GET['referer']);
    }
}

$mysql['click_referer_site_url_id'] = $db->real_escape_string($click_referer_site_url_id); 


 //see if this click should be filtered
$ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
$user_id = $tracker_row['user_id'];

//GEO Lookup
$GeoData = getGeoData($ip_address);

$country_id = INDEXES::get_country_id($db, $GeoData['country'], $GeoData['country_code']);
$mysql['country_id'] = $db->real_escape_string($country_id);

$region_id = INDEXES::get_region_id($db, $GeoData['region'], $mysql['country_id']);
$mysql['region_id'] = $db->real_escape_string($region_id);

$city_id = INDEXES::get_city_id($db, $GeoData['city'], $mysql['country_id']);
$mysql['city_id'] = $db->real_escape_string($city_id);


if ($user_row['maxmind_isp'] == '1') {
	$IspData = getIspData($ip_address);
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
$click_sql = "INSERT INTO   202_clicks_counter SET click_id=DEFAULT"; 
$click_result = $db->query($click_sql) or record_mysql_error($click_sql);

//now gather the info for the advance click insert
$click_id = $db->insert_id;
$mysql['click_id'] = $db->real_escape_string($click_id);
$click_id_public = rand(1,9) . $click_id . rand(1,9);
$mysql['click_id_public'] = $db->real_escape_string($click_id_public);

//because this is a simple landing page, set click_alp (which stands for click advanced landing page, equal to 0)
$mysql['click_alp'] = 1;

//ok we have the main data, now insert this row
$click_sql = "INSERT INTO   202_clicks
			  SET           	click_id='".$mysql['click_id']."',
							user_id = '".$mysql['user_id']."',   
							landing_page_id='".$mysql['landing_page_id']."',
							ppc_account_id = '".$mysql['ppc_account_id']."',   
							click_cpc = '".$mysql['click_cpc']."',   
							click_payout = '".$mysql['click_payout']."',   
							click_filtered = '".$mysql['click_filtered']."',
							click_bot = '".$mysql['click_bot']."',   
							click_alp = '".$mysql['click_alp']."',   
							click_time = '".$mysql['click_time']."'"; 
$click_result = $db->query($click_sql) or record_mysql_error($click_sql);


$total_vars = count($custom_var_ids);

if ($total_vars > 0) {

	$variables = implode (",", $custom_var_ids);
	$variable_set_id = INDEXES::get_variable_set_id($db, $variables);

	$mysql['variable_set_id'] = $db->real_escape_string($variable_set_id);  

	$var_sql = "INSERT INTO 202_clicks_variable (click_id, variable_set_id) VALUES ('".$mysql['click_id']."', '".$mysql['variable_set_id']."')";
	$var_result = $db->query($var_sql) or record_mysql_error($db, $var_sql);
} else {
	$var_sql = "INSERT INTO 202_clicks_variable (click_id, variable_set_id) VALUES ('".$mysql['click_id']."',0)";
	$var_result = $db->query($var_sql) or record_mysql_error($db, $var_sql);
}


// insert gclid and utm vars
$click_sql = "INSERT INTO   202_google
			  SET           	click_id='".$mysql['click_id']."',
							gclid = '".$mysql['gclid']."',
                            utm_source_id = '".$mysql['utm_source_id']."',
                            utm_medium_id = '".$mysql['utm_medium_id']."',
utm_campaign_id = '".$mysql['utm_campaign_id']."',
utm_term_id = '".$mysql['utm_term_id']."',
utm_content_id = '".$mysql['utm_content_id']."'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);

//ok we have the main data, now insert this row
$click_sql = "INSERT INTO  202_clicks_spy
			  SET          	 	click_id='".$mysql['click_id']."',
							user_id = '".$mysql['user_id']."',   
							landing_page_id='".$mysql['landing_page_id']."',
							ppc_account_id = '".$mysql['ppc_account_id']."',   
							click_cpc = '".$mysql['click_cpc']."',   
							click_payout = '".$mysql['click_payout']."',   
							click_filtered = '".$mysql['click_filtered']."',
							click_bot = '".$mysql['click_bot']."',   
							click_alp = '".$mysql['click_alp']."',   
							click_time = '".$mysql['click_time']."'"; 
$click_result = $db->query($click_sql) or record_mysql_error($click_sql);

//now we have the click's advance data, now insert this row
$click_sql = "INSERT INTO  202_clicks_advance
			  SET           	click_id='".$mysql['click_id']."',
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
$click_result = $db->query($click_sql) or record_mysql_error($click_sql);   

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
$click_result = $db->query($click_sql) or record_mysql_error($click_sql);

if (!$tracker_row['click_cloaking']) { 
	$mysql['click_cloaking'] = -1; 
} else {
	$mysql['click_cloaking'] = $db->real_escape_string($tracker_row['click_cloaking']); 	
}


//ok we have our click recorded table, now lets insert theses
$click_sql = "INSERT INTO   202_clicks_record
			  SET           	click_id='".$mysql['click_id']."',
							click_id_public='".$mysql['click_id_public']."',
							click_cloaking='".$mysql['click_cloaking']."',
							click_in='".$mysql['click_in']."',
							click_out='".$mysql['click_out']."'"; 
$click_result = $db->query($click_sql) or record_mysql_error($click_sql);
							


$landing_site_url = $_SERVER['HTTP_REFERER'];
$click_landing_site_url_id = INDEXES::get_site_url_id($db, $landing_site_url); 
$mysql['click_landing_site_url_id'] = $db->real_escape_string($click_landing_site_url_id);

$old_lp_site_url = 'http://'.$_SERVER['REDIRECT_SERVER_NAME'].'/lp/'.$landing_page_id_public;  

//insert this
$click_sql = "INSERT INTO   202_clicks_site
			  SET           	click_id='".$mysql['click_id']."',
							click_referer_site_url_id='".$mysql['click_referer_site_url_id']."',
							click_landing_site_url_id='".$mysql['click_landing_site_url_id']."',
							click_outbound_site_url_id='".$mysql['click_outbound_site_url_id']."',
							click_cloaking_site_url_id='".$mysql['click_cloaking_site_url_id']."',
							click_redirect_site_url_id='".$mysql['click_redirect_site_url_id']."'";
$click_result = $db->query($click_sql) or record_mysql_error($click_sql); 


//set the cookie
setClickIdCookie($mysql['click_id'],$mysql['aff_campaign_id']);
//set the PCI Cookie
setPCIdCookie($mysql['click_id_public']);

//set dirty hour
$de = new DataEngine();
$data=($de->setDirtyHour($mysql['click_id']));

if (isset($_COOKIE['p202_ipx'])) {
	$mysql['p202_ipx'] = $db->real_escape_string($_COOKIE['p202_ipx']);
	$db->query("UPDATE 202_clicks_impressions SET click_id = '".$mysql['click_id']."' WHERE impression_id = '".$mysql['p202_ipx']."'");
}

?> 


function t202initB() { 

	var subid ='<?php echo $click_id; ?>';
	createCookie('tracking202subid',subid,0);
	
	var pci = '<?php echo $click_id_public; ?>';
	createCookie('tracking202pci',pci,0);

}

t202initB(); 