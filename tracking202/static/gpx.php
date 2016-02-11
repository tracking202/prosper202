<?php //write out a transparent 1x1 gif
header("content-type: image/gif"); 
header('Content-Length: 43');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT'); // Date in the past
header("Pragma: no-cache");
header('P3P: CP="Prosper202 does not have a P3P policy"');
echo base64_decode("R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==");

include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/connect2.php'); 
include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/class-dataengine-slim.php');

//get the aff_camapaign_id
$mysql['user_id'] = 1;
$mysql['click_id'] = 0;
$mysql['cid'] = 0;
$mysql['use_pixel_payout'] = 0;

//grab the cid
if(array_key_exists('cid',$_GET) && is_numeric($_GET['cid'])) {
	$mysql['cid']= $db->real_escape_string($_GET['cid']);
}
    
// grab the subid
if (array_key_exists('subid', $_GET) && is_numeric($_GET['subid'])) {
    $mysql['click_id'] = $db->real_escape_string($_GET['subid']);
} elseif (array_key_exists('sid', $_GET) && is_numeric($_GET['sid'])) {
    $mysql['click_id'] = $db->real_escape_string($_GET['sid']);
} else { // no subid found get from cookie or fingerprint
       
    // see if it has the cookie in the campaign id, then the general match, then do whatever we can to grab SOMETHING to tie this lead to
    if ($_COOKIE['tracking202subid_a_' . $mysql['cid']] && $mysql['cid'] != '0') {
        $mysql['click_id'] = $db->real_escape_string($_COOKIE['tracking202subid_a_' . $mysql['cid']]);
    } else 
        if ($_COOKIE['tracking202subid']) {
            $mysql['click_id'] = $db->real_escape_string($_COOKIE['tracking202subid']);
        } else {
            // ok grab the last click from this ip_id
            $mysql['ip_address'] = $db->real_escape_string($_SERVER['REMOTE_ADDR']);
            $daysago = time() - 2592000; // 30 days ago
            $click_sql1 = "	SELECT 	202_clicks.click_id
					FROM 		202_clicks
					LEFT JOIN	202_clicks_advance USING (click_id)
					LEFT JOIN 	202_ips USING (ip_id) 
					WHERE 	202_ips.ip_address='" . $mysql['ip_address'] . "'
					AND		202_clicks.user_id='" . $mysql['user_id'] . "'  
					AND		202_clicks.click_time >= '" . $daysago . "'
					ORDER BY 	202_clicks.click_id DESC 
					LIMIT 		1";
            
            $click_result1 = $db->query($click_sql1) or record_mysql_error($click_sql1);
            $click_row1 = $click_result1->fetch_assoc();
            
            $mysql['click_id'] = $db->real_escape_string($click_row1['click_id']);
            $mysql['ppc_account_id'] = $db->real_escape_string($click_row1['ppc_account_id']);
        }
}

if (is_numeric($mysql['click_id'])) {

	$cpa_sql = "SELECT 202_cpa_trackers.tracker_id_public, 202_trackers.click_cpa, 202_clicks.user_id, 202_clicks.aff_campaign_id, 202_clicks.click_lead, 202_clicks.click_time
				FROM 202_clicks
				LEFT JOIN 202_cpa_trackers USING (click_id) 
				LEFT JOIN 202_trackers USING (tracker_id_public)  
				WHERE click_id = '".$mysql['click_id']."'";
	$cpa_result = $db->query($cpa_sql);
	$cpa_row = $cpa_result->fetch_assoc();

	if (!$cpa_row['click_lead']) {

		$mysql['campaign_id'] = $db->real_escape_string($cpa_row['aff_campaign_id']);
		$mysql['click_user_id'] = $db->real_escape_string($cpa_row['user_id']);
		$mysql['click_time'] = $db->real_escape_string($cpa_row['click_time']);

		$conv_time = time();
		$click_time_to_date = new DateTime(date('Y-m-d h:i:s', $mysql['click_time']));
		$conv_time_to_date = new DateTime(date('Y-m-d h:i:s', $conv_time));
		$diff = $click_time_to_date->diff($conv_time_to_date);
		$mysql['time_difference'] =  $db->real_escape_string($diff->d.' days, '.$diff->h.' hours, '.$diff->i.' min and '.$diff->s.' sec');
		$mysql['conv_time'] = $db->real_escape_string($conv_time);
		$mysql['ip'] = $db->real_escape_string($_SERVER['HTTP_X_FORWARDED_FOR']);
		$mysql['user_agent'] = $db->real_escape_string($_SERVER['HTTP_USER_AGENT']);
		
		$mysql['click_cpa'] = $db->real_escape_string($cpa_row['click_cpa']);
	
		if ($mysql['click_cpa']) {
			$sql_set = "click_cpc='".$mysql['click_cpa']."', click_lead='1', click_filtered='0'";
		} else {
			$sql_set = "click_lead='1', click_filtered='0'";
		}

		if ($_GET['amount'] && is_numeric($_GET['amount'])) {
			$mysql['use_pixel_payout'] = 1;
			$mysql['click_payout'] = $db->real_escape_string($_GET['amount']);
		}
		
		$click_sql = "
			UPDATE
				202_clicks 
			SET
				".$sql_set."
		";
		if ($mysql['use_pixel_payout']==1) {
			$click_sql .= "
				, click_payout='".$mysql['click_payout']."'
			";
		}
		$click_sql .= "
			WHERE
				click_id='".$mysql['click_id']."'
		";
		$db->query($click_sql);

		$click_sql = "
			UPDATE
				202_clicks_spy 
			SET
				".$sql_set."
		";
		if ($mysql['use_pixel_payout']==1) {
			$click_sql .= "
				, click_payout='".$mysql['click_payout']."'
			";
		}
		$click_sql .= "
			WHERE
				click_id='".$mysql['click_id']."'
		";
		$db->query($click_sql);

		$log_sql = "INSERT INTO 202_conversion_logs
				SET conv_id = DEFAULT,
					click_id = '".$mysql['click_id']."',
					campaign_id = '".$mysql['campaign_id']."',
					user_id = '".$mysql['click_user_id']."',
					click_time = '".$mysql['click_time']."',
					conv_time = '".$mysql['conv_time']."',
					time_difference = '".$mysql['time_difference']."',
					ip = '".$mysql['ip']."',
					pixel_type = '1',
					user_agent = '".$mysql['user_agent']."'";
		$db->query($log_sql);

		//set dirty hour
		$de = new DataEngine();
		$data=($de->setDirtyHour($mysql['click_id']));
	}
}


