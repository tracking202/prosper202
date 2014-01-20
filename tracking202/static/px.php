<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect2.php'); 
	
//get the aff_camapaign_id
$mysql['aff_campaign_id_public'] = mysql_real_escape_string($_GET['acip']);
$aff_campaign_sql = "SELECT user_id FROM 202_aff_campaigns WHERE aff_campaign_id_public='".$mysql['aff_campaign_id_public']."'";
$aff_campaign_row =  memcache_mysql_fetch_assoc($aff_campaign_sql);
$mysql['user_id'] = mysql_real_escape_string($aff_campaign_row['user_id']);



//see if it has the cookie, do whatever we can to grab to grab SOMETHING to tie this lead to
if ($_COOKIE['tracking202subid']) {  

	$mysql['click_id'] = mysql_real_escape_string($_COOKIE['tracking202subid']);
	
} else  {

	//ok grab the last click from this ip_id
	$mysql['ip_address'] = mysql_real_escape_string($_SERVER['REMOTE_ADDR']);
	$daysago = time() - 2592000; // 30 days ago
	$click_sql1 = "	SELECT 	202_clicks.click_id 
					FROM 		202_clicks
					LEFT JOIN	202_clicks_advance USING (click_id)
					LEFT JOIN 	202_ips USING (ip_id) 
					WHERE 	202_ips.ip_address='".$mysql['ip_address']."'
					AND		202_clicks.user_id='".$mysql['user_id']."'  
					AND		202_clicks.click_time >= '".$daysago."'
					ORDER BY 	202_clicks.click_id DESC 
					LIMIT 		1";
	$click_result1 = mysql_query($click_sql1) or record_mysql_error($click_sql1);
	$click_row1 = mysql_fetch_assoc($click_result1);
	$mysql['click_id'] = mysql_real_escape_string($click_row1['click_id']);

}



if ($mysql['click_id']) { 

	//ok now update and fire the pixel tracking
	$click_sql = "UPDATE 202_clicks SET click_lead='1', click_filtered='0'  WHERE click_id='".$mysql['click_id']."' ";
	delay_sql($click_sql);
	
	$click_sql = "UPDATE 202_clicks_spy SET click_lead='1', click_filtered='0' WHERE click_id='".$mysql['click_id']."' ";
	delay_sql($click_sql); 

}
