<?php

/**
 * Account › 3rd-party API integrations, on the v2 shell.
 *
 * Every form keeps its field names and posts the session token; the POST
 * handler refuses a request whose token does not match before it writes
 * anything. Removing a DNI network was a GET link guarded only by confirm()
 * in the browser, so any page could make a signed-in browser remove one; it
 * is a POST with the token now, behind the same confirmation.
 */

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');
include_once(str_repeat("../", 1) . '202-config/clickserver_api_management.php');
require_once __DIR__ . '/../202-config/functions-account-ui.php';

AUTH::require_user();

if (!$userObj->hasPermission("access_to_api_integrations")) {
	header('location: ' . get_absolute_url() . '202-account/');
	exit;
}

// Initialize variables to prevent undefined variable warnings
$error = [];
$html = [];
$mysql = [];

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

	if (!validateRequired($_POST[$post_key] ?? '', $error_key, $error_message, $error)) {
		return false;
	}

	if (!$error) {
		$new_value = (string)$_POST[$post_key];
		$old_value = $user_row[$field_name] ?? '';

		if ($new_value !== $old_value) {
			if (!updateUserPreference($field_name, $new_value, $user_id, $db)) {
				$error[$error_key] = 'This could not be saved just now; the old value is still in place. Try again.';
				return false;
			}

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
 * The ClickBank verification pill, in one place: the page renders it and the
 * "Check status" request answers with it.
 */
function apiint_cb_pill(bool $verified): string
{
	return $verified
		? '<span class="p202-pill p202-pill--good">Verified</span>'
		: '<span class="p202-pill p202-pill--warn">Unverified</span>';
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
$user_row = $user_results->fetch_assoc();
$username = $user_row['username'];
$editing_dni_network = false;
$dniNetworks = getAllDniNetworks($user_row['install_hash']);
// The network list comes from the DNI service; when it cannot be reached the
// page says so instead of offering an empty select.
$dniNetworksAvailable = is_array($dniNetworks);
if (!$dniNetworksAvailable) {
	$dniNetworks = [];
}
$dniProcesing = ['host' => getDNIHost(), 'install_hash' => $user_row['install_hash'], 'networks' => []];

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

if (isset($_GET['cb_status']) && $_GET['cb_status'] == 1) {
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$user_sql = "SELECT cb_verified
             FROM 202_users_pref
             WHERE user_id='" . $mysql['user_id'] . "'";
	$user_results = $db->query($user_sql);
	$user_row = $user_results ? $user_results->fetch_assoc() : null;
	if (!is_array($user_row)) {
		http_response_code(503);
		echo '<span class="p202-pill p202-pill--bad">Could not check</span>';
		die();
	}
	echo apiint_cb_pill((bool)$user_row['cb_verified']);
	die();
}

//get all of the user data
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$user_sql = "	SELECT 	*
				 FROM   	`202_users`
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='" . $mysql['user_id'] . "'";
$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();

$cb_verified = $user_row['cb_verified'];

// The retired GET address for removing a DNI network: never a write.
if (isset($_GET['delete_dni_network']) && !empty($_GET['delete_dni_network'])) {
	p202_account_flash('warn', 'Removing a network now asks first. Use remove beside the network below.');
	p202_account_redirect('202-account/api-integrations.php#dni');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	// validate token
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/api-integrations.php');
	}

	// ClickBank Key Update
	if (isset($_POST['change_cb_key']) && $_POST['change_cb_key'] == '1') {
		$config = [
			'post_key' => 'cb_key',
			'field_name' => 'cb_key',
			'error_key' => 'cb_key',
			'error_message' => 'Clickbank Secret Key can\'t be empty!',
			'slack_event' => 'cb_key_updated'
		];
		if (processApiKeyUpdate($config, $error, $change_cb_key, $user_row, $slack, $username, $db)) {
			p202_account_flash('ok', 'Your ClickBank secret key was saved.');
			p202_account_redirect('202-account/api-integrations.php#clickbank');
		}
	}

	// Slack Webhook Update
	if (isset($_POST['change_user_slack_incoming_webhook']) && $_POST['change_user_slack_incoming_webhook'] == '1') {
		$config = [
			'post_key' => 'user_slack_incoming_webhook',
			'field_name' => 'user_slack_incoming_webhook',
			'error_key' => 'user_slack_incoming_webhook',
			'error_message' => 'Slack Incoming Webhook URL can\'t be empty!',
			'slack_event' => 'user_slack_incoming_webhook_updated'
		];
		if (processApiKeyUpdate($config, $error, $change_user_slack_incoming_webhook, $user_row, $slack, $username, $db)) {
			p202_account_flash('ok', 'Your Slack incoming webhook URL was saved.');
			p202_account_redirect('202-account/api-integrations.php#slack');
		}
	}

	// Zaxaa API Signature Update
	if (isset($_POST['change_zaxaa_api_signature']) && $_POST['change_zaxaa_api_signature'] == '1') {
		$config = [
			'post_key' => 'zaxaa_api_signature',
			'field_name' => 'zaxaa_api_signature',
			'error_key' => 'zaxaa_api_signature_error',
			'error_message' => 'Zaxaa API signature can\'t be empty!',
			'slack_event' => 'zaxaa_api_signature_updated'
		];
		if (processApiKeyUpdate($config, $error, $change_zaxaa_api_signature, $user_row, $slack, $username, $db)) {
			p202_account_flash('ok', 'Your Zaxaa API signature was saved.');
			p202_account_redirect('202-account/api-integrations.php#zaxaa');
		}
	}

	// JVZoo Secret Key Update
	if (isset($_POST['change_jvzoo_secret_key']) && $_POST['change_jvzoo_secret_key'] == '1') {
		$config = [
			'post_key' => 'jvzoo_ipn_secret_key',
			'field_name' => 'jvzoo_ipn_secret_key',
			'error_key' => 'jvzoo_secret_key_error',
			'error_message' => 'JVZoo secret key can\'t be empty!',
			'slack_event' => 'jvzoo_secret_key_updated'
		];
		if (processApiKeyUpdate($config, $error, $change_jvzoo_secret_key, $user_row, $slack, $username, $db)) {
			p202_account_flash('ok', 'Your JVZoo secret key was saved.');
			p202_account_redirect('202-account/api-integrations.php#jvzoo');
		}
	}

	// IPQualityScore API Key Update
	if (isset($_POST['change_ipqs_api_key']) && $_POST['change_ipqs_api_key'] == '1') {
		$config = [
			'post_key' => 'ipqs_api_key',
			'field_name' => 'ipqs_api_key',
			'error_key' => 'ipqs_api_key_error',
			'error_message' => 'The IPQualityScore API Key can\'t be empty!',
			'slack_event' => 'ipqs_api_key_updated'
		];
		if (processApiKeyUpdate($config, $error, $change_ipqs_api_key, $user_row, $slack, $username, $db)) {
			p202_account_flash('ok', 'Your IPQualityScore API key was saved.');
			p202_account_redirect('202-account/api-integrations.php#ipqs');
		}
	}

	// Landing Page Optimizer pairing: connect registers a local
	// wildcard webhook and completes the SaaS handshake; disconnect reverses.
	if (isset($_POST['lpo_action']) && in_array($_POST['lpo_action'], ['connect', 'disconnect'], true)) {
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
	if (isset($_POST['lpo_ctx_kw_save']) && $_POST['lpo_ctx_kw_save'] == '1' && array_key_exists('lpo_ctx_kw', $user_row)) {
		$lpo_ctx_kw_new = !empty($_POST['lpo_ctx_kw']) ? '1' : '0';
		if ($lpo_ctx_kw_new !== (string) ($user_row['lpo_ctx_kw'] ?? '1')) {
			updateUserPreference('lpo_ctx_kw', $lpo_ctx_kw_new, $_SESSION['user_id'], $db);
			lpo_ctx_pref_cache_bust($_SESSION['user_id']);
			$user_row['lpo_ctx_kw'] = $lpo_ctx_kw_new;
		}
		p202_account_flash('ok', 'Context token preference saved.');
		p202_account_redirect('202-account/api-integrations.php#lpo');
	}

	if (isset($_POST['delete_dni_network'])) {
		$deleteDniId = (int)$_POST['delete_dni_network'];
		$mysql['deleteDniNetworkId'] = $db->real_escape_string((string)$deleteDniId);
		$dniDeleted = $db->query("DELETE FROM 202_dni_networks WHERE id = '" . $mysql['deleteDniNetworkId'] . "' AND user_id = '" . $mysql['user_id'] . "'");
		$dniDeletedRows = $dniDeleted ? $db->affected_rows : 0;
		if ($dniDeletedRows > 0) {
			// Only the network this user owned: the classic query retired
			// the affiliate network of whatever id it was handed.
			$sql = "UPDATE 202_aff_networks SET aff_network_deleted = '1', aff_network_time = '" . time() . "' WHERE dni_network_id = '" . $mysql['deleteDniNetworkId'] . "' AND user_id = '" . $mysql['user_id'] . "'";
			if ($db->query($sql)) {
				p202_account_flash('ok', 'The network is removed. Its offers stay in your campaigns; they stop updating.');
			} else {
				// The integration is gone; its category could not be retired.
				error_log('api-integrations.php: the DNI category was not retired: ' . $db->error);
				p202_account_flash('warn', 'The network is removed, but its campaign category could not be retired. If it is still listed under Setup › Campaign Categories, remove it there.');
			}
		} elseif (!$dniDeleted) {
			p202_account_flash('bad', 'The network could not be removed; try again.');
		} else {
			p202_account_flash('warn', 'That network was not found; nothing was removed.');
		}
		p202_account_redirect('202-account/api-integrations.php#dni');
	}

	if (isset($_POST['dni_network'])) {
		$dniRequired = ['dni_network', 'dni_network_type', 'dni_network_name', 'dni_network_api_key', 'dni_network_affiliate_id'];
		$dniMissing = array_filter($dniRequired, static fn (string $field): bool => trim((string)($_POST[$field] ?? '')) === '');
		if ($dniMissing) {
			$error['dni_network'] = 'Make sure all fields are selected and filled out!';
		} else {
			$mysql['dniNetworkId'] = $db->real_escape_string((string)$_POST['dni_network']);
			$mysql['dniNetworkType'] = $db->real_escape_string((string)$_POST['dni_network_type']);
			$dniNetworkName = explode(" (", (string) $_POST['dni_network_name'], 2);
			$mysql['dniNetworkName'] = $db->real_escape_string($dniNetworkName[0]);
			$mysql['dniAffiliateId'] = $db->real_escape_string((string)$_POST['dni_network_affiliate_id']);
			$mysql['dniApikey'] = $db->real_escape_string((string)$_POST['dni_network_api_key']);
			$dniAuth = authDniNetworks($user_row['install_hash'], $_POST['dni_network'], $_POST['dni_network_api_key'], $_POST['dni_network_affiliate_id']);

			if (empty($dniAuth['auth'])) {
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

					$dniProcessed = $db->real_escape_string((string)$dniAuth['processed']);

					$sql = "INSERT INTO 202_dni_networks SET user_id = '" . $mysql['user_id'] . "', networkId = '" . $mysql['dniNetworkId'] . "', name = '" . $mysql['dniNetworkName'] . "', type = '" . $mysql['dniNetworkType'] . "', apiKey = '" . $mysql['dniApikey'] . "', time = '" . time() . "', processed = '" . $dniProcessed . "', shortDescription = '" . $mysql['dniShortDescription'] . "', favIcon = '" . $mysql['dniFavIcon'] . "'";

					if ($_POST['dni_network_type'] == 'Cake') {
						$sql .= ", affiliateId = '" . $mysql['dniAffiliateId'] . "'";
					}

					// The integration and its campaign category land together:
					// the category's first query was checked and the second was
					// not, so a failure left an integration with no category
					// while the page said "configured" (#165, #1).
					$dniSaved = false;
					try {
						(new \Prosper202\Database\Connection($db))->transaction(static function () use ($db, $sql, $mysql): void {
							if (!$db->query($sql)) {
								throw new RuntimeException('the network: ' . $db->error);
							}
							$categorySql = "INSERT INTO 202_aff_networks SET dni_network_id = '" . (int) $db->insert_id . "', user_id = '" . $mysql['user_id'] . "', aff_network_name = '" . $mysql['dniNetworkName'] . " (DNI)" . "', aff_network_time = '" . time() . "'";
							if (!$db->query($categorySql)) {
								throw new RuntimeException('its category: ' . $db->error);
							}
						});
						$dniSaved = true;
					} catch (Throwable $failed) {
						error_log('api-integrations.php: a DNI network was not saved: ' . $failed->getMessage());
					}
					if ($dniSaved) {
						tagUserByNetwork($user_row['install_hash'], 'affiliate-networks', $dniNetworkName[0]);
						p202_account_flash('ok', $dniNetworkName[0] . ' network configured. API processing can take up to 5 minutes.');
						p202_account_redirect('202-account/api-integrations.php#dni');
					}
					$error['dni_network'] = 'The network could not be saved, and nothing was added; try again.';
				} else if (isset($_POST['editing_dni_network_id']) && !empty($_POST['editing_dni_network_id'])) {
					$mysql['editing_dni_network_id'] = $db->real_escape_string((string)$_POST['editing_dni_network_id']);
					$sql = "UPDATE 202_dni_networks SET networkId = '" . $mysql['dniNetworkId'] . "', name = '" . $mysql['dniNetworkName'] . "', type = '" . $mysql['dniNetworkType'] . "', apiKey = '" . $mysql['dniApikey'] . "', time = '" . time() . "'";

					if ($_POST['dni_network_type'] == 'Cake') {
						$sql .= ", affiliateId = '" . $mysql['dniAffiliateId'] . "'";
					}

					$sql .= " WHERE id = '" . $mysql['editing_dni_network_id'] . "' AND user_id = '" . $mysql['user_id'] . "'";

					// The edit names a network of this account's or changes
					// nothing: it flashed "updated" on a no-op for an id that is
					// not there. `time` always changes, so a row that exists is
					// always an affected row.
					$dniFound = true;
					$dniSaved = false;
					try {
						(new \Prosper202\Database\Connection($db))->transaction(static function () use ($db, $sql, $mysql, &$dniFound): void {
							if (!$db->query($sql)) {
								throw new RuntimeException('the network: ' . $db->error);
							}
							if ($db->affected_rows < 1) {
								$dniFound = false;
								return;
							}
							$categorySql = "UPDATE 202_aff_networks SET aff_network_name = '" . $mysql['dniNetworkName'] . " (DNI)" . "', aff_network_time = '" . time() . "' WHERE dni_network_id = '" . $mysql['editing_dni_network_id'] . "' AND user_id = '" . $mysql['user_id'] . "'";
							if (!$db->query($categorySql)) {
								throw new RuntimeException('its category: ' . $db->error);
							}
						});
						$dniSaved = $dniFound;
					} catch (Throwable $failed) {
						error_log('api-integrations.php: a DNI network was not updated: ' . $failed->getMessage());
					}
					if ($dniSaved) {
						p202_account_flash('ok', 'DNI network updated. API processing can take up to 5 minutes.');
						p202_account_redirect('202-account/api-integrations.php#dni');
					}
					$error['dni_network'] = $dniFound
						? 'The network could not be saved, and nothing changed; try again.'
						: 'That network was not found; nothing was changed.';
				}
			}
		}
	}
}

if (isset($_GET['edit_dni_network']) && !empty($_GET['edit_dni_network'])) {
	$mysql['editDniNetworkId'] = $db->real_escape_string((string)$_GET['edit_dni_network']);
	$sql_edit_dni = "SELECT * FROM 202_dni_networks WHERE id = '" . $mysql['editDniNetworkId'] . "' AND user_id = '" . $mysql['user_id'] . "'";
	$edit_dni_result = $db->query($sql_edit_dni);
	if ($edit_dni_result && $edit_dni_result->num_rows > 0) {
		$edit_dni_row = $edit_dni_result->fetch_assoc();
		$editing_dni_network = true;
	}
}

$dni_sql = "SELECT * FROM 202_dni_networks WHERE user_id = '1'";
$dni_result = $db->query($dni_sql);
$dniRows = [];
if ($dni_result) {
	while ($dni_row = $dni_result->fetch_assoc()) {
		$dniRows[] = $dni_row;
		if ($dni_row['processed'] == false) {
			$dniProcesing['networks'][] = ['id' => $dni_row['id'], 'networkId' => $dni_row['networkId'], 'api_key' => $dni_row['apiKey'], 'type' => $dni_row['type']];
		}
	}
}

// The networks still importing, for the progress poller's inline script.
// Network-supplied strings can be invalid UTF-8, which made json_encode()
// return false and the script read `var processing = ;` (#165, #1): they are
// substituted, and an encoding that still fails is said on the page.
$dniProcessingJson = json_encode($dniProcesing, JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
if ($dniProcessingJson === false) {
	error_log('api-integrations.php: the DNI progress data could not be encoded: ' . json_last_error_msg());
	$dniProcessingJson = '{"networks":[]}';
	$dniProgressUnavailable = true;
}

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$self = get_absolute_url() . '202-account/api-integrations.php';
$base = get_absolute_url();
/** What was typed on a refused submit, or what is stored. Secret fields here were shown in full on the classic page too. */
$fieldValue = static function (string $field) use ($user_row): string {
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST[$field])) {
		return (string)$_POST[$field];
	}
	return (string)($user_row[$field] ?? '');
};
/** A help article link, when this integration has one. */
$helpLink = static function (string $page) use ($e): string {
	$url = showHelpUrl($page);
	return $url === '' ? '' : '<a class="p202-help" href="' . $e($url) . '" target="_blank" rel="noopener" title="Help" aria-label="Help"><i class="bi bi-question-circle"></i></a>';
};
/** A labelled URL to paste somewhere else, with Copy. */
$endpoint = static function (string $label, string $url) use ($e): string {
	return '<label class="form-label">' . $e($label) . '</label>'
		. '<div class="p202-code mb-3"><pre class="p202-code__value">' . $e($url) . '</pre>'
		. '<button type="button" class="btn btn-secondary btn-sm p202-copy" data-p202-copy="' . $e($url) . '">Copy</button></div>';
};
$trackingBase = $strProtocol . getTrackingDomain() . get_absolute_url();

$flashExtra = [];
if (!empty($dniProgressUnavailable)) {
	$flashExtra[] = ['kind' => 'warn', 'text' => 'The import progress of your DNI networks cannot be shown just now; reload the page to see it.'];
}
if (isset($_GET['lpo']) && $_GET['lpo'] === 'connected') {
	$flashExtra[] = ['kind' => 'ok', 'text' => 'Landing Page Optimizer connected.'];
}
if (isset($_GET['lpo']) && $_GET['lpo'] === 'disconnected') {
	$flashExtra[] = ['kind' => 'ok', 'text' => 'Landing Page Optimizer disconnected.'];
}
if (!$dni_result) {
	$flashExtra[] = ['kind' => 'bad', 'text' => 'Your DNI networks could not be read just now. Reload the page to see them.'];
}

/** The key-based integrations, which all work the same way: one secret, one Save. */
$keyIntegrations = [
	'ipqs' => [
		'title' => 'IPQualityScore', 'icon' => 'ipqs.png', 'help' => '',
		'desc' => 'Detect and redirect click fraud in real time.',
		'extra' => '<a href="https://202.redirexit.com/tracking202/redirect/dl.php?t202id=12608&amp;t202kw=" target="_blank" rel="noopener">Get a free API key</a>',
		'flag' => 'change_ipqs_api_key', 'field' => 'ipqs_api_key', 'label' => 'IPQS API key', 'error' => 'ipqs_api_key_error',
		'endpoint' => null,
	],
	'clickbank' => [
		'title' => 'ClickBank', 'icon' => 'clickbank.png', 'help' => 'clickbank',
		'desc' => 'Update conversions automatically from ClickBank’s Instant Notification Service.',
		'extra' => '',
		'flag' => 'change_cb_key', 'field' => 'cb_key', 'label' => 'ClickBank secret key', 'error' => 'cb_key',
		'endpoint' => ['INS URL', $trackingBase . 'tracking202/static/cb202.php'],
	],
	'jvzoo' => [
		'title' => 'JVZoo', 'icon' => 'jvzoo.png', 'help' => 'jvzoo',
		'desc' => 'Update conversions from JVZoo’s Instant Payment Notification (JVZIPN).',
		'extra' => '',
		'flag' => 'change_jvzoo_secret_key', 'field' => 'jvzoo_ipn_secret_key', 'label' => 'JVZoo secret key', 'error' => 'jvzoo_secret_key_error',
		'endpoint' => ['IPN URL', $trackingBase . 'tracking202/static/jvzoo.php'],
	],
	'zaxaa' => [
		'title' => 'Zaxaa', 'icon' => 'zaxaa.png', 'help' => 'zaxaa',
		'desc' => 'Update conversions from Zaxaa Payment Notification (ZPN).',
		'extra' => '',
		'flag' => 'change_zaxaa_api_signature', 'field' => 'zaxaa_api_signature', 'label' => 'Zaxaa API signature', 'error' => 'zaxaa_api_signature_error',
		'endpoint' => ['ZPN URL', $trackingBase . 'tracking202/static/zpn.php'],
	],
	'slack' => [
		'title' => 'Slack', 'icon' => 'slack.png', 'help' => 'slack',
		'desc' => 'Send Prosper202 notifications into a Slack channel, and receive Slack commands at the webhook below.',
		'extra' => '',
		'flag' => 'change_user_slack_incoming_webhook', 'field' => 'user_slack_incoming_webhook', 'label' => 'Slack incoming webhook URL', 'error' => 'user_slack_incoming_webhook',
		'endpoint' => ['Prosper202 webhook', $trackingBase . 'tracking202/static/slack.php'],
	],
];
$cb_crypto_ok = extension_loaded('mcrypt') || function_exists("openssl_decrypt");

template_top('API Integrations', ['ui' => 'v2']);
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-plug"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">API integrations</h1>
		<p class="p202-page-header__desc">Connect Prosper202 to your affiliate networks and tools. Everything here is optional; connect only what you use.</p>
	</div>
</div>

<?php echo p202_account_render_flashes($flashExtra); ?>

<div class="row g-4">
	<div class="col-12">
		<section class="p202-panel" id="dni">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Direct Network Integration</h2>
				<?php echo $helpLink('dni'); ?>
				<?php if ($dniRows) { ?>
					<span class="p202-pill p202-pill--good"><?php echo count($dniRows) === 1 ? '1 network connected' : count($dniRows) . ' networks connected'; ?></span>
				<?php } else { ?>
					<span class="p202-pill">Not connected</span>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<p class="text-secondary">Search, apply to and set up offers from your affiliate networks without leaving Prosper202.</p>
				<?php
				foreach (['dni_network', 'dni_network_auth'] as $dniErrorKey) {
					if (isset($error[$dniErrorKey])) {
						echo p202_flash('bad', $error[$dniErrorKey]);
					}
				}
				?>
				<?php if ($dniRows) { ?>
					<div class="p202-table-wrap mb-3">
						<table class="table p202-table">
							<thead><tr><th>Network</th><th>API key</th><th>Affiliate ID</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
							<tbody>
								<?php foreach ($dniRows as $dni_row) {
									$dniKey = (string)$dni_row['apiKey'];
									$dniKeyId = 'dni-key-' . (int)$dni_row['id'];
									?>
									<tr>
										<td>
											<?php if ((string)$dni_row['favIcon'] !== '') { ?><img src="<?php echo $e($dni_row['favIcon']); ?>" width="16" height="16" alt="" class="me-1"><?php } ?>
											<span title="<?php echo $e($dni_row['shortDescription']); ?>"><?php echo $e($dni_row['name'] . ' (' . $dni_row['type'] . ')'); ?></span>
											<?php if ($dni_row['processed'] == false) { ?>
												<div class="mt-1" id="network-<?php echo (int)$dni_row['id']; ?>">
													<div class="form-text mt-0">Processing offers…</div>
													<div class="progress" role="progressbar" aria-label="Processing <?php echo $e($dni_row['name']); ?>" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
														<div class="progress-bar" data-dni-progress="<?php echo (int)$dni_row['id']; ?>" style="width: 0%">0%</div>
													</div>
												</div>
											<?php } ?>
										</td>
										<td>
											<div class="p202-code">
												<pre class="p202-code__value p202-code__value--masked" id="<?php echo $dniKeyId; ?>" data-p202-value="<?php echo $e($dniKey); ?>"><?php echo $e(substr($dniKey, 0, 12) . str_repeat("\u{2022}", 8)); ?></pre>
												<button type="button" class="btn btn-secondary btn-sm" data-p202-reveal="#<?php echo $dniKeyId; ?>">Reveal</button>
											</div>
										</td>
										<td><?php echo $e($dni_row['affiliateId'] ?? ''); ?></td>
										<td class="text-end text-nowrap">
											<a class="btn btn-secondary btn-sm" href="<?php echo $e($self . '?edit_dni_network=' . (int)$dni_row['id'] . '#dni'); ?>">Edit</a>
											<form method="post" action="<?php echo $e($self . '#dni'); ?>" class="d-inline" data-p202-confirm="<?php echo $e('Remove ' . $dni_row['name'] . '? Its offers stay in your campaigns but stop updating, and its DNI affiliate network is retired.'); ?>">
												<?php echo p202_account_token_field(); ?>
												<input type="hidden" name="delete_dni_network" value="<?php echo (int)$dni_row['id']; ?>">
												<button type="submit" class="btn btn-outline-danger btn-sm">Remove…</button>
											</form>
										</td>
									</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>
				<?php } ?>

				<?php if (!$dniNetworksAvailable) { ?>
					<?php echo p202_flash('warn', 'The list of DNI networks could not be loaded from the Prosper202 service just now, so a network cannot be added. Reload the page to try again.'); ?>
				<?php } else {
					$dniType = (string)($_POST['dni_network_type'] ?? ($edit_dni_row['type'] ?? ''));
					$dniSelected = (string)($_POST['dni_network'] ?? ($edit_dni_row['networkId'] ?? $mysql['add_dni']));
					?>
					<form method="post" action="<?php echo $e($self . '#dni'); ?>" data-dni-form>
						<?php echo p202_account_token_field(); ?>
						<input type="hidden" name="dni_network_type" id="dni_network_type" value="<?php echo $e($dniType); ?>">
						<input type="hidden" name="dni_network_name" id="dni_network_name" value="<?php echo $e($_POST['dni_network_name'] ?? ($edit_dni_row['name'] ?? '')); ?>">
						<?php if ($editing_dni_network) { ?>
							<input type="hidden" name="editing_dni_network" value="1">
							<input type="hidden" name="editing_dni_network_id" value="<?php echo $e($edit_dni_row['id'] ?? ''); ?>">
						<?php } ?>
						<div class="row g-2 align-items-end">
							<div class="col-md-4">
								<label class="form-label" for="dni_network"><?php echo $editing_dni_network ? 'Network' : 'Add a network'; ?></label>
								<select name="dni_network" id="dni_network" class="form-select" required>
									<option value="">Select network</option>
									<?php foreach ($dniNetworks as $dninetwork) { ?>
										<option value="<?php echo $e($dninetwork['networkId']); ?>" data-type="<?php echo $e($dninetwork['networkType']); ?>"<?php echo $dniSelected !== '' && $dniSelected == $dninetwork['networkId'] ? ' selected' : ''; ?>><?php echo $e($dninetwork['name'] . ' (' . $dninetwork['networkType'] . ')'); ?></option>
									<?php } ?>
								</select>
							</div>
							<div class="col-md" id="dni_api_key_input_group">
								<label class="form-label" for="dni_network_api_key">API key</label>
								<input type="text" name="dni_network_api_key" id="dni_network_api_key" class="form-control" required autocomplete="off" spellcheck="false" value="<?php echo $e($_POST['dni_network_api_key'] ?? ($edit_dni_row['apiKey'] ?? '')); ?>">
							</div>
							<div class="col-md-2" id="dni_affiliate_id_input_group"<?php echo $dniType === 'Cake' ? '' : ' hidden'; ?>>
								<label class="form-label" for="dni_network_affiliate_id">Affiliate ID</label>
								<input type="text" name="dni_network_affiliate_id" id="dni_network_affiliate_id" class="form-control" value="<?php echo $e($_POST['dni_network_affiliate_id'] ?? ($dniType === 'Cake' ? ($edit_dni_row['affiliateId'] ?? '') : 'null')); ?>">
							</div>
							<div class="col-md-auto">
								<div class="p202-toolbar">
									<?php if ($editing_dni_network) { ?>
										<a class="btn btn-secondary" href="<?php echo $e($self . '#dni'); ?>">Cancel</a>
									<?php } ?>
									<button class="btn btn-primary" type="submit"><?php echo $editing_dni_network ? 'Save changes' : 'Add network'; ?></button>
								</div>
							</div>
						</div>
						<div class="form-text">HasOffers networks need only the API key; Cake networks also ask for your affiliate ID.</div>
					</form>
				<?php } ?>
			</div>
		</section>
	</div>

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
	<div class="col-12 col-lg-6">
		<section class="p202-panel h-100" id="lpo">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Landing Page Optimizer</h2>
				<?php if ($lpo_connected) { ?>
					<span class="p202-pill p202-pill--good">Connected</span>
				<?php } elseif (!$lpo_schema_ready) { ?>
					<span class="p202-pill p202-pill--warn">Upgrade needed</span>
				<?php } elseif ($lpo_needs_subscription) { ?>
					<span class="p202-pill p202-pill--warn">Subscription required</span>
				<?php } elseif (isset($error['lpo'])) { ?>
					<span class="p202-pill p202-pill--bad">Action needed</span>
				<?php } else { ?>
					<span class="p202-pill">Not connected</span>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<p class="text-secondary">Run hosted A/B experiments on your landing pages. Connecting registers a signed conversion webhook; nothing else changes on this install.</p>
				<?php if (isset($error['lpo'])) {
					echo p202_flash('bad', $error['lpo']);
				} ?>
				<?php if ($lpo_connected) { ?>
					<?php echo $endpoint('Site key', $lpo_site_key); ?>
					<div class="p202-toolbar">
						<a href="<?php echo $e($lpo_saas_base); ?>/api/customers/experiments" target="_blank" rel="noopener" class="btn btn-secondary">Manage experiments <i class="bi bi-box-arrow-up-right"></i></a>
						<form method="post" action="<?php echo $e($self . '#lpo'); ?>" data-p202-confirm="Disconnect the Landing Page Optimizer? The pairing webhook will be removed.">
							<?php echo p202_account_token_field(); ?>
							<input type="hidden" name="lpo_action" value="disconnect">
							<button class="btn btn-outline-danger" type="submit">Disconnect…</button>
						</form>
					</div>
					<?php if (array_key_exists('lpo_ctx_kw', $user_row)) { ?>
						<details class="p202-disclosure mt-3" data-p202-remember="account-lpo-privacy">
							<summary>Advanced <span class="p202-disclosure__hint">keyword privacy</span></summary>
							<div class="p202-disclosure__body">
								<form method="post" action="<?php echo $e($self . '#lpo'); ?>">
									<?php echo p202_account_token_field(); ?>
									<input type="hidden" name="lpo_ctx_kw_save" value="1">
									<div class="form-check mb-2">
										<input class="form-check-input" type="checkbox" name="lpo_ctx_kw" value="1" id="lpo_ctx_kw"<?php if ((string) ($user_row['lpo_ctx_kw'] ?? '1') !== '0') echo ' checked'; ?>>
										<label class="form-check-label" for="lpo_ctx_kw">Include keyword text in optimizer context tokens</label>
										<div class="form-text">On by default. Keywords ride the signed t202ctx token on rotator-to-landing-page redirects so experiments can segment by search term; turn it off to keep search terms out of tokens.</div>
									</div>
									<button class="btn btn-secondary btn-sm" type="submit">Save</button>
								</form>
							</div>
						</details>
					<?php } ?>
					<p class="form-text mb-0 mt-3">Bridge v<?php echo $e(\Prosper202\Bridge\EventBridge::BRIDGE_VERSION); ?> · <?php echo $e(implode(', ', $lpo_capabilities['events'])); ?>, wildcard subscribe, remote config, v3 API, context tokens, dimensions sync</p>
				<?php } elseif (!$lpo_schema_ready) { ?>
					<p class="mb-0">Run the Prosper202 upgrade to enable this integration.</p>
				<?php } elseif ($lpo_needs_subscription) { ?>
					<?php echo p202_flash('warn', $lpo_sub_retry
						? 'We still don’t see an active Landing Page Optimizer subscription for this account. If you just subscribed, give it a minute, then connect again.'
						: 'Landing Page Optimizer runs on a Landing Page Optimizer plan, and this account doesn’t have one yet. Start a subscription, then connect this install.'); ?>
					<div class="p202-toolbar">
						<a class="btn btn-secondary" href="<?php echo $e($lpo_saas_base); ?>/api/customers/experiments" target="_blank" rel="noopener">Get Landing Page Optimizer <i class="bi bi-box-arrow-up-right"></i></a>
						<form method="post" action="<?php echo $e($self . '#lpo'); ?>">
							<?php echo p202_account_token_field(); ?>
							<input type="hidden" name="lpo_action" value="connect">
							<input type="hidden" name="lpo_retry" value="1">
							<button class="btn btn-secondary" type="submit">I’ve subscribed, connect</button>
						</form>
					</div>
				<?php } elseif ($lpo_has_api_key) { ?>
					<form method="post" action="<?php echo $e($self . '#lpo'); ?>">
						<?php echo p202_account_token_field(); ?>
						<input type="hidden" name="lpo_action" value="connect">
						<button class="btn btn-secondary" type="submit">Connect</button>
					</form>
				<?php } else { ?>
					<div class="p202-empty">
						<i class="bi bi-key p202-empty__icon"></i>
						<strong class="p202-empty__title">A customer API key comes first</strong>
						<div>Get your Prosper202 customer API key, save it in Personal settings, then connect here.</div>
						<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="<?php echo $e($lpo_saas_base); ?>/api/customers/login?redirect=get-api">Get your API key</a></div>
					</div>
				<?php } ?>
			</div>
		</section>
	</div>

	<?php foreach ($keyIntegrations as $anchor => $integration) {
		$stored = trim((string)($user_row[$integration['field']] ?? ''));
		$hasError = isset($error[$integration['error']]);
		$fieldErrors = $hasError ? [$integration['field'] => (string)$error[$integration['error']]] : [];
		$isClickbank = $anchor === 'clickbank';
		?>
		<div class="col-12 col-lg-6">
			<section class="p202-panel h-100" id="<?php echo $e($anchor); ?>">
				<div class="p202-panel__head">
					<img src="<?php echo $e($base . '202-img/icons/integrations/' . $integration['icon']); ?>" width="22" height="22" alt="" class="rounded-1">
					<h2 class="p202-panel__title"><?php echo $e($integration['title']); ?></h2>
					<?php echo $integration['help'] !== '' ? $helpLink($integration['help']) : ''; ?>
					<?php if ($isClickbank && !$cb_crypto_ok) { ?>
						<span class="p202-pill p202-pill--warn">Unavailable</span>
					<?php } elseif ($stored === '') { ?>
						<span class="p202-pill">Not connected</span>
					<?php } elseif ($isClickbank) { ?>
						<span data-cb-status><?php echo apiint_cb_pill((bool)$cb_verified); ?></span>
					<?php } else { ?>
						<span class="p202-pill p202-pill--good">Connected</span>
					<?php } ?>
				</div>
				<div class="p202-panel__body">
					<p class="text-secondary"><?php echo $e($integration['desc']); ?><?php echo $integration['extra'] !== '' ? ' ' . $integration['extra'] . '.' : ''; ?></p>
					<?php if ($isClickbank && !$cb_crypto_ok) { ?>
						<?php echo p202_flash('bad', 'The mcrypt (or OpenSSL) PHP extension is required for this integration. Install it, or ask your hosting provider for assistance.'); ?>
					<?php } else { ?>
						<?php if ($integration['endpoint'] !== null) {
							echo $endpoint($integration['endpoint'][0], $integration['endpoint'][1]);
						} ?>
						<details class="p202-disclosure"<?php echo $hasError ? ' open' : ''; ?>>
							<summary><?php echo $stored === '' ? 'Connect' : 'Update'; ?> <span class="p202-disclosure__hint"><?php echo $e($stored === '' ? 'paste your ' . $integration['label'] : $integration['label']); ?></span></summary>
							<div class="p202-disclosure__body">
								<form method="post" action="<?php echo $e($self . '#' . $anchor); ?>">
									<input type="hidden" name="<?php echo $e($integration['flag']); ?>" value="1">
									<?php echo p202_account_token_field(); ?>
									<label class="form-label" for="<?php echo $e($integration['field']); ?>"><?php echo $e($integration['label']); ?></label>
									<div class="input-group">
										<input type="text" class="form-control<?php echo p202_account_invalid($fieldErrors, $integration['field']); ?>" id="<?php echo $e($integration['field']); ?>" name="<?php echo $e($integration['field']); ?>" required autocomplete="off" spellcheck="false" value="<?php echo $e($fieldValue($integration['field'])); ?>">
										<button class="btn btn-secondary" type="submit">Save</button>
									</div>
									<?php echo p202_account_field_error($fieldErrors, $integration['field']); ?>
								</form>
								<?php if ($isClickbank && $stored !== '') { ?>
									<div class="p202-toolbar mt-2">
										<button type="button" class="btn btn-link btn-sm p-0" data-cb-check="<?php echo $e($self . '?cb_status=1'); ?>">Check verification again</button>
									</div>
								<?php } ?>
							</div>
						</details>
					<?php } ?>
				</div>
			</section>
		</div>
	<?php } ?>

	<div class="col-12 col-lg-6">
		<section class="p202-panel h-100" id="paykickstart">
			<div class="p202-panel__head">
				<img src="<?php echo $e($base . '202-img/icons/integrations/paykickstart.png'); ?>" width="22" height="22" alt="" class="rounded-1">
				<h2 class="p202-panel__title">PayKickstart</h2>
				<span class="p202-pill">No setup needed</span>
			</div>
			<div class="p202-panel__body">
				<p class="text-secondary">Update conversions from PayKickstart’s Affiliate IPN: paste this URL as your IPN URL in PayKickstart.</p>
				<?php echo $endpoint('IPN URL', $trackingBase . 'tracking202/static/paykickstart.php'); ?>
			</div>
		</section>
	</div>
</div>

<script>
	document.addEventListener('DOMContentLoaded', function () {
		var token = <?php echo json_encode((string)($_SESSION['token'] ?? '')); ?>;

		/* The DNI form: the chosen network decides its type and name (sent
		   as hidden fields, as before) and whether an affiliate ID is asked
		   for. HasOffers networks need none, and the server expects the
		   literal "null" for them. */
		var select = document.getElementById('dni_network');
		if (select) {
			var sync = function (initial) {
				var option = select.options[select.selectedIndex];
				var type = option ? (option.getAttribute('data-type') || '') : '';
				var affiliate = document.getElementById('dni_affiliate_id_input_group');
				var affiliateInput = document.getElementById('dni_network_affiliate_id');
				document.getElementById('dni_network_type').value = type;
				document.getElementById('dni_network_name').value = option && option.value ? option.text : '';
				if (type === 'Cake') {
					affiliate.hidden = false;
					if (!initial && affiliateInput.value === 'null') { affiliateInput.value = ''; }
					affiliateInput.required = true;
				} else {
					affiliate.hidden = true;
					affiliateInput.required = false;
					affiliateInput.value = 'null';
				}
			};
			select.addEventListener('change', function () { sync(false); });
			sync(true);
		}

		/* ClickBank: ask the server whether the key has verified yet. */
		document.addEventListener('click', function (event) {
			var button = event.target.closest ? event.target.closest('[data-cb-check]') : null;
			if (!button) { return; }
			event.preventDefault();
			var target = document.querySelector('[data-cb-status]');
			button.disabled = true;
			fetch(button.getAttribute('data-cb-check'), { credentials: 'same-origin' })
				.then(function (response) { return response.text(); })
				.then(function (html) { if (target) { target.innerHTML = html; } })
				.catch(function () { if (target) { target.innerHTML = '<span class="p202-pill p202-pill--bad">Could not check</span>'; } })
				.then(function () { button.disabled = false; });
		});

		/* Networks still importing their offers: poll their progress. */
		var processing = <?php echo $dniProcessingJson; ?>;
		if (!processing.networks || processing.networks.length === 0) { return; }
		var base = <?php echo json_encode($base . '202-account/ajax/dni.php'); ?>;
		var poll = function () {
			fetch(base + '?getProgress=true', { method: 'POST', credentials: 'same-origin', body: JSON.stringify(processing) })
				.then(function (response) { return response.json(); })
				.then(function (json) {
					(json && json.data ? json.data : []).forEach(function (item) {
						var bar = document.querySelector('[data-dni-progress="' + item.id + '"]');
						if (bar) {
							bar.style.width = item.progress + '%';
							bar.textContent = item.progress + '%';
							bar.parentNode.setAttribute('aria-valuenow', item.progress);
						}
						if (String(item.progress) === '100') {
							var body = new URLSearchParams();
							body.set('token', token);
							fetch(base + '?updateStatus=true&dni=' + encodeURIComponent(item.id), { method: 'POST', credentials: 'same-origin', body: body })
								.then(function (response) {
									if (response.ok) {
										var row = document.getElementById('network-' + item.id);
										if (row) { row.remove(); }
									}
								});
						}
					});
				})
				.catch(function () {});
		};
		poll();
		window.setInterval(poll, 3000);
	});
</script>
<?php template_bottom();
