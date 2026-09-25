<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user();
if (isset($_GET['getProgress'])) {
	$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
	$user_sql = "SELECT install_hash FROM 202_users WHERE user_id = '".$mysql['user_own_id']."'";
	$user_results = $db->query($user_sql);
	$user_row = $user_results->fetch_assoc();

	$postData = file_get_contents('php://input');
	$postData = json_decode($postData, true);
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
	$db->query($sql);
}