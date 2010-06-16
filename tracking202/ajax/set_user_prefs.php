<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

	AUTH::require_user();

//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') { 
    
	//start - update user user_preferences
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);   
		$mysql['user_pref_adv'] = mysql_real_escape_string($_POST['user_pref_adv']);
		$mysql['user_pref_ppc_network_id'] = mysql_real_escape_string($_POST['ppc_network_id']);
		$mysql['user_pref_ppc_account_id'] = mysql_real_escape_string($_POST['ppc_account_id']);  
		$mysql['user_pref_aff_network_id'] = mysql_real_escape_string($_POST['aff_network_id']);  
		$mysql['user_pref_aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);     
		$mysql['user_pref_text_ad_id'] = mysql_real_escape_string($_POST['text_ad_id']);  
		$mysql['user_pref_method_of_promotion'] = mysql_real_escape_string($_POST['method_of_promotion']);  
		$mysql['user_pref_landing_page_id'] = mysql_real_escape_string($_POST['landing_page_id']);  
		$mysql['user_pref_country_id'] = mysql_real_escape_string($_POST['country_id']);  
		$mysql['user_pref_ip'] = mysql_real_escape_string($_POST['ip']);  
		$mysql['user_pref_referer'] = mysql_real_escape_string($_POST['referer']);  
		$mysql['user_pref_keyword'] = mysql_real_escape_string($_POST['keyword']);  
		$mysql['user_pref_limit'] = mysql_real_escape_string($_POST['user_pref_limit']);
		$mysql['user_pref_breakdown'] = mysql_real_escape_string($_POST['user_pref_breakdown']);
		$mysql['user_pref_chart'] = mysql_real_escape_string($_POST['user_pref_chart']);
		$mysql['user_cpc_or_cpv'] = mysql_real_escape_string($_POST['user_cpc_or_cpv']);
		$mysql['user_pref_show'] = mysql_real_escape_string($_POST['user_pref_show']);
		if(is_array($_POST['details'])) {
			foreach($_POST['details'] AS $key=>$value) {
				$mysql['user_pref_group_'.($key+1)] = mysql_real_escape_string($value);
			}
		}
}

//predefined timelimit set, set the options
if ($_POST['user_pref_time_predefined'] != '') {
	switch($_POST['user_pref_time_predefined']) {
		case 'today';
		case 'yesterday';
		case 'last7';
        	case 'last14';
		case 'last30';
		case 'thismonth';
		case 'lastmonth';
        	case 'thisyear';
		case 'lastyear';
		case 'alltime';
		$clean['user_pref_time_predefined'] = $_POST['user_pref_time_predefined'];
		break;               
    }
    
	if (!isset($clean['user_pref_time_predefined'])) { $error['user_pref_time_predefined'] = '<div class="error">You choose an incorrect time user_preference</div>'; }
    
} else { 

	
	$from = explode('-', $_POST['from']); 
	$from = explode(':', $from[1]); 
	$from_hour = $from[0];
	$from_minute = $from[1];
	
	$from = explode('-', $_POST['from']); 
	$from = explode('/', $from[0]); 
      $from_month = trim($from[0]);
	$from_day = trim($from[1]);
	$from_year = trim($from[2]);

	$to = explode('-', $_POST['to']); 
	$to = explode(':', $to[1]); 
	$to_hour = $to[0];
	$to_minute = $to[1];
	
	$to = explode('-', $_POST['to']); 
    $to = explode('/', $to[0]); 
    $to_month = trim($to[0]);
    $to_day = trim($to[1]);
    $to_year = trim($to[2]);
    
    
    //if from or to, validate, and if validated, set it accordingly
	if (($from != '') and ((checkdate($from_month, $from_day, $from_year) == false) or (($from_hour < 0) or ($from_hour > 59) or (!is_numeric($from_hour)) or (($from_minute < 0) or ($from_minute > 59) or (!is_numeric($from_minute)))))) {
		$error['date'] = '<div class="error">Wrong date format, you must use the following military time format:   <strong>mm/dd/yyyy - hh:mms</strong></div>';     
	} else {
		$clean['user_pref_time_from'] = mktime($from_hour,$from_minute,0,$from_month,$from_day,$from_year);
	}                                                                                                                    
	
	if (($to != '') and ((checkdate($to_month, $to_day, $to_year) == false) or (($to_hour < 0) or ($to_hour > 59) or (!is_numeric($to_hour)) or (($to_minute < 0) or ($to_minute > 59) or (!is_numeric($to_minute)))))) {
		$error['date'] = '<div class="error">Wrong date format, you must use the following military time format:   <strong>mm/dd/yyyy - hh:mm</strong></div>';      
	} else {
		$clean['user_pref_time_to'] = mktime($to_hour,$to_minute,59,$to_month,$to_day,$to_year);  
    }     
}

echo $error['date'] . $error['user_pref_time_predefined'] .  $error['user_pref_limit'] . $error['user_pref_show'];    


if (!$error) {
    
	$mysql['user_pref_time_predefined'] = mysql_real_escape_string($clean['user_pref_time_predefined']);
	$mysql['user_pref_time_from'] = mysql_real_escape_string($clean['user_pref_time_from']);
	$mysql['user_pref_time_to'] = mysql_real_escape_string($clean['user_pref_time_to']);
	 
	$user_sql = "   UPDATE  `202_users_pref`
					SET     `user_pref_adv`='".$mysql['user_pref_adv']."',
							`user_pref_ppc_network_id`='".$mysql['user_pref_ppc_network_id']."',
							`user_pref_ppc_account_id`='".$mysql['user_pref_ppc_account_id']."',
							`user_pref_aff_network_id`='".$mysql['user_pref_aff_network_id']."',
							`user_pref_aff_campaign_id`='".$mysql['user_pref_aff_campaign_id']."',
							`user_pref_text_ad_id`='".$mysql['user_pref_text_ad_id']."',
							`user_pref_method_of_promotion`='".$mysql['user_pref_method_of_promotion']."',
							`user_pref_landing_page_id`='".$mysql['user_pref_landing_page_id']."',
							`user_pref_country_id`='".$mysql['user_pref_country_id']."',
							`user_pref_ip`='".$mysql['user_pref_ip']."',
							`user_pref_referer`='".$mysql['user_pref_referer']."',
							`user_pref_keyword`='".$mysql['user_pref_keyword']."',
							`user_pref_limit`='".$mysql['user_pref_limit']."',
							`user_pref_show`='".$mysql['user_pref_show']."',
							`user_pref_breakdown`='".$mysql['user_pref_breakdown']."',
							`user_pref_chart`='".$mysql['user_pref_chart']."',
							`user_cpc_or_cpv`='".$mysql['user_cpc_or_cpv']."',
							`user_pref_time_predefined`='".$mysql['user_pref_time_predefined']."',
							`user_pref_time_from`='".$mysql['user_pref_time_from']."',
							`user_pref_time_to`='".$mysql['user_pref_time_to']."',
							`user_pref_group_1`='".$mysql['user_pref_group_1']."',
							`user_pref_group_2`='".$mysql['user_pref_group_2']."',
							`user_pref_group_3`='".$mysql['user_pref_group_3']."',
							`user_pref_group_4`='".$mysql['user_pref_group_4']."'
					WHERE   `user_id`='".$mysql['user_id']."'"; 
	$user_result = mysql_query($user_sql) or record_mysql_error($user_sql);    
}