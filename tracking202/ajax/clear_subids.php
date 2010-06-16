<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();




	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	
	if ($_POST['aff_network_id'] == 0) { $error['clear_subids'] = '<div class="error">You have to at least select an affiliate network to clear out</div>'; }
	$mysql['aff_network_id'] = mysql_real_escape_string($_POST['aff_network_id']);
	
	if ($error){ 
		echo $error['clear_subids'];  
		die();
	}
	
	
	if (!$error) { 

		if ($_POST['aff_campaign_id'] != 0) { 
			$mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);
			$click_sql = "
				UPDATE 202_clicks
				SET click_lead=0
				WHERE user_id='".$mysql['user_id']."'
				AND aff_campaign_id='".$mysql['aff_campaign_id']."'
			";
			$click_result = mysql_query($click_sql);
			$clicks = mysql_affected_rows();
			if ($clicks < 0 ) { $clicks = 0; }
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
			$click_result = mysql_query($click_sql);
			$clicks = mysql_affected_rows();
			if ($clicks < 0 ) { $clicks = 0; }
		}
	
		echo "<div class=\"success\"><div><h3>You have reset <strong>$clicks</strong> subids!</h3>You can now re-upload your subids.</div></div>";
		
	}