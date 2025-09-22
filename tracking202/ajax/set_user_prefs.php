<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

// Initialize error array
$error = [];
$clean = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') { 
    
	//start - update user user_preferences
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);   
		$mysql['user_pref_adv'] = $db->real_escape_string(isset($_POST['user_pref_adv']) ? (string)$_POST['user_pref_adv'] : '');
		$mysql['user_pref_ppc_network_id'] = $db->real_escape_string(isset($_POST['ppc_network_id']) ? (string)$_POST['ppc_network_id'] : '');
		$mysql['user_pref_ppc_account_id'] = $db->real_escape_string(isset($_POST['ppc_account_id']) ? (string)$_POST['ppc_account_id'] : '');  
		$mysql['user_pref_aff_network_id'] = $db->real_escape_string(isset($_POST['aff_network_id']) ? (string)$_POST['aff_network_id'] : '');  
		$mysql['user_pref_aff_campaign_id'] = $db->real_escape_string(isset($_POST['aff_campaign_id']) ? (string)$_POST['aff_campaign_id'] : '');     
		$mysql['user_pref_text_ad_id'] = $db->real_escape_string(isset($_POST['text_ad_id']) ? (string)$_POST['text_ad_id'] : '');  
		$mysql['user_pref_method_of_promotion'] = $db->real_escape_string(isset($_POST['method_of_promotion']) ? (string)$_POST['method_of_promotion'] : '');  
		$mysql['user_pref_landing_page_id'] = $db->real_escape_string(isset($_POST['landing_page_id']) ? (string)$_POST['landing_page_id'] : '');  
		$mysql['user_pref_country_id'] = $db->real_escape_string(isset($_POST['country_id']) ? (string)$_POST['country_id'] : '');
		$mysql['user_pref_region_id'] = $db->real_escape_string(isset($_POST['region_id']) ? (string)$_POST['region_id'] : '');
		$mysql['user_pref_isp_id'] = $db->real_escape_string(isset($_POST['isp_id']) ? (string)$_POST['isp_id'] : '');    
		$mysql['user_pref_ip'] = $db->real_escape_string(isset($_POST['ip']) ? (string)$_POST['ip'] : '');  
		$mysql['user_pref_ref'] = $db->real_escape_string(isset($_POST['referer']) ? (string)$_POST['referer'] : '');  
		$mysql['user_pref_keyword'] = $db->real_escape_string(isset($_POST['keyword']) ? (string)$_POST['keyword'] : '');  
		$mysql['user_pref_limit'] = $db->real_escape_string(isset($_POST['user_pref_limit']) ? (string)$_POST['user_pref_limit'] : '');
		$mysql['user_pref_breakdown'] = $db->real_escape_string(isset($_POST['user_pref_breakdown']) ? (string)$_POST['user_pref_breakdown'] : '');
		$mysql['user_cpc_or_cpv'] = $db->real_escape_string(isset($_POST['user_cpc_or_cpv']) ? (string)$_POST['user_cpc_or_cpv'] : '');
		$mysql['user_pref_show'] = $db->real_escape_string(isset($_POST['user_pref_show']) ? (string)$_POST['user_pref_show'] : '');
		$mysql['user_pref_device_id'] = $db->real_escape_string(isset($_POST['device_id']) ? (string)$_POST['device_id'] : '');
		$mysql['user_pref_browser_id'] = $db->real_escape_string(isset($_POST['browser_id']) ? (string)$_POST['browser_id'] : '');
		$mysql['user_pref_platform_id'] = $db->real_escape_string(isset($_POST['platform_id']) ? (string)$_POST['platform_id'] : '');  
		if(isset($_POST['details']) && is_array($_POST['details'])) {
			foreach($_POST['details'] AS $key=>$value) {
				$mysql['user_pref_group_'.($key+1)] = $db->real_escape_string($value);
			}
		}
}

//predefined timelimit set, set the options
if (isset($_POST['user_pref_time_predefined']) && $_POST['user_pref_time_predefined'] != '') {
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
	
	$from = isset($_POST['from']) ? explode('/', (string) $_POST['from']) : ['', '', '']; 
    $from_month = isset($from[0]) ? trim($from[0]) : '';
	$from_day = isset($from[1]) ? trim($from[1]) : '';
	$from_year = isset($from[2]) ? trim($from[2]) : '';

    $to = isset($_POST['to']) ? explode('/', (string) $_POST['to']) : ['', '', '']; 
    $to_month = isset($to[0]) ? trim($to[0]) : '';
    $to_day = isset($to[1]) ? trim($to[1]) : '';
    $to_year = isset($to[2]) ? trim($to[2]) : '';
    
    
    //if from or to, validate, and if validated, set it accordingly
	if (($from_month != '' && $from_day != '' && $from_year != '') and (checkdate((int)$from_month, (int)$from_day, (int)$from_year) == false)) {
		$error['date'] = '<div class="error">Wrong date format, you must use the following military time format:   <strong>mm/dd/yyyy - hh:mms</strong></div>';     
	} else if ($from_month != '' && $from_day != '' && $from_year != '') {
		$clean['user_pref_time_from'] = mktime(0,00,0,(int)$from_month,(int)$from_day,(int)$from_year);
	}                                                                                                                    
	
	if (($to_month != '' && $to_day != '' && $to_year != '') and (checkdate((int)$to_month, (int)$to_day, (int)$to_year) == false)) {
		$error['date'] = '<div class="error">Wrong date format, you must use the following military time format:   <strong>mm/dd/yyyy - hh:mm</strong></div>';      
	} else if ($to_month != '' && $to_day != '' && $to_year != '') {
		$clean['user_pref_time_to'] = mktime(23,59,59,(int)$to_month,(int)$to_day,(int)$to_year);  
    }     
}

echo ($error['date'] ?? '') . ($error['user_pref_time_predefined'] ?? '') .  ($error['user_pref_limit'] ?? '') . ($error['user_pref_show'] ?? '');    


if (empty($error) && $_SERVER['REQUEST_METHOD'] == 'POST') {
    
	$mysql['user_pref_time_predefined'] = $db->real_escape_string(isset($clean['user_pref_time_predefined']) ? (string)$clean['user_pref_time_predefined'] : '');
	$mysql['user_pref_time_from'] = $db->real_escape_string(isset($clean['user_pref_time_from']) ? (string)$clean['user_pref_time_from'] : '');
	$mysql['user_pref_time_to'] = $db->real_escape_string(isset($clean['user_pref_time_to']) ? (string)$clean['user_pref_time_to'] : '');
	 
	$user_sql = "   UPDATE  `202_users_pref`
					SET     `user_pref_adv`='".$mysql['user_pref_adv']."',";
							if (!isset($_POST['ppc_network_id']) || $_POST['ppc_network_id'] == '') {
								$user_sql .= "`user_pref_ppc_network_id`=null,";
							} else {
								$user_sql .= "`user_pref_ppc_network_id`='".$mysql['user_pref_ppc_network_id']."',";
							}	 
							$user_sql .= "`user_pref_ppc_account_id`='".$mysql['user_pref_ppc_account_id']."',
							`user_pref_aff_network_id`='".$mysql['user_pref_aff_network_id']."',
							`user_pref_aff_campaign_id`='".$mysql['user_pref_aff_campaign_id']."',
							`user_pref_text_ad_id`='".$mysql['user_pref_text_ad_id']."',
							`user_pref_method_of_promotion`='".$mysql['user_pref_method_of_promotion']."',
							`user_pref_landing_page_id`='".$mysql['user_pref_landing_page_id']."',
							`user_pref_country_id`='".$mysql['user_pref_country_id']."',
							`user_pref_region_id`='".$mysql['user_pref_region_id']."',
							`user_pref_isp_id`='".$mysql['user_pref_isp_id']."',
							`user_pref_ip`='".$mysql['user_pref_ip']."',
							`user_pref_referer`='".$mysql['user_pref_ref']."',
							`user_pref_keyword`='".$mysql['user_pref_keyword']."',
							`user_pref_limit`='".$mysql['user_pref_limit']."',
							`user_pref_show`='".$mysql['user_pref_show']."',
							`user_pref_breakdown`='".$mysql['user_pref_breakdown']."',
							`user_cpc_or_cpv`='".$mysql['user_cpc_or_cpv']."',
							`user_pref_time_predefined`='".$mysql['user_pref_time_predefined']."',
							`user_pref_time_from`='".$mysql['user_pref_time_from']."',
							`user_pref_time_to`='".$mysql['user_pref_time_to']."',
							`user_pref_group_1`='".($mysql['user_pref_group_1'] ?? '')."',
							`user_pref_group_2`='".($mysql['user_pref_group_2'] ?? '')."',
							`user_pref_group_3`='".($mysql['user_pref_group_3'] ?? '')."',
							`user_pref_group_4`='".($mysql['user_pref_group_4'] ?? '')."',
							`user_pref_device_id`='".$mysql['user_pref_device_id']."',
							`user_pref_browser_id`='".$mysql['user_pref_browser_id']."',
							`user_pref_platform_id`='".$mysql['user_pref_platform_id']."'
					WHERE   `user_id`='".$mysql['user_id']."'";
	$user_result = $db->query($user_sql) or record_mysql_error($user_sql);    
}