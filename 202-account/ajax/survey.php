<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if (isset($_POST['skip']) && $_POST['skip'] == true) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$sql = "UPDATE 202_users SET modal_status='1' WHERE user_id='".$mysql['user_id']."'";
		$result = $db->query($sql);
		die();
	}

	$user_data = get_user_data_feedback($_SESSION['user_id']);
	$install_hash = $user_data['install_hash'] ?? '';

	if ($install_hash === '') {
		echo '<span class="fui-alert"></span> Unable to determine install reference. Please try again later.';
		die();
	}

	$response = updateSurveyData($install_hash, $_POST);
	$wasUpdated = is_array($response) && !empty($response['updated']);

	if ($wasUpdated) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$sql = "UPDATE 202_users SET modal_status='1', vip_perks_status='0' WHERE user_id='".$mysql['user_id']."'";
		$result = $db->query($sql);
	} else {
		echo '<span class="fui-alert"></span> An unexpected error occurred. Try again!';
	}
}

?>
