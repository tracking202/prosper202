<?php
include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php');    

AUTH::require_user();
if (isset($_GET['getProgress'])) {
	$mysql['user_own_id'] = $db->real_escape_string($_SESSION['user_own_id']);
	$user_sql = "SELECT install_hash FROM 202_users WHERE user_id = '".$mysql['user_own_id']."'";
	$user_results = $db->query($user_sql);
	$user_row = $user_results->fetch_assoc();

	$postData = file_get_contents('php://input');
	$postData = json_decode($postData, true);
	getDNICacheProgress($user_row['install_hash'], $postData);
}

if (isset($_GET['updateStatus'])) {
	$mysql['dni'] = $db->real_escape_string($_GET['dni']);
	$sql = "UPDATE 202_dni_networks SET processed = '1' WHERE id = '".$mysql['dni']."'";
	$db->query($sql);
}
?>