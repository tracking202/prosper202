<?php

include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php');    

AUTH::require_user();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if (isset($_POST['skip']) && $_POST['skip'] == true) {
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$sql = "UPDATE 202_users SET modal_status='1' WHERE user_id='".$mysql['user_id']."'";
		$result = $db->query($sql);
		die();
	}

	$user_data = get_user_data_feedback($_SESSION['user_id']);
	$response = updateSurveyData($user_data['install_hash'], $_POST);

	if ($response['updated']) {
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$sql = "UPDATE 202_users SET modal_status='1', vip_perks_status='0' WHERE user_id='".$mysql['user_id']."'";
		$result = $db->query($sql);
	} else {
		echo '<span class="fui-alert"></span> An unexpected error occurred. Try again!';
	}
}

?>