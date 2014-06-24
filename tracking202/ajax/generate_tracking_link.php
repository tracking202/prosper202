<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
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
		if($_POST['text_ad_id'] == '') { $error['text_ad_id'] = '<div class="error"><small><span class="fui-alert"></span> WARNING: This tracking link is not attached to any text ad, are you sure you want to do this?</small></div>'; }
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
	
	$click_cpc = $_POST['cpc_dollars'] . '.' . $_POST['cpc_cents'];
	$mysql['click_cpc'] = $db->real_escape_string($click_cpc);
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
	$mysql['text_ad_id'] = $db->real_escape_string($_POST['text_ad_id']); 
	$mysql['ppc_account_id'] = $db->real_escape_string($_POST['ppc_account_id']); 
	$mysql['click_cloaking'] = $db->real_escape_string($_POST['click_cloaking']); 
	$mysql['landing_page_id'] = $db->real_escape_string($landing_page_row['landing_page_id']);
	$mysql['rotator_id'] = $db->real_escape_string($_POST['tracker_rotator']);
	$mysql['tracker_time'] = time();
	
	$tracker_sql = "INSERT INTO `202_trackers`
					SET			`user_id`='".$mysql['user_id']."',
								`aff_campaign_id`='".$mysql['aff_campaign_id']."',
								`text_ad_id`='".$mysql['text_ad_id']."',
								`ppc_account_id`='".$mysql['ppc_account_id']."',
								`click_cpc`='".$mysql['click_cpc']."',
								`landing_page_id`='".$mysql['landing_page_id']."',
								`rotator_id`='".$mysql['rotator_id']."',
								`click_cloaking`='".$mysql['click_cloaking']."',
								`tracker_time`='".$mysql['tracker_time']."'";
	$tracker_result = $db->query($tracker_sql) or record_mysql_error($tracker_sql);
	
	$tracker_row['tracker_id'] = $db->insert_id;
	$tracker_id_public = rand(1,9) . $tracker_row['tracker_id'] . rand(1,9);
	$mysql['tracker_id_public'] = $db->real_escape_string($tracker_id_public);
	$mysql['tracker_id'] = $db->real_escape_string($tracker_row['tracker_id']);
	
	$tracker_sql = "UPDATE 		`202_trackers`
					SET			`tracker_id_public`='".$mysql['tracker_id_public']."'
					WHERE		`tracker_id`='".$mysql['tracker_id']."'"; 
	$tracker_result = $db->query($tracker_sql) or record_mysql_error($tracker_sql);
	
	$parsed_url = parse_url($landing_page_row['landing_page_url']);
	
	$html['c1'] = $db->real_escape_string($_POST['c1']);
	$html['c2'] = $db->real_escape_string($_POST['c2']);
	$html['c3'] = $db->real_escape_string($_POST['c3']);
	$html['c4'] = $db->real_escape_string($_POST['c4']);
	$tracking_variable_string = '&';
	if($html['c1']) {
		$tracking_variable_string .= 'c1=' . $html['c1'] . '&';
	}
	if($html['c2']) {
		$tracking_variable_string .= 'c2=' . $html['c2'] . '&';
	}
	if($html['c3']) {
		$tracking_variable_string .= 'c3=' . $html['c3'] . '&';
	}
	if($html['c4']) {
		$tracking_variable_string .= 'c4=' . $html['c4'] . '&';
	}
	
	
	?><small><em><u>Make sure you test out all the links to make sure they work yourself before running them live.</u></em></small><?
	
	
	if ($_POST['method_of_promotion'] == 'directlink') { 
		
		$destination_url = 'http://' . getTrackingDomain() . '/tracking202/redirect/dl.php?t202id=' . $tracker_id_public . $tracking_variable_string . 't202kw=';
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
		
		$destination_url = 'http://' . getTrackingDomain() . '/tracking202/redirect/rtr.php?t202id=' . $tracker_id_public . $tracking_variable_string . 't202kw=';
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
		$destination_url .= 't202kw=';
		
		
		 
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
	}   ?>


<br/><small><strong>Final Thoughts</strong></small><br/>
<span class="infotext">If you are confused about how to dynamically insert keywords into your url, here are some examples below:<br/>
<br/><ul>
    <li><strong>Bing Ads Example:</strong> &t202kw={keyword} </li>
    <li><strong>Google Adwords Example:</strong> &t202kw={keyword} - <a href="https://adwords.google.com/support/bin/answer.py?answer=74996&hl=en_US" target="_new">More Info</a></li>
</ul>

It is extremely important whenever you modify your PPC campaign, if you are to change your CPC on your bids for instance, you must update it with a new unique tracking202 destination URL. 
If you change your CPC and use a old destination URL, tracking202 will think the CPC is set to whatever, your last unique destination URL had its CPC set to. 

In most cases, for every text ad you use, you should have a unique tracking destination for that specific text ad.</span>
