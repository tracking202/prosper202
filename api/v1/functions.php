<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy/functions.php';

function getStats($db, $variables): mixed {
	$mysql['api_key'] = $db->real_escape_string($variables['apikey']);
	// Join 202_users so a soft-deleted user's key stops authenticating, exactly
	// as api/v3/Auth.php does. Deleting a user must revoke access on EVERY API
	// version, not just the newest one; so must deactivating one (below).
	$key_sql = "SELECT 	k.*, u.`user_active`
				FROM   	`202_api_keys` k
				INNER JOIN `202_users` u ON u.`user_id` = k.`user_id`
				WHERE  	k.`api_key`='".$mysql['api_key']."' AND u.`user_deleted` = 0";
	$key_result = _mysqli_query($db, $key_sql);
	if ($key_result === false) {
		return ['msg' => 'Database error', 'error' => true, 'status' => 500];
	}
	$key_row = $key_result->fetch_assoc();

	if($key_result->num_rows > 0) {

		// A deactivated user's key is refused, as the sign-in refuses them
		// (`user_active = 1`, functions-auth.php) and api/v3/Auth.php does:
		// this read user_deleted alone, so a user turned off in Account ›
		// Users kept reading reports with every key they held.
		if ((string) ($key_row['user_active'] ?? '') !== '1') {
			return ['msg' => 'The account this API key belongs to is deactivated', 'error' => true, 'status' => 401];
		}

		// A v3-scoped key is an attenuated credential. This legacy API
		// predates scoping and cannot enforce it, so refuse the key
		// outright rather than granting more than its scope allows.
		$legacy_key_scope = strtolower(trim((string) ($key_row['scope'] ?? '')));
		if ($legacy_key_scope !== '' && $legacy_key_scope !== '*') {
			return ['msg' => 'This API key is scoped and only valid for the v3 API', 'error' => true, 'status' => 403];
		}

		$mysql['user_id'] = $db->real_escape_string($key_row['user_id']);
		$user_sql = "SELECT 	`user_timezone`
					FROM   	`202_users`
					WHERE  	`user_id`='".$mysql['user_id']."'";
		$user_result = _mysqli_query($db, $user_sql);
		if ($user_result === false) {
			return ['msg' => 'Database error', 'error' => true, 'status' => 500];
		}
		$user_row = $user_result->fetch_assoc();

		return runReports($db, $variables, $key_row['user_id'], $user_row['user_timezone'], 'v1');
	} else {
		return ['msg' => 'Unauthorized', 'error' => true, 'status' => 401];
	}
}

function getDataForWP($db, $user): array {
	$data = [];
	$slp = [];
	$alp = [];
	$campaigns = [];
	$mysql['user_id'] = $db->real_escape_string($user);
	
	$sql = "SELECT landing_page_id_public, landing_page_nickname, landing_page_type, aff_campaign_id_public, aff_campaign_name FROM 202_landing_pages LEFT JOIN 202_aff_campaigns USING (aff_campaign_id, user_id) WHERE 202_landing_pages.user_id='".$mysql['user_id']."' AND landing_page_deleted='0'";
	$result = $db->query($sql);

	while ($row = $result->fetch_assoc()) {
		if ($row['landing_page_type'] == 0) {
			$slp[] = ['landing_page_id_public' => $row['landing_page_id_public'], 'landing_page_nickname' => $row['landing_page_nickname'], 'aff_campaign_name' => $row['aff_campaign_name']];
		} else if ($row['landing_page_type'] == 1) {
			$alp[] = ['landing_page_id_public' => $row['landing_page_id_public'], 'landing_page_nickname' => $row['landing_page_nickname']];
		}
	}

	$sql = "SELECT aff_campaign_id_public, aff_campaign_name FROM `202_aff_campaigns` WHERE `user_id`='" . $mysql ['user_id'] . "' AND `aff_campaign_deleted`='0' ORDER BY `aff_campaign_name` ASC";
	$result = $db->query($sql);
	while ($row = $result->fetch_assoc()) {
		$campaigns[] = $row;
	}

	$data[] = ['slp' => $slp, 'alp' => $alp, 'campaigns' => $campaigns];

	return $data;
}

function wpCreateLp($db, $user): array {
	if (isset($_GET['page_type']) && isset($_GET['page_title']) && isset($_GET['page_url'])) {
		$insert_id = 0;
		$title = $_GET['page_title'];
		if (strlen((string) $title) > 45) {
			$title = substr($_GET['page_title'], 0, 41);
			$title = substr($title, 0, strrpos($title, ' ')) . " ...";
		}
		$title = '[WP] '.$title;
		$mysql['landing_page_nickname'] = $db->real_escape_string($title);
		$mysql['landing_page_url'] = $db->real_escape_string((string)$_GET['page_url']);
		$mysql['user_id'] = $db->real_escape_string($user);
		$mysql['landing_page_time'] = time();

		if ($_GET['page_type'] == 'alp') {
			$sql = "INSERT INTO `202_landing_pages` 
					SET 
					aff_campaign_id = '0',
					landing_page_nickname = '".$mysql['landing_page_nickname']."',
					landing_page_url = '".$mysql['landing_page_url']."',
					landing_page_type = '1',
					user_id = '".$mysql['user_id']."',
					landing_page_time = '".$mysql['landing_page_time']."'";
			$result = $db->query($sql);
			$insert_id = $db->insert_id;
		} else if ($_GET['page_type'] == 'slp' && isset($_GET['slp_page_campaign'])) {
			$mysql['aff_campaign_id_public'] = $db->real_escape_string((string)$_GET['slp_page_campaign']);

			$sql = "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public = '".$mysql['aff_campaign_id_public']."' AND user_id = '".$mysql['user_id']."'";
			$result = $db->query($sql);
			
			if ($result->num_rows > 0) {
				$aff_campaign_id = $result->fetch_assoc();
				$sql = "INSERT INTO `202_landing_pages` 
						SET 
						aff_campaign_id = '".$aff_campaign_id['aff_campaign_id']."',
						landing_page_nickname = '".$mysql['landing_page_nickname']."',
						landing_page_url = '".$mysql['landing_page_url']."',
						landing_page_type = '0',
						user_id = '".$mysql['user_id']."',
						landing_page_time = '".$mysql['landing_page_time']."'";
				$result = $db->query($sql);
				$insert_id = $db->insert_id;
			}
		}

		if ($insert_id <= 0) {
			return ['error' => '1'];
		}

		$landing_page_id_public = random_int(1,9) . $insert_id . random_int(1,9);
		$landing_page_sql = "UPDATE `202_landing_pages` SET `landing_page_id_public`='".$landing_page_id_public."' WHERE `landing_page_id`='".$insert_id."'";
		$landing_page_result = $db->query($landing_page_sql);

		// Landing Page Optimizer (segments-v2 G10): WP-plugin LP creates
		// change the synced snapshot like the setup pages — flag this
		// user's snapshot dirty for the hourly push. DB-only, never fatal.
		\Prosper202\Lpo\DimensionSync::markDirty($db, (int) $user);

		if ($landing_page_result) {
			return ['error' => '0', 'lp_pid' => $landing_page_id_public];
		} else {
			return ['error' => '1'];
		}
	} else {
		return ['error' => true];
	}
}

function wpUpdateLp($db, $user): array {
	if (isset($_GET['page_type']) && isset($_GET['page_title']) && isset($_GET['page_url']) && isset($_GET['lp_pid'])) {
		$title = $_GET['page_title'];
		if (strlen((string) $title) > 45) {
			$title = substr($_GET['page_title'], 0, 41);
			$title = substr($title, 0, strrpos($title, ' ')) . " ...";
		}
		$title = '[WP] '.$title;
		$mysql['landing_page_nickname'] = $db->real_escape_string($title);
		$mysql['landing_page_url'] = $db->real_escape_string((string)$_GET['page_url']);
		$mysql['landing_page_id_public'] = $db->real_escape_string((string)$_GET['lp_pid']);
		$mysql['user_id'] = $db->real_escape_string($user);

		if ($_GET['page_type'] == 'alp') {
			$sql = "UPDATE `202_landing_pages` 
					SET 
					aff_campaign_id = '0',
					landing_page_nickname = '".$mysql['landing_page_nickname']."',
					landing_page_url = '".$mysql['landing_page_url']."',
					landing_page_type = '1',
					user_id = '".$mysql['user_id']."'
					WHERE landing_page_id_public = '".$mysql['landing_page_id_public']."' AND user_id = '".$mysql['user_id']."'";
			$result = $db->query($sql);

			if ($result) {
				// Landing Page Optimizer (segments-v2 G10): WP-plugin LP renames
				// change the synced snapshot — flag dirty for the hourly push.
				// Guarded: only a successful UPDATE can change the snapshot.
				\Prosper202\Lpo\DimensionSync::markDirty($db, (int) $user);
			}

			return ['error' => '0'];
		} else if ($_GET['page_type'] == 'slp' && isset($_GET['slp_page_campaign'])) {
			$mysql['aff_campaign_id_public'] = $db->real_escape_string((string)$_GET['slp_page_campaign']);

			$sql = "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public = '".$mysql['aff_campaign_id_public']."' AND user_id = '".$mysql['user_id']."'";
			$result = $db->query($sql);
			if ($result->num_rows > 0) {
				$aff_campaign_id = $result->fetch_assoc();
				$sql = "UPDATE `202_landing_pages` 
					SET 
					aff_campaign_id = '".$aff_campaign_id['aff_campaign_id']."',
					landing_page_nickname = '".$mysql['landing_page_nickname']."',
					landing_page_url = '".$mysql['landing_page_url']."',
					landing_page_type = '0',
					user_id = '".$mysql['user_id']."'
					WHERE landing_page_id_public = '".$mysql['landing_page_id_public']."' AND user_id = '".$mysql['user_id']."'";
				$result = $db->query($sql);

				if ($result) {
					// Landing Page Optimizer (segments-v2 G10): WP-plugin LP
					// renames/recampaigns change the synced snapshot — flag
					// dirty for the hourly push. Guarded: only a successful
					// UPDATE can change the snapshot.
					\Prosper202\Lpo\DimensionSync::markDirty($db, (int) $user);
				}

				return ['error' => '0'];
			}
		}
	}
	return ['error' => '1'];
}
