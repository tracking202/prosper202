<?php


include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();


//if they want to remove their stats202 app key on file, do so
if ($_GET['remove_user_stats202_app_key']) {
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$sql = "UPDATE 202_users SET user_stats202_app_key='' WHERE user_id='".$mysql['user_id']."'";
	$result = _mysql_query($sql);
	$_SESSION['user_stats202_app_key'] = '';
	header('location: /202-account/account.php');
	die();
}

//if they want to remove their stats202 app key on file, do so
if ($_GET['remove_user_api_key']) {
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$sql = "UPDATE 202_users SET user_api_key='' WHERE user_id='".$mysql['user_id']."'";
	$result = _mysql_query($sql);
	$_SESSION['user_api_key'] = '';
	header('location: /202-account/account.php');
	die();
}



//get all of the user data
$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
$user_sql = "	SELECT 	*
				 FROM   	`202_users` 
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='".$mysql['user_id']."'";
$user_result = _mysql_query($user_sql);
$user_row = mysql_fetch_assoc($user_result);
$html = array_map('htmlentities', $user_row);

//make it hide most of the api keys
$hideChars = 22;
for ($x = 0; $x < $hideChars; $x++) $hiddenPart .= '*';
if ($html['user_api_key']) $html['user_api_key'] = $hiddenPart . substr($html['user_api_key'], $hideChars, 99);
if ($html['user_stats202_app_key']) $html['user_stats202_app_key'] = $hiddenPart . substr($html['user_stats202_app_key'], $hideChars, 99);


if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if ($_POST['update_profile'] == '1') {

			
		if ($_POST['token'] != $_SESSION['token']){ $error['token'] = '<div class="error">You must use our forms to submit data.</div';  }
			
		//check user_email
		if (check_email_address($_POST['user_email']) == false) { $error['user_email'] = '<div class="error">Please enter a valid email address</div>'; }
		if (!$error['user_email_invalid']) {
			$mysql['user_email'] = mysql_real_escape_string($_POST['user_email']);
			$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
			$count_sql = "	SELECT 	COUNT(*)
						  	FROM  		`202_users` 
						  	WHERE 	`user_email` = '" . $mysql['user_email'] ."' 
						  	AND   		`user_id`!='".$mysql['user_id']."'";
			$count_result = _mysql_query($count_sql);
			if (mysql_result($count_result,0,0) > 0) {
				$error['user_email'] .= '<div class="error">That email address is already being used.<br/>Forget your account information? <a href="/202-login">Click here</a> to retrieve it.</div>';
			}
		}

		switch ($_POST['user_keyword_searched_or_bidded']) {

			case "searched":
			case "bidded":
				break;
			default:
				$error['user_keyword_searched_or_bidded'] = '<div class="error">You must select your keyword preference.</div>';
				break;
		}

		if (!$error) {

			$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
			$mysql['user_timezone'] = mysql_real_escape_string($_POST['user_timezone']);
			$mysql['user_keyword_searched_or_bidded'] = mysql_real_escape_string($_POST['user_keyword_searched_or_bidded']);
			$mysql['user_tracking_domain'] = mysql_real_escape_string($_POST['user_tracking_domain']);

			$user_sql = "
				UPDATE
					`202_users` 
				SET
					`user_email`='".$mysql['user_email']."',
					`user_timezone`='".$mysql['user_timezone']."'
				WHERE
					`user_id`='".$mysql['user_id']."'
			";
			$user_result = _mysql_query($user_sql);

			$user_sql = "
				UPDATE
					`202_users_pref`
				SET
					`user_keyword_searched_or_bidded`='".$mysql['user_keyword_searched_or_bidded']."',
					`user_tracking_domain`='".$mysql['user_tracking_domain']."'
				WHERE
					`user_id`='".$mysql['user_id']."'
			";
			$user_result = _mysql_query($user_sql);

			$update_profile = true;

			//set the  session's user_timezone
			$_SESSION['user_timezone'] = $_POST['user_timezone'];
		}
	}


	if ($_POST['change_user_api_key'] == '1') {

		if ($_POST['token'] != $_SESSION['token']) { $error['token'] = '<div class="error">You must use our forms to submit data.</div';  }

		if (!preg_match('/\*/', $_POST['user_api_key'])) {
			if (!AUTH::is_valid_api_key($_POST['user_api_key'])) { $error['user_api_key'] = '<div class="error">This API Key appears invalid.</div>'; }

			if (!$error) {
					
				$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$mysql['user_api_key'] = mysql_real_escape_string($_POST['user_api_key']);
				$user_sql = "	UPDATE 	`202_users`
								SET     		`user_api_key`='".$mysql['user_api_key']."'
								WHERE  	`user_id`='".$mysql['user_id']."'";
				$user_result = _mysql_query($user_sql);

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
					
				$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$mysql['user_stats202_app_key'] = mysql_real_escape_string($_POST['user_stats202_app_key']);
				$user_sql = "	UPDATE 	`202_users`
								SET     		`user_stats202_app_key`='".$mysql['user_stats202_app_key']."'
								WHERE  	`user_id`='".$mysql['user_id']."'";
				$user_result = _mysql_query($user_sql);
					
				$change_stats202_app_key = true;
					
				//set the  session's user_api_key
				$_SESSION['user_stats202_app_key'] = $_POST['user_stats202_app_key'];
			}
		}
	}

	if ($_POST['change_user_pass'] == '1') {
			
		//check token, and new user_pass
		if ($_POST['token'] != $_SESSION['token']){ $error['token'] = '<div class="error">You must use our forms to submit data.</div';  }
		if ($_POST['new_user_pass']=='') { $error['user_pass'] = '<div class="error">You must type in your desired password</div>'; }
		if ($_POST['retype_new_user_pass']=='') { $error['user_pass'] .= '<div class="error">You must type verify your password</div>'; }
		if ((strlen($_POST['new_user_pass']) < 6) OR (strlen($_POST['new_user_pass']) > 35)) { $error['user_pass'] .= '<div class="error">Your password must be between 6 and 35 characters long</div>'; }
		if ($_POST['new_user_pass'] != $_POST['retype_new_user_pass']) { $error['user_pass'] .= '<div class="error">Your password did not match, please try again</div>'; }

		//check to to see if old user_pass is correct
		$user_pass = salt_user_pass($_POST['user_pass']);
		$mysql['user_pass'] = mysql_real_escape_string($user_pass);
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);

		$user_sql = "	SELECT 	COUNT(*)
					FROM   		`202_users`
					WHERE   	`user_id`='".$mysql['user_id']."'
					AND     		`user_pass`='".$mysql['user_pass']."'"; 
		$user_result = _mysql_query($user_sql);
		if (mysql_result($user_result,0,0) == 0) $error['user_pass'] .= '<div class="error">Your old password was typed incorrectly.</div>';

		//if no user_pass errors
		if (!$error) {

			$user_pass = salt_user_pass($_POST['new_user_pass']);
			$mysql['user_pass'] = mysql_real_escape_string($user_pass);
			$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);

			$user_sql = "	UPDATE 	`202_users`
							SET    		`user_pass`='".$mysql['user_pass']."'
							WHERE  	`user_id`='".$mysql['user_id']."'";
			$user_result = _mysql_query($user_sql);

			$change_user_pass = true;
		}

	}

	$html = array_merge($html, array_map('htmlentities', $_POST));

}


$html['user_id'] = htmlentities($_SESSION['user_id'], ENT_QUOTES, 'UTF-8');
$html['user_username'] = htmlentities($_SESSION['user_username'], ENT_QUOTES, 'UTF-8');

//check to see if this user has stats202 enabled
$_SESSION['stats202_enabled'] = AUTH::is_valid_app_key('stats202', $_SESSION['user_api_key'], $_SESSION['user_stats202_app_key']);

template_top('User Profile',NULL,NULL,NULL);  ?>

<style>
.my-account-tables {
	margin: 0px auto;
}

.my-account-tables td {
	width: 150px;
}
</style>

<form method="post" action="" enctype="multipart/form-data"><input
	type="hidden" name="update_profile" value="1" /> <input type="hidden"
	name="token" value="<?php echo $_SESSION['token']; ?>" />
<table class="my-account-tables" cellpadding="5" cellspacing="0">
	<tr>
		<td colspan="2">
		<h2 class="green">My Account</h2>
		<p class="first bold">Here you can modify your account settings.
		Required fields marked with by *</p>
		</td>
	</tr>
	<tr>
		<td colspan="2"><? if ($update_profile == true) { ?>
		<div class="success">
		<div>
		<h3>Your submission was successful</h3>
		Your changes were made succesfully.</div>
		</div>
		<? } ?> <? echo $error['token'] . $error['user_email'] . $error['user_keyword_searched_or_bidded'] . $error['user_tracking_domain']; ?>
		</td>
	</tr>
	<tr>
		<td class="left_caption">Time zone (GMT) *</td>
		<td><select name="user_timezone">
			<option
			<? if ($html['user_timezone'] == '-11') { echo 'selected=""'; } ?>
				value="-11">-1100 : Samoa</option>
			<option
			<? if ($html['user_timezone'] == '-10') { echo 'selected=""'; } ?>
				value="-10">-1000 : Alaska, Hawai'i</option>
			<option
			<? if ($html['user_timezone'] == '-9') { echo 'selected=""'; } ?>
				value="-9">-0900 :</option>
			<option
			<? if ($html['user_timezone'] == '-8') { echo 'selected=""'; } ?>
				value="-8">-0800 : US Pacific</option>
			<option
			<? if ($html['user_timezone'] == '-7') { echo 'selected=""'; } ?>
				value="-7">-0700 : US Mountain</option>
			<option
			<? if ($html['user_timezone'] == '-6') { echo 'selected=""'; } ?>
				value="-6">-0600 : US Central</option>
			<option
			<? if ($html['user_timezone'] == '-5') { echo 'selected=""'; } ?>
				value="-5">-0500 : US Eastern</option>
			<option
			<? if ($html['user_timezone'] == '-4') { echo 'selected=""'; } ?>
				value="-4">-0400 : Atlantic</option>
			<option
			<? if ($html['user_timezone'] == '-3.5') { echo 'selected=""'; } ?>
				value="-3.5">-0350 : Newfoundland</option>
			<option
			<? if ($html['user_timezone'] == '-3') { echo 'selected=""'; } ?>
				value="-3">-0300 : Brazil, Argentina</option>
			<option
			<? if ($html['user_timezone'] == '-2') { echo 'selected=""'; } ?>
				value="-2">-0200 : Mid Atlantic</option>
			<option
			<? if ($html['user_timezone'] == '0') { echo 'selected=""'; } ?>
				value="0">+0000 : London, Dublin</option>
			<option
			<? if ($html['user_timezone'] == '1') { echo 'selected=""'; } ?>
				value="1">+0100 : Paris, Berlin, Amsterdam, Madrid</option>
			<option
			<? if ($html['user_timezone'] == '2') { echo 'selected=""'; } ?>
				value="2">+0200 : Athens, Istanbul, Helsinki</option>
			<option
			<? if ($html['user_timezone'] == '3') { echo 'selected=""'; } ?>
				value="3">+0300 : Kuwait, Moscow</option>
			<option
			<? if ($html['user_timezone'] == '3.5') { echo 'selected=""'; } ?>
				value="3.5">+0350 : Tehran</option>
			<option
			<? if ($html['user_timezone'] == '5.5') { echo 'selected=""'; } ?>
				value="5.5">+0530 : India</option>
			<option
			<? if ($html['user_timezone'] == '7') { echo 'selected=""'; } ?>
				value="7">+0700 : Bangkok</option>
			<option
			<? if ($html['user_timezone'] == '7.5') { echo 'selected=""'; } ?>
				value="7">+0700 :</option>
			<option
			<? if ($html['user_timezone'] == '8') { echo 'selected=""'; } ?>
				value="8">+0800 : Hong Kong</option>
			<option
			<? if ($html['user_timezone'] == '9') { echo 'selected=""'; } ?>
				value="9">+0900 : Tokyo</option>
			<option
			<? if ($html['user_timezone'] == '9.5') { echo 'selected=""'; } ?>
				value="9.5">+0950 : Darwin</option>
			<option
			<? if ($html['user_timezone'] == '10') { echo 'selected=""'; } ?>
				value="10">+1000 : Sydney</option>
			<option
			<? if ($html['user_timezone'] == '11') { echo 'selected=""'; } ?>
				value="11">+1100 : Magadan</option>
			<option
			<? if ($html['user_timezone'] == '12') { echo 'selected=""'; } ?>
				value="12">+1200 : Wellington</option>
		</select></td>
	</tr>

	<tr>
		<td class="left_caption">Keyword Preference *</td>
		<td><select name="user_keyword_searched_or_bidded">
			<option
			<? if ($html['user_keyword_searched_or_bidded'] == 'searched') { echo 'selected=""'; } ?>
				value="searched">Pickup Searched Keyword</option>
			<option
			<? if ($html['user_keyword_searched_or_bidded'] == 'bidded') { echo 'selected=""'; } ?>
				value="bidded">Pickup Bidded Keyword</option>
		</select></td>
	</tr>

	<tr>
		<td class="left_caption">Email *</td>
		<td><input type="text" name="user_email" size="40"
			value="<? echo $html['user_email']; ?>" /></td>
	</tr>

	<tr>
		<td class="left_caption">Tracking Domain</td>
		<td><input type="text" name="user_tracking_domain" size="40"
			value="<? echo $html['user_tracking_domain']; ?>" /></td>
	</tr>

	<tr>
		<td />
		<td><input class="submit" type="submit" value="Update Profile" />
	
	</tr>
</table>
</form>

<form method="post" action=""><input type="hidden"
	name="change_user_api_key" value="1" /> <input type="hidden"
	name="token" value="<?php echo $_SESSION['token']; ?>" />
<table class="my-account-tables" style="margin-top: 30px;"
	cellpadding="5" cellspacing="0">
	<tr>
		<td colspan="2">
		<h2 class="green">My Tracking202 Developer Key</h2>
		<p class="first bold">If you do not know your developer api key, you
		may get it <a href="http://developers.tracking202.com">here</a>.</p>
		</td>
	</tr>
	<tr>
		<td colspan="2"><?php
		if ($change_api_key) {
			echo '<div class="success"><div><h3>You have updated your Tracking202 API Key</h3></div></div>';
		}
		if ($removed_user_api_key) {
			echo '<div class="success"><div><h3>You have removed your Tracking202 API Key</h3></div></div>';
		}
		echo $error['user_api_key'];
		?></td>
	</tr>
	<tr>
		<td class="left_caption">My Tracking202 API Key</td>
		<td><input type="text" name="user_api_key" size="40"
			value="<? echo $html['user_api_key']; ?>" /></td>
	</tr>
	<tr>
		<td />
		<td><input class="submit" type="submit" value="Update API Keys" /> <? if ($_SESSION['user_api_key']) echo "&nbsp;&nbsp; <button class='submit' onclick='window.location=\"?remove_user_api_key=1\"; return false;'>Delete Api Key</a>"; ?>
		</td>
	</tr>
</table>
</form>


<form method="post" action=""><input type="hidden"
	name="change_user_pass" value="1" /> <input type="hidden" name="token"
	value="<?php echo $_SESSION['token']; ?>" />
<table class="my-account-tables" style="margin-top: 30px;"
	cellpadding="5" cellspacing="0">
	<tr>
		<td colspan="2">
		<h2 class="green">Change Password</h2>
		<p class="first bold">If you wish to change your password, use the
		forms below.</p>
		</td>
	</tr>
	<tr>
		<td colspan="2"><? if ($change_user_pass == true) { ?>
		<div class="success">
		<div>
		<h3>Your submission was successful</h3>
		Your changes were made succesfully.</div>
		</div>
		<? } ?> <? echo $error['token']; ?> <? echo $error['user_pass']; ?></td>
	</tr>
	<tr>
		<td class="left_caption">New Password</td>
		<td><input type="password" name="new_user_pass" size="40" /></td>
	</tr>
	<tr>
		<td class="left_caption">Retype New Password</td>
		<td><input type="password" name="retype_new_user_pass" size="40" /></td>
	</tr>
	<tr>
		<td class="left_caption">Old Password</td>
		<td><input type="password" name="user_pass" size="40" /></td>
	</tr>
	<tr>
		<td />
		<td><input class="submit" type="submit" value="Change Password" />
	
	</tr>
</table>
</form>



		<? template_bottom();