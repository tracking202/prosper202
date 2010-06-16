<? 

#only allow numeric t202ids
$lpip = $_GET['lpip']; 
if (!is_numeric($lpip)) die();


#cached redirects stored here:
$myFile = "cached/lp-cached.csv";


# check to see if mysql connection works, if not fail over to cached .CSV stored redirect urls
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config.php'); 

$usedCachedRedirect = false;
$dbconnect = @mysql_connect($dbhost,$dbuser,$dbpass); 
if (!$dbconnect) {
	$usedCachedRedirect = true;
}
if ($usedCachedRedirect==false) {
	$dbselect = @mysql_select_db($dbname);
}
if (!$dbselect) {
	$usedCachedRedirect = true;
}

#the mysql server is down, use the txt cached redirect
if ($usedCachedRedirect==true) { 

	$handle = @fopen($myFile, 'r');
	while ($row = @fgetcsv($handle, 100000, ",")) {
		
		//if a cached key is found for this t202id, redirect to that url
		if ($row[0] == $lpip) { 
			header('location: '. $row[1]); 
			die();
		}
	}
	@fclose($handle);
	
	die("<h2>Error establishing a database connection - please contact the webhost</h2>");
}


include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

$mysql['landing_page_id_public'] = mysql_real_escape_string($lpip);
$tracker_sql = "SELECT 202_landing_pages.user_id,
						202_landing_pages.landing_page_id,
						202_landing_pages.landing_page_id_public,
						202_landing_pages.aff_campaign_id,
						202_aff_campaigns.aff_campaign_rotate,
						202_aff_campaigns.aff_campaign_url,
						202_aff_campaigns.aff_campaign_url_2,
						202_aff_campaigns.aff_campaign_url_3,
						202_aff_campaigns.aff_campaign_url_4,
						202_aff_campaigns.aff_campaign_url_5,
						202_aff_campaigns.aff_campaign_payout,
						202_aff_campaigns.aff_campaign_cloaking
				FROM    202_landing_pages, 202_aff_campaigns
				WHERE   202_landing_pages.landing_page_id_public='".$mysql['landing_page_id_public']."'
				AND     202_aff_campaigns.aff_campaign_id = 202_landing_pages.aff_campaign_id";
$tracker_row = memcache_mysql_fetch_assoc($tracker_sql);
											   
if (!$tracker_row) { die(); }



if ( is_writable(dirname(__FILE__) . '/cached' )) {

	#if the file does not exist create it
	if (!file_exists($myFile)) { 
		$handle = @fopen($myFile, 'w');
		@fclose($handle);
	} 
	
	# now save this link to the 
	$handle = @fopen($myFile, 'r');
	$writeNewIndex = true;
	while (($row = @fgetcsv($handle, 100000, ",")) and ($writeNewIndex == true)) {
		if ($row[0] == $lpip) $writeNewIndex = false;
	}
	@fclose($handle);
	
	if ($writeNewIndex) { 
		//write this index to the txt file
		$newLine = "$lpip, {$tracker_row['aff_campaign_url']} \n";
		$newHandle = @fopen($myFile, 'a+');
		@fwrite($newHandle, $newLine);
		@fclose($newHandle);
	}
}
 





//grab the GET variables from the LANDING PAGE
$landing_page_site_url_address_parsed = parse_url($_SERVER['HTTP_REFERER']);  
parse_str($landing_page_site_url_address_parsed['query'], $_GET);       

if ($_GET['t202id']) { 
	//grab tracker data if avaliable
	$mysql['tracker_id_public'] = mysql_real_escape_string($_GET['t202id']);

	$tracker_sql2 = "SELECT  text_ad_id,
							ppc_account_id,
							click_cpc,
							click_cloaking
					FROM    202_trackers
					WHERE   tracker_id_public='".$mysql['tracker_id_public']."'";   
	$tracker_row2 = memcache_mysql_fetch_assoc($tracker_sql2);
	if ($tracker_row2) {
		$tracker_row = array_merge($tracker_row,$tracker_row2);
	}
}

//INSERT THIS CLICK BELOW, if this click doesn't already exisit

//get mysql variables 
$mysql['user_id'] = mysql_real_escape_string($tracker_row['user_id']);
$mysql['aff_campaign_id'] = mysql_real_escape_string($tracker_row['aff_campaign_id']);
$mysql['ppc_account_id'] = mysql_real_escape_string($tracker_row['ppc_account_id']);
$mysql['click_cpc'] = mysql_real_escape_string($tracker_row['click_cpc']);
$mysql['click_payout'] = mysql_real_escape_string($tracker_row['aff_campaign_payout']);
$mysql['click_time'] = time();

$mysql['landing_page_id'] = mysql_real_escape_string($tracker_row['landing_page_id']);
$mysql['text_ad_id'] = mysql_real_escape_string($tracker_row['text_ad_id']);

//now gather variables for the clicks record db
//lets determine if cloaking is on
if (($tracker_row['click_cloaking'] == 1) or //if tracker has overrided cloaking on                                                             
	(($tracker_row['click_cloaking'] == -1) and ($tracker_row['aff_campaign_cloaking'] == 1)) or
	((!isset($tracker_row['click_cloaking'])) and ($tracker_row['aff_campaign_cloaking'] == 1)) //if no tracker but but by default campaign has cloaking on
) {
	$cloaking_on = true;
	$mysql['click_cloaking'] = 1;
	//if cloaking is on, add in a click_id_public, because we will be forwarding them to a cloaked /cl/xxxx link
	$click_id_public = rand(1,9) . $click_id . rand(1,9);
	$mysql['click_id_public'] = mysql_real_escape_string($click_id_public); 
} else { 
	$mysql['click_cloaking'] = 0; 
}

/*
//ok we have our click recorded table, now lets insert theses
$click_sql = "INSERT INTO   clicks_record
			  SET           click_id='".$mysql['click_id']."',
							click_id_public='".$mysql['click_id_public']."',
							click_cloaking='".$mysql['click_cloaking']."',
							click_in='".$mysql['click_in']."',
							click_out='".$mysql['click_out']."'"; 
$click_result = mysql_query($click_sql) or record_mysql_error($click_sql);
							
//now lets get variables for clicks site
$click_landing_site_url_id = INDEXES::get_site_url_id($_SERVER['HTTP_REFERER']);
$mysql['click_landing_site_url_id'] = mysql_real_escape_string($click_landing_site_url_id); 

$outbound_site_url = 'http://'.$_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
$click_outbound_site_url_id = INDEXES::get_site_url_id($outbound_site_url); 
$mysql['click_outbound_site_url_id'] = mysql_real_escape_string($click_outbound_site_url_id); 
 */
if ($cloaking_on == true) {

	$cloaking_site_url = 'http://'.$_SERVER['SERVER_NAME'] . '/tracking202/redirect/lpc.php?lpip=' . $tracker_row['landing_page_id_public'];
	$click_cloaking_site_url_id = INDEXES::get_site_url_id($cloaking_site_url); 
	$mysql['click_cloaking_site_url_id'] = mysql_real_escape_string($click_cloaking_site_url_id);         
	
}

$redirect_site_url = rotateTrackerUrl($tracker_row); 
$click_id = $_COOKIE['tracking202subid_a_'.$tracker_row['aff_campaign_id']];
$mysql['click_id'] = mysql_real_escape_string($click_id);
$mysql['click_out'] = 1;


$update_sql = "
	UPDATE
		202_clicks_record
	SET
		click_out='".$mysql['click_out']."',
		click_cloaking='".$mysql['click_cloaking']."'
	WHERE
		click_id='".$mysql['click_id']."'";
delay_sql($update_sql);

//$redirect_site_url = $redirect_site_url . $click_id;
$redirect_site_url = replaceTrackerPlaceholders($redirect_site_url,$mysql['click_id']);

$click_redirect_site_url_id = INDEXES::get_site_url_id($redirect_site_url); 
$mysql['click_redirect_site_url_id'] = mysql_real_escape_string($click_redirect_site_url_id);

//now we've recorded, now lets redirect them
if ($cloaking_on == true) {
	//if cloaked, redirect them to the cloaked site. 
	header('location: '.$cloaking_site_url);    
} else {
	header('location: '.$redirect_site_url);        
}