<?php
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user(); 

if (isset($_POST['api_key'])) {
	if ($_POST['token'] != $_SESSION['token']) { $error['token'] = 'You must use our forms to submit data.';  }
	$mysql['p202_customer_api_key'] = $db->real_escape_string($_POST['api_key']);
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_own_id']);
	$validate = validateCustomersApiKey($mysql['p202_customer_api_key']);
	if ($validate['code'] != 200) {
		$error['p202_customer_api_key_invalid'] = "API key is not valid. Check your key and try again!";
	}
	if (!$error) {
		$db->query("UPDATE 202_users SET p202_customer_api_key = '".$mysql['p202_customer_api_key']."' WHERE user_id = '".$mysql['user_id']."'");
		$msg = array('error' => false, 'msg' => 'Valid');
	} else {
		$msg = array('error' => true, 'msg' => $error['token'] . $error['p202_customer_api_key_invalid']);
	}

	echo json_encode($msg, true);
}

if (isset($_POST['get_alert_body'])) { ?>
	<small><p><?php echo $_SESSION['premium_p202_details']['body'];?></p></small>
	<small><p>Release date: <?php echo $_SESSION['premium_p202_details']['release-date'];?> - <a href="#changelogs" id="see_changelogs" data-toggle="modal" data-target="#changelogsPremium" style="color:#428bca; font-weight:normal; margin-top: 15px;">See what's new</a></p></small>
	<a style="margin-right:5px;" href="<?php echo get_absolute_url();?>202-account/auto-upgrade-premium.php" class="btn btn-xs btn-warning"><?php echo $_SESSION['premium_p202_details']['order-button-text'];?> ($<?php echo $_SESSION['premium_p202_details']['upgrade-price'];?>)</a>
<?php } ?>