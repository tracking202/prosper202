<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

$slack = false;
$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$mysql['user_own_id'] = $db->real_escape_string($_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

//check variables
	if ($_POST['tracker_type'] == 0) { 

		if(empty($_POST['aff_network_id'])) { $error['aff_network_id'] = '<div class="error"><small><span class="fui-alert"></span> You have not selected an affiliate network.</small></div>'; }
		if(empty($_POST['aff_campaign_id'])) { $error['aff_campaign_id'] = '<div class="error"><small><span class="fui-alert"></span> You have not selected an affiliate campaign.</small></div>'; }
		if(empty($_POST['method_of_promotion'])) { $error['method_of_promotion'] = '<div class="error"><small><span class="fui-alert"></span> You have to select your method of promoting this affiliate link.</small></div>'; }
		
		echo $error['aff_network_id'] . $error['aff_campaign_id'] . $error['method_of_promotion'];
		
		if ($error) { die(); } 

	} else if($_POST['tracker_type'] == 2) {
		if(empty($_POST['tracker_rotator'])) { die('<div class="error"><small><span class="fui-alert"></span> You have not selected rotator.</small></div>'); }
	}
	
	//but we'll allow them to choose the following options, can make a tracker link without but they will be notified	
	if ($_POST['tracker_type'] != 2) {
		if($_POST['click_cloaking'] == '') { $error['click_cloaking'] = '<div class="error"><small><span class="fui-alert"></span> WARNING: This tracking link is not attached to any cloaking preference, are you sure you want to do this?</small></div>'; }
	}

	
	if ($_POST['ppc_network_id'] and !$_POST['ppc_account_id']) { 
		die('<div class="error"><small><span class="fui-alert"></span> ERROR: You have a traffic source selected, but YOU DO NOT HAVE A PPC ACCOUNT SELECTED.  In order to track your traffic-sources you must select a ppc-account. If you have not created one, go back to step #1 to add it now.</small></div>');
	}
	if($_POST['ppc_network_id'] == '') { $error['ppc_network_id'] = '<div class="error"><small><span class="fui-alert"></span> WARNING: This tracking link is not attached to any PPC network, are you sure you want to do this?</small></div>'; }
	if($_POST['ppc_account_id'] == '') { $error['ppc_account_id'] = '<div class="error"><small><span class="fui-alert"></span> WARNING: This tracking link is not attached to any PPC account, are you sure you want to do this?</small></div>'; }
	if((!is_numeric($_POST['cpc_dollars'])) or (!is_numeric($_POST['cpc_cents']))) { $error['cpc'] = '<div class="error"><small><span class="fui-alert"></span> WARNING: This tracking link does not have it\'s CPC set, are you sure you want to do this?</small></div>'; }

	//if they do a landing page, make sure they have one
	if ($_POST['method_of_promotion'] == 'landingpage') { 
		if (empty($_POST['landing_page_id'])) {
			$error['landing_page_id'] = '<div class="error"><small><span class="fui-alert"></span> You have not selected a landing page to use.</small></div>'; 
		}
		
		echo $error['landing_page_id']; 
		if ($error['landing_page_id']) { die(); }    
	}

//echo error
	echo $error['text_ad_id'] . $error['ppc_network_id'] . $error['ppc_account_id'] . $error['cpc'] . $error['click_cloaking'] . $error['cloaking_url'];

//show tracking code

	$mysql['landing_page_id'] = $db->real_escape_string($_POST['landing_page_id']);
	$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `landing_page_id`='".$mysql['landing_page_id']."'";
	$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = $landing_page_result->fetch_assoc();
	
	if ($_POST['cost_type'] == 'cpc') {
		$click_cpc = $_POST['cpc_dollars'] . '.' . $_POST['cpc_cents'];
		$mysql['click_cpc'] = $db->real_escape_string($click_cpc);
		$cost_sql = "`click_cpc`='".$mysql['click_cpc']."',";
	} else if ($_POST['cost_type'] == 'cpa') {
		$click_cpa = $_POST['cpa_dollars'] . '.' . $_POST['cpa_cents'];
		$mysql['click_cpa'] = $db->real_escape_string($click_cpa);
		$cost_sql = "`click_cpa`='".$mysql['click_cpa']."',";
	}

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
	$mysql['text_ad_id'] = $db->real_escape_string($_POST['text_ad_id']);
	$mysql['ppc_network_id'] = $db->real_escape_string($_POST['ppc_network_id']); 
	$mysql['ppc_account_id'] = $db->real_escape_string($_POST['ppc_account_id']); 
	$mysql['click_cloaking'] = $db->real_escape_string($_POST['click_cloaking']); 
	$mysql['landing_page_id'] = $db->real_escape_string($landing_page_row['landing_page_id']);
	$mysql['rotator_id'] = $db->real_escape_string($_POST['tracker_rotator']);
	$mysql['tracker_time'] = time();
	

	if ($_POST['edit_tracker'] && $_POST['tracker_id']) {
		$mysql['tracker_id_public'] = $db->real_escape_string($_POST['tracker_id']);
		$get_tracker_sql = "SELECT 
							tracker_id, 
							tracker_id_public,
							202_trackers.aff_campaign_id,
							text_ad_id,
							ppc_account_id,
							aff_network_name,
							aff_campaign_name,
							landing_page_nickname,
							202_trackers.landing_page_id,
							rotator_id,
							text_ad_name,
							ppc_network_name,
							ppc_account_name,
							click_cloaking,
							click_cpc,
							click_cpa,
							name 
							FROM 202_trackers
							LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
							LEFT JOIN 202_aff_networks USING (aff_network_id)
							LEFT JOIN 202_text_ads USING (text_ad_id)
							LEFT JOIN 202_ppc_accounts USING (ppc_account_id)
							LEFT JOIN 202_ppc_networks USING (ppc_network_id)
							LEFT JOIN 202_landing_pages ON (202_trackers.landing_page_id = 202_landing_pages.landing_page_id) 
							LEFT JOIN 202_rotators ON (202_trackers.rotator_id = 202_rotators.id)
							WHERE 202_trackers.tracker_id_public = '".$mysql['tracker_id_public']."' AND 202_trackers.user_id = '".$mysql['user_id']."'";
		
		$get_tracker_result = $db->query($get_tracker_sql);
		$get_tracker_row = $get_tracker_result->fetch_assoc();		

		if ($get_tracker_result->num_rows > 0) {
			$drop_tracker = "DELETE FROM 202_trackers WHERE tracker_id = '".$get_tracker_row['tracker_id']."'";
			$drop_tracker_result = $db->query($drop_tracker);
		}
	}

	$tracker_sql = "INSERT INTO `202_trackers`
					SET			`user_id`='".$mysql['user_id']."',
								`aff_campaign_id`='".$mysql['aff_campaign_id']."',
								`tracker_id_public`='0',
								`text_ad_id`='".$mysql['text_ad_id']."',
								`ppc_account_id`='".$mysql['ppc_account_id']."',
								".$cost_sql."
								`landing_page_id`='".$mysql['landing_page_id']."',
								`rotator_id`='".$mysql['rotator_id']."',
								`click_cloaking`='".$mysql['click_cloaking']."',
								`tracker_time`='".$mysql['tracker_time']."'";
	$tracker_result = $db->query($tracker_sql) or record_mysql_error($tracker_sql);
	
	$tracker_row['tracker_id'] = $db->insert_id;
	$mysql['tracker_id'] = $db->real_escape_string($tracker_row['tracker_id']);

	if ($_POST['edit_tracker'] && $_POST['tracker_id'] && $get_tracker_result->num_rows > 0) {
		$mysql['tracker_id_public'] = $db->real_escape_string($get_tracker_row['tracker_id_public']);
	} else {
		$tracker_id_public = rand(1,9) . $tracker_row['tracker_id'] . rand(1,9);
		$mysql['tracker_id_public'] = $db->real_escape_string($tracker_id_public);
	}
	
	$tracker_id_public = $mysql['tracker_id_public'];

	$tracker_sql = "UPDATE 		`202_trackers`
					SET			`tracker_id_public`='".$mysql['tracker_id_public']."'
					WHERE		`tracker_id`='".$mysql['tracker_id']."'"; 
	$tracker_result = $db->query($tracker_sql) or record_mysql_error($tracker_sql);

	$parsed_url = parse_url($landing_page_row['landing_page_url']);

	//setup array of all internally recognized url variables
	$t202variables = array(
	    "c1",
	    "c2",
	    "c3",
	    "c4",
	    "utm_source",
	    "utm_medium",
	    "utm_campaign",
	    "utm_term",
	    "utm_content",
	    "t202ref",
	    "t202b",
	    "t202kw"
	);
	
	$tracking_variable_string = '&';
	
	$get_variables = "SELECT * FROM 202_ppc_network_variables WHERE ppc_network_id = '".$mysql['ppc_network_id']."' AND deleted = 0";
	$get_variables_result = $db->query($get_variables);
	
	//loop over all our internal vars to see if user has set up a custom var in step 1
	if ($get_variables_result->num_rows > 0) {
	    while ($get_variables_row = $get_variables_result->fetch_assoc()) {

	        $key=array_search($get_variables_row['parameter'], $t202variables); // look for the current paramaeter in the list of internal url variables
	        
	        if($key===FALSE){ //if not found the output 
	             $tracking_variable_string .= $get_variables_row['parameter'].'=' . $get_variables_row['placeholder'] . '&';
	        }
	        else{ //if found save into our html array for later
	            $html[$t202variables[$key]]=$get_variables_row['placeholder'];
	        }
	       unset($key); //unset just in case old values get stuck
	        
	    }
	}

	//loop over all our internal variables again
    foreach ($t202variables as $key) {
        
        if (isset($_POST[$key]) && trim($_POST[$key]) != '') { //if there is a non empty value posted, then overwrite current value in html array with it 
            $html[$key] = $db->real_escape_string(trim($_POST[$key]));
        }
        if (isset($html[$key]) || $key=='t202kw')
            $tracking_variable_string .= $key . '=' . $html[$key] . '&'; //now write out the values/ but only if they are not empty with the exception of t202kw with we will write out no matter what
    }
    
    //remove & from end of the variable
    $tracking_variable_string=rtrim($tracking_variable_string,'&');


	if ($slack) {
		
		switch ($_POST['tracker_type']) {
			case '0':
				if ($_POST['method_of_promotion'] == 'directlink') {
						$tracker_type = 'Direct Link';
				} else if ($_POST['method_of_promotion'] == 'landingpage') {
						$tracker_type = 'Simple Landing Page';
				}
				break;
				
			case '1':
				$tracker_type = 'Advanced Landing Page';
				break;

			case '2':
				$tracker_type = 'Smart Rotator';
				break;	
		}

		if (!$_POST['edit_tracker']) {

			$slack->push('tracking_link_created', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'user' => $user_row['username']));

		} else if($_POST['edit_tracker'] && $_POST['tracker_id'] && $get_tracker_result->num_rows > 0) {

			if ($_POST['tracker_type'] == '0') {
				if ($_POST['aff_campaign_id'] != $get_tracker_row['aff_campaign_id']) {
					
					$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
					$sql = "SELECT aff_network_name, aff_campaign_name FROM 202_aff_campaigns LEFT JOIN 202_aff_networks USING (aff_network_id) WHERE aff_campaign_id = '".$mysql['aff_campaign_id']."'";
					$result = $db->query($sql);
					$row = $result->fetch_assoc();

					$slack->push('tracking_link_category_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_category' => $get_tracker_row['aff_network_name'], 'new_category' => $row['aff_network_name'], 'user' => $user_row['username']));
					$slack->push('tracking_link_campaign_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_campaign' => $get_tracker_row['aff_campaign_name'], 'new_campaign' => $row['aff_campaign_name'], 'user' => $user_row['username']));
				}

				if ($_POST['method_of_promotion'] == 'directlink') {
					if ($get_tracker_row['landing_page_id']) {
						$slack->push('tracking_link_method_of_promotion_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_method' => 'Landing Page', 'new_method' => 'Direct Link', 'user' => $user_row['username']));
					}
				}

				if ($_POST['method_of_promotion'] == 'landingpage') {
					if ($get_tracker_row['landing_page_id'] == 0) {
						$slack->push('tracking_link_method_of_promotion_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_method' => 'Direct Link', 'new_method' => 'Landing Page', 'user' => $user_row['username']));
					}
				}
			}

			if ($_POST['method_of_promotion'] == 'landingpage' || $_POST['tracker_type'] == '1') {
				if (($get_tracker_row['landing_page_id']) && $_POST['landing_page_id'] != $get_tracker_row['landing_page_id']) {
					
					$mysql['landing_page_id'] = $db->real_escape_string($_POST['landing_page_id']);
					$sql = "SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id = '".$mysql['landing_page_id']."'";
					$result = $db->query($sql);
					$row = $result->fetch_assoc();

					$slack->push('tracking_link_landing_page_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_lp' => $get_tracker_row['landing_page_nickname'], 'new_lp' => $row['landing_page_nickname'], 'user' => $user_row['username']));
				}
			}

			if ($_POST['tracker_type'] == '0' || $_POST['tracker_type'] == '1') {

				if (isset($_POST['text_ad_id']) && $get_tracker_row['text_ad_id']) {
					$mysql['text_ad_id'] = $db->real_escape_string($_POST['text_ad_id']);
					$sql = "SELECT text_ad_name FROM 202_text_ads WHERE text_ad_id = '".$mysql['text_ad_id']."'";
					$result = $db->query($sql);
					$row = $result->fetch_assoc();

					$slack->push('tracking_link_text_ad_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_ad' => $get_tracker_row['text_ad_name'], 'new_ad' => $row['text_ad_name'], 'user' => $user_row['username']));
				}

				if (isset($_POST['text_ad_id']) && !$get_tracker_row['text_ad_id']) {
					$mysql['text_ad_id'] = $db->real_escape_string($_POST['text_ad_id']);
					$sql = "SELECT text_ad_name FROM 202_text_ads WHERE text_ad_id = '".$mysql['text_ad_id']."'";
					$result = $db->query($sql);
					$row = $result->fetch_assoc();

					$slack->push('tracking_link_text_ad_added', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'ad' => $row['text_ad_name'], 'user' => $user_row['username']));
				}

				if (!$_POST['text_ad_id'] && $get_tracker_row['text_ad_id']) {
					
					$slack->push('tracking_link_text_ad_removed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'ad' => $get_tracker_row['text_ad_name'], 'user' => $user_row['username']));

				}

				if ($_POST['click_cloaking'] != $get_tracker_row['click_cloaking']) {
					if ($get_tracker_row['click_cloaking'] == '-1') {
						$from_type = 'Campaign Default On/Off';
					} else if ($get_tracker_row['click_cloaking'] == '0') {
						$from_type = 'Off - Overide Campaign Default';
					} else {
						$from_type = 'On - Overide Campaign Default';
					}

					if ($_POST['click_cloaking'] == '-1') {
						$to_type = 'Campaign Default On/Off';
					} else if ($_POST['click_cloaking'] == '0') {
						$to_type = 'Off - Overide Campaign Default';
					} else {
						$to_type = 'On - Overide Campaign Default';
					}

					$slack->push('tracking_link_cloaking_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_type' => $from_type, 'new_type' => $to_type, 'user' => $user_row['username']));
				}
			}

			if ($_POST['ppc_account_id'] != $get_tracker_row['ppc_account_id']) {
				$mysql['ppc_account_id'] = $db->real_escape_string($_POST['ppc_account_id']);
				$sql = "SELECT ppc_account_name, ppc_network_name FROM 202_ppc_accounts LEFT JOIN 202_ppc_networks USING (ppc_network_id) WHERE ppc_account_id = '".$mysql['ppc_account_id']."'";
				$result = $db->query($sql);
				$row = $result->fetch_assoc();

				$slack->push('tracking_link_pcc_network_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_source' => $get_tracker_row['ppc_network_name'], 'new_source' => $row['ppc_network_name'], 'user' => $user_row['username']));
				$slack->push('tracking_link_ppc_account_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_account' => $get_tracker_row['ppc_account_name'], 'new_account' => $row['ppc_account_name'], 'user' => $user_row['username']));
			}

			if ($_POST['cost_type'] == 'cpc') {
				if ($get_tracker_row['click_cpc'] == null) {
					$slack->push('tracking_link_cost_type_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_type' => 'CPA', 'new_type' => 'CPC', 'user' => $user_row['username']));
				} else {
					if ($click_cpc != $get_tracker_row['click_cpc']) {
						$slack->push('tracking_link_cost_value_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_value' => $get_tracker_row['click_cpc'], 'new_value' => $click_cpc, 'user' => $user_row['username']));
					}
				}

			} else if ($_POST['cost_type'] == 'cpa') {
				if ($get_tracker_row['click_cpa'] == null) {
					$slack->push('tracking_link_cost_type_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_type' => 'CPC', 'new_type' => 'CPA', 'user' => $user_row['username']));
				} else if ($click_cpa != $get_tracker_row['click_cpa']) {
					$slack->push('tracking_link_cost_value_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_value' => $get_tracker_row['click_cpa'], 'new_value' => $click_cpa, 'user' => $user_row['username']));
				}
			}

			if ($_POST['tracker_type'] == '2') {
				if ($_POST['tracker_rotator'] != $get_tracker_row['rotator_id']) {
					$mysql['rotator_id'] = $db->real_escape_string($_POST['tracker_rotator']);
					$sql = "SELECT name FROM 202_rotators WHERE id = '".$mysql['rotator_id']."'";
					$result = $db->query($sql);
					$row = $result->fetch_assoc();

					$slack->push('tracking_link_rotator_changed', array('type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_rotator' => $get_tracker_row['name'], 'new_rotator' => $row['name'], 'user' => $user_row['username']));
				}
			}
		}
	}
	

	?><?php if($_POST['edit_tracker'] && $_POST['tracker_id']) { ?><small
	class="success"><em><u>Tracker updated! Your tracking link stays the
			same.</u></em></small><?php } ?>
<br>
<small><em><u>Make sure you test out all the links to make sure they
			work yourself before running them live.</u></em></small><?php 	
	
	if ($_POST['method_of_promotion'] == 'directlink') { 
		
		$destination_url = 'http://' . getTrackingDomain() . get_absolute_url().'tracking202/redirect/dl.php?t202id=' . $tracker_id_public . $tracking_variable_string;
		$html['destination_url'] = htmlentities($destination_url, ENT_QUOTES, 'UTF-8');
		printf('<br></br><small><strong>Destination URL:</strong></small><br/>
            <span class="infotext">This is the destination URL you should use in your PPC campaigns. 
            This destination URL stores your above settings,
            so when someone goes through this destination URL we know the CPC, the PPC account, 
			the Ad Copy and everything else you have set above to this unique tracking destination URL.<br/>
			If you modify your PPC campaign from the above settings, 
			always make sure to update it with a new tracking202 destination.
            You should have a unique tracking202 destination URL for each different above configuration you use.<br/>
            In order to track keywords, make sure immediately following &t202kw= you insert your dynamic keyword.
            For example: &t202kw={keyword}</span><br></br>
            <textarea class="form-control" rows="2" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>',$html['destination_url']); 

	} 

	if ($_POST['tracker_type'] == 2) { 
		
		$destination_url = 'http://' . getTrackingDomain() . get_absolute_url().'tracking202/redirect/rtr.php?t202id=' . $tracker_id_public . $tracking_variable_string;
		$html['destination_url'] = htmlentities($destination_url, ENT_QUOTES, 'UTF-8');
		printf('<br></br><small><strong>Destination URL:</strong></small><br/>
            <span class="infotext">This is the destination URL you should use in your PPC campaigns. 
            This destination URL stores your above settings,
            so when someone goes through this destination URL we know the CPC, the PPC account, 
			the Ad Copy and everything else you have set above to this unique tracking destination URL.<br/>
			If you modify your PPC campaign from the above settings, 
			always make sure to update it with a new tracking202 destination.
            You should have a unique tracking202 destination URL for each different above configuration you use.<br/>
            In order to track keywords, make sure immediately following &t202kw= you insert your dynamic keyword.
            For example: &t202kw={keyword}</span><br></br>
            <textarea class="form-control" rows="2" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>',$html['destination_url']); 

	} 
	
	if (($_POST['method_of_promotion'] == 'landingpage') or ($_POST['tracker_type'] == 1)) {

		$destination_url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'] . '?';
		if (!empty($parsed_url['query'])) {
			$destination_url .= $parsed_url['query'] . '&';  ;
		}
		$destination_url .= 't202id=' . $tracker_id_public;
		if (!empty($parsed_url['fragment'])) {
			$destination_url .= '#' . $parsed_url['fragment'];
		}
		$destination_url .= $tracking_variable_string;
		
		 
		$html['destination_url'] = htmlentities($destination_url, ENT_QUOTES, 'UTF-8');
		printf('<br></br><small><strong>Destination URL:</strong></small><br/>
	            <span class="infotext">This is the destination URL you should use in your PPC campaigns. 
	            This destination URL stores your above settings,
	            so when someone goes through this destination URL we know the CPC, the PPC account, 
	            the Ad Copy and everything else you have set above to this unique tracking destination URL.<br/>
				If you modify your PPC campaign from the above settings, 
				always make sure to update it with a new tracking202 destination.
				You should have a unique tracking202 destination URL for each different above configuration you use.<br/>
				In order to track keywords, make sure immediately following &t202kw= you insert your dynamic keyword.
	            For example: &t202kw={keyword}</span><br></br>
	            <textarea class="form-control" rows="2" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>', $html['destination_url']);
	}   

	/*
	if ($_POST['tracker_type'] != 2) {
		$destination_url = 'http://' . getTrackingDomain() . get_absolute_url().'tracking202/static/ipx.php?t202id=' . $tracker_id_public;
		$html['destination_url'] = htmlentities($destination_url, ENT_QUOTES, 'UTF-8');
		printf('<br/><small><strong>Impression URL:</strong></small><br></br>
	            <textarea class="form-control" rows="2" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>', $html['destination_url']);

		printf('<br/><small><strong>Impression Pixel</strong></small><br></br>
	            <textarea class="form-control" rows="2" style="background-color: #f5f5f5; font-size: 12px;"><img height="1" width="1" border="0" style="display: none;" src="%s" /></textarea>', $html['destination_url']);
	}

	*/

	?>


<br />
<small><strong>Final Thoughts</strong></small>
<br />
<span class="infotext">If you are confused about how to dynamically
	insert keywords into your url, here are some examples below:<br /> <br />
<ul>
		<li><strong>Bing Ads Example:</strong> &t202kw={QueryString}</li>
		<li><strong>Google Adwords Example:</strong> &t202kw={keyword} - <a
			href="https://adwords.google.com/support/bin/answer.py?answer=74996&hl=en_US"
			target="_new">More Info</a></li>
	</ul> It is extremely important whenever you modify your PPC campaign,
	if you are to change your CPC on your bids for instance, you must
	update it with a new unique tracking202 destination URL. If you change
	your CPC and use a old destination URL, tracking202 will think the CPC
	is set to whatever, your last unique destination URL had its CPC set
	to. In most cases, for every text ad you use, you should have a unique
	tracking destination for that specific text ad.
</span>
