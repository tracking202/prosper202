<?php
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();

if (isset($_POST['add_rest_api_key'])) {
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['rest_api_key'] = $db->real_escape_string($_POST['rest_api_key']);
	$key_sql = "INSERT INTO 202_api_keys SET user_id='".$mysql['user_id']."', api_key = '".$mysql['rest_api_key']."', created_at='".time()."'";
	$key_result = $db->query($key_sql);
	die();
}

if (isset($_POST['remove_rest_api_key'])) {
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['rest_api_key'] = $db->real_escape_string($_POST['rest_api_key']);
	$key_sql = "DELETE FROM 202_api_keys WHERE api_key='".$mysql['rest_api_key']."'";
	$key_result = $db->query($key_sql);
	die();
}

//if they want to remove their stats202 app key on file, do so
if ($_GET['remove_user_stats202_app_key']) {
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$sql = "UPDATE 202_users SET user_stats202_app_key='' WHERE user_id='".$mysql['user_id']."'";
	$result = $db->query($sql);
	$_SESSION['user_stats202_app_key'] = '';
	header('location: /202-account/account.php');
	die();
}

//if they want to remove their stats202 app key on file, do so
if ($_GET['remove_user_api_key']) {
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$sql = "UPDATE 202_users SET user_api_key='' WHERE user_id='".$mysql['user_id']."'";
	$result = $db->query($sql);
	$_SESSION['user_api_key'] = '';
	header('location: /202-account/account.php');
	die();
}



//get all of the user data
$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$user_sql = "	SELECT 	*
				 FROM   	`202_users` 
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='".$mysql['user_id']."'";
$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();
$html = array_map('htmlentities', $user_row);

//make it hide most of the api keys
$hideChars = 22;
for ($x = 0; $x < $hideChars; $x++) $hiddenPart .= '*';
if ($html['user_api_key']) $html['user_api_key'] = $hiddenPart . substr($html['user_api_key'], $hideChars, 99);
if ($html['user_stats202_app_key']) $html['user_stats202_app_key'] = $hiddenPart . substr($html['user_stats202_app_key'], $hideChars, 99);
if ($html['clickserver_api_key']) $html['clickserver_api_key'] = $hiddenPart . substr($html['clickserver_api_key'], $hideChars, 99);


if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if ($_POST['update_profile'] == '1') {

			
		if ($_POST['token'] != $_SESSION['token']){ $error['token'] = 'You must use our forms to submit data.';  }
			
		//check user_email
		if (check_email_address($_POST['user_email']) == false) { $error['user_email'] = 'Please enter a valid email address'; }
		if (!$error['user_email_invalid']) {
			$mysql['user_email'] = $db->real_escape_string($_POST['user_email']);
			$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
			$count_sql = "	SELECT 	*
						  	FROM  		`202_users` 
						  	WHERE 	`user_email` = '" . $mysql['user_email'] ."' 
						  	AND   		`user_id`!='".$mysql['user_id']."'";
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

		if (!$error) {

			$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
			$mysql['user_timezone'] = $db->real_escape_string($_POST['user_timezone']);
			$mysql['cache_time'] = $db->real_escape_string($_POST['user_cached_reports']);
			$mysql['user_keyword_searched_or_bidded'] = $db->real_escape_string($_POST['user_keyword_searched_or_bidded']);
			$mysql['user_tracking_domain'] = $db->real_escape_string($_POST['user_tracking_domain']);

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
					`user_tracking_domain`='".$mysql['user_tracking_domain']."',
					`cache_time`='".$mysql['cache_time']."'
				WHERE
					`user_id`='".$mysql['user_id']."'
			";
			$user_result = $db->query($user_sql);
			$html['cache_time'] = $mysql['cache_time'];

			$update_profile = true;

			//set the  session's user_timezone
			$_SESSION['user_timezone'] = $_POST['user_timezone'];
		}
	}


	if ($_POST['update_clickserver_api_key'] == '1') {

		if ($_POST['token'] != $_SESSION['token']) { $error['token'] = 'You must use our forms to submit data.';  }

		if (!preg_match('/\*/', $_POST['clickserver_api_key'])) {
			if (!clickserver_api_key_validate($_POST['clickserver_api_key'])) { $error['clickserver_api_key'] = 'This API Key appears invalid.'; }

			if (!$error) {
					
				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
				$mysql['clickserver_api_key'] = $db->real_escape_string($_POST['clickserver_api_key']);
				$user_sql = "	UPDATE 	`202_users`
								SET     		`clickserver_api_key`='".$mysql['clickserver_api_key']."'
								WHERE  	`user_id`='".$mysql['user_id']."'";
				$user_result = $db->query($user_sql);

				$update_clickserver_api_key_done = true;
					
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
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);

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
			$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);

			$user_sql = "	UPDATE 	`202_users`
							SET    		`user_pass`='".$mysql['user_pass']."'
							WHERE  	`user_id`='".$mysql['user_id']."'";
			$user_result = $db->query($user_sql);

			$change_user_pass = true;
		}

	}

	$html = array_merge($html, array_map('htmlentities', $_POST));

}


$html['user_id'] = htmlentities($_SESSION['user_id'], ENT_QUOTES, 'UTF-8');
$html['user_username'] = htmlentities($_SESSION['user_username'], ENT_QUOTES, 'UTF-8');

//check to see if this user has stats202 enabled
$_SESSION['stats202_enabled'] = AUTH::is_valid_app_key('stats202', $_SESSION['user_api_key'], $_SESSION['user_stats202_app_key']);

template_top('Personal Settings',NULL,NULL,NULL);  

if ( isset( $_SERVER["HTTPS"] ) && strtolower( $_SERVER["HTTPS"] ) == "on" ) {
$strProtocol = 'https://';
} else {
$strProtocol = 'http://';
}

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
						<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['token'] . $error['user_email'] . $error['clickserver_api_key'] . $error['user_api_key'] . $error['user_pass']; ?></small></div>
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
				<div class="form-group">
				    <label for="user_timezone" class="col-xs-4 control-label">* Time zone (GMT):</label>
				    <div class="col-xs-8">
				      <?php
			
						function formatOffset($offset) {
					        $hours = $offset / 3600;
					        $remainder = $offset % 3600;
					        $sign = $hours > 0 ? '+' : '-';
					        $hour = (int) abs($hours);
					        $minutes = (int) abs($remainder / 60);

					        if ($hour == 0 AND $minutes == 0) {
					            $sign = ' ';
					        }
					        return $sign . str_pad($hour, 2, '0', STR_PAD_LEFT) .':'. str_pad($minutes,2, '0');
						}

						$utc = new DateTimeZone('UTC');
						$dt = new DateTime('now', $utc);

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
				    <label for="user_cached_reports" class="col-xs-4 control-label">Cache reports every: <span class="fui-info" style="font-size: 12px;" data-toggle="tooltip" title="If you have memcache installed and working, it will cache reports for fast output. Select how often stats will be cached and updated!"></span></label>
				    <div class="col-xs-8">
				      <select class="form-control input-sm" id="user_cached_reports" name="user_cached_reports" <?php if (!$memcacheWorking) echo "disabled";?>>
						<option
							<?php if ($html['cache_time'] == '0') { echo 'selected=""'; } ?>
								value="0">don't cache</option>
						<option
							<?php if ($html['cache_time'] == '60') { echo 'selected=""'; } ?>
								value="60">1 minute</option>
						<option
							<?php if ($html['cache_time'] == '120') { echo 'selected=""'; } ?>
								value="120">2 minutes</option>
						<option
							<?php if ($html['cache_time'] == '180') { echo 'selected=""'; } ?>
								value="180">3 minutes</option>
						<option
							<?php if ($html['cache_time'] == '240') { echo 'selected=""'; } ?>
								value="240">4 minutes</option>
						<option
							<?php if ($html['cache_time'] == '300') { echo 'selected=""'; } ?>
								value="300">5 minutes</option>
							<?php
								$min_min = 10;
								$max_min = 55;

								do {
									$sec = $min_min*60;
									if ($html['cache_time'] == $sec){
										echo '<option value="'.$sec.'" selected="">'.$min_min.' minutes</option>';
									} else {
										echo '<option value="'.$sec.'">'.$min_min.' minutes</option>';
									}

									$min_min = $min_min + 5;

								} while ($min_min <= $max_min);

							?>
							<option
							<?php if ($html['cache_time'] == '3600') { echo 'selected=""'; } ?>
								value="3600">hour</option>
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

				<div class="form-group <?php if($error['user_email']) echo "has-error";?>">
				    <label for="user_email" class="col-xs-4 control-label">* Email: 
				    	<?php if($error['user_email']) { ?> <span class="fui-alert" style="font-size: 12px;" data-toggle="tooltip" title="<?php echo $error['user_email']; ?>"></span> <?php } ?>
				    </label>
				    <div class="col-xs-8">
				    	<input type="text" class="form-control input-sm" id="user_email" name="user_email" value="<?php echo $html['user_email']; ?>">
					</div>
				</div>

				<div class="form-group">
				    <label for="user_tracking_domain" class="col-xs-4 control-label">Tracking Domain:</label>
				    <div class="col-xs-8">
				    	<input type="text" class="form-control input-sm" id="user_tracking_domain" name="user_tracking_domain" value="<?php echo $html['user_tracking_domain']; ?>">
					</div>
				</div>

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
		<h6>Prosper202 ClickServer API Key</h6>
	</div>
	<div class="col-xs-4">
		<div class="panel panel-default account_left">
			<div class="panel-body">
			    Update your Prosper202 ClickServer API Key. Warning: NEVER share your Prosper202 ClickServer API key with anyone!
			</div>
		</div>
	</div>
	<div class="col-xs-8">
		<form class="form-horizontal" style="padding-top:0px;" role="form" method="post" action="">
		<input type="hidden" name="update_clickserver_api_key" value="1" />
		<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
			<div class="form-group <?php if($error['clickserver_api_key']) echo "has-error";?>">
				<label for="clickserver_api_key" class="col-xs-4 control-label">My ClickServer API Key:
					<?php if($error['clickserver_api_key']) { ?> <span class="fui-alert" style="font-size: 12px;" data-toggle="tooltip" title="<?php echo $error['clickserver_api_key']; ?>"></span> <?php } ?>
				</label>
				<div class="col-xs-8">
					<input type="text" class="form-control input-sm" id="clickserver_api_key" name="clickserver_api_key" value="<?php echo $html['clickserver_api_key']; ?>">
				</div>
			</div>

			<div class="form-group">
				<div class="col-xs-8 col-xs-offset-4">
					<button class="btn btn-md btn-p202 btn-block" type="submit">Update API Key</button>					
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
		<h6>My Tracking202 Developer Key</h6>
	</div>
	<div class="col-xs-4">
		<div class="panel panel-default account_left">
			<div class="panel-body">
			    Use this to activate Offers202. If you do not know your developer api key, you may get it <a href="http://developers.tracking202.com">here</a>.
			</div>
		</div>
	</div>
	<div class="col-xs-8">
		<form class="form-horizontal" style="padding-top:0px;" role="form" method="post" action="">
		<input type="hidden" name="change_user_api_key" value="1" />
		<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
			<div class="form-group <?php if($error['user_api_key']) echo "has-error";?>">
				<label for="user_api_key" class="col-xs-4 control-label">My Tracking202 API Key:
					<?php if($error['user_api_key']) { ?> <span class="fui-alert" style="font-size: 12px;" data-toggle="tooltip" title="<?php echo $error['user_api_key']; ?>"></span> <?php } ?>
				</label>
				<div class="col-xs-8">
					<input type="text" class="form-control input-sm" id="user_api_key" name="user_api_key" value="<?php echo $html['user_api_key']; ?>">
				</div>
			</div>

			<div class="form-group">
				<div class="col-xs-8 col-xs-offset-4">
				<?php if ($_SESSION['user_api_key']) { ?>
					<div class="col-xs-6" style="padding-left: 0px;">
						<button class="btn btn-md btn-p202 btn-block" type="submit">Update API Key</button>
					</div>
					<div class="col-xs-6" style="padding-right: 0px;">
						<button class="btn btn-md btn-p202 btn-block" onclick="window.location='?remove_user_api_key=1'; return false;">Delete Api Key</button>
					</div>					
				<?php } else { ?>
					<button class="btn btn-md btn-p202 btn-block" type="submit">Update API Key</button>
				<?php } ?>
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
