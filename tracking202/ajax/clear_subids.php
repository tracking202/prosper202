<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	
	if ($_POST['aff_network_id'] == 0) { $error['clear_subids'] = '<div class="error"><small><span class="fui-alert"></span>You have to at least select an affiliate network to clear out</small></div>'; }
	$mysql['aff_network_id'] = $db->real_escape_string($_POST['aff_network_id']);
	
	if ($error){ 
		echo $error['clear_subids'];  
		die();
	}
	
	
	if (!$error) { 

		$de = array();
		$de['ppc_account_id'] = 0;

		if ($_POST['aff_campaign_id'] != 0) {
			$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
			$click_sql = "
				UPDATE 202_clicks
				SET click_lead=0
				WHERE user_id='".$mysql['user_id']."'
				AND aff_campaign_id='".$mysql['aff_campaign_id']."'
			";
			$click_result = $db->query($click_sql);
			$clicks = $db->affected_rows;
			if ($clicks < 0 ) { $clicks = 0; }

			$de['aff_campaign_id'] = $mysql['aff_campaign_id'];

			$click_sql = "
				SELECT click_time
				FROM 202_clicks
				WHERE aff_campaign_id='".$mysql['aff_campaign_id']."
				LIMIT 1';
			";
			$click_result = $db->query($click_sql);
			$row = $click_result->fetch_assoc();
			$de['user_id'] = $mysql['user_id'];
			$de['click_time_from'] = $row['click_time'];
			$de['click_time_to'] = time();

		} else {
			
			$click_sql = "
				UPDATE 202_clicks AS 2c
				INNER JOIN 202_aff_campaigns AS 2ac ON (
					2c.aff_campaign_id = 2ac.aff_campaign_id
					AND 2ac.aff_network_id='".$mysql['aff_network_id']."'
				)
				SET click_lead=0
				WHERE 2c.user_id='".$mysql['user_id']."'
			";
			$click_result = $db->query($click_sql);
			$clicks = $db->affected_rows;
			if ($clicks < 0 ) { $clicks = 0; }

			$de['aff_campaign_id'] = 0;

			$click_sql = "
				SELECT 2c.click_time 
				FROM 202_clicks AS 2c
				INNER JOIN 202_aff_campaigns AS 2ac ON (
					2c.aff_campaign_id = 2ac.aff_campaign_id
					AND 2ac.aff_network_id='".$mysql['aff_network_id']."'
				)
				WHERE 2c.user_id='".$mysql['user_id']."'
			";
			$click_result = $db->query($click_sql);
			$row = $click_result->fetch_assoc();

			$de['user_id'] = $mysql['user_id'];
			$de['click_time_from'] = $row['click_time'];
			$de['click_time_to'] = time();
		}

		$dirty_hours_sql = "INSERT IGNORE INTO 
							202_dirty_hours 
							SET 
							ppc_account_id = '".$de['ppc_account_id']."', 
							aff_campaign_id = '".$de['aff_campaign_id']."',
							aff_network_id = '".$mysql['aff_network_id']."',
							user_id = '".$de['user_id']."',
							click_time_from = '".$de['click_time_from']."',
							click_time_to = '".$de['click_time_to']."'";

		if ($clicks) {
			$db->query($dirty_hours_sql);
		}

		echo "<div class=\"success\"><span class=\"fui-check-inverted\"></span><small>You have reset <strong>$clicks</strong> subids!<br/>You can now re-upload your subids.</small></div>";
		
	}