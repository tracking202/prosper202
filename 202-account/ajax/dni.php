<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user();
if (isset($_GET['getProgress'])) {
	$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
	$user_sql = "SELECT install_hash FROM 202_users WHERE user_id = '".$mysql['user_own_id']."'";
	$user_results = $db->query($user_sql);
	if (!$user_results instanceof mysqli_result) {
		error_log('ajax/dni.php: the install could not be read: ' . $db->error);
		http_response_code(500);
		die('The account could not be read.');
	}
	$user_row = $user_results->fetch_assoc();
	if ($user_row === null) {
		http_response_code(404);
		die('The account could not be found.');
	}

	// The poller's list of networks: a body that does not read is refused,
	// not passed on as nothing (#4).
	$postData = json_decode((string) file_get_contents('php://input'), true);
	if (!is_array($postData)) {
		http_response_code(400);
		die('The progress request could not be read.');
	}
	getDNICacheProgress($user_row['install_hash'], $postData);
}

if (isset($_GET['updateStatus'])) {
	// A write, so it carries the session token like every other Account
	// write: the API integrations page posts it, and the shell's jQuery
	// prefilter (template.php) adds it to same-origin jQuery posts.
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !AUTH::check_csrf_token()) {
		http_response_code(403);
		die('Invalid token.');
	}
	$mysql['dni'] = $db->real_escape_string((string)$_GET['dni']);
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$sql = "UPDATE 202_dni_networks SET processed = '1' WHERE id = '".$mysql['dni']."' AND user_id = '".$mysql['user_id']."'";
	if (!$db->query($sql)) {
		error_log('ajax/dni.php: the network was not marked processed: ' . $db->error);
		http_response_code(500);
		die('The network could not be updated.');
	}
}