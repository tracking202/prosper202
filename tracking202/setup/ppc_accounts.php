<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

$slack = false;
$slack_pixel_added_message = false;
$error = [];
$html = [];
$selected = [];
$add_success = '';
$delete_success = '';
$network_editing = false;
$editing = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2u.install_hash, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

if (!empty($_GET['edit_ppc_account_id'])) {
	$editing = true;
} elseif (!empty($_GET['edit_ppc_network_id'])) {
	$network_editing = true;
	$mysql['ppc_network_id'] = $db->real_escape_string((string)$_GET['edit_ppc_network_id']);
}
$pixel_array = [];
$pixel_array[] = ['pixel_type_id' => '', 'pixel_code' => '', 'pixel_id' => ''];
$pixel_types = [];

$ppc_pixel_type_sql = "SELECT * FROM `202_pixel_types`";
$ppc_pixel_type_result = _mysqli_query($ppc_pixel_type_sql);

while ($ppc_pixel_type_row = $ppc_pixel_type_result->fetch_assoc()) {
	$pixel_types[] = ['pixel_type' => htmlentities((string)($ppc_pixel_type_row['pixel_type'] ?? ''), ENT_QUOTES, 'UTF-8'), 'pixel_type_id' => htmlentities((string)($ppc_pixel_type_row['pixel_type_id'] ?? ''), ENT_QUOTES, 'UTF-8')];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		$error['token'] = 'Invalid or expired form token. Please reload the page and try again.';
	}

	if (isset($_POST['ppc_network_name'])) {
		$ppc_network_name = trim((string) $_POST['ppc_network_name']);
		if (empty($ppc_network_name)) {
			$error['ppc_network_name'] = 'Type in the name the traffic source.';
		}

		if (empty($error)) {
			$mysql['ppc_network_id'] = isset($_POST['ppc_network_id']) ? $db->real_escape_string((string)$_POST['ppc_network_id']) : '';
			$mysql['ppc_network_name'] = $db->real_escape_string((string)$_POST['ppc_network_name']);
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
			$mysql['ppc_network_time'] = time();

			if ($network_editing == true) {
				$ppc_network_sql  = " UPDATE 202_ppc_networks SET";
			} else {
				$ppc_network_sql = "INSERT INTO `202_ppc_networks` SET";
			}
			$ppc_network_sql .= " `user_id`='" . $mysql['user_id'] . "',
								  `ppc_network_name`='" . $mysql['ppc_network_name'] . "',
								  `ppc_network_time`='" . $mysql['ppc_network_time'] . "'";
			if ($network_editing == true) {
				$ppc_network_sql  .= "WHERE ppc_network_id='" . $mysql['ppc_network_id'] . "'";
			}
			$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
			$add_success = true;
			// Landing Page Optimizer (segments-v2 G10): flag this user's dimension
			// snapshot dirty; the hourly cron pushes it. DB-only — no HTTP here.
			\Prosper202\Lpo\DimensionSync::markDirty($db, (int) ($_SESSION['user_id'] ?? 0));
			if ($network_editing == true) {
				if ($slack)
					$slack->push('traffic_source_name_changed', ['old_name' => $_GET['edit_ppc_network_name'], 'new_name' => $_POST['ppc_network_name'], 'user' => $user_row['username']]);
				//if editing true, refresh back with the edit get variable GONE GONE!
				header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
			} else {
				if ($slack)
					$slack->push('traffic_source_created', ['name' => $_POST['ppc_network_name'], 'user' => $user_row['username']]);
			}

			tagUserByNetwork($user_row['install_hash'], 'traffic-sources', $_POST['ppc_network_name']);
		}
	}

	if (isset($_POST['ppc_network_id']) && ($network_editing == false)) {

		$pixel_ids = [];
		// pixel id => [type, correction URL], saved once the pixels are.
		$correctionPixels = [];

		$ppc_account_name = trim((string) $_POST['ppc_account_name']);
		$do_edit_ppc_account = trim(filter_input(INPUT_POST, 'do_edit_ppc_account', FILTER_SANITIZE_NUMBER_INT));
		if ($ppc_account_name == '' && $do_edit_ppc_account == '1') {
			$error['ppc_account_name'] = 'What is the username for this account?';
		}

		$ppc_network_id = trim((string) $_POST['ppc_network_id']);
		if ($ppc_network_id == '') {
			$error['ppc_network_id'] = 'What traffic source is this account attached to?';
		}

		// A correction URL (plan §5.5, PR 11) is read before anything is
		// written: a server-to-server pixel's only, http(s) addresses matched
		// to the pixel code's URLs by position (at most one per URL).
		// Refused here, it is refused with the rest of the form, which keeps
		// what was typed; the pixels below are never half-saved around it.
		foreach ((array) ($_POST['pixel_correction_url'] ?? []) as $key => $correctionUrl) {
			$correctionUrl = trim((string) $correctionUrl);
			if ($correctionUrl === '') {
				continue;
			}
			if ((string) ($_POST['pixel_type_id'][$key] ?? '') !== (string) \Prosper202\Notifications\CorrectionUrls::SERVER_PIXEL_TYPE) {
				$error['pixel_correction_url'] = 'A correction URL goes on a server-to-server (Postback URL) pixel only: the other pixel types are fired by a browser, which is not there when a correction is sent.';
				break;
			}
			$problem = \Prosper202\Notifications\CorrectionUrls::problem($correctionUrl, trim((string) ($_POST['pixel_code'][$key] ?? '')));
			if ($problem !== null) {
				$error['pixel_correction_url'] = $problem;
				break;
			}
		}

		if (empty($error)) {
			//check to see if this user is the owner of the ppc network hes trying to add an account to
			$mysql['ppc_network_id'] = $db->real_escape_string((string)($_POST['ppc_network_id'] ?? ''));
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

			$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_network_id`='" . $mysql['ppc_network_id'] . "'";
			$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
			if ($ppc_network_result->num_rows == 0) {
				$error['wrong_user'] = 'You are not authorized to add an account to another user\'s traffic source';
			}
		}
		if (empty($error)) {
			//check to see if this user is the owner of the ppc network hes trying to edit
			$mysql['ppc_network_id'] = $db->real_escape_string((string)($_POST['ppc_network_id'] ?? ''));
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

			$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_network_id`='" . $mysql['ppc_network_id'] . "'";
			$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
			if ($ppc_network_result->num_rows == 0) {
				$error['wrong_user'] = 'You are not authorized to add an account to another user\'s traffic source';
			}
		}
		if (empty($error)) {
			//if editing, check to make sure the own the ppc account they are editing
			if ($editing == true) {
				$mysql['ppc_account_id'] = $db->real_escape_string((string)$_GET['edit_ppc_account_id']);
				$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
				$ppc_account_sql = "SELECT * FROM `202_ppc_accounts` LEFT JOIN 202_ppc_account_pixels USING (ppc_account_id) LEFT JOIN 202_pixel_types USING (pixel_type_id) WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_account_id`='" . $mysql['ppc_account_id'] . "'";
				$ppc_account_result = _mysqli_query($ppc_account_sql); //($ppc_account_sql);
				if ($ppc_account_result->num_rows == 0) {
					$error['wrong_user'] = ($error['wrong_user'] ?? '') . 'You are not authorized to modify another user\'s traffic source account';
				}

				$ppc_old_account_row = $ppc_account_result->fetch_assoc();
			}
		}

		if (empty($error)) {

			$ppc_network_row = $ppc_network_result->fetch_assoc();
			$mysql['ppc_network_id'] = $db->real_escape_string((string)($_POST['ppc_network_id'] ?? ''));
			$mysql['ppc_account_name'] = $db->real_escape_string((string)$_POST['ppc_account_name']);
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
			$mysql['ppc_account_time'] = time();

			if ($editing == true) {
				$ppc_account_sql  = " UPDATE 202_ppc_accounts SET";
			} else {
				$ppc_account_sql  = " INSERT INTO 202_ppc_accounts SET";
			}

			$ppc_account_sql .= " ppc_account_name='" . $mysql['ppc_account_name'] . "',
								  ppc_network_id='" . $mysql['ppc_network_id'] . "',
								  user_id='" . $mysql['user_id'] . "',
								  ppc_account_time='" . $mysql['ppc_account_time'] . "'";

			if ($editing == true) {
				$ppc_account_sql  .= "WHERE ppc_account_id='" . $mysql['ppc_account_id'] . "'";
			}

			$ppc_account_result = _mysqli_query($ppc_account_sql); //($ppc_account_sql);
			$add_success = true;
			// Cast to int: this id is interpolated into SQL without quotes, where
			// real_escape_string() would not prevent injection in a numeric context.
			$the_ppc_account_id = (int)($db->insert_id != 0 ? $db->insert_id : $mysql['ppc_account_id']);
			// Landing Page Optimizer (segments-v2 G10): flag this user's dimension
			// snapshot dirty; the hourly cron pushes it. DB-only — no HTTP here.
			// (After the insert_id capture above: markDirty's queries reset it.)
			\Prosper202\Lpo\DimensionSync::markDirty($db, (int) ($_SESSION['user_id'] ?? 0));

			foreach ($_POST['pixel_type_id'] as $key => $value) {
				$mysql['pixel_type_id'] = $db->real_escape_string($value);
				$mysql['pixel_id'] = $db->real_escape_string($_POST['pixel_id'][$key]);

				$pixel_type_sql = "SELECT * FROM `202_pixel_types` WHERE pixel_type_id = '" . $mysql['pixel_type_id'] . "'";
				$pixel_type_result = _mysqli_query($pixel_type_sql);
				$pixel_type_row = $pixel_type_result->fetch_assoc();

				$pixelCode = trim((string) $_POST['pixel_code'][$key]);
				$mysql['pixel_code'] = $db->real_escape_string($pixelCode);

				if ($mysql['pixel_code'] != "" && $mysql['pixel_type_id'] != "") {

					if ($mysql['pixel_id'] != "") {
						$pixel_sql = "UPDATE 202_ppc_account_pixels SET pixel_code='" . $mysql['pixel_code'] . "', pixel_type_id=" . (int)$mysql['pixel_type_id'] . " WHERE pixel_id=" . (int)$mysql['pixel_id'] . " AND ppc_account_id=" . $the_ppc_account_id;

						if ($slack) {
							if ($ppc_old_account_row['pixel_type_id'] != $value) {
								$slack->push('traffic_source_account_pixel_type_changed', ['network_name' => $ppc_network_row['ppc_network_name'], 'account_name' => $ppc_old_account_row['ppc_account_name'], 'old_pixel_type' => $ppc_old_account_row['pixel_type'], 'new_pixel_type' => $pixel_type_row['pixel_type'], 'user' => $user_row['username']]);
							}

							if ($ppc_old_account_row['pixel_code'] != $_POST['pixel_code'][$key]) {
								$slack->push('traffic_source_account_pixel_code_changed', ['network_name' => $ppc_network_row['ppc_network_name'], 'account_name' => $ppc_old_account_row['ppc_account_name'], 'user' => $user_row['username']]);
							}
						}
						$db->query($pixel_sql);
						$pixel_ids[] = (int)$mysql['pixel_id'];
						$correctionPixels[(int)$mysql['pixel_id']] = [(int)$mysql['pixel_type_id'], trim((string) ($_POST['pixel_correction_url'][$key] ?? ''))];
					} else {
						$pixel_sql = "INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code,pixel_type_id)
								VALUES(" . $the_ppc_account_id . ",'"
							. $mysql['pixel_code'] . "',"
							. (int)$mysql['pixel_type_id'] . ")";

						$slack_pixel_added_message_vars = ['type' => $pixel_type_row['pixel_type'], 'network_name' => $ppc_network_row['ppc_network_name'], 'account_name' => $_POST['ppc_account_name'], 'user' => $user_row['username']];
						$slack_pixel_added_message = true;

						$db->query($pixel_sql);
						$pixel_ids[] = $db->insert_id;
						if ((int) $db->insert_id > 0) {
							$correctionPixels[(int) $db->insert_id] = [(int)$mysql['pixel_type_id'], trim((string) ($_POST['pixel_correction_url'][$key] ?? ''))];
						}
					}

					$sql = "DELETE FROM 202_ppc_account_pixels WHERE pixel_id NOT IN (" . implode(",", $pixel_ids) . ") AND ppc_account_id=" . $the_ppc_account_id;
					//_mysqli_query($sql);
				}

				if ($editing == true) {
					if ($slack) {
						if ($ppc_old_account_row['ppc_account_name'] != $_POST['ppc_account_name']) {
							$slack->push('traffic_source_account_name_changed', ['network_name' => $ppc_network_row['ppc_network_name'], 'old_account_name' => $ppc_old_account_row['ppc_account_name'], 'new_account_name' => $_POST['ppc_account_name'], 'user' => $user_row['username']]);
						}
					}
					//if editing true, refresh back with the edit get variable GONE GONE!
					//_mysqli_query($sql);

				} else {
					if ($slack) {
						$slack->push('traffic_source_account_created', ['account_name' => $_POST['ppc_account_name'], 'network_name' => $ppc_network_row['ppc_network_name'], 'user' => $user_row['username']]);

						if ($slack_pixel_added_message) {
							$slack->push('traffic_source_account_pixel_added', $slack_pixel_added_message_vars);
						}
					}
				}
			}
			if (isset($sql) && !empty($sql)) {
				_mysqli_query($sql);
			}
			// Each server pixel's correction URL, set or cleared; a pixel that
			// stopped being a server pixel, or stopped existing, keeps none.
			// saveForAccount() reads which pixels the account has, and their
			// types, itself: the ids above came from the request.
			(new \Prosper202\Notifications\CorrectionUrls(new \Prosper202\Database\Connection($db)))->saveForAccount(
				(int) $_SESSION['user_id'],
				$the_ppc_account_id,
				array_map(static fn (array $pixel): string => $pixel[1], $correctionPixels),
				time()
			);
			header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
		}
	}
}

if (isset($_GET['delete_ppc_network_id'])) {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_GET['token'] ?? ''))) {
		header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
		die();
	}

	if ($userObj->hasPermission("remove_traffic_source")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['ppc_network_id'] = $db->real_escape_string((string)$_GET['delete_ppc_network_id']);
		$mysql['ppc_network_time'] = time();

		$delete_sql = " UPDATE  `202_ppc_networks`
						SET     `ppc_network_deleted`='1',
								`ppc_network_time`='" . $mysql['ppc_network_time'] . "'
						WHERE   `user_id`='" . $mysql['user_id'] . "'
						AND     `ppc_network_id`='" . $mysql['ppc_network_id'] . "'";
		if ($delete_result = _mysqli_query($delete_sql)) { //($delete_result)) {
			$delete_success = true;
			// Landing Page Optimizer (segments-v2 G10): deletes change the
			// synced snapshot too (buildSnapshot omits deleted rows) — flag
			// dirty so the hourly cron re-pushes. DB-only — no HTTP here.
			\Prosper202\Lpo\DimensionSync::markDirty($db, (int) ($_SESSION['user_id'] ?? 0));
			if ($slack)
				$slack->push('traffic_source_deleted', ['name' => $_GET['delete_ppc_network_name'], 'user' => $user_row['username']]);
		}
	} else {
		header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
	}
}

if (isset($_GET['delete_ppc_account_id'])) {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_GET['token'] ?? ''))) {
		header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
		die();
	}

	if ($userObj->hasPermission("remove_traffic_source_account")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['ppc_account_id'] = $db->real_escape_string((string)$_GET['delete_ppc_account_id']);
		$mysql['ppc_account_time'] = time();

		$delete_sql = " UPDATE  `202_ppc_accounts`
						SET     `ppc_account_deleted`='1',
								`ppc_account_time`='" . $mysql['ppc_account_time'] . "'
						WHERE   `user_id`='" . $mysql['user_id'] . "'
						AND     `ppc_account_id`='" . $mysql['ppc_account_id'] . "'";
		if ($delete_result = _mysqli_query($delete_sql)) {
			$delete_success = true;
			// Landing Page Optimizer (segments-v2 G10): deletes change the
			// synced snapshot too (buildSnapshot omits deleted rows) — flag
			// dirty so the hourly cron re-pushes. DB-only — no HTTP here.
			\Prosper202\Lpo\DimensionSync::markDirty($db, (int) ($_SESSION['user_id'] ?? 0));
			if ($slack)
				$slack->push('traffic_source_account_deleted', ['account_name' => $_GET['delete_ppc_account_name'], 'user' => $user_row['username']]);
		}
	} else {
		header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
	}
}

if (!empty($_GET['edit_ppc_network_id'])) {

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['ppc_network_id'] = $db->real_escape_string((string)$_GET['edit_ppc_network_id']);

	$ppc_network_sql = "SELECT  *
						 FROM   `202_ppc_networks`
						 WHERE  `ppc_network_id`='" . $mysql['ppc_network_id'] . "'
						 AND    `user_id`='" . $mysql['user_id'] . "'";
	$ppc_network_result = _mysqli_query($ppc_network_sql);
	$ppc_network_row = $ppc_network_result->fetch_assoc() ?? [];

	$html['ppc_network_name'] = htmlentities((string)($ppc_network_row['ppc_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	$autocomplete_ppc_network_name =  $html['ppc_network_name'];
}

if (!empty($_GET['edit_ppc_account_id'])) {

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['ppc_account_id'] = $db->real_escape_string((string)$_GET['edit_ppc_account_id']);

	$ppc_account_sql = "SELECT  *
						 FROM   `202_ppc_accounts`
						 WHERE  `ppc_account_id`='" . $mysql['ppc_account_id'] . "'
						 AND    `user_id`='" . $mysql['user_id'] . "'";
	$ppc_account_result = _mysqli_query($ppc_account_sql); //($ppc_account_sql);
	$ppc_account_row = $ppc_account_result->fetch_assoc() ?? [];

	$selected['ppc_network_id'] = $ppc_account_row['ppc_network_id'] ?? '';
	$html['ppc_account_name'] = htmlentities((string)($ppc_account_row['ppc_account_name'] ?? ''), ENT_QUOTES, 'UTF-8');


	$selected['ppc_network_id'] = $ppc_account_row['ppc_network_id'] ?? '';
	$ppc_account_pixel_sql = "SELECT  *
						 FROM   `202_ppc_account_pixels`
						 WHERE  `ppc_account_id`=" . (int) ($ppc_account_row['ppc_account_id'] ?? 0) . "";
	//echo $ppc_account_pixel_sql;
	$ppc_account_pixel_result = _mysqli_query($ppc_account_pixel_sql); //($ppc_account_sql);

	$pixel_array = [];

	if ($ppc_account_pixel_result->num_rows > 0) {
		while ($ppc_account_pixel_row = $ppc_account_pixel_result->fetch_assoc()) {
			// Raw values: the v2 form escapes on output, once. (The classic
			// form escaped Raw pixels here and printed the others unescaped.)
			if ($ppc_account_pixel_row['pixel_type_id'] == 5) {
				$selected['pixel_code'] = stripslashes((string) $ppc_account_pixel_row['pixel_code']);
			} else {
				$selected['pixel_code'] = $ppc_account_pixel_row['pixel_code'];
			}

			$pixel_array[] = ['pixel_type_id' => $ppc_account_pixel_row['pixel_type_id'], 'pixel_code' => $selected['pixel_code'], 'pixel_id' => $ppc_account_pixel_row['pixel_id']];
		}
	}
	$correctionUrls = (new \Prosper202\Notifications\CorrectionUrls(new \Prosper202\Database\Connection($db)))
		->forPixels((int) $_SESSION['user_id'], array_map(static fn (array $p): int => (int) $p['pixel_id'], $pixel_array));
	foreach ($pixel_array as &$pixelWithCorrection) {
		$pixelWithCorrection['correction_url'] = $correctionUrls[(int) $pixelWithCorrection['pixel_id']] ?? '';
	}
	unset($pixelWithCorrection);
}

if (!empty($error)) {
	//if someone happend take the post stuff and add it
	$selected['ppc_network_id'] = $_POST['ppc_network_id'] ?? '';
	$html['ppc_account_name'] = htmlentities((string)($_POST['ppc_account_name'] ?? ''), ENT_QUOTES, 'UTF-8');
}


// Post-redirect-get: a saved source or account, and a removal, answer with a
// redirect, so a reload cannot submit the form a second time.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $add_success == true) {
	$flash = isset($_POST['ppc_network_name']) ? ($network_editing ? 'source_saved' : 'source_added') : ($editing ? 'account_saved' : 'account_added');
	header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php?' . $flash . '=1');
	exit;
}
if ($delete_success == true) {
	header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php?deleted=1');
	exit;
}

require_once __DIR__ . '/_includes/setup_ui.php';

$base = get_absolute_url();
$self = $base . 'tracking202/setup/ppc_accounts.php';
$token = (string) ($_SESSION['token'] ?? '');
$uid = $db->real_escape_string((string) $_SESSION['user_id']);
$posted = $_SERVER['REQUEST_METHOD'] == 'POST';
$sources = p202_setup_rows($db, "SELECT * FROM `202_ppc_networks` WHERE `user_id`='" . $uid . "' AND `ppc_network_deleted`='0' ORDER BY `ppc_network_name` ASC");
$accountsBySource = [];
foreach (p202_setup_rows($db, "SELECT ppc_account_id, ppc_account_name, ppc_network_id FROM `202_ppc_accounts` WHERE `user_id`='" . $uid . "' AND `ppc_account_deleted`='0' ORDER BY `ppc_account_name` ASC") as $account) {
	$accountsBySource[(int) $account['ppc_network_id']][] = $account;
}
$variablesBySource = [];
foreach (p202_setup_rows($db, "SELECT pv.ppc_variable_id, pv.ppc_network_id, pv.name, pv.parameter, pv.placeholder FROM 202_ppc_network_variables AS pv INNER JOIN 202_ppc_networks AS pn ON (pn.ppc_network_id = pv.ppc_network_id) WHERE pn.user_id='" . $uid . "' AND pv.deleted = 0 ORDER BY pv.ppc_variable_id ASC") as $variable) {
	$variablesBySource[(int) $variable['ppc_network_id']][] = [
		'id' => (string) $variable['ppc_variable_id'],
		'name' => (string) $variable['name'],
		'parameter' => (string) $variable['parameter'],
		'placeholder' => (string) $variable['placeholder'],
	];
}
$pixelTypes = [];
foreach (p202_setup_rows($db, "SELECT pixel_type_id, pixel_type FROM `202_pixel_types` ORDER BY pixel_type_id ASC") as $type) {
	$pixelTypes[(string) $type['pixel_type_id']] = (string) $type['pixel_type'];
}

// The two forms' values: what was just refused, or the row being edited.
$sourceFormName = isset($_POST['ppc_network_name']) ? (string) $_POST['ppc_network_name'] : (string) ($ppc_network_row['ppc_network_name'] ?? '');
$accountPosted = $posted && !isset($_POST['ppc_network_name']);
$accountSource = $accountPosted ? (string) ($_POST['ppc_network_id'] ?? '') : (string) ($selected['ppc_network_id'] ?? '');
$accountName = $accountPosted ? (string) ($_POST['ppc_account_name'] ?? '') : (string) ($ppc_account_row['ppc_account_name'] ?? '');
if ($accountPosted) {
	// A refused save keeps the pixels that were typed; the classic form lost them.
	$pixel_array = [];
	foreach ((array) ($_POST['pixel_type_id'] ?? []) as $key => $typeId) {
		$pixel_array[] = [
			'pixel_type_id' => (string) $typeId,
			'pixel_code' => (string) ($_POST['pixel_code'][$key] ?? ''),
			'pixel_id' => (string) ($_POST['pixel_id'][$key] ?? ''),
			'correction_url' => (string) ($_POST['pixel_correction_url'][$key] ?? ''),
		];
	}
}
if ($pixel_array === []) {
	// The handler loops over pixel_type_id[], so the form always posts one row.
	$pixel_array[] = ['pixel_type_id' => '', 'pixel_code' => '', 'pixel_id' => ''];
}
$hasPixels = false;
foreach ($pixel_array as $pixel) {
	if (trim((string) $pixel['pixel_code']) !== '' || (string) $pixel['pixel_type_id'] !== '') {
		$hasPixels = true;
	}
}
$sourceOptions = [];
foreach ($sources as $source) {
	$sourceOptions[(string) $source['ppc_network_id']] = (string) $source['ppc_network_name'];
}
$editSourceId = $network_editing ? (int) ($_GET['edit_ppc_network_id'] ?? 0) : 0;
$editAccountId = $editing ? (int) ($_GET['edit_ppc_account_id'] ?? 0) : 0;
// With no source yet, adding one IS the page; after that, adding accounts is.
$sourceIsPrimary = $sources === [] || $network_editing;
if ($accountSource === '' && count($sourceOptions) === 1) {
	// One source: the account can only belong to it, so it is chosen.
	$accountSource = (string) array_key_first($sourceOptions);
}

$pixelRow = static function (array $pixel, string $index, array $pixelTypes): string {
	$typeId = 'pixel-type-' . $index;
	$codeId = 'pixel-code-' . $index;
	$correctionId = 'pixel-correction-' . $index;
	return '<div class="p202-panel mb-3" data-p202-row>'
		. '<div class="p202-panel__body">'
		. '<div class="mb-2"><label class="form-label" for="' . $typeId . '">Pixel type</label>'
		. '<select class="form-select" id="' . $typeId . '" name="pixel_type_id[]">' . p202_setup_options($pixelTypes, $pixel['pixel_type_id'], 'None') . '</select></div>'
		. '<div class="mb-2"><label class="form-label" for="' . $codeId . '">Pixel code</label>'
		. '<textarea class="form-control font-monospace" id="' . $codeId . '" name="pixel_code[]" rows="3">' . p202_setup_e($pixel['pixel_code']) . '</textarea>'
		. '<div class="form-text">For every type except Raw, paste only the URL from the pixel\'s src.</div></div>'
		. '<div class="mb-2"><label class="form-label" for="' . $correctionId . '">Correction URL <span class="text-body-secondary">Postback URL pixels only, optional</span></label>'
		. '<input type="text" inputmode="url" spellcheck="false" class="form-control font-monospace" id="' . $correctionId . '" name="pixel_correction_url[]" value="' . p202_setup_e($pixel['correction_url'] ?? '') . '" placeholder="https://network.example/correct?tx=[[transactionid]]&amp;value=[[p202_goal_value]]">'
		. '<div class="form-text">Empty by default: most networks cannot take a correction. For a pixel with several URLs, one per URL in the same order. When a goal this pixel already announced is replaced, the correction goes here with <code>[[p202_goal_value]]</code> (the value now), <code>[[p202_previous_value]]</code>, <code>[[p202_original_conv_id]]</code> and <code>[[p202_notification]]</code> filled in.</div></div>'
		. '<input type="hidden" name="pixel_id[]" value="' . p202_setup_e($pixel['pixel_id']) . '">'
		. ($index !== '0' ? '<button type="button" class="btn btn-link btn-sm text-danger p-0" data-p202-remove-row>Remove this pixel</button>' : '')
		. '</div></div>';
};

template_top('Traffic Sources', ['ui' => 'v2']); ?>

<div class="p202-page-header p202-page-header--accent">
	<div class="p202-page-header__icon"><i class="bi bi-globe"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Traffic Sources</h1>
		<p class="p202-page-header__desc">Where your clicks come from, and the accounts you buy them with, so each account reports on its own.</p>
	</div>
</div>

<?php
echo p202_setup_query_flashes([
	'source_added' => 'Traffic source added. Add the account you buy it with next.',
	'source_saved' => 'Traffic source renamed.',
	'account_added' => 'Account added.',
	'account_saved' => 'Account saved.',
	'deleted' => 'Removed. Clicks already tracked keep their history.',
	'variables_saved' => 'Custom variables saved. New tracking links for this source carry them.',
], $_GET);
if ($error) {
	echo p202_setup_error_flashes($error, ['ppc_network_name', 'ppc_network_id', 'ppc_account_name']);
	if (isset($error['pixel_correction_url'])) {
		// The pixels sit under a disclosure, so the sentence is said at the
		// top as well as opening it (below).
		$hasPixels = true;
	}
}
?>

<div class="row g-4">
	<div class="col-12 col-lg-6">
		<section class="p202-panel mb-4" id="source-form">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title"><?php echo $network_editing ? 'Rename traffic source' : 'Add a traffic source'; ?></h2>
				<p class="p202-panel__sub">Facebook Ads, Google Ads, a newsletter: anywhere you send clicks from.</p>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo p202_setup_e($network_editing ? $self . '?edit_ppc_network_id=' . $editSourceId . '&edit_ppc_network_name=' . rawurlencode((string) ($_GET['edit_ppc_network_name'] ?? '')) : $self); ?>">
					<?php echo p202_setup_token_field($token); ?>
					<?php if ($network_editing) { ?>
						<input type="hidden" name="ppc_network_id" value="<?php echo $editSourceId; ?>">
					<?php } ?>
					<div class="mb-3">
						<label class="form-label" for="ppc_network_name">Traffic source name</label>
						<input type="text" class="form-control<?php echo p202_setup_invalid($error, 'ppc_network_name'); ?>" id="ppc_network_name" name="ppc_network_name" value="<?php echo p202_setup_e($sourceFormName); ?>" maxlength="255" required<?php echo $sourceIsPrimary ? ' autofocus' : ''; ?>>
						<?php echo p202_setup_feedback($error, 'ppc_network_name'); ?>
					</div>
					<div class="p202-form-actions">
						<?php if ($network_editing) { ?>
							<a class="btn btn-link" href="<?php echo p202_setup_e($self); ?>">Cancel</a>
						<?php } ?>
						<button type="submit" class="btn <?php echo $sourceIsPrimary ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $network_editing ? 'Save changes' : 'Add traffic source'; ?></button>
					</div>
				</form>
			</div>
		</section>

		<?php if ($sources !== [] && !$network_editing) { ?>
		<section class="p202-panel" id="account-form">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title"><?php echo $editing ? 'Edit account' : 'Add an account'; ?></h2>
				<p class="p202-panel__sub">Two Facebook ad accounts? Add both, and each reports on its own.</p>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo p202_setup_e($editing ? $self . '?edit_ppc_account_id=' . $editAccountId : $self); ?>">
					<?php echo p202_setup_token_field($token); ?>
					<input type="hidden" name="do_edit_ppc_account" value="1">
					<div class="mb-3">
						<label class="form-label" for="ppc_network_id">Traffic source</label>
						<select class="form-select<?php echo p202_setup_invalid($error, 'ppc_network_id'); ?>" id="ppc_network_id" name="ppc_network_id" required>
							<?php echo p202_setup_options($sourceOptions, $accountSource, 'Choose a traffic source'); ?>
						</select>
						<?php echo p202_setup_feedback($error, 'ppc_network_id'); ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="ppc_account_name">Account username</label>
						<input type="text" class="form-control<?php echo p202_setup_invalid($error, 'ppc_account_name'); ?>" id="ppc_account_name" name="ppc_account_name" value="<?php echo p202_setup_e($accountName); ?>" maxlength="255" required<?php echo $editing ? ' autofocus' : ''; ?>>
						<div class="form-text">The name you know the account by at the traffic source.</div>
						<?php echo p202_setup_feedback($error, 'ppc_account_name'); ?>
					</div>
					<details class="p202-disclosure mb-3" data-p202-remember="setup-traffic-sources-pixels"<?php echo $hasPixels ? ' open' : ''; ?>>
						<summary>Advanced <span class="p202-disclosure__hint">pixels this account fires on a conversion</span></summary>
						<div class="p202-disclosure__body">
							<p class="form-text mt-0">Optional; none by default. A conversion is tracked either way, and a pixel also reports it back to the traffic source.</p>
							<div id="pixel-rows">
								<?php foreach ($pixel_array as $index => $pixel) {
									echo $pixelRow($pixel, (string) $index, $pixelTypes);
								} ?>
							</div>
							<template id="pixel-row-template"><?php echo $pixelRow(['pixel_type_id' => '', 'pixel_code' => '', 'pixel_id' => '', 'correction_url' => ''], 'new', $pixelTypes); ?></template>
							<button type="button" class="btn btn-secondary btn-sm" data-p202-add-row="#pixel-row-template" data-p202-add-into="#pixel-rows"><i class="bi bi-plus"></i> Add a pixel</button>
						</div>
					</details>
					<div class="p202-form-actions">
						<?php if ($editing) { ?>
							<a class="btn btn-link" href="<?php echo p202_setup_e($self); ?>">Cancel</a>
						<?php } ?>
						<button type="submit" class="btn btn-primary"><?php echo $editing ? 'Save changes' : 'Add account'; ?></button>
					</div>
				</form>
			</div>
		</section>
		<?php } ?>
	</div>

	<div class="col-12 col-lg-6">
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Your traffic sources</h2>
				<span class="p202-pill p202-pill--accent"><?php echo count($sources) . ' ' . (count($sources) === 1 ? 'source' : 'sources'); ?></span>
				<?php if (count($sources) > 5) { ?>
					<div class="p202-panel__aside"><?php echo p202_setup_list_filter('source-list', 'Filter sources or accounts…'); ?></div>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<?php if ($sources === []) { ?>
					<div class="p202-empty">
						<i class="bi bi-globe p202-empty__icon"></i>
						<strong class="p202-empty__title">No traffic sources yet</strong>
						<div>Name the first place you buy clicks from; its accounts come next.</div>
						<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="#ppc_network_name">Name your first traffic source</a></div>
					</div>
				<?php } else { ?>
					<ul class="p202-list" id="source-list">
						<?php foreach ($sources as $source) {
							$sid = (int) $source['ppc_network_id'];
							$sname = (string) $source['ppc_network_name'];
							$accounts = $accountsBySource[$sid] ?? []; ?>
							<li class="p202-list__item<?php echo $editSourceId === $sid ? ' is-active' : ''; ?>" data-p202-filter-text="<?php echo p202_setup_e($sname); ?>">
								<span class="p202-list__name"><?php echo p202_setup_e($sname); ?></span>
								<span class="p202-pill"><?php echo count($accounts) . ' ' . (count($accounts) === 1 ? 'account' : 'accounts'); ?></span>
								<span class="p202-list__actions">
									<a class="p202-list__action" href="<?php echo p202_setup_e($self . '?edit_ppc_network_id=' . $sid . '&edit_ppc_network_name=' . rawurlencode($sname)); ?>">edit</a>
									<?php if ($userObj->hasPermission("remove_traffic_source")) { ?>
										<button type="button" class="p202-list__action" data-bs-toggle="modal" data-bs-target="#variables-modal" data-ppc-network-id="<?php echo $sid; ?>" data-ppc-network-name="<?php echo p202_setup_e($sname); ?>" data-variables="<?php echo p202_setup_e(json_encode($variablesBySource[$sid] ?? [], JSON_THROW_ON_ERROR)); ?>">variables</button>
										<?php echo p202_setup_remove_form($self, [
											'delete_ppc_network_id' => $sid,
											'delete_ppc_network_name' => $sname,
											'token' => $token,
										], 'Remove the traffic source "' . $sname . '"? Clicks already tracked keep their history.'); ?>
									<?php } ?>
								</span>
								<?php if ($accounts !== []) { ?>
									<ul class="p202-list__children">
										<?php foreach ($accounts as $account) {
											$aid = (int) $account['ppc_account_id'];
											$aname = (string) $account['ppc_account_name']; ?>
											<li class="p202-list__item<?php echo $editAccountId === $aid ? ' is-active' : ''; ?>" data-p202-filter-text="<?php echo p202_setup_e($aname); ?>">
												<span class="p202-list__name"><?php echo p202_setup_e($aname); ?></span>
												<span class="p202-list__actions">
													<a class="p202-list__action" href="<?php echo p202_setup_e($self . '?edit_ppc_account_id=' . $aid); ?>">edit</a>
													<?php if ($userObj->hasPermission("remove_traffic_source_account")) {
														echo p202_setup_remove_form($self, [
															'delete_ppc_account_id' => $aid,
															'delete_ppc_account_name' => $aname,
															'token' => $token,
														], 'Remove the account "' . $aname . '"? Clicks already tracked keep their history.');
													} ?>
												</span>
											</li>
										<?php } ?>
									</ul>
								<?php } else { ?>
									<span class="p202-list__meta">No accounts yet</span>
								<?php } ?>
							</li>
						<?php } ?>
					</ul>
				<?php } ?>
			</div>
		</section>
	</div>
</div>

<div class="modal fade" id="variables-modal" tabindex="-1" aria-labelledby="variables-modal-title" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="variables-modal-title">Custom variables</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<form id="variables-form" data-variables-url="<?php echo p202_setup_e($base . 'tracking202/ajax/custom_variables.php'); ?>">
				<div class="modal-body">
					<p class="text-body-secondary">Extra parameters this source's tracking links carry, each with the placeholder the traffic source fills in, as in <code>p202.com?parameter=[[placeholder]]</code>.</p>
					<div data-variables-error></div>
					<div id="variable-rows"></div>
					<template id="variable-row-template">
						<div class="row g-2 mb-2 align-items-end" data-p202-row data-var-id="false">
							<div class="col-12 col-sm-4"><label class="form-label small w-100">Name in reports<input type="text" class="form-control form-control-sm" data-var="name" required></label></div>
							<div class="col-6 col-sm-3"><label class="form-label small w-100">Parameter<input type="text" class="form-control form-control-sm" data-var="parameter" required></label></div>
							<div class="col-6 col-sm-4"><label class="form-label small w-100">Placeholder<input type="text" class="form-control form-control-sm" data-var="placeholder" required></label></div>
							<div class="col-12 col-sm-1"><button type="button" class="btn btn-link btn-sm text-danger" data-p202-remove-row aria-label="Remove this variable"><i class="bi bi-x-lg"></i></button></div>
						</div>
					</template>
					<button type="button" class="btn btn-secondary btn-sm" data-p202-add-row="#variable-row-template" data-p202-add-into="#variable-rows"><i class="bi bi-plus"></i> Add a variable</button>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
					<button type="submit" class="btn btn-primary" data-variables-save>Save variables</button>
				</div>
			</form>
		</div>
	</div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom(); ?>
