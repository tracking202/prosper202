<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');

AUTH::require_user();

$slack = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $userObj->hasPermission("remove_tracker")) {

	// Deleting a tracker is a write, and every other Setup write asks for the
	// session token (error pattern #5); this one did not until U4. The page
	// posts through jQuery, whose prefilter attaches the token.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		http_response_code(403);
		die('Invalid token, please reload the page and try again.');
	}

	if (!isset($_POST['tracker_id'])) {
		die("Error: No tracker ID provided");
	}
	$mysql['tracker_id'] = $db->real_escape_string((string)$_POST['tracker_id']);
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

	if ($slack) {

		$sql = "SELECT * FROM 202_trackers WHERE tracker_id = '".$mysql['tracker_id']."' AND user_id = '".$mysql['user_id']."'";
		$result = $db->query($sql);
		$row = false;
		if ($result->num_rows > 0) {
			$row = $result->fetch_assoc();
		}

		$type = 'Unknown';
		if ($row && !$row['landing_page_id'] && !$row['rotator_id'] && $row['aff_campaign_id']) {
			$type = 'Direct Link';
		} else if ($row && $row['landing_page_id'] && !$row['rotator_id'] && $row['aff_campaign_id']) {
			$type = 'Simple Landing Page';
		} else if ($row && $row['landing_page_id'] && !$row['rotator_id'] && !$row['aff_campaign_id']) {
			$type = 'Advance Landing Page';
		} else if ($row && !$row['landing_page_id'] && $row['rotator_id'] && !$row['aff_campaign_id']) {
			$type = 'Smart Redirector';
		}

		$slack->push('tracking_link_deleted', ['type' => $type, 'id' => $mysql['tracker_id'], 'user' => $user_row['username']]);
	}

	$sql = "DELETE FROM 202_trackers WHERE tracker_id = '".$mysql['tracker_id']."' AND user_id = '".$mysql['user_id']."'";
	$result = $db->query($sql);

}
