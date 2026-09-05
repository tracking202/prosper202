<?php

/**
 * API Integrations Management - Refactored for improved readability
 */

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');
include_once(str_repeat("../", 1) . '202-config/clickserver_api_management.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_api_integrations")) {
	header('location: ' . get_absolute_url() . '202-account/');
	exit;
}

// Initialize variables to prevent undefined variable warnings
$error = [];
$html = [];
$mysql = [];
$selected = [];
$add_success = false;
$delete_success = false;

// Initialize change status variables
$change_cb_key = false;
$change_jvzoo_secret_key = false;
$change_zaxaa_api_signature = false;
$change_user_slack_incoming_webhook = false;
$change_ipqs_api_key = false;

/**
 * Helper Functions for API Integration Management
 */

/**
 * Validate required field and add error if empty
 */
function validateRequired($field_value, $error_key, $error_message, &$error)
{
	if (empty($field_value)) {
		$error[$error_key] = ($error[$error_key] ?? '') . $error_message;
		return false;
	}
	return true;
}

/**
 * Update user preference in database
 */
function updateUserPreference($field_name, $value, $user_id, $db)
{
	$escaped_value = $db->real_escape_string((string)$value);
	$escaped_user_id = $db->real_escape_string((string)$user_id);

	$sql = "UPDATE `202_users_pref` SET `{$field_name}` = '{$escaped_value}' WHERE `user_id` = '{$escaped_user_id}'";
	return $db->query($sql);
}

/**
 * Send Slack notification if configured and value changed
 */
function sendSlackNotification($slack, $event_name, $username, $old_value, $new_value)
{
	if ($slack && $old_value !== $new_value) {
		$slack->push($event_name, ['user' => $username]);
	}
}

/**
 * Process API key update with validation and notification
 */
function processApiKeyUpdate($config, &$error, &$change_flag, $user_row, $slack, $username, $db)
{
	$post_key = $config['post_key'];
	$field_name = $config['field_name'];
	$error_key = $config['error_key'];
	$error_message = $config['error_message'];
	$slack_event = $config['slack_event'];
	$user_id = $_SESSION['user_id'];

	if (!validateRequired($_POST[$post_key], $error_key, $error_message, $error)) {
		return false;
	}

	if (!$error) {
		$new_value = $_POST[$post_key];
		$old_value = $user_row[$field_name] ?? '';

		if ($new_value !== $old_value) {
			updateUserPreference($field_name, $new_value, $user_id, $db);

			// Special handling for cb_key verification reset
			if ($field_name === 'cb_key') {
				updateUserPreference('cb_verified', '0', $user_id, $db);
			}
		}

		$change_flag = true;
		sendSlackNotification($slack, $slack_event, $username, $old_value, $new_value);
		return true;
	}

	return false;
}

/**
 * Display success message
 */
function showSuccessMessage($condition, $message)
{
	if ($condition) {
		echo '<div class="apiint-note apiint-note--ok" role="status"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13.5 4.5L6.5 11.5L2.5 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span>' . htmlspecialchars((string) $message) . '</span></div>';
	}
}

/**
 * Display error message
 */
function showErrorMessage($errors, $key)
{
	if (isset($errors[$key]) && $errors[$key]) {
		echo '<div class="apiint-note apiint-note--err" role="alert"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 5v3.5M8 11h.01M8 1.5 14.5 13a.6.6 0 0 1-.52.9H2.02a.6.6 0 0 1-.52-.9L8 1.5z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg><span>' . htmlspecialchars((string) $errors[$key]) . '</span></div>';
	}
}

/**
 * Status pill: $state is one of ok | off | warn | err
 */
function apiint_pill($state, $text)
{
	echo '<span class="apiint-pill apiint-pill--' . $state . '"><span class="apiint-pill-dot"></span>' . htmlspecialchars((string) $text) . '</span>';
}

/**
 * Labeled endpoint URL row with a copy button
 */
function apiint_endpoint($label, $url)
{
	$safe = htmlspecialchars((string) $url);
	echo '<div class="apiint-endpoint"><span class="apiint-endpoint-label">' . htmlspecialchars((string) $label) . '</span><code>' . $safe . '</code><button type="button" class="apiint-copy" data-copy="' . $safe . '" title="Copy to clipboard">Copy</button></div>';
}

$strProtocol = getSecureStatus() ? 'https://' : 'http://'; // SERVER_PROTOCOL is "HTTP/1.1" even over TLS (review finding)

/**
 * rtr.php caches the Landing Page Optimizer t202ctx prefs (lpo_status, lpo_bridge_config,
 * lpo_ctx_kw) for 3 minutes under md5(<exact SELECT> . systemHash()). Any
 * pref change that alters minting behavior (keyword privacy opt-out, connect/
 * disconnect) must drop that key so live redirects pick the change up
 * immediately, not at TTL expiry (review finding). Keep the SELECT byte-
 * identical to rtr.php's.
 */
function lpo_ctx_pref_cache_bust($userId)
{
	if (empty($GLOBALS['memcacheWorking']) || empty($GLOBALS['memcache'])) {
		return;
	}
	$sql = "SELECT lpo_status, lpo_bridge_config, lpo_ctx_kw FROM 202_users_pref WHERE user_id='" . (int) $userId . "'";
	$GLOBALS['memcache']->delete(md5($sql . systemHash()));
}
$mysql['add_dni'] = $db->real_escape_string((string)($_GET['add_dni_network'] ?? ''));
$slack = false;
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url, 2u.install_hash, 2u.p202_customer_api_key FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results ? ($user_results->fetch_assoc() ?: []) : [];
$username = $user_row['username'];
$editing_dni_network = false;
$dniNetworks = getAllDniNetworks($user_row['install_hash']);
$dniProcesing = ['host' => getDNIHost(), 'install_hash' => $user_row['install_hash'], 'networks' => []];

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

if (isset($_GET['cb_status']) && $_GET['cb_status'] == 1) {
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$user_sql = "SELECT cb_verified
             FROM 202_users_pref
             WHERE user_id='" . $mysql['user_id'] . "'";
	$user_results = $db->query($user_sql);
	$user_row = $user_results ? ($user_results->fetch_assoc() ?: []) : [];
	if ($user_row['cb_verified']) {
		echo '<span class="label label-primary">Verified</span>';
	} else {
		echo '<span class="label label-important">Unverified</span>';
	}
	die();
}

//get all of the user data
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$user_sql = "	SELECT 	*
				 FROM   	`202_users` 
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='" . $mysql['user_id'] . "'";
$user_result = $db->query($user_sql);
$user_row = $user_result ? ($user_result->fetch_assoc() ?: []) : [];
$html = array_map('htmlentities', $user_row);

$cb_verified = $user_row['cb_verified'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	// validate token
	if (!hash_equals((string)($_SESSION['token'] ?? ''), (string)($_POST['token'] ?? ''))) {
		$error['token'] = 'You must use our forms to submit data.';
	}

	// ClickBank Key Update
	if (!isset($error['token']) && isset($_POST['change_cb_key']) && $_POST['change_cb_key'] == '1') {
		$config = [
			'post_key' => 'cb_key',
			'field_name' => 'cb_key',
			'error_key' => 'cb_key',
			'error_message' => 'Clickbank Secret Key can\'t be empty!',
			'slack_event' => 'cb_key_updated'
		];
		processApiKeyUpdate($config, $error, $change_cb_key, $user_row, $slack, $username, $db);
	}

	// Slack Webhook Update
	if (!isset($error['token']) && isset($_POST['change_user_slack_incoming_webhook']) && $_POST['change_user_slack_incoming_webhook'] == '1') {
		$config = [
			'post_key' => 'user_slack_incoming_webhook',
			'field_name' => 'user_slack_incoming_webhook',
			'error_key' => 'user_slack_incoming_webhook',
			'error_message' => 'Slack Incoming Webhook URL can\'t be empty!',
			'slack_event' => 'user_slack_incoming_webhook_updated'
		];
		processApiKeyUpdate($config, $error, $change_user_slack_incoming_webhook, $user_row, $slack, $username, $db);
	}

	// Zaxaa API Signature Update
	if (!isset($error['token']) && isset($_POST['change_zaxaa_api_signature']) && $_POST['change_zaxaa_api_signature'] == '1') {
		$config = [
			'post_key' => 'zaxaa_api_signature',
			'field_name' => 'zaxaa_api_signature',
			'error_key' => 'zaxaa_api_signature_error',
			'error_message' => 'Zaxaa API signature can\'t be empty!',
			'slack_event' => 'zaxaa_api_signature_updated'
		];
		processApiKeyUpdate($config, $error, $change_zaxaa_api_signature, $user_row, $slack, $username, $db);
	}

	// JVZoo Secret Key Update
	if (!isset($error['token']) && isset($_POST['change_jvzoo_secret_key']) && $_POST['change_jvzoo_secret_key'] == '1') {
		$config = [
			'post_key' => 'jvzoo_ipn_secret_key',
			'field_name' => 'jvzoo_ipn_secret_key',
			'error_key' => 'jvzoo_secret_key_error',
			'error_message' => 'JVZoo secret key can\'t be empty!',
			'slack_event' => 'jvzoo_secret_key_updated'
		];
		processApiKeyUpdate($config, $error, $change_jvzoo_secret_key, $user_row, $slack, $username, $db);
	}

	// IPQualityScore API Key Update
	if (!isset($error['token']) && isset($_POST['change_ipqs_api_key']) && $_POST['change_ipqs_api_key'] == '1') {
		$config = [
			'post_key' => 'ipqs_api_key',
			'field_name' => 'ipqs_api_key',
			'error_key' => 'ipqs_api_key_error',
			'error_message' => 'The IPQualityScore API Key can\'t be empty!',
			'slack_event' => 'ipqs_api_key_updated'
		];
		processApiKeyUpdate($config, $error, $change_ipqs_api_key, $user_row, $slack, $username, $db);
	}

	// Landing Page Optimizer pairing: connect registers a local
	// wildcard webhook and completes the SaaS handshake; disconnect reverses.
	if (!isset($error['token']) && isset($_POST['lpo_action']) && in_array($_POST['lpo_action'], ['connect', 'disconnect'], true)) {
		$lpo_user_id = (int) $_SESSION['user_id'];
		$lpo_conn = new \Prosper202\Database\Connection($db);
		$lpo_webhooks = new \Prosper202\Ltv\MysqlWebhookRepository($lpo_conn);
		$lpo_client = new \Prosper202\Lpo\PairingClient();
		$lpo_api_key = trim((string) ($user_row['p202_customer_api_key'] ?? ''));
		$lpo_install_hash = trim((string) ($user_row['install_hash'] ?? ''));

		try {
			if (!array_key_exists('lpo_status', $user_row)) {
				throw new RuntimeException('Run the Prosper202 upgrade first (the Landing Page Optimizer bridge columns are missing).');
			}

			if ($_POST['lpo_action'] === 'connect') {
				if ((string) ($user_row['lpo_status'] ?? '') === 'active') {
					// Replayed/double submit: never stack a second webhook.
					throw new RuntimeException('Already connected. Disconnect first to re-pair.');
				}
				if ($lpo_api_key === '') {
					throw new RuntimeException('A Prosper202 Customer API key is required to connect — use the button below to get yours.');
				}
				if ($lpo_install_hash === '') {
					// distinct cause, distinct message (a blank install hash used
					// to masquerade as a missing API key): the hash is created by
					// the installer on the owner account and identifies this
					// install to the SaaS — without it pairing cannot proceed.
					throw new RuntimeException('This account has no install hash, so the install cannot pair. Log in as the account owner (user 1) to connect.');
				}
				$lpo_install_url = $strProtocol . $_SERVER['HTTP_HOST'] . rtrim(get_absolute_url(), '/');
				$lpo_init = $lpo_client->pairInit($lpo_api_key, $lpo_install_hash, $lpo_install_url);
				$lpo_site_key = trim((string) ($lpo_init['site_key'] ?? ''));
				$lpo_hook_url = trim((string) ($lpo_init['hook_url'] ?? ''));
				if ($lpo_site_key === '' || $lpo_hook_url === '') {
					throw new RuntimeException('Pairing init did not return a site key and webhook URL.');
				}

				// The webhook secret is generated where it is used and
				// transported to the SaaS exactly once, in pair/complete.
				$lpo_created = $lpo_webhooks->create($lpo_user_id, $lpo_hook_url, ['*']);
				try {
					$lpo_client->pairComplete($lpo_api_key, $lpo_install_hash, $lpo_created['webhookId'], $lpo_created['secret']);
				} catch (Throwable $lpo_complete_error) {
					// Don't leave a half-paired endpoint delivering nowhere —
					// and never let the rollback mask the original failure.
					try {
						$lpo_webhooks->delete($lpo_user_id, $lpo_created['webhookId']);
					} catch (Throwable $lpo_rollback_error) {
						error_log('lpo connect: webhook rollback failed after pair/complete error: ' . $lpo_rollback_error->getMessage());
					}
					throw $lpo_complete_error;
				}

				$lpo_state = json_encode([
					'webhook_id' => $lpo_created['webhookId'],
					// Derived t202ctx signing key (p202-edge-sync §3.3), cached
					// at pairing time so the redirect hot path never touches
					// 202_ltv_webhooks to mint context tokens.
					'ctx_key' => bin2hex(\Prosper202\Lpo\CtxToken::deriveKey($lpo_created['secret'])),
				]);
				if ($lpo_state === false) {
					throw new RuntimeException('Failed to encode bridge state.');
				}
				updateUserPreference('lpo_site_key', $lpo_site_key, $lpo_user_id, $db);
				updateUserPreference('lpo_status', 'active', $lpo_user_id, $db);
				updateUserPreference('lpo_bridge_config', $lpo_state, $lpo_user_id, $db);
				// bust AFTER the last pref write: a redirect racing between the
				// status and config writes could otherwise cache active-with-
				// stale-config for 3 minutes (no t202ctx until TTL expiry)
				lpo_ctx_pref_cache_bust($lpo_user_id);
				header('Location: ' . get_absolute_url() . '202-account/api-integrations.php?lpo=connected#lpo');
				die();
			}

			// Disconnect: remove the local webhook, revoke SaaS-side
			// (best-effort — local state always clears), clear pairing prefs.
			$lpo_state = json_decode((string) ($user_row['lpo_bridge_config'] ?? ''), true);
			$lpo_webhook_id = is_array($lpo_state) ? (int) ($lpo_state['webhook_id'] ?? 0) : 0;
			if ($lpo_webhook_id > 0) {
				try {
					$lpo_webhooks->delete($lpo_user_id, $lpo_webhook_id);
				} catch (\Prosper202\Ltv\RecordNotFoundException) {
					// Already gone locally; disconnect must still proceed.
				}
			}
			if ($lpo_api_key !== '' && $lpo_install_hash !== '') {
				try {
					$lpo_client->pairDisconnect($lpo_api_key, $lpo_install_hash);
				} catch (Throwable $lpo_disconnect_error) {
					error_log('lpo disconnect: SaaS revoke failed (link will expire server-side): ' . $lpo_disconnect_error->getMessage());
				}
			}
			updateUserPreference('lpo_site_key', '', $lpo_user_id, $db);
			updateUserPreference('lpo_status', '', $lpo_user_id, $db);
			updateUserPreference('lpo_bridge_config', '', $lpo_user_id, $db);
			lpo_ctx_pref_cache_bust($lpo_user_id); // after the last write (see connect)
			header('Location: ' . get_absolute_url() . '202-account/api-integrations.php?lpo=disconnected#lpo');
			die();
		} catch (\Prosper202\Lpo\PairingRequestException $lpo_error) {
			// Full technical detail goes to the log; the UI gets plain
			// English with a next step.
			error_log('lpo ' . $_POST['lpo_action'] . ': ' . $lpo_error->getMessage());
			// "Subscription required" is an expected upsell, not a failure —
			// route it to the actionable "start a plan" state, not a red error.
			if (stripos($lpo_error->userMessage(), 'subscription') !== false) {
				$lpo_needs_subscription = true;
				$lpo_sub_retry = !empty($_POST['lpo_retry']); // came from the "I've subscribed — connect" button
			} else {
				$error['lpo'] = $lpo_error->userMessage();
			}
		} catch (Throwable $lpo_error) {
			error_log('lpo ' . $_POST['lpo_action'] . ': ' . $lpo_error->getMessage());
			$error['lpo'] = $lpo_error->getMessage();
		}
	}

	// Landing Page Optimizer privacy pref: include/omit keyword text in
	// t202ctx context tokens (lpo_ctx_kw — default on, '0' = omit;
	// p202-edge-sync §8). Storage only; rtr.php honors it at mint time.
	if (!isset($error['token']) && isset($_POST['lpo_ctx_kw_save']) && $_POST['lpo_ctx_kw_save'] == '1' && array_key_exists('lpo_ctx_kw', $user_row)) {
		$lpo_ctx_kw_new = !empty($_POST['lpo_ctx_kw']) ? '1' : '0';
		if ($lpo_ctx_kw_new !== (string) ($user_row['lpo_ctx_kw'] ?? '1')) {
			updateUserPreference('lpo_ctx_kw', $lpo_ctx_kw_new, $_SESSION['user_id'], $db);
			lpo_ctx_pref_cache_bust($_SESSION['user_id']);
			$user_row['lpo_ctx_kw'] = $lpo_ctx_kw_new;
		}
		$lpo_ctx_kw_saved = true;
	}

	if (!isset($error['token']) && isset($_POST['dni_network'])) {
		if (array_search('', $_POST) !== false) {
			$error['dni_network'] = 'Make sure all fields are selected and filled out!';
		} else {
			$mysql['dniNetworkId'] = $db->real_escape_string((string)$_POST['dni_network']);
			$mysql['dniNetworkType'] = $db->real_escape_string((string)$_POST['dni_network_type']);
			$dniNetworkName = explode(" (", (string) $_POST['dni_network_name'], 2);
			$mysql['dniNetworkName'] = $db->real_escape_string($dniNetworkName[0]);
			$mysql['dniAffiliateId'] = $db->real_escape_string((string)$_POST['dni_network_affiliate_id']);
			$mysql['dniApikey'] = $db->real_escape_string((string)$_POST['dni_network_api_key']);
			$dniAuth = authDniNetworks($user_row['install_hash'], $_POST['dni_network'], $_POST['dni_network_api_key'], $_POST['dni_network_affiliate_id']);

			if ($dniAuth['auth'] == false) {
				$error['dni_network_auth'] = 'Can\'t authenticate with provided credentials. Try again!';
			} else {
				if (!isset($_POST['editing_dni_network'])) {
					$dniShortDescription = '';
					$dniFavIcon = '';
					foreach ($dniNetworks as $dniNetwork) {
						if ($dniNetwork['networkId'] == $_POST['dni_network']) {
							$dniShortDescription = $dniNetwork['shortDescription'];
							$dniFavIcon = $dniNetwork['favIconUrl'];
						}
					}

					$mysql['dniShortDescription'] = $db->real_escape_string($dniShortDescription);
					$mysql['dniFavIcon'] = $db->real_escape_string($dniFavIcon);
					$mysql['dniFavIcon'] = $db->real_escape_string($dniFavIcon);

					$dniProcessed = $db->real_escape_string($dniAuth['processed']);

					$sql = "INSERT INTO 202_dni_networks SET user_id = '" . $mysql['user_id'] . "', networkId = '" . $mysql['dniNetworkId'] . "', name = '" . $mysql['dniNetworkName'] . "', type = '" . $mysql['dniNetworkType'] . "', apiKey = '" . $mysql['dniApikey'] . "', time = '" . time() . "', processed = '" . $dniProcessed . "', shortDescription = '" . $mysql['dniShortDescription'] . "', favIcon = '" . $mysql['dniFavIcon'] . "'";

					if ($_POST['dni_network_type'] == 'Cake') {
						$sql .= ", affiliateId = '" . $mysql['dniAffiliateId'] . "'";
					}

					if ($db->query($sql)) {
						$success['dni_network_added'] = $mysql['dniNetworkName'] . " network configured. API processing can take up to 5 minutes.";
						$sql = "INSERT INTO 202_aff_networks SET dni_network_id = '" . $db->insert_id . "', user_id = '" . $mysql['user_id'] . "', aff_network_name = '" . $mysql['dniNetworkName'] . " (DNI)" . "', aff_network_time = '" . time() . "'";
						$db->query($sql);
					}
				} else if (isset($_POST['editing_dni_network_id']) && !empty($_POST['editing_dni_network_id'])) {
					$mysql['editing_dni_network_id'] = $db->real_escape_string((string)$_POST['editing_dni_network_id']);
					$sql = "UPDATE 202_dni_networks SET networkId = '" . $mysql['dniNetworkId'] . "', name = '" . $mysql['dniNetworkName'] . "', type = '" . $mysql['dniNetworkType'] . "', apiKey = '" . $mysql['dniApikey'] . "', time = '" . time() . "'";

					if ($_POST['dni_network_type'] == 'Cake') {
						$sql .= ", affiliateId = '" . $mysql['dniAffiliateId'] . "'";
					}

					$sql .= " WHERE id = '" . $mysql['editing_dni_network_id'] . "' AND user_id = '" . $mysql['user_id'] . "'";

					if ($db->query($sql)) {
						$sql = "UPDATE 202_aff_networks SET aff_network_name = '" . $mysql['dniNetworkName'] . " (DNI)" . "', aff_network_time = '" . time() . "' WHERE dni_network_id = '" . $mysql['editing_dni_network_id'] . "' AND user_id = '" . $mysql['user_id'] . "'";
						$db->query($sql);
						header('Location: ' . get_absolute_url() . '202-account/api-integrations.php?dni_network_updated=1');
						die();
					}
				}

				tagUserByNetwork($user_row['install_hash'], 'affiliate-networks', $dniNetworkName[0]);
			}
		}
	}

	$html = array_merge($html, array_map('htmlentities', $_POST));
}


// Deleting is a POST: a GET carrying the CSRF token put that token into browser
// history, Referer headers and access logs, and it guards every POST mutation in
// the session.
if (isset($_POST['delete_dni_network']) && !empty($_POST['delete_dni_network'])) {
	// CSRF check — this deletes a DNI network and marks the linked aff network
	// deleted.
	if (!hash_equals((string)($_SESSION['token'] ?? ''), (string)($_POST['token'] ?? ''))) {
		http_response_code(403);
		die('Invalid token.');
	}
	$mysql['deleteDniNetworkId'] = $db->real_escape_string((string)$_POST['delete_dni_network']);
	$db->query("DELETE FROM 202_dni_networks WHERE id = '" . $mysql['deleteDniNetworkId'] . "' AND user_id = '" . $mysql['user_id'] . "'");
	$sql = "UPDATE 202_aff_networks SET aff_network_deleted = '1', aff_network_time = '" . time() . "' WHERE dni_network_id = '" . $mysql['deleteDniNetworkId'] . "'";
	$db->query($sql);
	header('Location: ' . get_absolute_url() . '202-account/api-integrations.php');
	die();
}

if (isset($_GET['edit_dni_network']) && !empty($_GET['edit_dni_network'])) {
	$mysql['editDniNetworkId'] = $db->real_escape_string((string)$_GET['edit_dni_network']);
	$sql_edit_dni = "SELECT * FROM 202_dni_networks WHERE id = '" . $mysql['editDniNetworkId'] . "' AND user_id = '" . $mysql['user_id'] . "'";
	$edit_dni_result = $db->query($sql_edit_dni);
	if ($edit_dni_result->num_rows > 0) {
		$edit_dni_row = $edit_dni_result->fetch_assoc();
		$editing_dni_network = true;
	}
}

$dni_sql = "SELECT * FROM 202_dni_networks WHERE user_id = '1'";
$dni_result = $db->query($dni_sql);

template_top('API Integrations');

?>

<style>
	/* API Integrations page — scoped card layout (Notion-grade pass) */
	.apiint{max-width:1100px;margin:0 auto;padding:4px 4px 48px;font-size:14px;color:#37352f;}
	.apiint *,.apiint *::before,.apiint *::after{box-sizing:border-box;}
	.apiint-head{margin:18px 2px 20px;}
	.apiint-head h1{font-size:22px;font-weight:700;margin:0 0 6px;color:#37352f;letter-spacing:-.01em;}
	.apiint-head p{margin:0;color:#787774;font-size:14px;}
	.apiint-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:16px;align-items:start;}
	.apiint-card{background:#fff;border:1px solid #e9e9e7;border-radius:10px;padding:20px 22px 18px;box-shadow:0 1px 2px rgba(15,15,15,.03);transition:border-color .15s ease,box-shadow .15s ease;}
	.apiint-card:hover{border-color:#d3d3d0;box-shadow:0 2px 6px rgba(15,15,15,.05);}
	.apiint-card--wide{grid-column:1/-1;}
	.apiint-card-head{display:flex;align-items:center;gap:10px;margin-bottom:6px;}
	.apiint-card-head img{width:22px;height:22px;border-radius:5px;object-fit:contain;flex:none;}
	.apiint-card-head h2{font-size:15px;font-weight:600;margin:0;color:#37352f;flex:1 1 auto;line-height:1.3;}
	.apiint-icon-fallback{width:22px;height:22px;border-radius:5px;background:#eef3fe;color:#2383e2;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;flex:none;letter-spacing:.02em;}
	.apiint-card-head h2 .btn{background:none;border:none;color:#c8c7c4;padding:0 2px;font-size:13px;line-height:1;vertical-align:1px;box-shadow:none;}
	.apiint-card-head h2 .btn:hover,.apiint-card-head h2 .btn:focus{color:#2383e2;background:none;}
	.apiint-pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:500;line-height:1;padding:4px 9px;border-radius:999px;white-space:nowrap;flex:none;}
	.apiint-pill-dot{width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.75;}
	.apiint-pill--ok{background:#dbeddb;color:#1c3829;}
	.apiint-pill--off{background:#f1f1ef;color:#6f6e69;}
	.apiint-pill--warn{background:#fdecc8;color:#402c1b;}
	.apiint-pill--err{background:#ffe2dd;color:#5d1715;}
	.apiint-desc{color:#787774;font-size:13px;line-height:1.5;margin:0 0 14px;}
	.apiint-desc a{color:#2383e2;}
	.apiint-note{display:flex;gap:8px;align-items:flex-start;font-size:13px;line-height:1.45;border-radius:6px;padding:9px 12px;margin:0 0 12px;text-align:left;}
	.apiint-note svg{flex:none;margin-top:2px;}
	.apiint-note--ok{background:#f0f9f0;color:#1c3829;border:1px solid #cfe8cf;}
	.apiint-note--err{background:#fdf0ef;color:#5d1715;border:1px solid #f5d5d0;}
	.apiint-note--warn{background:#fdf5e6;color:#6b4415;border:1px solid #f0e0bf;}
	.apiint-endpoint{display:flex;align-items:center;gap:8px;background:#f7f7f5;border:1px solid #edece9;border-radius:6px;padding:7px 10px;margin:0 0 10px;}
	.apiint-endpoint-label{font-size:11px;font-weight:600;color:#9b9a97;text-transform:uppercase;letter-spacing:.04em;flex:none;}
	.apiint-endpoint code{flex:1 1 auto;background:none;border:none;padding:0;font-size:12px;color:#37352f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:SFMono-Regular,Consolas,"Liberation Mono",Menlo,monospace;}
	.apiint-copy{flex:none;font-size:12px;font-weight:500;color:#2383e2;background:none;border:none;padding:2px 6px;border-radius:4px;cursor:pointer;}
	.apiint-copy:hover{background:#e8f2fc;}
	.apiint-copy.is-copied{color:#1c8a50;}
	.apiint-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:13px;font-weight:500;line-height:1;padding:9px 14px;border-radius:6px;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:background .12s ease;}
	.apiint-btn--primary{background:#2383e2;color:#fff;}
	.apiint-btn--primary:hover,.apiint-btn--primary:focus{background:#0e70cf;color:#fff;text-decoration:none;}
	.apiint-btn--ghost{background:#fff;border-color:#e0e0dd;color:#37352f;}
	.apiint-btn--ghost:hover,.apiint-btn--ghost:focus{background:#f7f7f5;color:#37352f;text-decoration:none;}
	.apiint-btn--danger{background:#fff;border-color:#f0d4d0;color:#c4554d;}
	.apiint-btn--danger:hover,.apiint-btn--danger:focus{background:#fdf0ef;color:#c4554d;text-decoration:none;}
	.apiint-actions{display:flex;align-items:center;gap:8px;margin-top:2px;flex-wrap:wrap;}
	.apiint-actions form{margin:0;display:inline;}
	.apiint-meta{font-size:12px;color:#9b9a97;margin:12px 0 0;line-height:1.5;}
	.apiint-config{margin-top:14px;border-top:1px solid #f1f1ef;padding-top:10px;}
	.apiint-config summary{list-style:none;display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:500;color:#6f6e69;cursor:pointer;user-select:none;border-radius:5px;padding:4px 8px;margin-left:-8px;}
	.apiint-config summary:hover{background:#f1f1ef;color:#37352f;}
	.apiint-config summary::-webkit-details-marker{display:none;}
	.apiint-config summary::before{content:"";width:0;height:0;border-left:5px solid currentColor;border-top:4px solid transparent;border-bottom:4px solid transparent;transition:transform .12s ease;flex:none;}
	.apiint-config[open] summary::before{transform:rotate(90deg);}
	.apiint-config form{margin:12px 0 0;}
	.apiint-field{margin:0 0 12px;}
	.apiint-field label{display:block;font-size:12px;font-weight:600;color:#6f6e69;margin:0 0 5px;}
	.apiint .apiint-field input.form-control{height:34px;font-size:13px;border:1px solid #e0e0dd;border-radius:6px;box-shadow:none;padding:6px 10px;}
	.apiint .apiint-field input.form-control:focus{border-color:#2383e2;box-shadow:0 0 0 2px rgba(35,131,226,.18);}
	.apiint .table{margin:0 0 16px;border:1px solid #edece9;border-radius:8px;border-collapse:separate;border-spacing:0;overflow:hidden;font-size:13px;width:100%;}
	.apiint .table th{background:#f7f7f5;color:#6f6e69;font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:600;border:none;border-bottom:1px solid #edece9;padding:8px 12px;text-align:left;}
	.apiint .table td{border:none;border-bottom:1px solid #f1f1ef;padding:9px 12px;vertical-align:middle;}
	.apiint .table tr:last-child td{border-bottom:none;}
	.apiint .table a{color:#6f6e69;}
	.apiint .table a:hover{color:#37352f;}
	.apiint-dni-form .form-control{height:34px;font-size:13px;border:1px solid #e0e0dd;border-radius:6px;box-shadow:none;}
	@media (max-width:767px){.apiint-grid{grid-template-columns:1fr;}.apiint-card{padding:16px;}}
</style>

<div class="apiint">
	<header class="apiint-head">
		<h1>API Integrations</h1>
		<p>Connect Prosper202 to your affiliate networks and tools. Everything here is optional &mdash; connect only what you use.</p>
	</header>
	<div class="apiint-grid">

		<section class="apiint-card apiint-card--wide" id="dni">
			<div class="apiint-card-head">
				<img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/dni.jpg" alt="">
				<h2>Direct Network Integration <?php showHelp("dni"); ?></h2>
				<?php if ($dni_result->num_rows > 0) {
					apiint_pill('ok', $dni_result->num_rows . ' network' . ($dni_result->num_rows === 1 ? '' : 's') . ' connected');
				} else {
					apiint_pill('off', 'Not connected');
				} ?>
			</div>
			<p class="apiint-desc">Search, apply to and set up offers from your affiliate networks without leaving Prosper202.</p>
			<?php showErrorMessage($error, 'dni_network'); ?>
			<?php showErrorMessage($error, 'dni_network_auth'); ?>
			<?php showSuccessMessage(isset($success['dni_network_added']) && $success['dni_network_added'], $success['dni_network_added'] ?? ''); ?>
			<?php showSuccessMessage(isset($_GET['dni_network_updated']), 'DNI Network updated successfully. API processing can take up to 5 minutes.'); ?>
			<?php if ($dni_result->num_rows > 0) { ?>
				<table class="table" id="stats-table">
					<thead>
						<tr>
							<th>Network</th>
							<th>API Key</th>
							<th>ID</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php while ($dni_row = $dni_result->fetch_assoc()) {
							if ($dni_row['processed'] == false) {
								$dniProcesing['networks'][] = ['id' => $dni_row['id'], 'networkId' => $dni_row['networkId'], 'api_key' => $dni_row['apiKey'], 'type' => $dni_row['type']];
							}
						?>
							<tr>
								<td> <img src="<?php echo htmlspecialchars((string) ($dni_row['favIcon']), ENT_QUOTES, 'UTF-8'); ?>" width=16>&nbsp;&nbsp;<?php echo htmlspecialchars((string) ($dni_row['name']), ENT_QUOTES, 'UTF-8') . " (" . htmlspecialchars((string) ($dni_row['type']), ENT_QUOTES, 'UTF-8') . ")"; ?><span class="fui-info-circle" style="font-size: 12px; margin: -25px 0px 0px 5px;" data-toggle="tooltip" title="" data-original-title="<?php echo htmlspecialchars((string) ($dni_row['shortDescription']), ENT_QUOTES, 'UTF-8'); ?>"></span><br>
									<?php if ($dni_row['processed'] == false) { ?>
										<div id="network-<?php echo $dni_row['id']; ?>">
											<span style='font-size:10px'>processing... <img src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif"></span>
											<div class="progress" style="margin: 0px 5px;">
												<div id="<?php echo $dni_row['id']; ?>" class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%; color:#34495E">
													0.00%
												</div>
											</div>
											<div>
											<?php } ?>
								</td>
								<td><?php echo substr((string) $dni_row['apiKey'], 0, 12) . "... "; ?><a href="#" class="link showFullDniApikey" data-long="<?php echo htmlspecialchars((string) ($dni_row['apiKey']), ENT_QUOTES, 'UTF-8'); ?>" data-short="<?php echo substr((string) $dni_row['apiKey'], 0, 12); ?>">show</a></td>
								<td><?php echo $dni_row['affiliateId']; ?></td>
								<td><a href="<?php echo get_absolute_url(); ?>202-account/api-integrations.php?edit_dni_network=<?php echo $dni_row['id']; ?>" title="Edit"><i class="glyphicon glyphicon-pencil"></i></a> <form method="post" style="display:inline" onsubmit="return confirm('Delete This DNI Network?');"><input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="delete_dni_network" value="<?php echo htmlspecialchars((string) $dni_row['id'], ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" title="Delete" class="btn btn-link" style="padding:0;border:0;vertical-align:baseline"><i class="glyphicon glyphicon-trash"></i></button></form></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			<?php } else { ?>
				<p class="apiint-meta" style="margin:0 0 12px;">No networks connected yet &mdash; pick a network below to get started.</p>
			<?php } ?>
			<div class="apiint-dni-form">
				<form class="form-horizontal" role="form" method="post" action="">
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>">
					<input type="hidden" name="dni_network_type" id="dni_network_type" value="<?php echo htmlspecialchars((string) ($edit_dni_row['type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="dni_network_name" id="dni_network_name" value="<?php echo htmlspecialchars((string) ($edit_dni_row['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
					<?php if (isset($editing_dni_network) && $editing_dni_network) { ?>
						<input type="hidden" name="editing_dni_network" value="1">
						<input type="hidden" name="editing_dni_network_id" value="<?php echo $edit_dni_row['id'] ?? ''; ?>">
					<?php } ?>
					<div class="col-xs-3" style="padding: 0px; padding-right: 5px;">
						<label class="sr-only" for="dni_network">Select Network</label>
						<select name="dni_network" class="form-control input-sm">
							<option value="">Select network</option>
							<?php foreach ($dniNetworks as $dninetwork) { ?>
								<option value="<?php echo $dninetwork['networkId']; ?>" data-type="<?php echo $dninetwork['networkType']; ?>" <?php if (isset($edit_dni_row['networkId']) && $edit_dni_row['networkId'] == $dninetwork['networkId'] || isset($mysql['add_dni']) && $mysql['add_dni'] == $dninetwork['networkId']) echo 'selected'; ?>><?php echo $dninetwork['name']; ?> (<?php echo $dninetwork['networkType']; ?>)</option>
							<?php } ?>
						</select>
					</div>
					<div class="<?php if (isset($editing_dni_network) && $editing_dni_network) {
									if (isset($edit_dni_row['type']) && $edit_dni_row['type'] == 'HasOffers') echo 'col-xs-7';
									else echo 'col-xs-5';
								} else {
									echo 'col-xs-7';
								} ?>" id="dni_api_key_input_group" style="padding: 0px; padding-right: 5px;">
						<label class="sr-only" for="dni_network_api_key">Add API key</label>
						<input type="text" name="dni_network_api_key" class="form-control input-sm" placeholder="API Key" value="<?php echo htmlspecialchars((string) ($edit_dni_row['apiKey'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
						<div id="dniInfo"></div>
					</div>
					<div class="col-xs-2" id="dni_affiliate_id_input_group" style="<?php if (isset($editing_dni_network) && $editing_dni_network) {
																						if (isset($edit_dni_row['type']) && $edit_dni_row['type'] == 'HasOffers') echo 'display:none;';
																					} else {
																						echo 'display:none;';
																					} ?> padding: 0px; padding-right: 5px;">
						<label class="sr-only" for="dni_network_affiliate_id">Add Affiliate ID</label>
						<input type="text" name="dni_network_affiliate_id" id="dni_network_affiliate_id" class="form-control input-sm" placeholder="Affiliate ID" value="<?php if (isset($editing_dni_network) && $editing_dni_network) {
																																								if (isset($edit_dni_row['type']) && $edit_dni_row['type'] == 'HasOffers') echo 'null';
																																							} else {
																																								echo $edit_dni_row['affiliateId'] ?? '';
																																							} ?>">
					</div>
					<div class="col-xs-2" style="padding: 0px;">
						<button class="apiint-btn apiint-btn--primary" type="submit" style="width:100%;"><?php if (isset($editing_dni_network) && $editing_dni_network) echo 'Save changes';
																											else echo 'Add network'; ?></button>
					</div>
				</form>
			</div>
		</section>

		<?php
		// Landing Page Optimizer pairing card. Status lives in
		// 202_users_pref (lpo_status / lpo_site_key); every feature screen is
		// hosted — this card only connects and disconnects the generic bridge.
		$lpo_schema_ready = array_key_exists('lpo_status', $user_row);
		$lpo_connected = $lpo_schema_ready && (string) ($user_row['lpo_status'] ?? '') === 'active';
		$lpo_site_key = (string) ($user_row['lpo_site_key'] ?? '');
		$lpo_has_api_key = trim((string) ($user_row['p202_customer_api_key'] ?? '')) !== '';
		$lpo_needs_subscription = !empty($lpo_needs_subscription); // set by the connect catch above
		$lpo_sub_retry = !empty($lpo_sub_retry); // the failed attempt came from the "I've subscribed" retry
		$lpo_saas_base = \Prosper202\Lpo\PairingClient::saasBaseUrl();
		$lpo_capabilities = \Prosper202\Lpo\PairingClient::CAPABILITIES;
		?>
		<section class="apiint-card" id="lpo">
			<div class="apiint-card-head">
				<div class="apiint-icon-fallback" aria-hidden="true">LP</div>
				<h2>Landing Page Optimizer</h2>
				<?php if ($lpo_connected) {
					apiint_pill('ok', 'Connected');
				} elseif (!$lpo_schema_ready) {
					apiint_pill('warn', 'Upgrade needed');
				} elseif ($lpo_needs_subscription) {
					apiint_pill('warn', 'Subscription required');
				} elseif (isset($error['lpo'])) {
					apiint_pill('err', 'Action needed');
				} else {
					apiint_pill('off', 'Not connected');
				} ?>
			</div>
			<p class="apiint-desc">Run hosted A/B experiments on your landing pages. Connecting registers a signed conversion webhook &mdash; nothing else changes on this install.</p>
			<?php showSuccessMessage(isset($_GET['lpo']) && $_GET['lpo'] === 'connected', 'Landing Page Optimizer connected.'); ?>
			<?php showSuccessMessage(isset($_GET['lpo']) && $_GET['lpo'] === 'disconnected', 'Landing Page Optimizer disconnected.'); ?>
			<?php showErrorMessage($error, 'lpo'); ?>
			<?php if ($lpo_connected) { ?>
				<?php apiint_endpoint('Site key', $lpo_site_key); ?>
				<div class="apiint-actions">
					<a href="<?php echo htmlspecialchars($lpo_saas_base); ?>/api/customers/experiments" target="_blank" rel="noopener" class="apiint-btn apiint-btn--primary">Manage experiments&nbsp;&rarr;</a>
					<form method="post" action="">
						<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
						<input type="hidden" name="lpo_action" value="disconnect" />
						<button class="apiint-btn apiint-btn--danger" type="submit" onclick="return confirm('Disconnect the Landing Page Optimizer? The pairing webhook will be removed.');">Disconnect</button>
					</form>
				</div>
				<?php if (array_key_exists('lpo_ctx_kw', $user_row)) { ?>
					<details class="apiint-config"<?php if (!empty($lpo_ctx_kw_saved)) echo ' open'; ?>>
						<summary>Privacy</summary>
						<?php showSuccessMessage(!empty($lpo_ctx_kw_saved), 'Context token preference saved.'); ?>
						<form method="post" action="">
							<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
							<input type="hidden" name="lpo_ctx_kw_save" value="1" />
							<div class="apiint-field">
								<label style="display:flex;align-items:flex-start;gap:8px;font-weight:400;color:#37352f;font-size:13px;cursor:pointer;text-transform:none;">
									<input type="checkbox" name="lpo_ctx_kw" value="1" style="margin:2px 0 0;"<?php if ((string) ($user_row['lpo_ctx_kw'] ?? '1') !== '0') echo ' checked'; ?>>
									<span>Include keyword text in optimizer context tokens<br><span class="apiint-meta" style="margin:0;">Keywords ride the signed t202ctx token on rotator&rarr;landing-page redirects so experiments can segment by search term. Turn off to keep search terms out of tokens.</span></span>
								</label>
							</div>
							<button class="apiint-btn apiint-btn--ghost" type="submit">Save</button>
						</form>
					</details>
				<?php } ?>
				<p class="apiint-meta">Bridge v<?php echo htmlspecialchars(\Prosper202\Bridge\EventBridge::BRIDGE_VERSION); ?> &middot; <?php echo htmlspecialchars(implode(', ', $lpo_capabilities['events'])); ?>, wildcard subscribe, remote config, v3 API, context tokens, dimensions sync</p>
			<?php } elseif (!$lpo_schema_ready) { ?>
				<p class="apiint-desc" style="margin-bottom:0;">Run the Prosper202 upgrade to enable this integration.</p>
			<?php } elseif ($lpo_needs_subscription) { ?>
				<div class="apiint-note apiint-note--warn" role="status">
					<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4"/><path d="M8 7.4v3.4M8 4.9h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
					<span><?php if ($lpo_sub_retry) { ?>We still don&rsquo;t see an active Landing Page Optimizer subscription for this account. If you just subscribed, give it a minute, then connect again.<?php } else { ?>Landing Page Optimizer runs on a Landing Page Optimizer plan, and this account doesn&rsquo;t have one yet. Start a subscription, then connect this install.<?php } ?></span>
				</div>
				<div class="apiint-actions">
					<a class="apiint-btn apiint-btn--primary" href="<?php echo htmlspecialchars($lpo_saas_base); ?>/api/customers/experiments" target="_blank" rel="noopener">Get Landing Page Optimizer&nbsp;&rarr;</a>
					<form method="post" action="" style="display:inline;">
						<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
						<input type="hidden" name="lpo_action" value="connect" />
						<input type="hidden" name="lpo_retry" value="1" />
						<button class="apiint-btn apiint-btn--ghost" type="submit">I&rsquo;ve subscribed &mdash; connect</button>
					</form>
				</div>
			<?php } elseif ($lpo_has_api_key) { ?>
				<div class="apiint-actions">
					<form method="post" action="">
						<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
						<input type="hidden" name="lpo_action" value="connect" />
						<button class="apiint-btn apiint-btn--primary" type="submit">Connect</button>
					</form>
				</div>
			<?php } else { ?>
				<p class="apiint-desc">You&rsquo;ll need your Prosper202 Customer API key first &mdash; it only takes a minute.</p>
				<div class="apiint-actions">
					<a class="apiint-btn apiint-btn--primary" href="<?php echo htmlspecialchars($lpo_saas_base); ?>/api/customers/login?redirect=get-api">Get your API key</a>
				</div>
			<?php } ?>
		</section>

		<section class="apiint-card" id="ipqs">
			<div class="apiint-card-head">
				<img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/ipqs.png" alt="">
				<h2>IPQualityScore <?php showHelp("jvzoo"); ?></h2>
				<?php apiint_pill(trim((string) ($user_row['ipqs_api_key'] ?? '')) !== '' ? 'ok' : 'off', trim((string) ($user_row['ipqs_api_key'] ?? '')) !== '' ? 'Connected' : 'Not connected'); ?>
			</div>
			<p class="apiint-desc">Detect and redirect click fraud in real time. <a href='https://202.redirexit.com/tracking202/redirect/dl.php?t202id=12608&t202kw=' target='_blank' rel='noopener'>Get a free API key</a>.</p>
			<?php showSuccessMessage($change_ipqs_api_key, 'Your IPQualityScore API key was changed successfully.'); ?>
			<?php showErrorMessage($error, 'ipqs_api_key_error'); ?>
			<details class="apiint-config"<?php if (isset($error['ipqs_api_key_error'])) echo ' open'; ?>>
				<summary><?php echo trim((string) ($user_row['ipqs_api_key'] ?? '')) !== '' ? 'Update API key' : 'Connect'; ?></summary>
				<form method="post" action="">
					<input type="hidden" name="change_ipqs_api_key" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="apiint-field">
						<label for="ipqs_api_key">IPQS API key</label>
						<input type="text" class="form-control input-sm" id="ipqs_api_key" name="ipqs_api_key" value="<?php echo $html['ipqs_api_key']; ?>">
					</div>
					<button class="apiint-btn apiint-btn--primary" type="submit">Save</button>
				</form>
			</details>
		</section>

		<section class="apiint-card" id="clickbank">
			<div class="apiint-card-head">
				<img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/clickbank.png" alt="">
				<h2>ClickBank <?php showHelp("clickbank"); ?></h2>
				<?php
				$cb_crypto_ok = extension_loaded('mcrypt') || function_exists("openssl_decrypt");
				if (!$cb_crypto_ok) {
					apiint_pill('warn', 'Unavailable');
				} elseif (trim((string) ($user_row['cb_key'] ?? '')) === '') {
					apiint_pill('off', 'Not connected');
				} elseif ($cb_verified) {
					apiint_pill('ok', 'Verified');
				} else {
					apiint_pill('warn', 'Unverified');
				} ?>
			</div>
			<p class="apiint-desc">Update conversions automatically from ClickBank&rsquo;s Instant Notification Service.</p>
			<?php showSuccessMessage($change_cb_key, 'Your Clickbank secret key was changed successfully.'); ?>
			<?php showErrorMessage($error, 'cb_key'); ?>
			<?php if ($cb_crypto_ok) { ?>
				<?php apiint_endpoint('INS URL', $strProtocol . '' . getTrackingDomain() . get_absolute_url() . 'tracking202/static/cb202.php'); ?>
				<details class="apiint-config"<?php if (isset($error['cb_key'])) echo ' open'; ?>>
					<summary><?php echo trim((string) ($user_row['cb_key'] ?? '')) !== '' ? 'Update secret key' : 'Connect'; ?></summary>
					<form method="post" action="">
						<input type="hidden" name="change_cb_key" value="1" />
						<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
						<div class="apiint-field">
							<label for="cb_key">ClickBank secret key</label>
							<input type="text" class="form-control input-sm" id="cb_key" name="cb_key" value="<?php echo $html['cb_key']; ?>">
						</div>
						<div class="apiint-actions">
							<button class="apiint-btn apiint-btn--primary" type="submit">Save</button>
							<a id="cb_status" class="apiint-btn apiint-btn--ghost">Check status</a>
							<small><span id="cb_verified">
								<?php if (!$cb_verified) { ?>
									<span class="label label-important">Unverified</span>
								<?php } else { ?>
									<span class="label label-primary">Verified</span>
								<?php } ?>
							</span></small>
						</div>
					</form>
				</details>
			<?php } else { ?>
				<div class="apiint-note apiint-note--err" role="alert"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 5v3.5M8 11h.01M8 1.5 14.5 13a.6.6 0 0 1-.52.9H2.02a.6.6 0 0 1-.52-.9L8 1.5z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg><span>The mcrypt (or OpenSSL) PHP extension is required for this integration. Install it, or ask your hosting provider for assistance.</span></div>
			<?php } ?>
		</section>

		<section class="apiint-card" id="jvzoo">
			<div class="apiint-card-head">
				<img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/jvzoo.png" alt="">
				<h2>JVZoo <?php showHelp("jvzoo"); ?></h2>
				<?php apiint_pill(trim((string) ($user_row['jvzoo_ipn_secret_key'] ?? '')) !== '' ? 'ok' : 'off', trim((string) ($user_row['jvzoo_ipn_secret_key'] ?? '')) !== '' ? 'Connected' : 'Not connected'); ?>
			</div>
			<p class="apiint-desc">Update conversions from JVZoo&rsquo;s Instant Payment Notification (JVZIPN).</p>
			<?php showSuccessMessage($change_jvzoo_secret_key, 'Your JVZoo secret key was changed successfully.'); ?>
			<?php showErrorMessage($error, 'jvzoo_secret_key_error'); ?>
			<?php apiint_endpoint('IPN URL', $strProtocol . '' . getTrackingDomain() . get_absolute_url() . 'tracking202/static/jvzoo.php'); ?>
			<details class="apiint-config"<?php if (isset($error['jvzoo_secret_key_error'])) echo ' open'; ?>>
				<summary><?php echo trim((string) ($user_row['jvzoo_ipn_secret_key'] ?? '')) !== '' ? 'Update secret key' : 'Connect'; ?></summary>
				<form method="post" action="">
					<input type="hidden" name="change_jvzoo_secret_key" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="apiint-field">
						<label for="jvzoo_ipn_secret_key">JVZoo secret key</label>
						<input type="text" class="form-control input-sm" id="jvzoo_ipn_secret_key" name="jvzoo_ipn_secret_key" value="<?php echo $html['jvzoo_ipn_secret_key']; ?>">
					</div>
					<button class="apiint-btn apiint-btn--primary" type="submit">Save</button>
				</form>
			</details>
		</section>

		<section class="apiint-card" id="zaxaa">
			<div class="apiint-card-head">
				<img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/zaxaa.png" alt="">
				<h2>Zaxaa <?php showHelp("zaxaa"); ?></h2>
				<?php apiint_pill(trim((string) ($user_row['zaxaa_api_signature'] ?? '')) !== '' ? 'ok' : 'off', trim((string) ($user_row['zaxaa_api_signature'] ?? '')) !== '' ? 'Connected' : 'Not connected'); ?>
			</div>
			<p class="apiint-desc">Update conversions from Zaxaa Payment Notification (ZPN).</p>
			<?php showSuccessMessage($change_zaxaa_api_signature, 'Your Zaxaa API signature was changed successfully.'); ?>
			<?php showErrorMessage($error, 'zaxaa_api_signature_error'); ?>
			<?php apiint_endpoint('ZPN URL', $strProtocol . '' . getTrackingDomain() . get_absolute_url() . 'tracking202/static/zpn.php'); ?>
			<details class="apiint-config"<?php if (isset($error['zaxaa_api_signature_error'])) echo ' open'; ?>>
				<summary><?php echo trim((string) ($user_row['zaxaa_api_signature'] ?? '')) !== '' ? 'Update API signature' : 'Connect'; ?></summary>
				<form method="post" action="">
					<input type="hidden" name="change_zaxaa_api_signature" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="apiint-field">
						<label for="zaxaa_api_signature">Zaxaa API signature</label>
						<input type="text" class="form-control input-sm" id="zaxaa_api_signature" name="zaxaa_api_signature" value="<?php echo $html['zaxaa_api_signature']; ?>">
					</div>
					<button class="apiint-btn apiint-btn--primary" type="submit">Save</button>
				</form>
			</details>
		</section>

		<section class="apiint-card" id="slack">
			<div class="apiint-card-head">
				<img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/slack.png" alt="">
				<h2>Slack <?php showHelp("slack"); ?></h2>
				<?php apiint_pill(trim((string) ($user_row['user_slack_incoming_webhook'] ?? '')) !== '' ? 'ok' : 'off', trim((string) ($user_row['user_slack_incoming_webhook'] ?? '')) !== '' ? 'Connected' : 'Not connected'); ?>
			</div>
			<p class="apiint-desc">Send Prosper202 notifications into a Slack channel, and receive Slack commands via the webhook below.</p>
			<?php showSuccessMessage($change_user_slack_incoming_webhook, 'Your Slack Incoming Webhook URL was changed successfully.'); ?>
			<?php showErrorMessage($error, 'user_slack_incoming_webhook'); ?>
			<?php apiint_endpoint('P202 webhook', $strProtocol . '' . getTrackingDomain() . get_absolute_url() . 'tracking202/static/slack.php'); ?>
			<details class="apiint-config"<?php if (isset($error['user_slack_incoming_webhook'])) echo ' open'; ?>>
				<summary><?php echo trim((string) ($user_row['user_slack_incoming_webhook'] ?? '')) !== '' ? 'Update webhook URL' : 'Connect'; ?></summary>
				<form method="post" action="">
					<input type="hidden" name="change_user_slack_incoming_webhook" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="apiint-field">
						<label for="user_slack_incoming_webhook">Slack incoming webhook URL</label>
						<input type="text" class="form-control input-sm" id="user_slack_incoming_webhook" name="user_slack_incoming_webhook" value="<?php echo $html['user_slack_incoming_webhook']; ?>">
					</div>
					<button class="apiint-btn apiint-btn--primary" type="submit">Save</button>
				</form>
			</details>
		</section>

		<section class="apiint-card" id="paykickstart">
			<div class="apiint-card-head">
				<img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/paykickstart.png" alt="">
				<h2>PayKickstart <?php showHelp("paykickstart"); ?></h2>
				<?php apiint_pill('off', 'No setup needed'); ?>
			</div>
			<p class="apiint-desc">Update conversions from PayKickstart&rsquo;s Affiliate IPN &mdash; just paste this URL as your IPN URL in PayKickstart.</p>
			<?php apiint_endpoint('IPN URL', $strProtocol . '' . getTrackingDomain() . get_absolute_url() . 'tracking202/static/paykickstart.php'); ?>
		</section>

	</div>
</div>

<script>
	$(function() {
		// Copy-to-clipboard for endpoint URLs
		$('.apiint').on('click', '.apiint-copy', function() {
			var $btn = $(this);
			var text = $btn.attr('data-copy');
			var done = function() {
				$btn.addClass('is-copied').text('Copied');
				setTimeout(function() { $btn.removeClass('is-copied').text('Copy'); }, 1600);
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done);
			} else {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.style.position = 'fixed';
				ta.style.opacity = '0';
				document.body.appendChild(ta);
				ta.select();
				try { document.execCommand('copy'); done(); } catch (e) {}
				document.body.removeChild(ta);
			}
		});
		// After a POST, bring the outcome into view
		var note = $('.apiint-note').first();
		if (note.length) {
			note.closest('details').attr('open', true);
			note[0].scrollIntoView({ block: 'center' });
		}
	});
</script>

<?php if (count($dniProcesing['networks']) > 0) { ?>
	<script type="text/javascript">
		$(document).ready(function() {
			var DNIdata = JSON.stringify(<?php echo json_encode($dniProcesing, JSON_NUMERIC_CHECK); ?>);
			getDNIProgress(DNIdata);

			window.setInterval(function() {
				getDNIProgress(DNIdata);
			}, 3000);

			function getDNIProgress(DNIdata) {
				$.post("<?php echo get_absolute_url(); ?>202-account/ajax/dni.php?getProgress=true", DNIdata).done(function(response) {
					var json = $.parseJSON(response);
					$.each(json.data, function(index, item) {
						if (item.progress == '100') {
							$.post("<?php echo get_absolute_url(); ?>202-account/ajax/dni.php?updateStatus=true&dni=" + item.id, function(data1) {
								$("#network-" + item.id).remove();
							});
						}
						$("#" + item.id).css('width', item.progress + '%').attr('aria-valuenow', item.progress).text(item.progress + '%');
					});
				});
			}
			$('select[name=dni_network]').trigger("change");
		});
	</script>
<?php } else { ?>
	<script type="text/javascript">
		$(document).ready(function() {
			//manually trigger the change function
			$('select[name=dni_network]').trigger("change");
		});
	</script>
<?php } ?>
<script>
	dniNetworks = <?php echo json_encode(getAllDniNetworks($user_row['install_hash'])); ?>;

	function dni() {
		var selectedNetwork = $('select[name=dni_network] option:selected').val()
		var dniNetwork = $(dniNetworks).filter(function(i, n) {
			return n.networkId === selectedNetwork
		});
		var dniInfo = '<small> <img src="' + dniNetwork[0].favIconUrl + '" width="16"> <strong>' + dniNetwork[0].name + '</strong><br><br>' + dniNetwork[0].shortDescription + ' <br><br><a href="' + dniNetwork[0].websiteURL + '" target="_blank" class="btn btn-xs btn-info btn-block">Get An Account with ' + dniNetwork[0].name + '</a></small>'
		$("#dniInfo").html(dniInfo);
	}
</script>
<?php template_bottom();
