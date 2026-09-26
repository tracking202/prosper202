<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user(); 

if (isset($_POST['api_key'])) {
	// The session token, compared as every other Account write compares it
	// (it was `!=`), and nothing else runs without it.
	$error = [];
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		http_response_code(403);
		echo json_encode(['error' => true, 'msg' => 'You must use our forms to submit data.']);
		die();
	}
	$mysql['p202_customer_api_key'] = $db->real_escape_string((string)$_POST['api_key']);
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
	$validate = validateCustomersApiKey($mysql['p202_customer_api_key']);
	if ($validate['code'] != 200) {
		$error['p202_customer_api_key_invalid'] = "API key is not valid. Check your key and try again!";
	}
	if (!$error) {
		if ($db->query("UPDATE 202_users SET p202_customer_api_key = '".$mysql['p202_customer_api_key']."' WHERE user_id = '".$mysql['user_id']."'")) {
			$msg = ['error' => false, 'msg' => 'Valid'];
		} else {
			error_log('upgrade_submit_api_key.php: the key was not saved: ' . $db->error);
			$msg = ['error' => true, 'msg' => 'The key is valid but could not be saved. Try again.'];
		}
	} else {
		$msg = ['error' => true, 'msg' => implode(' ', $error)];
	}

	echo json_encode($msg);
}

if (isset($_POST['get_alert_body'])) { ?>
	<small><p><?php echo $_SESSION['premium_p202_details']['body'];?></p></small>
	<small><p>Release date: <?php echo $_SESSION['premium_p202_details']['release-date'];?> - <a href="#changelogs" id="see_changelogs" data-toggle="modal" data-target="#changelogsPremium" style="color:#428bca; font-weight:normal; margin-top: 15px;">See what's new</a></p></small>
	<a style="margin-right:5px;" href="<?php echo get_absolute_url();?>202-account/auto-upgrade-premium.php" class="btn btn-xs btn-warning"><?php echo $_SESSION['premium_p202_details']['order-button-text'];?> ($<?php echo $_SESSION['premium_p202_details']['upgrade-price'];?>)</a>
<?php } ?>