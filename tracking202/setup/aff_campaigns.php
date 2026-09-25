<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

// Initialize variables to prevent undefined variable warnings
if (!isset($error)) $error = [];
if (!isset($html)) $html = [];
if (!isset($selected)) $selected = [];

// Initialize default HTML values
$html['aff_campaign_rotate'] = '0';
if (!isset($add_success)) $add_success = false;
if (!isset($delete_success)) $delete_success = false;
if (!isset($editing)) $editing = false;
if (!isset($copying)) $copying = false;
if (!isset($getDlDniRow)) $getDlDniRow = null;
if (!isset($aff_campaign_row)) $aff_campaign_row = null;
if (!isset($aff_network_row)) $aff_network_row = null;

$slack = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url, 2u.install_hash FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

$rotateUrlCampaignsSql = "SELECT * FROM 202_aff_campaigns WHERE user_id = '" . $mysql['user_id'] . "' AND aff_campaign_deleted = 0 AND aff_campaign_rotate = 1";
$rotateUrlCampaignsResults = $db->query($rotateUrlCampaignsSql);

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

if (!empty($_GET['edit_aff_campaign_id'])) {
	$editing = true;
}

if (!empty($_GET['copy_aff_campaign_id'])) {
	$copying = true;
}

// The Goals panel's two forms (add or edit a goal, archive one) post here
// with goal_action; they are the campaign's goals, not the campaign, so the
// campaign handler below never sees them. Every write goes through the REST
// controller (_includes/campaign_goals.php).
require_once __DIR__ . '/_includes/campaign_goals.php';
$goalPost = $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['goal_action']);
$goalErrors = [];
if ($goalPost) {
	$goalCampaignId = (int) ($_GET['edit_aff_campaign_id'] ?? 0);
	$goalAction = (string) $_POST['goal_action'];
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		$goalErrors['goal'] = 'Invalid or expired form token. Please reload the page and try again.';
	} else {
		$goalOwnerStmt = $db->prepare('SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id = ? AND user_id = ? AND aff_campaign_deleted = 0 LIMIT 1');
		$goalOwner = null;
		if ($goalOwnerStmt !== false) {
			$goalSessionUser = (int) $_SESSION['user_id'];
			$goalOwnerStmt->bind_param('ii', $goalCampaignId, $goalSessionUser);
			if (!$goalOwnerStmt->execute()) {
				$goalOwnerStmt->close();
				throw new \RuntimeException('aff_campaigns: the campaign owner lookup failed');
			}
			$goalOwnerResult = $goalOwnerStmt->get_result();
			if ($goalOwnerResult === false) {
				$goalOwnerStmt->close();
				throw new \RuntimeException('aff_campaigns: the campaign owner lookup returned no result');
			}
			$goalOwner = $goalOwnerResult->fetch_assoc();
			$goalOwnerStmt->close();
		} else {
			throw new \RuntimeException('aff_campaigns: the campaign owner lookup could not be prepared');
		}
		if ($goalOwner === null) {
			$goalErrors['goal'] = 'Goals belong to a campaign of yours: open the campaign to edit its goals.';
		} elseif ($goalAction === 'save') {
			$goalErrors = p202_goal_save($db, (int) $_SESSION['user_id'], $goalCampaignId, p202_goal_form_values(null, $_POST));
		} elseif ($goalAction === 'archive') {
			$goalRefusal = p202_goal_archive($db, (int) $_SESSION['user_id'], $goalCampaignId, (string) ($_POST['goal_id'] ?? ''));
			if ($goalRefusal !== null) {
				$goalErrors['goal'] = $goalRefusal;
			}
		} else {
			$goalErrors['goal'] = 'Unknown goal action.';
		}
	}
	if ($goalErrors === []) {
		header('location: ' . get_absolute_url() . 'tracking202/setup/aff_campaigns.php?edit_aff_campaign_id=' . $goalCampaignId
			. '&' . ($goalAction === 'archive' ? 'goal_archived' : 'goal_saved') . '=1#campaign-goals');
		exit;
	}
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$goalPost) {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		$error['token'] = '<div class="error">Invalid or expired form token. Please reload the page and try again.</div>';
	}

	$aff_network_id = trim((string) $_POST['aff_network_id']);
	if (empty($aff_network_id)) {
		$error['aff_network_id'] = '<div class="error">Select a category.</div>';
	}

	// The per-campaign attribution model override (plan §6.3): one of this
	// account's own models, or blank for the account default. Anything else
	// is refused rather than cast and stored.
	$postedModelId = trim((string) ($_POST['attribution_model_id'] ?? ''));
	if ($postedModelId !== '') {
		$ownModel = preg_match('/^[1-9][0-9]{0,18}$/D', $postedModelId) === 1
			? (new \Prosper202\Attribution\ModelRepository(new \Prosper202\Database\Connection($db)))->row((int) $_SESSION['user_id'], (int) $postedModelId)
			: null;
		if ($ownModel === null) {
			$error['attribution_model_id'] = '<div class="error">Choose one of your attribution models, or leave it on the account default.</div>';
		}
	}

	$aff_campaign_name = trim((string) $_POST['aff_campaign_name']);
	if (empty($aff_campaign_name)) {
		$error['aff_campaign_name'] = '<div class="error">What is the name of this campaign.</div>';
	}

	$aff_campaign_url = trim((string) $_POST['aff_campaign_url']);
	if (empty($aff_campaign_url)) {
		$error['aff_campaign_url'] = '<div class="error">What is your affiliate link? Make sure subids can be added to it.</div>';
	}


	if ((!str_starts_with((string) $_POST['aff_campaign_url'], 'http://')) and (!str_starts_with((string) $_POST['aff_campaign_url'], 'https://'))) {
		if (!isset($error['aff_campaign_url'])) {
			$error['aff_campaign_url'] = '';
		}
		$error['aff_campaign_url'] .= '<div class="error">Your Landing Page URL must start with http:// or https://</div>';
	}

	// What a click that converts more than once is worth: its latest
	// conversion (the default, and every campaign's behaviour before the
	// ledger) or all of them added up (a funnel of goals).
	// A post without the field (an older form, a script) leaves the stored
	// mode alone rather than resetting an accumulating campaign.
	$payoutMode = array_key_exists('payout_mode', $_POST) ? (string) $_POST['payout_mode'] : null;
	if ($payoutMode !== null && !in_array($payoutMode, ['replace', 'accumulate'], true)) {
		$error['payout_mode'] = '<div class="error">Choose keep the latest or add them up.</div>';
	}

	$aff_campaign_payout = trim((string) $_POST['aff_campaign_payout']);
	if (! is_numeric($aff_campaign_payout)) {
		if (!isset($error['aff_campaign_payout'])) {
			$error['aff_campaign_payout'] = '';
		}
		$error['aff_campaign_payout'] .= '<div class="error">Please enter in a numeric number for the payout.</div>';
	}

	//check to see if they are the owners of this affiliate network
	$mysql['aff_network_id'] = $db->real_escape_string((string)$_POST['aff_network_id']);
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `aff_network_id`='" . $mysql['aff_network_id'] . "'";
	$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
	if ($aff_network_result->num_rows == 0) {
		$error['wrong_user'] = '<div class="error">You are not authorized to add an campaign to another users network</div>';
	} else {
		$aff_network_row = $aff_network_result->fetch_assoc();
	}

	//if editing, check to make sure the own the campaign they are editing
	if ($editing == true) {
		$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_POST['aff_campaign_id']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$aff_campaign_sql = "SELECT * FROM 202_aff_campaigns AS 2cp LEFT JOIN 202_aff_networks AS 2an USING (aff_network_id) WHERE 2cp.user_id='" . $mysql['user_id'] . "' AND 2cp.aff_campaign_id='" . $mysql['aff_campaign_id'] . "'";
		$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		if ($aff_campaign_result->num_rows == 0) {
			$error['wrong_user'] = ($error['wrong_user'] ?? '') . '<div class="error">You are not authorized to modify another users campaign</div>';
		} else {
			$aff_campaign_row = $aff_campaign_result->fetch_assoc();
		}
	}

	if (! $error) {
		$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_POST['aff_campaign_id']);
		$mysql['aff_network_id'] = $db->real_escape_string((string)$_POST['aff_network_id']);
		$mysql['aff_campaign_name'] = $db->real_escape_string(trim($_POST['aff_campaign_name'] ?? ''));
		$mysql['aff_campaign_url'] = $db->real_escape_string(trim($_POST['aff_campaign_url'] ?? ''));
		$mysql['aff_campaign_url_2'] = $db->real_escape_string(trim($_POST['aff_campaign_url_2'] ?? ''));
		$mysql['aff_campaign_url_3'] = $db->real_escape_string(trim($_POST['aff_campaign_url_3'] ?? ''));
		$mysql['aff_campaign_url_4'] = $db->real_escape_string(trim($_POST['aff_campaign_url_4'] ?? ''));
		$mysql['aff_campaign_url_5'] = $db->real_escape_string(trim($_POST['aff_campaign_url_5'] ?? ''));
		$post_aff_campaign_rotate = isset($_POST['aff_campaign_rotate']) ? (string)$_POST['aff_campaign_rotate'] : '0';
		$mysql['aff_campaign_rotate'] = $db->real_escape_string($post_aff_campaign_rotate);
		$mysql['aff_campaign_payout'] = $db->real_escape_string(trim($_POST['aff_campaign_payout'] ?? ''));
		$mysql['aff_campaign_cloaking'] = $db->real_escape_string((string)($_POST['aff_campaign_cloaking'] ?? '0'));
		
		// Handle attribution model ID
		$attributionModelId = null;
		if (isset($_POST['attribution_model_id']) && $_POST['attribution_model_id'] !== '') {
			$attributionModelId = (int)$_POST['attribution_model_id'];
		}
		$mysql['attribution_model_id'] = $attributionModelId;
		
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['aff_campaign_time'] = time();

		if ($editing == true) {
			$aff_campaign_sql = "UPDATE `202_aff_campaigns` SET";
		} else {
			$aff_campaign_sql = "INSERT INTO `202_aff_campaigns` SET";
		}

		$aff_campaign_sql .= "`aff_network_id`='" . $mysql['aff_network_id'] . "',
													  `user_id`='" . $mysql['user_id'] . "',
													  `aff_campaign_name`='" . $mysql['aff_campaign_name'] . "',
													  `aff_campaign_url`='" . $mysql['aff_campaign_url'] . "',
													  `aff_campaign_url_2`='" . $mysql['aff_campaign_url_2'] . "',
													  `aff_campaign_url_3`='" . $mysql['aff_campaign_url_3'] . "',
													  `aff_campaign_url_4`='" . $mysql['aff_campaign_url_4'] . "',
													  `aff_campaign_url_5`='" . $mysql['aff_campaign_url_5'] . "',
													  `aff_campaign_rotate`='" . $mysql['aff_campaign_rotate'] . "',
													  `aff_campaign_payout`='" . $mysql['aff_campaign_payout'] . "',
													  `aff_campaign_cloaking`='" . $mysql['aff_campaign_cloaking'] . "',
													  `attribution_model_id`=" . ($mysql['attribution_model_id'] ? "'" . (int)$mysql['attribution_model_id'] . "'" : 'NULL') . ",
													  " . ($payoutMode === null ? '' : "`payout_mode`='" . ($payoutMode === 'accumulate' ? 'accumulate' : 'replace') . "',") . "
													  `aff_campaign_time`='" . $mysql['aff_campaign_time'] . "'";

		if ($editing == true) {
			$aff_campaign_sql .= "WHERE `aff_campaign_id`='" . $mysql['aff_campaign_id'] . "'";
		}
		$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		$add_success = true;

		if ($slack) {
			if ($editing == true) {
				if ($aff_campaign_row['aff_campaign_name'] != $_POST['aff_campaign_name']) {
					$slack->push('campaign_name_changed', ['old_name' => $aff_campaign_row['aff_campaign_name'], 'new_name' => $_POST['aff_campaign_name'], 'user' => $user_row['username']]);
				}

				if ($aff_campaign_row['aff_network_id'] != $_POST['aff_network_id']) {
					$slack->push('campaign_category_changed', ['name' => $_POST['aff_campaign_name'], 'old_category' => $aff_campaign_row['aff_network_name'], 'new_category' => $aff_network_row['aff_network_name'], 'user' => $user_row['username']]);
				}

				if (isset($aff_campaign_row['aff_campaign_rotate']) && $aff_campaign_row['aff_campaign_rotate'] != $post_aff_campaign_rotate) {
					if ($post_aff_campaign_rotate === '1') {
						$rotation_status = 'on';
					} else {
						$rotation_status = 'off';
					}

					$slack->push('campaign_category_rotation_changed', ['name' => $_POST['aff_campaign_name'], 'status' => $rotation_status, 'user' => $user_row['username']]);
				}

				if ($aff_campaign_row['aff_campaign_url'] != $_POST['aff_campaign_url']) {
					$slack->push('campaign_url_changed', ['name' => $_POST['aff_campaign_name'], 'old_url' => $aff_campaign_row['aff_campaign_url'], 'new_url' => $_POST['aff_campaign_url'], 'user' => $user_row['username']]);
				}

				if ($aff_campaign_row['aff_campaign_payout'] != $_POST['aff_campaign_payout']) {
					$slack->push('campaign_payout_changed', ['name' => $_POST['aff_campaign_name'], 'old_payout' => $aff_campaign_row['aff_campaign_payout'], 'new_payout' => $_POST['aff_campaign_payout'], 'user' => $user_row['username']]);
				}

				if ($aff_campaign_row['aff_campaign_cloaking'] != $_POST['aff_campaign_cloaking']) {
					if ($_POST['aff_campaign_cloaking'] == true) {
						$claoking_status = 'on';
					} else {
						$claoking_status = 'off';
					}

					$slack->push('campaign_cloaking_changed', ['name' => $_POST['aff_campaign_name'], 'status' => $claoking_status, 'user' => $user_row['username']]);
				}
			}
		}


		if ($editing != true) {
			//if this landing page is brand new, add on a landing_page_id_public
			$aff_campaign_row['aff_campaign_id'] = $db->insert_id;
			$aff_campaign_id_public = random_int(1, 9) . $aff_campaign_row['aff_campaign_id'] . random_int(1, 9);
			$mysql['aff_campaign_id_public'] = $db->real_escape_string($aff_campaign_id_public);
			$mysql['aff_campaign_id'] = $db->real_escape_string((string)$aff_campaign_row['aff_campaign_id']);

			$aff_campaign_sql = "	UPDATE       `202_aff_campaigns`
								 	SET          	 `aff_campaign_id_public`='" . $mysql['aff_campaign_id_public'] . "'
								 	WHERE        `aff_campaign_id`='" . $mysql['aff_campaign_id'] . "'";
			$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);

			if (isset($_POST['dni_id']) && isset($_POST['dni_offer_id'])) {
				$ddlci = false;
				if (isset($_GET['ddlci']) && is_numeric($_GET['ddlci'])) {
					$ddlci = $_GET['ddlci'];
				}

				$mysql['dni_id'] = $db->real_escape_string((string)$_POST['dni_id']);
				$dniSql = 'SELECT networkId, apiKey, affiliateId FROM 202_dni_networks WHERE user_id = "' . $mysql['user_id'] . '" AND id = "' . $mysql['dni_id'] . '"';
				$dniResult = $db->query($dniSql);

				if ($dniResult->num_rows > 0) {
					$dniRow = $dniResult->fetch_assoc();
					setupDniOfferTrack($user_row['install_hash'], $dniRow['networkId'], $dniRow['apiKey'], $dniRow['affiliateId'], $_POST['dni_offer_id'], $ddlci);
				}
			}

			if ($slack)
				$slack->push('campaign_created', ['name' => $_POST['aff_campaign_name'], 'user' => $user_row['username']]);
		}

		// Landing Page Optimizer (segments-v2 G10): flag this user's dimension
		// snapshot dirty; the hourly cron pushes it. DB-only — no HTTP here.
		// (After the public-id block above: markDirty's queries reset insert_id.)
		\Prosper202\Lpo\DimensionSync::markDirty($db, (int) ($_SESSION['user_id'] ?? 0));

		$_GET['copy_aff_campaign_id'] = false;
	}
}

if (isset($_GET['delete_aff_campaign_id'])) {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_GET['token'] ?? ''))) {
		header('location: ' . get_absolute_url() . 'tracking202/setup/aff_campaigns.php');
		die();
	}

	if ($userObj->hasPermission("remove_campaign")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_GET['delete_aff_campaign_id']);
		$mysql['date_deleted'] = time();

		$delete_sql = " UPDATE  `202_aff_campaigns`
						SET     `aff_campaign_deleted`='1',
								`aff_campaign_time`='" . ($mysql['aff_campaign_time'] ?? time()) . "'
						WHERE   `user_id`='" . $mysql['user_id'] . "'
						AND     `aff_campaign_id`='" . $mysql['aff_campaign_id'] . "'";
		if ($delete_result = $db->query($delete_sql) or record_mysql_error($delete_sql)) {
			$delete_success = true;

			// Landing Page Optimizer (segments-v2 G10): deletes change the
			// synced snapshot too (buildSnapshot omits deleted rows) — flag
			// dirty so the hourly cron re-pushes. DB-only — no HTTP here.
			\Prosper202\Lpo\DimensionSync::markDirty($db, (int) ($_SESSION['user_id'] ?? 0));
		}
	} else {
		header('location: ' . get_absolute_url() . 'tracking202/setup/aff_campaigns.php');
	}
}

if (!empty($_GET['edit_aff_campaign_id'])) {

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_GET['edit_aff_campaign_id']);

	$aff_campaign_sql = "SELECT 	* 
						 FROM   	`202_aff_campaigns`
						 WHERE  	`aff_campaign_id`='" . $mysql['aff_campaign_id'] . "'
						 AND    		`user_id`='" . $mysql['user_id'] . "'";

	$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
	$aff_campaign_row = $aff_campaign_result->fetch_assoc() ?? [];

	$selected['aff_network_id'] = $aff_campaign_row['aff_network_id'] ?? '';
	$html = array_map(fn($value) => htmlentities((string)($value ?? ''), ENT_QUOTES, 'UTF-8'), $aff_campaign_row);
	$html['aff_campaign_id'] = htmlentities((string)($_GET['edit_aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');
}

if (!empty($_GET['copy_aff_campaign_id'])) {

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_GET['copy_aff_campaign_id']);

	$aff_campaign_sql = "SELECT 	* 
						 FROM   	`202_aff_campaigns`
						 WHERE  	`aff_campaign_id`='" . $mysql['aff_campaign_id'] . "'
						 AND    		`user_id`='" . $mysql['user_id'] . "'";

	$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
	$aff_campaign_row = $aff_campaign_result->fetch_assoc() ?? [];

	$selected['aff_network_id'] = $aff_campaign_row['aff_network_id'] ?? '';
	$html = array_map(fn($value) => htmlentities((string)($value ?? ''), ENT_QUOTES, 'UTF-8'), $aff_campaign_row);
	$html['aff_campaign_id'] = htmlentities((string)($_GET['copy_aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['aff_campaign_name'] = ($html['aff_campaign_name'] ?? '') . " (Copy)"; //append (Copy) to the campaign name so the user knows its a copy
	// Clear attribution model ID so user can choose for the copied campaign
	$html['attribution_model_id'] = ''; 

}

//this will override the edit, if posting and edit fail
if (($_SERVER['REQUEST_METHOD'] == 'POST') and !$goalPost and (!isset($add_success) || $add_success != true)) {

	if (isset($_POST['aff_network_id'])) {
		$selected['aff_network_id'] = $_POST['aff_network_id'];
	}
	$html = array_map(htmlentities(...), $_POST);
}

// Post-redirect-get: a saved or removed campaign answers with a redirect, so
// a reload cannot submit the form (or the remove link) a second time.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $add_success == true) {
	header('location: ' . get_absolute_url() . 'tracking202/setup/aff_campaigns.php?' . ($editing ? 'saved=1' : 'added=1'));
	exit;
}
if ($delete_success == true) {
	header('location: ' . get_absolute_url() . 'tracking202/setup/aff_campaigns.php?deleted=1');
	exit;
}

require_once __DIR__ . '/_includes/setup_ui.php';

$base = get_absolute_url();
$self = $base . 'tracking202/setup/aff_campaigns.php';
$token = (string) ($_SESSION['token'] ?? '');
$uid = $db->real_escape_string((string) $_SESSION['user_id']);

// The categories, with their network integration when they have one.
$categoryRows = p202_setup_rows($db, "SELECT af.aff_network_id, af.aff_network_name, af.dni_network_id, dni.favicon, dni.processed FROM 202_aff_networks AS af LEFT JOIN 202_dni_networks AS dni ON (af.dni_network_id = dni.id) WHERE af.user_id='" . $uid . "' AND af.aff_network_deleted='0' ORDER BY af.aff_network_name ASC");
$campaignsByCategory = [];
foreach (p202_setup_rows($db, "SELECT aff_campaign_id, aff_network_id, aff_campaign_name, aff_campaign_url, aff_campaign_payout, aff_campaign_rotate FROM `202_aff_campaigns` WHERE `user_id`='" . $uid . "' AND `aff_campaign_deleted`='0' ORDER BY `aff_campaign_name` ASC") as $campaign) {
	$campaignsByCategory[(int) $campaign['aff_network_id']][] = $campaign;
}
$categories = [];
foreach ($categoryRows as $category) {
	$categories[(string) $category['aff_network_id']] = (string) $category['aff_network_name'];
}

// What the form shows: what was just refused, the campaign being edited or
// copied, or a blank campaign.
$posted = $_SERVER['REQUEST_METHOD'] == 'POST' && !$goalPost;
$source = $posted ? $_POST : (is_array($aff_campaign_row) ? $aff_campaign_row : []);
$field = static fn (string $name): string => (string) ($source[$name] ?? '');
$values = [
	'aff_campaign_id' => $posted ? $field('aff_campaign_id') : (string) ($editing || $copying ? ($_GET['edit_aff_campaign_id'] ?? $_GET['copy_aff_campaign_id'] ?? '') : ''),
	'aff_network_id' => $field('aff_network_id'),
	'aff_campaign_name' => $field('aff_campaign_name') . (!$posted && $copying && $source !== [] ? ' (Copy)' : ''),
	'aff_campaign_url' => $field('aff_campaign_url'),
	'aff_campaign_url_2' => $field('aff_campaign_url_2'),
	'aff_campaign_url_3' => $field('aff_campaign_url_3'),
	'aff_campaign_url_4' => $field('aff_campaign_url_4'),
	'aff_campaign_url_5' => $field('aff_campaign_url_5'),
	'aff_campaign_rotate' => $field('aff_campaign_rotate') === '1' ? '1' : '0',
	'aff_campaign_payout' => $field('aff_campaign_payout'),
	'aff_campaign_cloaking' => $field('aff_campaign_cloaking') === '1' ? '1' : '0',
	// A copy chooses its own model, as the classic page did.
	'attribution_model_id' => !$posted && $copying ? '' : $field('attribution_model_id'),
	'payout_mode' => $field('payout_mode') === 'accumulate' ? 'accumulate' : 'replace',
];
if ($values['aff_network_id'] === '' && count($categories) === 1) {
	// One category: a campaign can only go in it, so it is chosen.
	$values['aff_network_id'] = (string) array_key_first($categories);
}
$campaignForm = [
	'values' => $values,
	'errors' => $error,
	'categories' => $categories,
	'rotation_offered' => $rotateUrlCampaignsResults->num_rows > 0,
];
$editId = $editing ? (int) ($_GET['edit_aff_campaign_id'] ?? 0) : 0;

/*
 * The campaign form is a list of sections, rendered in order inside one
 * <form>. A section is a file that reads $campaignForm and prints its fields;
 * payout mode and goals (later PRs) are one file and one line here each,
 * between the offer and Advanced.
 */
$campaignFormSections = [
	__DIR__ . '/_includes/campaign_form/offer.php',
	__DIR__ . '/_includes/campaign_form/advanced.php',
];

if (isset($_GET['dl_dni']) && isset($_GET['dl_offer_id']) && !isset($_POST['aff_network_id'])) {
	$mysql['dl_dni'] = $db->real_escape_string((string)$_GET['dl_dni']);
	$getDlDniSql = "SELECT id FROM 202_dni_networks WHERE networkId = '" . $mysql['dl_dni'] . "' AND user_id = '" . $mysql['user_id'] . "'";
	$getDlDniResult = $db->query($getDlDniSql) or record_mysql_error($getDlDniSql);
	$getDlDniRow = $getDlDniResult->num_rows > 0 ? $getDlDniResult->fetch_assoc() : null;
}

template_top('Affiliate Campaigns Setup');
?>

<div class="p202-page-header p202-page-header--accent">
	<div class="p202-page-header__icon"><i class="bi bi-link-45deg"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Campaigns</h1>
		<p class="p202-page-header__desc">The offers you promote: where a click goes, and what a conversion pays.</p>
	</div>
</div>

<?php
echo p202_setup_query_flashes([
	'added' => 'Campaign added. Get its tracking link from Get Links.',
	'saved' => 'Campaign saved.',
	'deleted' => 'Campaign removed. Its clicks and conversions keep their history.',
	'goal_saved' => 'Goal saved. It evaluates events received from now on.',
	'goal_archived' => 'Goal archived. Its outcomes and conversions keep their history.',
], $_GET);
if ($error) {
	echo p202_setup_error_flashes($error, ['aff_network_id', 'aff_campaign_name', 'aff_campaign_url', 'aff_campaign_payout', 'aff_campaign_url_2', 'aff_campaign_url_3', 'aff_campaign_url_4', 'aff_campaign_url_5', 'payout_mode']);
}
?>

<div class="row g-4">
	<div class="col-12 col-lg-6">
		<?php if ($categories === []) { ?>
			<div class="p202-empty">
				<i class="bi bi-grid p202-empty__icon"></i>
				<strong class="p202-empty__title">Add a category first</strong>
				<div>Every campaign sits in a category, such as the network that pays for it.</div>
				<div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="<?php echo p202_setup_e($base . 'tracking202/setup/aff_networks.php'); ?>">Add a category</a></div>
			</div>
		<?php } else { ?>
		<section class="p202-panel" id="campaign-form">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title"><?php echo $editing ? 'Edit campaign' : ($copying ? 'Copy campaign' : 'Add a campaign'); ?></h2>
				<p class="p202-panel__sub"><?php echo $editing ? 'Changes apply to clicks from now on.' : 'The offer, where a click goes, and what it pays.'; ?></p>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo p202_setup_e($self . ($editing ? '?edit_aff_campaign_id=' . $editId : ($copying ? '?copy_aff_campaign_id=' . (int) ($_GET['copy_aff_campaign_id'] ?? 0) : ''))); ?>">
					<?php echo p202_setup_token_field($token); ?>
					<input type="hidden" name="aff_campaign_id" value="<?php echo p202_setup_e($values['aff_campaign_id']); ?>">
					<input type="hidden" name="dni_id" value="<?php echo p202_setup_e($posted ? (string) ($_POST['dni_id'] ?? '') : ''); ?>">
					<input type="hidden" name="dni_offer_id" value="<?php echo p202_setup_e($posted ? (string) ($_POST['dni_offer_id'] ?? '') : ''); ?>">
					<?php foreach ($campaignFormSections as $campaignFormSection) {
						include $campaignFormSection;
					} ?>
					<div class="p202-form-actions">
						<?php if ($editing || $copying) { ?>
							<a class="btn btn-link" href="<?php echo p202_setup_e($self); ?>">Cancel</a>
						<?php } ?>
						<button type="submit" class="btn btn-primary" id="addCampaign"><?php echo $editing ? 'Save changes' : 'Add campaign'; ?></button>
					</div>
				</form>
			</div>
		</section>
		<?php
		// The campaign's goals, once it exists: edited through their own
		// forms under the campaign's (_includes/campaign_goals_panel.php).
		if ($editing && $editId > 0 && is_array($aff_campaign_row) && $aff_campaign_row !== []) {
			$goalList = p202_goal_list($db, (int) $_SESSION['user_id'], $editId);
			$editGoal = null;
			if (!$goalPost && !empty($_GET['edit_goal_id'])) {
				foreach ($goalList['own'] as $g) {
					if ((string) $g['goal_id'] === (string) $_GET['edit_goal_id'] && $g['archived_at'] === null && is_array($g['definition']) && p202_goal_form_fits($g['definition'])) {
						$editGoal = $g;
					}
				}
			}
			$goalFormValues = p202_goal_form_values($editGoal, $goalPost && ($_POST['goal_action'] ?? '') === 'save' ? $_POST : null);
			$goalPanel = [
				'campaign_id' => $editId,
				'self' => $self,
				'token' => $token,
				'goals' => $goalList,
				'form' => $goalFormValues,
				'errors' => $goalErrors,
				'editing' => $goalFormValues['goal_id'] !== '',
				'payout_mode' => (string) ($aff_campaign_row['payout_mode'] ?? 'replace'),
			];
			include __DIR__ . '/_includes/campaign_goals_panel.php';
		}
		?>
		<?php } ?>
	</div>

	<div class="col-12 col-lg-6">
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Your campaigns</h2>
				<?php $campaignTotal = array_sum(array_map('count', $campaignsByCategory)); ?>
				<span class="p202-pill p202-pill--accent"><?php echo $campaignTotal . ' ' . ($campaignTotal === 1 ? 'campaign' : 'campaigns'); ?></span>
				<?php if ($campaignTotal > 5) { ?>
					<div class="p202-panel__aside"><?php echo p202_setup_list_filter('campaign-list', 'Filter campaigns…'); ?></div>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<?php if ($categoryRows === []) { ?>
					<p class="text-body-secondary mb-0">No categories yet, so no campaigns.</p>
				<?php } else { ?>
					<ul class="p202-list" id="campaign-list">
						<?php foreach ($categoryRows as $category) {
							$cid = (int) $category['aff_network_id'];
							$campaigns = $campaignsByCategory[$cid] ?? []; ?>
							<li class="p202-list__item" data-p202-filter-text="<?php echo p202_setup_e($category['aff_network_name']); ?>">
								<span class="p202-list__name"><?php echo p202_setup_e($category['aff_network_name']); ?></span>
								<span class="p202-pill"><?php echo count($campaigns) . ' ' . (count($campaigns) === 1 ? 'campaign' : 'campaigns'); ?></span>
								<?php if ($category['dni_network_id'] !== null) { ?>
									<span class="p202-list__actions">
										<?php if (!$category['processed']) { ?>
											<span class="p202-pill p202-pill--warn">offers loading</span>
										<?php } else { ?>
											<button type="button" class="p202-list__action" data-bs-toggle="modal" data-bs-target="#dni-offers-modal" data-dni-id="<?php echo (int) $category['dni_network_id']; ?>">search offers</button>
										<?php } ?>
									</span>
								<?php } ?>
								<?php if ($campaigns !== []) { ?>
									<ul class="p202-list__children">
										<?php foreach ($campaigns as $campaign) {
											$id = (int) $campaign['aff_campaign_id'];
											$name = (string) $campaign['aff_campaign_name']; ?>
											<li class="p202-list__item<?php echo $editId === $id ? ' is-active' : ''; ?>" data-p202-filter-text="<?php echo p202_setup_e($name); ?>">
												<span class="p202-list__name"><?php echo p202_setup_e($name); ?></span>
												<span class="p202-pill p202-pill--good">$<?php echo p202_setup_e($campaign['aff_campaign_payout']); ?></span>
												<?php if ((string) $campaign['aff_campaign_rotate'] === '1') { ?>
													<span class="p202-pill">rotates URLs</span>
												<?php } ?>
												<span class="p202-list__actions">
													<a class="p202-list__action" href="<?php echo p202_setup_e($campaign['aff_campaign_url']); ?>" target="_blank" rel="noopener noreferrer">link</a>
													<a class="p202-list__action" href="<?php echo p202_setup_e($self . '?edit_aff_campaign_id=' . $id); ?>">edit</a>
													<a class="p202-list__action" href="<?php echo p202_setup_e($self . '?copy_aff_campaign_id=' . $id); ?>">copy</a>
													<?php if ($userObj->hasPermission("remove_campaign")) {
														echo p202_setup_remove_form($self, ['delete_aff_campaign_id' => $id, 'token' => $token],
															'Remove the campaign "' . $name . '"? Its clicks and conversions keep their history.');
													} ?>
												</span>
											</li>
										<?php } ?>
									</ul>
								<?php } else { ?>
									<span class="p202-list__meta">No campaigns yet</span>
								<?php } ?>
							</li>
						<?php } ?>
					</ul>
				<?php } ?>
			</div>
		</section>
	</div>
</div>

<div class="modal fade" id="dni-offers-modal" tabindex="-1" aria-labelledby="dni-offers-title" aria-hidden="true"
	data-offers-url="<?php echo p202_setup_e($base . 'tracking202/ajax/dni_get_offers.php'); ?>"
	data-ddlci="<?php echo p202_setup_e(isset($_GET['ddlci']) && is_numeric($_GET['ddlci']) ? (string) $_GET['ddlci'] : ''); ?>"
	<?php if ($getDlDniRow && isset($getDlDniRow['id'])) { ?>data-open-dni="<?php echo (int) $getDlDniRow['id']; ?>" data-open-offer="<?php echo (int) ($_GET['dl_offer_id'] ?? 0); ?>"<?php } ?>>
	<div class="modal-dialog modal-xl">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="dni-offers-title">Network offers</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<form class="p202-toolbar mb-3" data-dni-filter>
					<label class="visually-hidden" for="dni-filter-name">Offer name</label>
					<input type="search" class="form-control form-control-sm" id="dni-filter-name" placeholder="Filter by offer name" style="max-width: 18rem;">
					<button type="submit" class="btn btn-secondary btn-sm">Filter</button>
				</form>
				<div data-dni-status></div>
				<div class="p202-table-wrap">
					<table class="table p202-table" data-dni-table>
						<thead><tr><th>ID</th><th>Name</th><th class="num">Payout</th><th>Type</th><th>Preview</th><th>Status</th></tr></thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
			<div class="modal-footer">
				<span class="text-body-secondary small me-auto" data-dni-count></span>
				<button type="button" class="btn btn-secondary btn-sm" data-dni-page="-1">Previous</button>
				<button type="button" class="btn btn-secondary btn-sm" data-dni-page="1">Next</button>
			</div>
		</div>
	</div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom(); ?>
