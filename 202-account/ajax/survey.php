<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	// Both branches write (the modal status here, the answers to the VIP
	// Perks service below), so the survey posts the session token like every
	// other Account form; the classic shell's jQuery prefilter adds it.
	if (!AUTH::check_csrf_token()) {
		http_response_code(403);
		echo 'This form expired. Reload the page and try again.';
		die();
	}
	// The token is this session's secret; it is not an answer, and must not
	// travel to the VIP Perks service with the answers.
	$answers = $_POST;
	unset($answers['token']);

	if (isset($_POST['skip']) && $_POST['skip'] == true) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$sql = "UPDATE 202_users SET modal_status='1' WHERE user_id='".$mysql['user_id']."'";
		$result = $db->query($sql);
		die();
	}

	$user_data = get_user_data_feedback($_SESSION['user_id']);
	$install_hash = $user_data['install_hash'] ?? '';

	if ($install_hash === '') {
		echo 'Unable to determine install reference. Please try again later.';
		die();
	}

	$response = updateSurveyData($install_hash, $answers);
	$wasUpdated = is_array($response) && !empty($response['updated']);

	if ($wasUpdated) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$sql = "UPDATE 202_users SET modal_status='1', vip_perks_status='0' WHERE user_id='".$mysql['user_id']."'";
		$result = $db->query($sql);
	} else {
		echo 'An unexpected error occurred. Try again!';
	}
}
