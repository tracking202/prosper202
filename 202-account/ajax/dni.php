<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user();
AUTH::require_permissions('access_to_api_integrations');
if (isset($_GET['getProgress'])) {
	// The account's own importing networks, keys and all, read here: the
	// page used to post them, so every key was in its markup. A body is not read.
	try {
		$progress = \Prosper202\Setup\DniNetworks::progressRequest($db, (int) $_SESSION['user_id'], getDNIHost());
	} catch (\Prosper202\Database\Exceptions\QueryException $failed) {
		error_log('ajax/dni.php: the account or its networks could not be read: ' . $failed->getMessage());
		http_response_code(500);
		die('The account could not be read.');
	}
	if ($progress === null) {
		http_response_code(404);
		die('The account could not be found.');
	}
	if ($progress['networks'] === []) {
		header('Content-Type: application/json');
		die('{"data":[]}');
	}
	if (getDNICacheProgress($progress['install_hash'], $progress) === false) {
		http_response_code(502);
		die('The DNI service could not be reached.');
	}
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
	if (!_mysqli_query($db, $sql)) {
		error_log('ajax/dni.php: the network was not marked processed: ' . $db->error);
		http_response_code(500);
		die('The network could not be updated.');
	}
}