<?php
include_once(str_repeat("../", 1).'202-config/connect.php');

AUTH::require_user();

$utc = new DateTimeZone('UTC');
$dt = new DateTime('now', $utc);

$slack = false;
$mysql['user_own_id'] = $db->real_escape_string($_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();
$username = $user_row['username'];

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

if (isset($_POST['add_rest_api_key'])) {
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['rest_api_key'] = $db->real_escape_string($_POST['rest_api_key']);
	$key_sql = "INSERT INTO 202_api_keys SET user_id='".$mysql['user_id']."', api_key = '".$mysql['rest_api_key']."', created_at='".time()."'";
	$key_result = $db->query($key_sql);

	if($slack)
		$slack->push('user_added_app_api_key', array('user' => $username));
	die();
}

if (isset($_POST['remove_rest_api_key'])) {
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['rest_api_key'] = $db->real_escape_string($_POST['rest_api_key']);
	$key_sql = "DELETE FROM 202_api_keys WHERE api_key='".$mysql['rest_api_key']."'";
	$key_result = $db->query($key_sql);

	if($slack)
		$slack->push('user_removed_app_api_key', array('user' => $username));

	die();
}

if ($_GET['customers_api_key']) {
	$mysql['p202_customer_api_key'] = $db->real_escape_string(base64_decode($_GET['customers_api_key']));
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_own_id']);
	$validate = validateCustomersApiKey($mysql['p202_customer_api_key']);
	if ($validate['code'] != 200) {
		$error['p202_customer_api_key_invalid'] = "API key is not valid. Check your key and try again!";
	}
	if (!$error) {
		$db->query("UPDATE 202_users SET p202_customer_api_key = '".$mysql['p202_customer_api_key']."' WHERE user_id = '".$mysql['user_id']."'");
		$change_p202_customer_api_key = true;
	}	
}

//if they want to remove their stats202 app key on file, do so
if ($_GET['remove_user_stats202_app_key']) {
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$sql = "UPDATE 202_users SET user_stats202_app_key='' WHERE user_id='".$mysql['user_id']."'";
	$result = $db->query($sql);
	$_SESSION['user_stats202_app_key'] = '';
	header('location: '.get_absolute_url().'202-account/account.php');
	die();
}

//if they want to remove their user api key on file, do so
if ($_GET['remove_user_api_key']) {
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$sql = "UPDATE 202_users SET user_api_key='' WHERE user_id='".$mysql['user_id']."'";
	$result = $db->query($sql);
	$_SESSION['user_api_key'] = '';
	$_SESSION['user_cirrus_link'] = '';
	header('location: '.get_absolute_url().'202-account/account.php');
	die();
}



//get all of the user data
if (!$userObj->hasPermission("access_to_personal_settings")) {
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_own_id']);
	$user_sql = "SELECT 	user_email
				 FROM   	`202_users` 
				 WHERE  	`user_id`='".$mysql['user_id']."'";
} else {
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT 	*
				 FROM   	`202_users` 
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='".$mysql['user_id']."'";
}

$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();
$html = array_map('htmlentities', $user_row);

//make it hide most of the api keys
$hideChars = 22;

if ($userObj->hasPermission("access_to_personal_settings")) {
	for ($x = 0; $x < $hideChars; $x++) $hiddenPart .= '*';
	if ($html['user_api_key']) $html['user_api_key'] = $hiddenPart . substr($html['user_api_key'], $hideChars, 99);
	if ($html['user_stats202_app_key']) $html['user_stats202_app_key'] = $hiddenPart . substr($html['user_stats202_app_key'], $hideChars, 99);
	if ($html['clickserver_api_key']) $html['clickserver_api_key'] = $hiddenPart . substr($html['clickserver_api_key'], $hideChars, 99);
}



if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if ($_POST['update_profile'] == '1') {

		if ($_POST['token'] != $_SESSION['token']){ $error['token'] = 'You must use our forms to submit data.';  }
		if (check_email_address($_POST['user_email']) == false) { $error['user_email'] = 'Please enter a valid email address'; }

		if ($userObj->hasPermission("access_to_personal_settings")) {
			//check user_email
			if (!$error['user_email_invalid']) {
				$mysql['user_email'] = $db->real_escape_string($_POST['user_email']);
				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
				$count_sql = "	SELECT 	*
							  	FROM  		`202_users` 
							  	WHERE 	`user_email` = '" . $mysql['user_email'] ."' 
								AND   		`user_id`!='".$mysql['user_id']."'
								AND user_deleted != 1";
				$count_result = $db->query($count_sql);
				if ($count_result->num_rows > 0) {
					$error['user_email'] .= 'That email address is already being used.';
				}
			}

			switch ($_POST['user_keyword_searched_or_bidded']) {

				case "searched":
				case "bidded":
					break;
				default:
					$error['user_keyword_searched_or_bidded'] = 'You must select your keyword preference.';
					break;
			}

			switch ($_POST['user_referer']) {
			
			    case "browser":
			    case "t202ref":
			        break;
			    default:
			        $error['user_referer'] = 'You must select your referer preference.';
			        break;
			}
			
			if (!$error) {

				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
				$mysql['user_timezone'] = $db->real_escape_string($_POST['user_timezone']);
				$mysql['user_daily_email'] = $db->real_escape_string($_POST['user_daily_email']);
				$mysql['cache_time'] = $db->real_escape_string($_POST['user_cached_reports']);
				$mysql['user_keyword_searched_or_bidded'] = $db->real_escape_string($_POST['user_keyword_searched_or_bidded']);
				$mysql['user_referer'] = $db->real_escape_string($_POST['user_referer']);
				$mysql['cloak_referer'] = $db->real_escape_string($_POST['cloak_referer']);
				$mysql['user_pref_ad_settings'] = $db->real_escape_string($_POST['user_pref_ad_settings']);
				$mysql['user_pref_dynamic_bid'] = $db->real_escape_string($_POST['user_bid']);
				$mysql['user_tracking_domain'] = $db->real_escape_string($_POST['user_tracking_domain']);
				$mysql['user_pref_privacy'] = $db->real_escape_string($_POST['user_pref_privacy']);

				$user_sql = "
					UPDATE
						`202_users` 
					SET
						`user_email`='".$mysql['user_email']."',
						`user_timezone`='".$mysql['user_timezone']."'
					WHERE
						`user_id`='".$mysql['user_id']."'
				";
				$user_result = $db->query($user_sql);

				$user_sql = "
					UPDATE
						`202_users_pref`
					SET
						`user_keyword_searched_or_bidded`='".$mysql['user_keyword_searched_or_bidded']."',
						`user_pref_referer_data`='".$mysql['user_referer']."',
						`user_tracking_domain`='".$mysql['user_tracking_domain']."',
						`cache_time`='".$mysql['cache_time']."',
						`user_pref_cloak_referer`='".$mysql['cloak_referer']."',
						`user_pref_dynamic_bid`='".$mysql['user_pref_dynamic_bid']."', 
						`user_pref_ad_settings`='".$mysql['user_pref_ad_settings']."',
						`user_pref_privacy`='".$mysql['user_pref_privacy']."',    
						`user_daily_email`='".$mysql['user_daily_email']."'
					WHERE
						`user_id`='".$mysql['user_id']."'
				";	

				$user_result = $db->query($user_sql);
				$update_profile = true;
				$_SESSION['user_pref_ad_settings'] = $mysql['user_pref_ad_settings'];
				registerDailyEmail($mysql['user_daily_email'], $mysql['user_timezone'], $html['install_hash']);
				
				//try to set non expiring cache for values that are used in redirects
				 if ($memcacheWorking) {
				        setCache(md5('user_id_'.$tid.systemHash()), $mysql['user_id'], 0);
				        setCache(md5('user_timezone_'.$tid.systemHash()), $mysql['user_timezone'], 0);
				        setCache(md5('user_keyword_searched_or_bidded_'.$tid.systemHash()), $mysql['user_keyword_searched_or_bidded'], 0);
				        setCache(md5('user_referer_'.$tid.systemHash()), $mysql['user_referer'], 0);
				        setCache(md5('cloak_referer_'.$tid.systemHash()), $mysql['cloak_referer'], 0);
				        setCache(md5('user_pref_dynamic_bid_'.$tid.systemHash()), $mysql['user_pref_dynamic_bid'], 0);
				        setCache(md5('user_pref_privacy_'.$tid.systemHash()), $mysql['user_pref_privacy'], 0);
				    } 
				}

				//set the  session's user_timezone
				$_SESSION['user_timezone'] = $_POST['user_timezone'];

				if ($slack) {
					if ($_POST['user_timezone'] != $user_row['user_timezone']) {
						$slack->push('user_time_zone_changed', array('user' => $username, 'old_zone' => $user_row['user_timezone'], 'new_zone' => $_POST['user_timezone']));
					}

					if ($_POST['user_keyword_searched_or_bidded'] != $user_row['user_keyword_searched_or_bidded']) {
						
						if ($user_row['user_keyword_searched_or_bidded'] == 'bidded') {
							$from_type = 'Pickup Bidded Keyword';
						} else {
							$from_type = 'Pickup Searched Keyword';
						}

						if ($_POST['user_referer'] == 't202ref') {
							$to_type = 'Pickup Bidded Keyword';
						} else {
							$to_type = 'Pickup Searched Keyword';
						}

						$slack->push('user_keyword_preference_changed', array('user' => $username, 'old_pref' => $from_type, 'new_pref' => $to_type));
					}

					if ($_POST['user_referer'] != $user_row['user_pref_referer_data']) {
						
						if ($user_row['user_pref_referer_data'] == 't202ref') {
							$from_type = 'Pickup Referer from t202ref variable';
						} else {
							$from_type = 'Pickup Referer from browser';
						}

						if ($_POST['user_referer'] == 't202ref') {
							$to_type = 'Pickup Referer from t202ref variable';
						} else {
							$to_type = 'Pickup Referer from browser';
						}

						$slack->push('user_referer_changed', array('user' => $username, 'old_pref' => $from_type, 'new_pref' => $to_type));
					}

					if ($_POST['cloak_referer'] != $user_row['user_pref_cloak_referer']) {
						
						if ($user_row['user_pref_cloak_referer'] == 'origin') {
							$from_type = 'Show Prosper202 Domain';
						} else {
							$from_type = 'Show Blank Referer';
						}

						if ($_POST['user_referer'] == 'origin') {
							$tom_type = 'Show Prosper202 Domain';
						} else {
							$to_type = 'Show Blank Referer';
						}

						$slack->push('user_pref_cloak_referer_changed', array('user' => $username, 'old_pref' => $from_type, 'new_pref' => $to_type));
					}

					if ($_POST['user_email'] != $user_row['user_email']) {
							$slack->push('user_email_changed', array('user' => $username, 'old_email' => $user_row['user_email'], 'new_email' => $_POST['user_email']));
					}
				}

			}
		} else {
			if (!$error['user_email_invalid']) {
				$mysql['user_email'] = $db->real_escape_string($_POST['user_email']);
				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_own_id']);
				$count_sql = "	SELECT 	*
							  	FROM  		`202_users` 
							  	WHERE 	`user_email` = '" . $mysql['user_email'] ."' 
							  	AND   		`user_id`!='".$mysql['user_id']."'";
				$count_result = $db->query($count_sql);
				if ($count_result->num_rows > 0) {
					$error['user_email'] .= 'That email address is already being used.';
				}

				if (!$error) {
					$mysql['user_id'] = $db->real_escape_string($_SESSION['user_own_id']);
					$mysql['user_email'] = $db->real_escape_string($_POST['user_email']);
					$sql = "UPDATE 202_users SET user_email = '".$mysql['user_email']."' WHERE user_id = '".$mysql['user_id']."'";
					$result = $db->query($sql);
					$update_profile = true;

					if ($slack)
						$slack->push('user_email_changed', array('user' => $username, 'old_email' => $user_row['user_email'], 'new_email' => $_POST['user_email']));
				}
			}
		}
	}

	if ($_POST['update_account_currency'] == '1') {
	    
		if ($_POST['token'] != $_SESSION['token']) { $error['token'] = 'You must use our forms to submit data.';  }
		$mysql['account_currency'] = $db->real_escape_string($_POST['account_currency']);

		if (!$error) {
			$user_sql = "
					UPDATE
						`202_users_pref`
					SET
						`user_account_currency`='".$mysql['account_currency']."'
					WHERE
						`user_id`='".$mysql['user_id']."'
				";	
			$user_result = $db->query($user_sql);
		}

		if ($user_row['user_account_currency'] != $_POST['account_currency']) {
			$sql = "SELECT aff_campaign_id, aff_campaign_payout, aff_campaign_currency, aff_campaign_foreign_payout FROM 202_aff_campaigns WHERE aff_campaign_deleted = 0";
			$result = $db->query($sql);
			if ($result->num_rows > 0) {
				while ($row = $result->fetch_assoc()) {

					if ($row['aff_campaign_foreign_payout'] == '0.00') {
						$payout = getForeignPayout($_POST['account_currency'], $row['aff_campaign_currency'], $row['aff_campaign_payout']);
						$db->query("UPDATE 202_aff_campaigns SET aff_campaign_foreign_payout = '".$row['aff_campaign_payout']."', aff_campaign_payout = '".$payout['exchange_payout']."'");
					} else {
						if ($_POST['account_currency'] == $row['aff_campaign_currency']) {
							$db->query("UPDATE 202_aff_campaigns SET aff_campaign_payout = '".$row['aff_campaign_foreign_payout']."', aff_campaign_foreign_payout = '0.00'");
						} else {
							$payout = getForeignPayout($_POST['account_currency'], $row['aff_campaign_currency'], $row['aff_campaign_foreign_payout']);
							$db->query("UPDATE 202_aff_campaigns SET aff_campaign_payout = '".$payout['exchange_payout']."'");
						}
					}
				}
			}
		}

		$update_profile = true;
	}

	if ($_POST['update_clickserver_api_key'] == '1') {

		if ($_POST['token'] != $_SESSION['token']) { $error['token'] = 'You must use our forms to submit data.';  }
		
		$mysql['clickserver_api_key'] = $db->real_escape_string($_POST['clickserver_api_key']);
		
		if (!preg_match('/\*/', $_POST['clickserver_api_key'])) {
			if (!clickserver_api_key_validate($mysql['clickserver_api_key']) && $mysql['clickserver_api_key'] !='') { $error['clickserver_api_key'] = 'This API Key appears invalid.'; }

			if (!$error || $mysql['clickserver_api_key'] =='') {
					
				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
				$mysql['clickserver_api_key'] = $db->real_escape_string($_POST['clickserver_api_key']);
				$user_sql = "	UPDATE 	`202_users`
								SET     		`clickserver_api_key`='".$mysql['clickserver_api_key']."'
								WHERE  	`user_id`='".$mysql['user_id']."'";
				$user_result = $db->query($user_sql);

				$update_clickserver_api_key_done = true;

				if($slack) {
					if ($_POST['clickserver_api_key'] != $user_row['clickserver_api_key']) {
						$slack->push('user_updated_clickserver_api_key', array('user' => $username));
					}
				}
					
			}
		}
	}

	if ($_POST['change_user_api_key'] == '1') {

		if ($_POST['token'] != $_SESSION['token']) { $error['token'] = 'You must use our forms to submit data.';  }

		if (!preg_match('/\*/', $_POST['user_api_key'])) {
			if (!AUTH::is_valid_api_key($_POST['user_api_key'])) { $error['user_api_key'] = 'This API Key appears invalid.'; }

			if (!$error) {
					
				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
				$mysql['user_api_key'] = $db->real_escape_string($_POST['user_api_key']);
				$user_sql = "	UPDATE 	`202_users`
								SET     		`user_api_key`='".$mysql['user_api_key']."'
								WHERE  	`user_id`='".$mysql['user_id']."'";
				$user_result = $db->query($user_sql);

				$change_api_key = true;
					
				//set the  session's user_api_key
				$_SESSION['user_api_key'] = $_POST['user_api_key'];
				$_SESSION['user_cirrus_link'] = $_POST['user_api_key'];
			}
		}
	}

	if ($_POST['change_user_stats202_app_key'] == '1') {
		if (!preg_match('/\*/', $_POST['user_stats202_app_key'])) {
			if (!AUTH::is_valid_app_key('stats202', $_SESSION['user_api_key'], $_POST['user_stats202_app_key'])) { $error['user_stats202_app_key'] = '<div class="error">This Tracking202 API Key &amp; Stats202 App Key combination appears invalid.</div>'; }

			if (!$error) {
					
				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
				$mysql['user_stats202_app_key'] = $db->real_escape_string($_POST['user_stats202_app_key']);
				$user_sql = "	UPDATE 	`202_users`
								SET     		`user_stats202_app_key`='".$mysql['user_stats202_app_key']."'
								WHERE  	`user_id`='".$mysql['user_id']."'";
				$user_result = $db->query($user_sql);
					
				$change_stats202_app_key = true;
					
				//set the  session's user_api_key
				$_SESSION['user_stats202_app_key'] = $_POST['user_stats202_app_key'];
			}
		}
	}

	if ($_POST['update_p202_customer_api_key'] == '1') {
		if ($_POST['token'] != $_SESSION['token']) { $error['token'] = 'You must use our forms to submit data.';  }
		$mysql['p202_customer_api_key'] = $db->real_escape_string($_POST['p202_customer_api_key']);
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_own_id']);
		$validate = validateCustomersApiKey($_POST['p202_customer_api_key']);
		if ($validate['code'] != 200 && $mysql['p202_customer_api_key'] != '') {
			$error['p202_customer_api_key_invalid'] = "API key is not valid. Check your key and try again!";
		}
		if (!$error) {
			$db->query("UPDATE 202_users SET p202_customer_api_key = '".$mysql['p202_customer_api_key']."' WHERE user_id = '".$mysql['user_id']."'");
			$change_p202_customer_api_key = true;
		}
	}

	if ($_POST['change_user_pass'] == '1') {
			
		//check token, and new user_pass
		if ($_POST['token'] != $_SESSION['token']){ $error['token'] = 'You must use our forms to submit data.';  }
		if ($_POST['new_user_pass']=='') { $error['user_pass'] = ' You must type in your desired password.'; }
		if ($_POST['retype_new_user_pass']=='') { $error['user_pass'] .= ' You must type verify your password.'; }
		if ((strlen($_POST['new_user_pass']) < 6) OR (strlen($_POST['new_user_pass']) > 35)) { $error['user_pass'] .= ' Your password must be between 6 and 35 characters long.'; }
		if ($_POST['new_user_pass'] != $_POST['retype_new_user_pass']) { $error['user_pass'] .= ' Your password did not match, please try again.'; }

		//check to to see if old user_pass is correct
		$user_pass = salt_user_pass($_POST['user_pass']);		
		$mysql['user_pass'] = $db->real_escape_string($user_pass);
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_own_id']);

		$user_sql = "	SELECT 	*
					FROM   		`202_users`
					WHERE   	`user_id`='".$mysql['user_id']."'
					AND     		`user_pass`='".$mysql['user_pass']."'"; 
		$user_result = $db->query($user_sql);

		if ($user_result->num_rows == 0) $error['user_pass'] .= 'Your old password was typed incorrectly.';

		//if no user_pass errors
		if (!$error) {

			$user_pass = salt_user_pass($_POST['new_user_pass']);
			$mysql['user_pass'] = $db->real_escape_string($user_pass);
			$mysql['user_id'] = $db->real_escape_string($_SESSION['user_own_id']);

			$user_sql = "	UPDATE 	`202_users`
							SET    		`user_pass`='".$mysql['user_pass']."'
							WHERE  	`user_id`='".$mysql['user_id']."'";
			$user_result = $db->query($user_sql);

			$change_user_pass = true;
		}

	}

	$html = array_merge($html, array_map('htmlentities', $_POST));




$html['user_id'] = htmlentities($_SESSION['user_id'], ENT_QUOTES, 'UTF-8');
$html['user_username'] = htmlentities($_SESSION['user_username'], ENT_QUOTES, 'UTF-8');


template_top('Personal Settings',NULL,NULL,NULL);  

if ( isset( $_SERVER["HTTPS"] ) && strtolower( $_SERVER["HTTPS"] ) == "on" ) {
$strProtocol = 'https://';
} else {
$strProtocol = 'http://';
}

//update new values from the db
$user_sql = "	SELECT 	*
				 FROM   	`202_users`
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='".$mysql['user_id']."'";
$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();
$html = array_map('htmlentities', $user_row);
?>
 
<div class="row account">
		<div class="col-xs-12">
			<div class="row">
				<div class="col-xs-4">
					<h6>My Account</h6>
				</div>
				<div class="col-xs-8">
					<?php if ($update_profile == true || $change_user_pass == true) { ?>
						<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> Your submission was successful. Your changes have been saved.</small></div>
					<?php } ?>

					<?php if ($update_clickserver_api_key_done) { ?>
						<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> You have updated your Prosper202 ClickServer API Key</small></div>
					<?php } ?>

					<?php if ($change_api_key) { ?>
						<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> You have updated your Tracking202 API Key</small></div>
					<?php } ?>

					<?php if ($removed_user_api_key) { ?>
						<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> You have removed your Tracking202 API Key</small></div>
					<?php } ?>

					<?php if ($error) { ?>
						<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['token'] . $error['user_email'] . $error['clickserver_api_key'] . $error['user_api_key'] . $error['user_pass'] . $error['p202_customer_api_key_invalid']; ?></small></div>
					<?php } ?>
					<?php if ($change_p202_customer_api_key) { ?>
						<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> Your submission was successful. Your Prosper202 customer API key have been saved.</small></div>
					<?php } ?>
				</div>
			</div>
		</div>
		<div class="col-xs-4">
			<div class="panel panel-default account_left">
			  <div class="panel-body">
			    Modify your account settings. Required fields marked with *
			  </div>
			</div>
		</div>

		<div class="col-xs-8">
			<form class="form-horizontal" style="padding-top:0px;" role="form" method="post" action="">
			<input type="hidden" name="update_profile" value="1" />
			<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
				<?php if($userObj->hasPermission("access_to_personal_settings")) { ?>
				<div class="form-group">
				    <label for="user_timezone" class="col-xs-4 control-label">* Time zone (GMT):</label>
				    <div class="col-xs-8">
				      <?php
						echo '<select class="form-control input-sm" name="user_timezone" id="user_timezone">';
						foreach(DateTimeZone::listIdentifiers() as $tz) {
						    $current_tz = new DateTimeZone($tz);
						    $offset =  $current_tz->getOffset($dt);
						    $transition =  $current_tz->getTransitions($dt->getTimestamp(), $dt->getTimestamp());
						    $abbr = $transition[0]['abbr'];

						    if ($html['user_timezone'] == $tz) {
						    	echo '<option selected="selected" value="' .$tz. '">' .$tz. ' [' .$abbr. ' '. formatOffset($offset). ']</option>';
						    }

						    echo '<option value="' .$tz. '">' .$tz. ' [' .$abbr. ' '. formatOffset($offset). ']</option>';
						}
						echo '</select>';
						?>
				    </div>
				</div>

				<div class="form-group">
				    <label for="user_daily_email" class="col-xs-4 control-label">Daily Email Report: </label>
				    <div class="col-xs-8">
				      <select class="form-control input-sm" id="user_daily_email" name="user_daily_email">
				        <option value="" <?php if ($html['user_daily_email'] == '') echo 'selected';?>>Never</option>
						<option value="00" <?php if ($html['user_daily_email'] == '00') echo 'selected';?>>12 AM</option>
						<option value="01" <?php if ($html['user_daily_email'] == '01') echo 'selected';?>>1  AM</option>
						<option value="02" <?php if ($html['user_daily_email'] == '02') echo 'selected';?>>2  AM</option>
						<option value="03" <?php if ($html['user_daily_email'] == '03') echo 'selected';?>>3  AM</option>
						<option value="04" <?php if ($html['user_daily_email'] == '04') echo 'selected';?>>4  AM</option>
						<option value="05" <?php if ($html['user_daily_email'] == '05') echo 'selected';?>>5 AM</option>
						<option value="06" <?php if ($html['user_daily_email'] == '06') echo 'selected';?>>6 AM</option>
						<option value="07" <?php if ($html['user_daily_email'] == '07') echo 'selected';?>>7 AM</option>
						<option value="08" <?php if ($html['user_daily_email'] == '08') echo 'selected';?>>8 AM</option>
						<option value="09" <?php if ($html['user_daily_email'] == '09') echo 'selected';?>>9 AM</option>
						<option value="10" <?php if ($html['user_daily_email'] == '10') echo 'selected';?>>10 AM</option>
						<option value="11" <?php if ($html['user_daily_email'] == '11') echo 'selected';?>>11 AM</option>
						<option value="12" <?php if ($html['user_daily_email'] == '12') echo 'selected';?>>12 PM</option>
						<option value="13" <?php if ($html['user_daily_email'] == '13') echo 'selected';?>>1 PM</option>
						<option value="14" <?php if ($html['user_daily_email'] == '14') echo 'selected';?>>2 PM</option>
						<option value="15" <?php if ($html['user_daily_email'] == '15') echo 'selected';?>>3 PM</option>
						<option value="16" <?php if ($html['user_daily_email'] == '16') echo 'selected';?>>4 PM</option>
						<option value="17" <?php if ($html['user_daily_email'] == '17') echo 'selected';?>>5 PM</option>
						<option value="18" <?php if ($html['user_daily_email'] == '18') echo 'selected';?>>6 PM</option>
						<option value="19" <?php if ($html['user_daily_email'] == '19') echo 'selected';?>>7 PM</option>
						<option value="20" <?php if ($html['user_daily_email'] == '20') echo 'selected';?>>8 PM</option>
						<option value="21" <?php if ($html['user_daily_email'] == '21') echo 'selected';?>>9 PM</option>
						<option value="22" <?php if ($html['user_daily_email'] == '22') echo 'selected';?>>10 PM</option>
						<option value="23" <?php if ($html['user_daily_email'] == '23') echo 'selected';?>>11 PM</option>
					  </select>
				    </div>
				</div>
				
				<div class="form-group">
				    <label for="user_keyword_searched_or_bidded" class="col-xs-4 control-label">* Keyword Preference:</label>
				    <div class="col-xs-8">
				    	<select class="form-control input-sm" name="user_keyword_searched_or_bidded" id="user_keyword_searched_or_bidded">
							<option
							<?php if ($html['user_keyword_searched_or_bidded'] == 'searched') { echo 'selected=""'; } ?>
								value="searched">Pickup Searched Keyword</option>
							<option
							<?php if ($html['user_keyword_searched_or_bidded'] == 'bidded') { echo 'selected=""'; } ?>
								value="bidded">Pickup Bidded Keyword</option>
						</select>
					</div>
				</div>
				<div class="form-group">
				    <label for="user_referer" class="col-xs-4 control-label">* Cost Data Preference:</label>
				    <div class="col-xs-8">
				    	<select class="form-control input-sm" name="user_bid" id="user_bid">
							<option
							<?php if ($html['user_pref_dynamic_bid'] == '0') { echo 'selected=""'; } ?>
								value="0">Pickup Bid from setup data</option>
							<option
							<?php if ($html['user_pref_dynamic_bid'] == '1') { echo 'selected=""'; } ?>
								value="1">Pickup Bid dynamically from t202b variable</option>
						</select>
					</div>
				</div>
				<div class="form-group">
				    <label for="user_referer" class="col-xs-4 control-label">* Referer Preference:</label>
				    <div class="col-xs-8">
				    	<select class="form-control input-sm" name="user_referer" id="user_referer">
							<option
							<?php if ($html['user_pref_referer_data'] == 'browser') { echo 'selected=""'; } ?>
								value="browser">Pickup Referer from browser</option>
							<option
							<?php if ($html['user_pref_referer_data'] == 't202ref') { echo 'selected=""'; } ?>
								value="t202ref">Pickup Referer from t202ref variable</option>
						</select>
					</div>
				</div>
				<div class="form-group">
				    <label for="user_referer" class="col-xs-4 control-label">* GDPR & Privacy:</label>
				    <div class="col-xs-8">
				    	<select class="form-control input-sm" name="user_pref_privacy" id="user_pref_privacy">
							<option
							<?php if ($html['user_pref_privacy'] == 'disabled') { echo 'selected=""'; } ?>
								value="disabled">Disabled</option>
						<option
							<?php if ($html['user_pref_privacy'] == 'eu') { echo 'selected=""'; } ?>
								value="eu">Enabled for European Traffic</option>	
							<option
							<?php if ($html['user_pref_privacy'] == 'all') { echo 'selected=""'; } ?>
								value="all">Enabled for All Traffic</option>
						</select>
					</div>
				</div>
				<div class="form-group">
				    <label for="cloak_referer" class="col-xs-4 control-label">* Cloaked Referer:</label>
				    <div class="col-xs-8">
				    	<select class="form-control input-sm" name="cloak_referer" id="cloak_referer">
							<option
							<?php if ($html['user_pref_cloak_referer'] == 'origin') { echo 'selected=""'; } ?>
								value="origin">Show Prosper202 Domain</option>
							<option
							<?php if ($html['user_pref_cloak_referer'] == 'never') { echo 'selected=""'; } ?>
								value="never">Show Blank Referer</option>
						</select>
					</div>
				</div>
                <div class="form-group">
				    <label for="cloak_referer" class="col-xs-4 control-label">* Ad Settings:</label>
				    <div class="col-xs-8">
				    	<select class="form-control input-sm" name="user_pref_ad_settings" id="user_pref_ad_settings">
							<option
							<?php if ($html['user_pref_ad_settings'] == 'show_all') { echo 'selected=""'; } ?>
								value="show_all">Show All Ads</option>
							<option
							<?php if ($html['user_pref_ad_settings'] == 'hide_login') { echo 'selected=""'; } ?>
								value="hide_login">Hide Ads On Login Screen</option>
							<option
							<?php if ($html['user_pref_ad_settings'] == 'hide_all') { echo 'selected=""'; } ?>
								value="hide_all">Hide All Ads</option>	
						</select>
					</div>
				</div>
				<?php } //closing brace for permissions check ?>
				<div class="form-group <?php if($error['user_email']) echo "has-error";?>">
				    <label for="user_email" class="col-xs-4 control-label">* Email: 
				    	<?php if($error['user_email']) { ?> <span class="fui-alert" style="font-size: 12px;" data-toggle="tooltip" title="<?php echo $error['user_email']; ?>"></span> <?php } ?>
				    </label>
				    <div class="col-xs-8">
				    	<input type="text" class="form-control input-sm" id="user_email" name="user_email" value="<?php echo $html['user_email']; ?>">
					</div>
				</div>

				<?php if($userObj->hasPermission("access_to_personal_settings")) { ?>
				<div class="form-group">
				    <label for="user_tracking_domain" class="col-xs-4 control-label">Tracking Domain:</label>
				    <div class="col-xs-8">
				    	<input type="text" class="form-control input-sm" id="user_tracking_domain" name="user_tracking_domain" value="<?php echo $html['user_tracking_domain']; ?>">
					</div>
				</div>
				<?php } ?>
				<div class="form-group">
				    <div class="col-xs-8 col-xs-offset-4">
						<button class="btn btn-md btn-p202 btn-block" type="submit">Update profile</button>					
					</div>
				</div>

			</form>
		</div>
</div>

<div class="row form_seperator">
	<div class="col-xs-12"></div>
</div>

<?php if($userObj->hasPermission("access_to_personal_settings")) { ?>
<div class="row account">
	<div class="col-xs-12">
		<h6>Account currency</h6>
	</div>
	<div class="col-xs-4">
		<div class="panel panel-default account_left">
			<div class="panel-body">
			    Here you can change your account currency and have your data converted to new currency (paid feature).
			</div>
		</div>
	</div>
	<div class="col-xs-8">
		<form class="form-horizontal" style="padding-top:0px;" role="form" method="post" action="">
		<input type="hidden" name="update_account_currency" value="1" />
		<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
		<div class="form-group">
			<label for="account_currency" class="col-xs-4 control-label">Account currency:</label>
				    <div class="col-xs-8">
				    	<select class="form-control input-sm" name="account_currency" id="account_currency">
							  <option value="USD" <?php if ($html['user_account_currency'] == 'USD') echo 'selected=""'; ?>>U.S. Dollar</option>
							  <option value="AUD" <?php if ($html['user_account_currency'] == 'AUD') echo 'selected=""'; ?>>Australian Dollar</option>
							  <option value="BRL" <?php if ($html['user_account_currency'] == 'BRL') echo 'selected=""'; ?>>Brazilian Real</option>
							  <option value="CAD" <?php if ($html['user_account_currency'] == 'CAD') echo 'selected=""'; ?>>Canadian Dollar</option>
							  <option value="CZK" <?php if ($html['user_account_currency'] == 'CZK') echo 'selected=""'; ?>>Czech Koruna</option>
							  <option value="DKK" <?php if ($html['user_account_currency'] == 'DKK') echo 'selected=""'; ?>>Danish Krone</option>
							  <option value="EUR" <?php if ($html['user_account_currency'] == 'EUR') echo 'selected=""'; ?>>Euro</option>
							  <option value="HKD" <?php if ($html['user_account_currency'] == 'HKD') echo 'selected=""'; ?>>Hong Kong Dollar</option>
							  <option value="HUF" <?php if ($html['user_account_currency'] == 'HUF') echo 'selected=""'; ?>>Hungarian Forint</option>
							  <option value="ILS" <?php if ($html['user_account_currency'] == 'ILS') echo 'selected=""'; ?>>Israeli New Sheqel</option>
							  <option value="JPY" <?php if ($html['user_account_currency'] == 'JPY') echo 'selected=""'; ?>>Japanese Yen</option>
							  <option value="MYR" <?php if ($html['user_account_currency'] == 'MYR') echo 'selected=""'; ?>>Malaysian Ringgit</option>
							  <option value="MXN" <?php if ($html['user_account_currency'] == 'MXN') echo 'selected=""'; ?>>Mexican Peso</option>
							  <option value="NOK" <?php if ($html['user_account_currency'] == 'NOK') echo 'selected=""'; ?>>Norwegian Krone</option>
							  <option value="NZD" <?php if ($html['user_account_currency'] == 'NZD') echo 'selected=""'; ?>>New Zealand Dollar</option>
							  <option value="PHP" <?php if ($html['user_account_currency'] == 'PHP') echo 'selected=""'; ?>>Philippine Peso</option>
							  <option value="PLN" <?php if ($html['user_account_currency'] == 'PLN') echo 'selected=""'; ?>>Polish Zloty</option>
							  <option value="GBP" <?php if ($html['user_account_currency'] == 'GBP') echo 'selected=""'; ?>>Pound Sterling</option>
							  <option value="SGD" <?php if ($html['user_account_currency'] == 'SGD') echo 'selected=""'; ?>>Singapore Dollar</option>
							  <option value="SEK" <?php if ($html['user_account_currency'] == 'SEK') echo 'selected=""'; ?>>Swedish Krona</option>
							  <option value="CHF" <?php if ($html['user_account_currency'] == 'CHF') echo 'selected=""'; ?>>Swiss Franc</option>
							  <option value="TWD" <?php if ($html['user_account_currency'] == 'TWD') echo 'selected=""'; ?>>Taiwan New Dollar</option>
							  <option value="THB" <?php if ($html['user_account_currency'] == 'THB') echo 'selected=""'; ?>>Thai Baht</option>
							  <option value="TRY" <?php if ($html['user_account_currency'] == 'TRY') echo 'selected=""'; ?>>Turkish Lira</option>
							  <option value="CNY" <?php if ($html['user_account_currency'] == 'CNY') echo 'selected=""'; ?>>Chinese Yuan</option>
							  <option value="INR" <?php if ($html['user_account_currency'] == 'INR') echo 'selected=""'; ?>>Indian Rupee</option>
							  <option value="RUB" <?php if ($html['user_account_currency'] == 'RUB') echo 'selected=""'; ?>>Russian ruble</option>
						</select>
					</div>
		</div>
		<div class="form-group">
			<div class="col-xs-8 col-xs-offset-4">
				<button class="btn btn-md btn-p202 btn-block" type="submit">Update account currency</button>					
			</div>
		</div>
		</form>
	</div>
</div>

<div class="row form_seperator">
	<div class="col-xs-12"></div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<h6>Prosper202 App API keys</h6>
	</div>
	<div class="col-xs-4">
		<div class="panel panel-default account_left">
			<div class="panel-body">
			    If you want to use the new Prosper202 API to get raw stats data, you need a valid API key. Tip: make a new API key for each integration
			</div>
		</div>
	</div>
	<div class="col-xs-8">
		<div class="row">
			<div class="col-xs-4">
			<a id="generate-new-api-key" class="btn btn-xs btn-info btn-block">Generate new key</a>	
		</div>
		<div class="col-xs-8">
			<ul class="list-unstyled" id="rest-api-keys">
			<?php 
				$key_sql = "	SELECT 	*
								 FROM   	`202_api_keys` 
								 WHERE  	`user_id`='".$mysql['user_id']."'";
				$key_result = $db->query($key_sql);
				$rows = $key_result->num_rows;

				if ($rows > 0) {
					while ($key_row = $key_result->fetch_assoc()) {
						echo '<li id="'.$key_row['api_key'].'"><span class="infotext">Date created: '.date("m/d/Y", $key_row['created_at']).'</span> - <code>'.$key_row['api_key'].'</code> <a id="delete-rest-key" class="close fui-cross"></a></li>';
					}
				} else {
					echo '<li id="no-api-keys">No API keys generated</li>';
				}
			?>	
			</ul>
		</div>
		</div>
	</div>
</div>

<div class="row form_seperator">
	<div class="col-xs-12"></div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<h6>Prosper202 Customer API Key</h6>
	</div>
	<div class="col-xs-4">
		<div class="panel panel-default account_left">
			<div class="panel-body">
			    If you want to use special mods and paid features built into Prosper202, sign up <a href="https://my.tracking202.com/api/customers/register">here</a>, fill out yout billing information, receive and insert your API key here.
			</div>
		</div>
	</div>
	<div class="col-xs-8">
		<form class="form-horizontal" style="padding-top:0px;" role="form" method="post" action="">
		<input type="hidden" name="update_p202_customer_api_key" value="1" />
		<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
		<div class="form-group">
			<label for="p202_customer_api_key" class="col-xs-4 control-label">API key:</label>
			<div class="col-xs-8">
				<input type="text" class="form-control input-sm" id="p202_customer_api_key" name="p202_customer_api_key" value="<?php echo $html['p202_customer_api_key']; ?>">
			</div>
		</div>
		<div class="form-group">
			<div class="col-xs-8 col-xs-offset-4">
				<button class="btn btn-md btn-p202 btn-block" type="submit">Update API key</button>					
			</div>
		</div>
		</form>
	</div>
</div>

<div class="row form_seperator">
	<div class="col-xs-12"></div>
</div>
<?php } ?>

<div class="row account">
	<div class="col-xs-12">
		<h6>Change Password</h6>
	</div>
	<div class="col-xs-4">
		<div class="panel panel-default account_left">
			<div class="panel-body">
			    If you wish to change your password, use the forms below.
			</div>
		</div>
	</div>
	<div class="col-xs-8">
		<form class="form-horizontal" style="padding-top:0px;" role="form" method="post" action="">
		<input type="hidden" name="change_user_pass" value="1" />
		<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
			<div class="form-group <?php if($error['user_pass']) echo "has-error";?>">
				<label for="user_pass" class="col-xs-4 control-label">Old Password:
					<?php if($error['user_pass']) { ?> <span class="fui-alert" style="font-size: 12px;" data-toggle="tooltip" title="<?php echo $error['user_pass']; ?>"></span> <?php } ?>
				</label>
				<div class="col-xs-8">
					<input type="password" class="form-control input-sm" id="user_pass" name="user_pass">
				</div>
			</div>

			<div class="form-group <?php if($error['user_pass']) echo "has-error";?>">
				<label for="new_user_pass" class="col-xs-4 control-label">New Password:
					<?php if($error['user_pass']) { ?> <span class="fui-alert" style="font-size: 12px;" data-toggle="tooltip" title="<?php echo $error['user_pass']; ?>"></span> <?php } ?>
				</label>
				<div class="col-xs-8">
					<input type="password" class="form-control input-sm" id="new_user_pass" name="new_user_pass">
				</div>
			</div>

			<div class="form-group <?php if($error['user_pass']) echo "has-error";?>">
				<label for="retype_new_user_pass" class="col-xs-4 control-label">Retype New Password:
					<?php if($error['user_pass']) { ?> <span class="fui-alert" style="font-size: 12px;" data-toggle="tooltip" title="<?php echo $error['user_pass']; ?>"></span> <?php } ?>
				</label>
				<div class="col-xs-8">
					<input type="password" class="form-control input-sm" id="retype_new_user_pass" name="retype_new_user_pass">
				</div>
			</div>

			<div class="form-group">
				<div class="col-xs-8 col-xs-offset-4">
					<button class="btn btn-md btn-p202 btn-block" type="submit">Change Password</button>					
				</div>
			</div>
		</form>
	</div>
</div>
		<?php template_bottom();
