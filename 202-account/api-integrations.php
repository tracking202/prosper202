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

use Prosper202\PaymentNotification\JVZoo\JVZooKey;
use Prosper202\PaymentNotification\JVZoo\JVZooRole;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartKeys;
use Prosper202\PaymentNotification\Shopify\ShopifyWebhookSecret;
use Prosper202\Setup\SavedSecret;

include_once(str_repeat("../", 1) . '202-config/connect.php');
include_once(str_repeat("../", 1) . '202-config/clickserver_api_management.php');
require_once __DIR__ . '/../202-config/functions-account-ui.php';
require_once __DIR__ . '/../202-config/Messaging/Analytics.class.php';

AUTH::require_user();

if (!$userObj->hasPermission("access_to_api_integrations")) {
	header('location: ' . get_absolute_url() . '202-account/');
	exit;
}

// Initialize variables to prevent undefined variable warnings
$error = [];
$html = [];
$mysql = [];

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
 * Update user preference in database. Throws when the write fails: most
 * callers wrote several in a row and read none of the answers.
 */
function updateUserPreference($field_name, $value, $user_id, $db): void
{
	$escaped_value = $db->real_escape_string((string)$value);

	$sql = "UPDATE `202_users_pref` SET `{$field_name}` = '{$escaped_value}'"
        . " WHERE `user_id` = '" . (int) $user_id . "'";
	if (_mysqli_query($db, $sql) === false) {
		throw new RuntimeException('The ' . $field_name . ' setting could not be saved.');
	}
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
 * Process API key update with validation and notification: 'saved', 'kept'
 * (the field was left empty and a value is saved), or null when refused.
 */
function processApiKeyUpdate($config, &$error, &$change_flag, $user_row, $slack, $username, $db): ?string
{
	$post_key = $config['post_key'];
	$field_name = $config['field_name'];
	$error_key = $config['error_key'];
	$error_message = $config['error_message'];
	$slack_event = $config['slack_event'];
	$user_id = $_SESSION['user_id'];
	$posted = $_POST[$post_key] ?? null;
	$old_value = (string)($user_row[$field_name] ?? '');

	// The saved value is never shown, so an empty field keeps it.
	if (SavedSecret::keeps($posted) && SavedSecret::isSaved($old_value)) {
		return 'kept';
	}
	if (!validateRequired(SavedSecret::keeps($posted) ? '' : $posted, $error_key, $error_message, $error)) {
		return null;
	}

	if (!$error) {
		$new_value = (string)$posted;

		// PUT /users/{id}/preferences asks the same rule of a Slack webhook.
		if ($field_name === 'user_slack_incoming_webhook') {
			try {
				$new_value = Prosper202\Notifications\SlackWebhook::accepted($new_value);
			} catch (InvalidArgumentException $refused) {
				$error[$error_key] = $refused->getMessage();
				return null;
			}
		}

		if ($new_value !== $old_value) {
			try {
				updateUserPreference($field_name, $new_value, $user_id, $db);
			} catch (RuntimeException $e) {
				error_log('api-integrations.php: ' . $e->getMessage());
				$error[$error_key] = 'This could not be saved just now; the old value is still in place. Try again.';
				return null;
			}

			// Special handling for cb_key verification reset
			if ($field_name === 'cb_key') {
				try {
					updateUserPreference('cb_verified', '0', $user_id, $db);
				} catch (RuntimeException $e) {
					error_log('api-integrations.php: ' . $e->getMessage());
					$error[$error_key] = 'The key is saved, but it still shows as verified; save it again.';
					return null;
				}
			}
		}

		$change_flag = true;
		sendSlackNotification($slack, $slack_event, $username, $old_value, $new_value);
		return 'saved';
	}

	return null;
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

/** A DNI network's id as the page writes it, or null: `12abc` named network 12. */
function apiint_dni_id(mixed $value): ?int
{
	return is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1 && (int)$value <= 4294967295 ? (int)$value : null;
}

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
$user_sql = "SELECT 2u.user_name as username, 2u.install_hash, 2u.p202_customer_api_key FROM 202_users AS 2u WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = _mysqli_query($db, $user_sql);
// A failed query must stop here, not read as an account with no settings:
// this row supplies the install hash the DNI network lookup below sends
// (CLAUDE.md #1). record_mysql_error() logs and exits.
if (!$user_results) {
	record_mysql_error($user_sql);
}
$user_row = p202FetchRow($user_results, $user_sql) ?? [];
$username = $user_row['username'] ?? '';
$editing_dni_network = false;
$dniNetworks = dniNetworkList(getAllDniNetworks($user_row['install_hash']));
// The network list comes from the DNI service; when it cannot be reached the
// page says so instead of offering an empty select.
$dniNetworksAvailable = is_array($dniNetworks);
if (!$dniNetworksAvailable) {
	$dniNetworks = [];
}

$slack = Slack::forAccount($db, (int) ($_SESSION['user_id'] ?? 0)) ?? false;

if (isset($_GET['cb_status']) && $_GET['cb_status'] == 1) {
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$user_sql = "SELECT cb_verified
             FROM 202_users_pref
             WHERE user_id='" . $mysql['user_id'] . "'";
	$cbStatus = _mysqli_query($db, $user_sql);
	if ($cbStatus === false) {
		http_response_code(503);
		echo '<span class="p202-pill p202-pill--bad">Could not check</span>';
		die();
	}
	$user_row = $cbStatus->fetch_assoc();
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
$user_result = _mysqli_query($db, $user_sql);
if (!$user_result) {
	record_mysql_error($user_sql);
}
$user_row = p202FetchRow($user_result, $user_sql) ?? [];

$cb_verified = $user_row['cb_verified'];

// The retired GET address for removing a DNI network: never a write.
if (isset($_GET['delete_dni_network']) && !empty($_GET['delete_dni_network'])) {
	p202_account_flash('warn', 'Removing a network now asks first. Use remove beside the network below.');
	p202_account_redirect('202-account/api-integrations.php#dni');
}

// A PayKickstart key's Remove form names the key by an HMAC of the whole key
// under this session's secret, which never leaves the server
// (PayKickstartKeys::removalToken()).
if (!is_string($_SESSION['paykickstart_key_secret'] ?? null) || strlen($_SESSION['paykickstart_key_secret']) !== 64) {
	withWritableSession(static function (): void {
		$_SESSION['paykickstart_key_secret'] = bin2hex(random_bytes(32));
	});
}
$pkSecret = $_SESSION['paykickstart_key_secret'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	// validate token
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/api-integrations.php');
	}

	// Postback authentication (Prosper202\Conversion\PostbackAuth): the mode,
	// and rotating the token or the signing secret.
	$pbAction = $_POST['postback_auth_action'] ?? null;
	if (in_array($pbAction, ['mode', 'rotate'], true)) {
		$pbStore = new \Prosper202\Conversion\PostbackAuth\PostbackAuthStore(new \Prosper202\Database\Connection($db));
		$pbUser = (int) $_SESSION['user_id'];
		$pbHours = ['0' => 0, '24' => 24, '168' => 168, '720' => 720];
		$pbGrace = $_POST['grace_hours'] ?? null;
		$pbMode = is_string($_POST['mode'] ?? null) ? \Prosper202\Conversion\PostbackAuth\PostbackAuthMode::tryFrom($_POST['mode']) : null;
		$pbCredential = is_string($_POST['credential'] ?? null)
			? \Prosper202\Conversion\PostbackAuth\PostbackCredential::tryFrom($_POST['credential']) : null;
		if ($pbAction === 'mode' && $pbMode === null) {
			p202_account_flash('bad', 'Choose what your postbacks and pixels must carry.');
			p202_account_redirect('202-account/api-integrations.php#postback-auth');
		}
		if ($pbAction === 'rotate' && ($pbCredential === null || !(is_string($pbGrace) && isset($pbHours[$pbGrace])))) {
			p202_account_flash('bad', 'Choose how long the old one keeps working: not at all, a day, a week or 30 days.');
			p202_account_redirect('202-account/api-integrations.php#postback-auth');
		}
		try {
			$pbSaved = null;
			if ($pbAction === 'rotate' && $pbCredential !== null && is_string($pbGrace)) {
				$pbSaved = $pbStore->rotate($pbUser, $pbCredential, $pbHours[$pbGrace] * 3600, time());
				$pbWhat = $pbCredential === \Prosper202\Conversion\PostbackAuth\PostbackCredential::TOKEN ? 'postback token' : 'signing secret';
				// An old one that could not be read was not kept, whatever the grace.
				$pbKept = $pbCredential === \Prosper202\Conversion\PostbackAuth\PostbackCredential::TOKEN
					? $pbSaved->previousTokenValidUntil(time()) : $pbSaved->previousSecretValidUntil(time());
				p202_account_flash('ok', $pbKept === null
					? 'Your ' . $pbWhat . ' was replaced; the old one stopped working.'
						. ($pbWhat === 'postback token' ? ' Copy your postback URLs and pixels again from Setup › Postback / Pixel.' : ' Give the new one to everything that signs.')
					: 'Your ' . $pbWhat . ' was replaced; the old one keeps working for ' . ($pbHours[$pbGrace] / 24)
						. ' day(s) while you move to the new one.');
			} elseif ($pbMode !== null) {
				// The mode and its credentials are saved together or not at all.
				$pbSaved = $pbStore->setMode($pbUser, $pbMode, time());
				p202_account_flash('ok', match ($pbMode) {
					\Prosper202\Conversion\PostbackAuth\PostbackAuthMode::OFF => 'Every postback and pixel is accepted again, with or without credentials.',
					\Prosper202\Conversion\PostbackAuth\PostbackAuthMode::TOKEN => 'Postbacks and pixels without your token or a signature are now refused.',
					\Prosper202\Conversion\PostbackAuth\PostbackAuthMode::SIGNED => 'Only signed postbacks are accepted now: the token alone is refused, and so is every pixel.',
				});
			}
			if ($pbSaved?->recoveryNotice() !== null) {
				p202_account_flash('bad', (string) $pbSaved->recoveryNotice());
			}
		} catch (Throwable $pbError) {
			error_log('api-integrations.php: postback authentication: ' . $pbError->getMessage());
			p202_account_flash('bad', 'This could not be saved just now; nothing changed. Try again.');
		}
		p202_account_redirect('202-account/api-integrations.php#postback-auth');
	}

	// The four key cards: one secret each; a field left empty keeps the saved one.
	$keyCards = [
		'change_cb_key' => ['cb_key', 'cb_key',
			'Clickbank Secret Key can\'t be empty!', 'ClickBank secret key', 'clickbank'],
		'change_user_slack_incoming_webhook' => ['user_slack_incoming_webhook', 'user_slack_incoming_webhook',
			'Slack Incoming Webhook URL can\'t be empty!', 'Slack incoming webhook URL', 'slack'],
		'change_zaxaa_api_signature' => ['zaxaa_api_signature', 'zaxaa_api_signature_error',
			'Zaxaa API signature can\'t be empty!', 'Zaxaa API signature', 'zaxaa'],
		'change_ipqs_api_key' => ['ipqs_api_key', 'ipqs_api_key_error',
			'The IPQualityScore API Key can\'t be empty!', 'IPQualityScore API key', 'ipqs'],
	];
	foreach ($keyCards as $flag => [$keyField, $keyError, $keyEmpty, $keyLabel, $keyAnchor]) {
		if (($_POST[$flag] ?? null) != '1') {
			continue;
		}
		$config = ['post_key' => $keyField, 'field_name' => $keyField, 'error_key' => $keyError,
			'error_message' => $keyEmpty, 'slack_event' => $keyField . '_updated'];
		$keyOutcome = processApiKeyUpdate($config, $error, $keyChanged, $user_row, $slack, $username, $db);
		if ($keyOutcome !== null && $keyOutcome !== 'kept') {
			Analytics::event('integration_configured', ['via' => 'ui', 'integration' => $keyAnchor], 'analytics');
		}
		if ($keyOutcome !== null) {
			p202_account_flash('ok', $keyOutcome === 'kept' ? 'Nothing changed: your saved ' . $keyLabel . ' is kept.'
				: 'Your ' . $keyLabel . ' was saved.');
			p202_account_redirect('202-account/api-integrations.php#' . $keyAnchor);
		}
	}

	// JVZoo's JVZIPN secret key, held to the rule its endpoint reads it by
	// (JVZooKey), so a key saved here is one a notification verifies with.
	if (isset($_POST['change_jvzoo_secret_key']) && $_POST['change_jvzoo_secret_key'] == '1') {
		$jvzooPosted = is_string($_POST['jvzoo_ipn_secret_key'] ?? null) ? $_POST['jvzoo_ipn_secret_key'] : '';
		$jvzooOld = (string) ($user_row[JVZooKey::COLUMN] ?? '');
		$jvzooKey = null;
		$jvzooKept = false;
		try {
			$jvzooKey = JVZooKey::parse($jvzooPosted);
			if ($jvzooKey === '' && JVZooKey::stored($jvzooOld) !== null) {
				// The saved key is never shown, so an empty field keeps it.
				$jvzooKept = true;
			} elseif ($jvzooKey === '') {
				$jvzooUnusable = JVZooKey::unreadable($jvzooOld) ? 'The saved key cannot be used. ' : '';
				throw new InvalidArgumentException($jvzooUnusable . 'Paste your JVZIPN secret key: JVZoo shows it under'
					. ' My Account › Account Details › Seller Settings.');
			} elseif ($jvzooKey !== $jvzooOld) {
				updateUserPreference(JVZooKey::COLUMN, $jvzooKey, $_SESSION['user_id'], $db);
			}
		} catch (InvalidArgumentException $jvzooRefused) {
			$error['jvzoo_secret_key_error'] = $jvzooRefused->getMessage();
			$jvzooKey = null;
		} catch (RuntimeException $e) {
			error_log('api-integrations.php: ' . $e->getMessage());
			$error['jvzoo_secret_key_error'] = 'This could not be saved just now; the old value is still in place.'
				. ' Try again.';
			$jvzooKey = null;
		}
		if ($jvzooKept) {
			p202_account_flash('ok', 'Nothing changed: your saved JVZoo secret key is kept.');
			p202_account_redirect('202-account/api-integrations.php#jvzoo');
		}
		if ($jvzooKey !== null) {
			sendSlackNotification($slack, 'jvzoo_secret_key_updated', $username, $jvzooOld, $jvzooKey);
			Analytics::event('integration_configured', ['via' => 'ui', 'integration' => 'jvzoo'], 'analytics');
			p202_account_flash('ok', 'Your JVZoo secret key was saved.');
			p202_account_redirect('202-account/api-integrations.php#jvzoo');
		}
	}

	// How the 1.9.56 JVZoo URL (no ?role=) reads this account's notifications (JVZooRole::ROLELESS_COLUMN).
	if (isset($_POST['change_jvzoo_roleless_role']) && $_POST['change_jvzoo_roleless_role'] == '1') {
		$jvzooRolelessRole = JVZooRole::fromQuery($_POST['jvzoo_roleless_role'] ?? null);
		if ($jvzooRolelessRole === null) {
			p202_account_flash('bad', 'Choose affiliate or vendor for the 1.9.56 JVZIPN URL.');
			p202_account_redirect('202-account/api-integrations.php#jvzoo');
		}
		try {
			updateUserPreference(JVZooRole::ROLELESS_COLUMN, $jvzooRolelessRole->value, $_SESSION['user_id'], $db);
		} catch (RuntimeException $e) {
			error_log('api-integrations.php: ' . $e->getMessage());
			p202_account_flash('bad', 'This could not be saved just now; the old choice is still in place. Try again.');
			p202_account_redirect('202-account/api-integrations.php#jvzoo');
		}
		p202_account_flash('ok', 'Saved: the 1.9.56 JVZIPN URL reads your notifications as '
			. ($jvzooRolelessRole === JVZooRole::VENDOR ? 'a vendor\'s.' : 'an affiliate\'s.'));
		p202_account_redirect('202-account/api-integrations.php#jvzoo');
	}

	// Shopify's webhook secret, held to the rule its endpoint reads it by
	// (ShopifyWebhookSecret), so a secret saved here is one a webhook verifies with.
	if (isset($_POST['change_shopify_webhook_secret']) && $_POST['change_shopify_webhook_secret'] == '1') {
		$shopifyPosted = $_POST[ShopifyWebhookSecret::COLUMN] ?? '';
		$shopifyOld = (string) ($user_row[ShopifyWebhookSecret::COLUMN] ?? '');
		$shopifySecret = null;
		$shopifyKept = false;
		try {
			if (!is_string($shopifyPosted)) {
				throw new InvalidArgumentException('The secret arrived as a list; paste it as one value.');
			}
			$shopifySecret = ShopifyWebhookSecret::parse($shopifyPosted);
			if ($shopifySecret === '' && ShopifyWebhookSecret::stored($shopifyOld) !== null) {
				// The saved secret is never shown, so an empty field keeps it.
				$shopifyKept = true;
			} elseif ($shopifySecret === '') {
				$shopifyUnusable = ShopifyWebhookSecret::unreadable($shopifyOld) ? 'The saved secret cannot be used. ' : '';
				throw new InvalidArgumentException($shopifyUnusable . 'Paste the secret your Shopify webhooks are signed'
					. ' with: Shopify shows it under Settings › Notifications › Webhooks.');
			} elseif ($shopifySecret !== $shopifyOld) {
				updateUserPreference(ShopifyWebhookSecret::COLUMN, $shopifySecret, $_SESSION['user_id'], $db);
			}
		} catch (InvalidArgumentException $shopifyRefused) {
			$error['shopify_webhook_secret_error'] = $shopifyRefused->getMessage();
			$shopifySecret = null;
		} catch (RuntimeException $e) {
			error_log('api-integrations.php: ' . $e->getMessage());
			$error['shopify_webhook_secret_error'] = 'This could not be saved just now; the old value is still in'
				. ' place. Try again.';
			$shopifySecret = null;
		}
		if ($shopifyKept) {
			p202_account_flash('ok', 'Nothing changed: your saved Shopify webhook secret is kept.');
			p202_account_redirect('202-account/api-integrations.php#shopify');
		}
		if ($shopifySecret !== null) {
			sendSlackNotification($slack, 'shopify_webhook_secret_updated', $username, $shopifyOld, $shopifySecret);
			p202_account_flash('ok', 'Your Shopify webhook secret was saved.');
			p202_account_redirect('202-account/api-integrations.php#shopify');
		}
	}

	// PayKickstart IPN secret keys: one for each PayKickstart campaign, added
	// and removed one at a time, and never shown back (PayKickstartKeys).
	$pkAction = $_POST['paykickstart_key_action'] ?? null;
	if ($pkAction === 'add' || $pkAction === 'remove') {
		$pkColumn = $user_row[PayKickstartKeys::COLUMN] ?? null;
		$pkOld = PayKickstartKeys::stored(is_string($pkColumn) ? $pkColumn : null)['keys'];
		$pkErrorKey = $pkAction === 'add' ? 'paykickstart' : 'paykickstart_remove';
		$pkPosted = static fn (string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
		$pkKeys = null;
		try {
			$pkKeys = $pkAction === 'add'
				? PayKickstartKeys::added($pkOld, $pkPosted('paykickstart_ipn_secret_key'))
				: PayKickstartKeys::removed(
					$pkOld,
					$pkPosted('paykickstart_key_place'),
					$pkPosted('paykickstart_key_token'),
					$pkSecret
				);
			updateUserPreference(PayKickstartKeys::COLUMN, PayKickstartKeys::serialize($pkKeys), $_SESSION['user_id'], $db);
		} catch (InvalidArgumentException $pkRefused) {
			$error[$pkErrorKey] = $pkRefused->getMessage();
			$pkKeys = null;
		} catch (RuntimeException $e) {
			error_log('api-integrations.php: ' . $e->getMessage());
			$error[$pkErrorKey] = 'This could not be saved just now; the stored keys are as they were. Try again.';
			$pkKeys = null;
		}
		if ($pkKeys !== null) {
			$pkBefore = PayKickstartKeys::serialize($pkOld);
			$pkAfter = PayKickstartKeys::serialize($pkKeys);
			sendSlackNotification($slack, 'paykickstart_ipn_keys_updated', $username, $pkBefore, $pkAfter);
			if ($pkAction === 'add') {
				Analytics::event('integration_configured', ['via' => 'ui', 'integration' => 'paykickstart'], 'analytics');
			}
			$pkCount = count($pkKeys) === 1 ? '1 key is stored' : count($pkKeys) . ' keys are stored';
			$pkDone = $pkAction === 'add' ? 'Your PayKickstart IPN secret key was saved' : 'The key was removed';
			p202_account_flash('ok', $pkDone . '; ' . $pkCount . '.');
			p202_account_redirect('202-account/api-integrations.php#paykickstart');
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
				$lpo_install_url = rtrim(\Prosper202\Click\TrackingBaseUrl::forRequest($_SERVER), '/');
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

				// Milestone event after pairComplete() succeeded and pairing state
				// is persisted. Analytics tier → self-gates on consent; Analytics
				// swallows its own failures — never blocks the redirect.
				require_once dirname(__DIR__) . '/202-config/Messaging/Analytics.class.php';
				Analytics::event('lpo_paired', ['via' => 'ui'], 'analytics');

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
			try {
				updateUserPreference('lpo_ctx_kw', $lpo_ctx_kw_new, $_SESSION['user_id'], $db);
			} catch (RuntimeException $e) {
				error_log('api-integrations.php: ' . $e->getMessage());
				p202_account_flash('bad', 'The context token preference could not be saved; try again.');
				p202_account_redirect('202-account/api-integrations.php#lpo');
			}
			lpo_ctx_pref_cache_bust($_SESSION['user_id']);
			$user_row['lpo_ctx_kw'] = $lpo_ctx_kw_new;
		}
		p202_account_flash('ok', 'Context token preference saved.');
		p202_account_redirect('202-account/api-integrations.php#lpo');
	}

	if (isset($_POST['delete_dni_network'])) {
		$deleteDniId = (int)$_POST['delete_dni_network'];
		$mysql['deleteDniNetworkId'] = $db->real_escape_string((string)$deleteDniId);
		$dniDeleted = _mysqli_query($db, "DELETE FROM 202_dni_networks WHERE id = '" . $mysql['deleteDniNetworkId'] . "' AND user_id = '" . $mysql['user_id'] . "'");
		if ($dniDeleted === false) {
			p202_account_flash('bad', 'The network could not be removed; try again.');
		} elseif ($db->affected_rows > 0) {
			// Only the network this user owned: the classic query retired
			// the affiliate network of whatever id it was handed.
			$sql = "UPDATE 202_aff_networks SET aff_network_deleted = '1', aff_network_time = '" . time() . "' WHERE dni_network_id = '" . $mysql['deleteDniNetworkId'] . "' AND user_id = '" . $mysql['user_id'] . "'";
			if (_mysqli_query($db, $sql)) {
				p202_account_flash('ok', 'The network is removed. Its offers stay in your campaigns; they stop updating.');
			} else {
				// The integration is gone; its category could not be retired.
				error_log('api-integrations.php: the DNI category was not retired: ' . $db->error);
				p202_account_flash('bad', 'The network is removed, but its campaign category could not be retired. If it is still listed under Setup › Campaign Categories, remove it there.');
			}
		} else {
			p202_account_flash('warn', 'That network was not found; nothing was removed.');
		}
		p202_account_redirect('202-account/api-integrations.php#dni');
	}

	if (isset($_POST['dni_network'])) {
		// An edit may leave the key empty to keep the saved one, which the page never shows.
		$dniEditing = isset($_POST['editing_dni_network']);
		$dniEditId = apiint_dni_id($_POST['editing_dni_network_id'] ?? null);
		$dniApiKey = (string)($_POST['dni_network_api_key'] ?? '');
		$dniRequired = array_merge(['dni_network', 'dni_network_type', 'dni_network_name', 'dni_network_affiliate_id'], $dniEditing ? [] : ['dni_network_api_key']);
		$dniMissing = array_filter($dniRequired, static fn (string $field): bool => trim((string)($_POST[$field] ?? '')) === '');
		if ($dniMissing) {
			$error['dni_network'] = 'Make sure all fields are selected and filled out!';
		} elseif ($dniEditing && $dniEditId === null) {
			$error['dni_network'] = 'That network was not found; nothing was changed.';
		} elseif ($dniEditId !== null && $dniEditing && trim($dniApiKey) === '') {
			[$dniApiKey, $dniRefused] = \Prosper202\Setup\DniNetworks::keptKey($db, (int)$_SESSION['user_id'], $dniEditId, (string)$_POST['dni_network']);
			if ($dniRefused !== null) {
				$error['dni_network'] = $dniRefused;
			}
		}
		if (!isset($error['dni_network'])) {
			$mysql['dniNetworkId'] = $db->real_escape_string((string)$_POST['dni_network']);
			$mysql['dniNetworkType'] = $db->real_escape_string((string)$_POST['dni_network_type']);
			$dniNetworkName = explode(" (", (string) $_POST['dni_network_name'], 2);
			$mysql['dniNetworkName'] = $db->real_escape_string($dniNetworkName[0]);
			$mysql['dniAffiliateId'] = $db->real_escape_string((string)$_POST['dni_network_affiliate_id']);
			$mysql['dniApikey'] = $db->real_escape_string($dniApiKey);
			$dniAuth = authDniNetworks($user_row['install_hash'], $_POST['dni_network'], $dniApiKey, $_POST['dni_network_affiliate_id']);

			if (empty($dniAuth['auth'])) {
				$error['dni_network_auth'] = 'Can\'t authenticate with provided credentials. Try again!';
			} else {
				if (!$dniEditing) {
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
							if (!_mysqli_query($db, $sql)) {
								throw new RuntimeException('the network: ' . $db->error);
							}
							$categorySql = "INSERT INTO 202_aff_networks SET dni_network_id = '" . (int) $db->insert_id . "', user_id = '" . $mysql['user_id'] . "', aff_network_name = '" . $mysql['dniNetworkName'] . " (DNI)" . "', aff_network_time = '" . time() . "'";
							if (!_mysqli_query($db, $categorySql)) {
								throw new RuntimeException('its category: ' . $db->error);
							}
						});
						$dniSaved = true;
					} catch (Throwable $failed) {
						error_log('api-integrations.php: a DNI network was not saved: ' . $failed->getMessage());
					}
					if ($dniSaved) {
						p202_account_flash('ok', $dniNetworkName[0] . ' network configured. API processing can take up to 5 minutes.');
						p202_account_redirect('202-account/api-integrations.php#dni');
					}
					$error['dni_network'] = 'The network could not be saved, and nothing was added; try again.';
				} elseif ($dniEditId !== null) {
					$mysql['editing_dni_network_id'] = (string)$dniEditId;
					$dniAffiliateSet = $_POST['dni_network_type'] == 'Cake' ? ", affiliateId = '" . $mysql['dniAffiliateId'] . "'" : '';
					// One statement, so its WHERE is read with the rest of it.
					$sql = "UPDATE 202_dni_networks SET networkId = '" . $mysql['dniNetworkId'] . "', name = '" . $mysql['dniNetworkName'] . "', type = '" . $mysql['dniNetworkType'] . "', apiKey = '" . $mysql['dniApikey'] . "', time = '" . time() . "'" . $dniAffiliateSet
						. " WHERE id = '" . $mysql['editing_dni_network_id'] . "' AND user_id = '" . $mysql['user_id'] . "'";

					// The edit names a network of this account's or changes
					// nothing: it flashed "updated" on a no-op for an id that is
					// not there. `time` always changes, so a row that exists is
					// always an affected row.
					$dniFound = true;
					$dniSaved = false;
					try {
						(new \Prosper202\Database\Connection($db))->transaction(static function () use ($db, $sql, $mysql, &$dniFound): void {
							if (!_mysqli_query($db, $sql)) {
								throw new RuntimeException('the network: ' . $db->error);
							}
							if ($db->affected_rows < 1) {
								$dniFound = false;
								return;
							}
							$categorySql = "UPDATE 202_aff_networks SET aff_network_name = '" . $mysql['dniNetworkName'] . " (DNI)" . "', aff_network_time = '" . time() . "' WHERE dni_network_id = '" . $mysql['editing_dni_network_id'] . "' AND user_id = '" . $mysql['user_id'] . "'";
							if (!_mysqli_query($db, $categorySql)) {
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

// The signed-in account's networks, and the one being edited, without their
// keys: the list read user 1's, and put every key into the page.
$edit_dni_row = null;
$dniEditMissing = false;
try {
	if (isset($_GET['edit_dni_network']) && (string)$_GET['edit_dni_network'] !== '') {
		$dniEditGetId = apiint_dni_id($_GET['edit_dni_network']);
		$edit_dni_row = $dniEditGetId === null ? null : \Prosper202\Setup\DniNetworks::oneFor($db, (int)$_SESSION['user_id'], $dniEditGetId);
		$editing_dni_network = $edit_dni_row !== null;
		$dniEditMissing = $edit_dni_row === null;
	}
	$dniRows = \Prosper202\Setup\DniNetworks::listFor($db, (int)$_SESSION['user_id']);
} catch (\Prosper202\Database\Exceptions\QueryException $failed) {
	// Not "no networks": the page would offer to add ones that exist.
	record_mysql_error($db, 'api-integrations.php: the DNI networks', $failed->getMessage());
}

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$self = get_absolute_url() . '202-account/api-integrations.php';
$base = get_absolute_url();
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
// On the base the tracking links use, the saved scheme included.
$trackingBase = p202TrackingBaseForResponse();

$flashExtra = [];
if ($dniEditMissing) {
	$flashExtra[] = ['kind' => 'warn', 'text' => 'That DNI network is not one of yours, or it was removed.'];
}
if (isset($_GET['lpo']) && $_GET['lpo'] === 'connected') {
	$flashExtra[] = ['kind' => 'ok', 'text' => 'Landing Page Optimizer connected.'];
}
if (isset($_GET['lpo']) && $_GET['lpo'] === 'disconnected') {
	$flashExtra[] = ['kind' => 'ok', 'text' => 'Landing Page Optimizer disconnected.'];
}

/** The key-based integrations, which all work the same way: one secret, one Save. */
$keyIntegrations = [
	'ipqs' => [
		'title' => 'IPQualityScore', 'icon' => 'ipqs.png', 'help' => '',
		'desc' => p202_ipqs_card_desc(),
		'extra' => '<a href="https://202.redirexit.com/tracking202/redirect/dl.php?t202id=12608&amp;t202kw=" target="_blank" rel="noopener">Get a free API key</a>',
		'flag' => 'change_ipqs_api_key', 'field' => 'ipqs_api_key', 'label' => 'IPQS API key', 'error' => 'ipqs_api_key_error',
		'endpoint' => null,
	],
	'clickbank' => [
		'title' => 'ClickBank', 'icon' => 'clickbank.png', 'help' => 'clickbank',
		'desc' => 'Update conversions from ClickBank’s Instant Notifications: put tid=[[subid]] on your HopLink.',
		'extra' => '',
		'flag' => 'change_cb_key', 'field' => 'cb_key', 'label' => 'ClickBank secret key', 'error' => 'cb_key',
		'endpoint' => ['INS URL', $trackingBase . 'tracking202/static/cb202.php'],
	],
	'zaxaa' => [
		'title' => 'Zaxaa', 'icon' => 'zaxaa.png', 'help' => 'zaxaa',
		'desc' => 'Update conversions from Zaxaa Payment Notification (ZPN): put custom=[[subid]] on your Zaxaa order link and paste the ZPN URL into the product’s Notification Handler URL. Use the https:// form of the ZPN URL.',
		'extra' => '',
		'flag' => 'change_zaxaa_api_signature', 'field' => 'zaxaa_api_signature', 'label' => 'Zaxaa API signature', 'error' => 'zaxaa_api_signature_error',
		'endpoint' => ['ZPN URL', $trackingBase . 'tracking202/static/zpn.php'],
	],
	'slack' => [
		'title' => 'Slack', 'icon' => 'slack.png', 'help' => 'slack',
		'desc' => 'Send Prosper202 notifications into a Slack channel through a Slack incoming webhook.',
		'extra' => '',
		'flag' => 'change_user_slack_incoming_webhook', 'field' => 'user_slack_incoming_webhook', 'label' => 'Slack incoming webhook URL', 'error' => 'user_slack_incoming_webhook',
		'endpoint' => null,
	],
];
$cb_crypto_ok = function_exists('openssl_decrypt');

// The account's postback token, minted on first view as every place that
// hands out a postback URL mints it.
$pbAuth = null;
try {
	$pbAuth = (new \Prosper202\Conversion\PostbackAuth\PostbackAuthStore(new \Prosper202\Database\Connection($db)))
		->ensureCredentials((int) $_SESSION['user_id'], time());
} catch (Throwable $pbReadError) {
	error_log('api-integrations.php: the postback token could not be read: ' . $pbReadError->getMessage());
}
// Who a stricter setting would refuse, from the last 7 days of the inbound log.
$pbReady = null;
try {
	$pbReady = (new \Prosper202\Conversion\PostbackAuth\PostbackAuthReadiness(new \Prosper202\Database\Connection($db)))
		->forAccount((int) $_SESSION['user_id'], time());
} catch (Throwable $pbReadyError) {
	error_log('api-integrations.php: postback readiness could not be read: ' . $pbReadyError->getMessage());
}
$ipqsSaved = SavedSecret::isSaved($user_row['ipqs_api_key'] ?? null);
$ipqsNote = p202_ipqs_card_note($db, (int) $_SESSION['user_id'], $ipqsSaved);

template_top('API Integrations');
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
		<section class="p202-panel" id="postback-auth">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Postback authentication</h2>
				<?php if ($pbAuth === null) { ?>
					<span class="p202-pill p202-pill--warn">Unreadable</span>
				<?php } elseif ($pbAuth->mode === \Prosper202\Conversion\PostbackAuth\PostbackAuthMode::OFF) { ?>
					<span class="p202-pill p202-pill--warn">Not required</span>
				<?php } elseif ($pbAuth->mode === \Prosper202\Conversion\PostbackAuth\PostbackAuthMode::TOKEN) { ?>
					<span class="p202-pill p202-pill--good">Token or signature</span>
				<?php } else { ?>
					<span class="p202-pill p202-pill--good">Signed only</span>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<?php if ($pbAuth === null) { ?>
					<p class="text-danger">Your postback settings could not be read just now. Reload the page; if it persists, see the server's error log.</p>
				<?php } else {
					if ($pbAuth->recoveryNotice() !== null) { ?>
					<div class="alert alert-warning" role="alert"><?php echo $e((string) $pbAuth->recoveryNotice()); ?></div>
					<?php }
					$pbNow = time();
					$pbModes = [
						'off' => ['Not required', 'Every postback and pixel is accepted, as before 1.9.80, with or without the token.'],
						'token' => ['Token or signature', 'A postback or pixel must carry the token (as the URLs in Setup › Postback / Pixel do) or a signature.'],
						'signed' => ['Signed postbacks only', 'Only a request signed with the signing secret is accepted; the token alone is refused. Pixels cannot sign, so every pixel is refused.'],
					];
					$pbGraceSelect = static function (string $id): string {
						return '<select class="form-select" id="' . $id . '" name="grace_hours">'
							. '<option value="168" selected>for 7 days</option><option value="24">for 1 day</option>'
							. '<option value="720">for 30 days</option><option value="0">not at all (it leaked)</option></select>';
					}; ?>
					<p class="text-secondary">Click ids count up, so anyone who can guess one could record or reverse a
						conversion with a bare postback URL. Your postback URLs and pixels carry this account's token as
						<code>p202_token</code>. A sender that can compute an HMAC can instead sign each request with
						<code>p202_ts</code> and <code>p202_sig</code>, keyed with the signing secret below, which no URL ever
						carries. Unless authentication is off, a wrong token or signature is refused.</p>
					<p class="text-secondary">A pixel runs in the visitor's browser, so its token can be read on the
						advertiser's page: it stops guessing, not someone who saw the page. A pixel cannot sign, so
						<strong>Signed postbacks only</strong> refuses every pixel.</p>
					<?php
					$pbOff = $pbAuth->mode === \Prosper202\Conversion\PostbackAuth\PostbackAuthMode::OFF;
					$pbWithout = $pbReady['without_credentials'] ?? 0;
					$pbEndpoints = ['pb' => 'Postback (pb.php)', 'gpb' => 'Global postback (gpb.php)', 'px' => 'Pixel (px.php)',
						'gpx' => 'Global pixel (gpx.php)', 'upx' => 'Universal pixel (upx.php)'];
					$pbCame = ['none' => 'nothing', 'invalid' => 'a wrong token or signature', 'token' => 'the token', 'signed' => 'a signature'];
					?>
					<div class="mb-4" id="postback-auth-readiness">
						<p class="mb-1"><strong>Who would be refused</strong> <span class="text-secondary">(your postbacks and pixels of the last <?php echo (int) \Prosper202\Conversion\PostbackAuth\PostbackAuthReadiness::WINDOW_DAYS; ?> days)</span></p>
						<?php if ($pbReady === null) { ?>
							<p class="text-danger">This could not be checked just now, so it is not known who a stricter setting would refuse. Reload the page before changing the setting.</p>
						<?php } elseif ($pbReady['requests'] === 0) { ?>
							<p class="text-secondary" data-postback-ready="unknown">No postback or pixel has arrived in that time, so there is nothing to judge a stricter setting by.</p>
						<?php } elseif ($pbWithout === 0 && $pbReady['unread'] === 0) { ?>
							<p data-postback-ready="yes"><span class="p202-pill p202-pill--good">Safe to require the token</span>
								All <?php echo $e(number_format($pbReady['requests'])); ?> carried your token or a signature.</p>
						<?php } elseif ($pbWithout === 0) { ?>
							<p class="text-secondary" data-postback-ready="unknown">None is known to have come without your token, and for <?php echo $e(number_format($pbReady['unread'])); ?> of <?php echo $e(number_format($pbReady['requests'])); ?> what they carried was not read. Look again once newer ones have arrived.</p>
						<?php } else { ?>
							<p data-postback-ready="no"><span class="p202-pill p202-pill--warn"><?php echo $pbOff ? 'Not yet safe to require the token' : 'Being refused'; ?></span>
								<?php echo $e(number_format($pbWithout)); ?> of <?php echo $e(number_format($pbReady['requests'])); ?> came without your token or a signature that verifies.
								<?php echo $pbOff
									? 'Requiring the token would refuse them and record nothing. Give each sender below its URL from <a href="' . $e($base . 'tracking202/setup/get_postback.php') . '">Setup › Postback / Pixel</a> first.'
									: 'They are refused and nothing is recorded. Give each sender below its URL from <a href="' . $e($base . 'tracking202/setup/get_postback.php') . '">Setup › Postback / Pixel</a>; a refused request can be recorded from the <a href="' . $e($base . 'tracking202/setup/inbound_log.php?outcome=unauthenticated') . '">Inbound Log</a>.'; ?></p>
						<?php } ?>
						<?php if ($pbReady !== null && $pbReady['senders'] !== []) { ?>
							<div class="p202-table-wrap">
								<table class="table p202-table" id="postback-auth-senders">
									<thead><tr><th>Endpoint</th><th>Campaign</th><th>Carried</th><th class="num">Requests</th><th class="num">Conversions recorded</th><th>Last one</th></tr></thead>
									<tbody>
									<?php foreach ($pbReady['senders'] as $pbSender) { ?>
										<tr<?php echo in_array($pbSender['presented'], ['none', 'invalid'], true) ? ' class="table-warning"' : ''; ?>>
											<td><?php echo $e($pbEndpoints[$pbSender['endpoint']] ?? $pbSender['endpoint']); ?></td>
											<td><?php echo $pbSender['campaign_name'] === null
												? '<span class="text-secondary">' . ($pbSender['subids'] === [] ? 'not known' : 'not known; subid ' . $e(implode(', ', $pbSender['subids']))) . '</span>'
												: $e($pbSender['campaign_name']) . ($pbSender['network_name'] !== null ? ' <span class="text-secondary">(' . $e($pbSender['network_name']) . ')</span>' : ''); ?></td>
											<td><?php echo $e($pbCame[$pbSender['presented']] ?? $pbSender['presented']); ?></td>
											<td class="num"><?php echo $e(number_format($pbSender['requests'])); ?></td>
											<td class="num"><?php echo $e(number_format($pbSender['conversions'])); ?></td>
											<td><?php echo $e(date('M j, g:i a', $pbSender['last_received_at'])); ?></td>
										</tr>
									<?php } ?>
									</tbody>
								</table>
							</div>
							<?php if ($pbReady['more_senders'] > 0) { ?>
								<p class="form-text"><?php echo $e(number_format($pbReady['more_senders'])); ?> more with fewer requests are not listed; the <a href="<?php echo $e($base . 'tracking202/setup/inbound_log.php'); ?>">Inbound Log</a> has every request.</p>
							<?php } ?>
							<?php if ($pbReady['token_only'] + $pbReady['pixels'] > 0) { ?>
								<p class="form-text">Signed postbacks only would also refuse the <?php echo $e(number_format($pbReady['token_only'])); ?> that carried the token alone, and every pixel (<?php echo $e(number_format($pbReady['pixels'])); ?> here).</p>
							<?php } ?>
						<?php } ?>
						<?php if ($pbReady !== null && $pbReady['covers_since'] > $pbReady['since']) { ?>
							<p class="form-text">The inbound log keeps a number of requests, not a number of days: for this account it reaches back to <?php echo $e(date('M j, g:i a', $pbReady['covers_since'])); ?>.</p>
						<?php } ?>
					</div>
					<form method="post" action="<?php echo $e($self . '#postback-auth'); ?>" class="mb-4"
						data-p202-confirm="<?php echo $e('Change what your postbacks and pixels must carry? Anything your networks call that does not carry it will be refused from now on, and nothing recorded.'
							. ($pbOff && $pbWithout > 0 ? ' In the last 7 days ' . number_format($pbWithout) . ' came without your token.' : '')); ?>">
						<?php echo p202_account_token_field(); ?>
						<input type="hidden" name="postback_auth_action" value="mode">
						<fieldset>
							<legend class="form-label">Postbacks and pixels must carry</legend>
							<?php foreach ($pbModes as $pbValue => [$pbLabel, $pbHelp]) { ?>
								<div class="form-check">
									<input class="form-check-input" type="radio" name="mode" id="pb_mode_<?php echo $e($pbValue); ?>"
										value="<?php echo $e($pbValue); ?>"<?php echo $pbAuth->mode->value === $pbValue ? ' checked' : ''; ?>>
									<label class="form-check-label" for="pb_mode_<?php echo $e($pbValue); ?>"><?php echo $e($pbLabel); ?>
										<span class="form-text d-block"><?php echo $e($pbHelp); ?></span></label>
								</div>
							<?php } ?>
						</fieldset>
						<button class="btn btn-secondary mt-2" type="submit">Save</button>
					</form>
					<?php foreach ([
						'token' => ['Token', 'p202_token, in every postback URL and pixel', (string) $pbAuth->token, $pbAuth->tokenCreatedAt, $pbAuth->previousTokenValidUntil($pbNow), 'Copy the URLs that carry it from <a href="' . $e($base . 'tracking202/setup/get_postback.php') . '">Setup › Postback / Pixel</a>.'],
						'signing_secret' => ['Signing secret', 'the key of p202_sig; never put it in a URL', (string) $pbAuth->secret, $pbAuth->secretCreatedAt, $pbAuth->previousSecretValidUntil($pbNow), 'Give it only to a server that signs its postbacks.'],
					] as $pbCred => [$pbTitle, $pbWhat, $pbValue, $pbMade, $pbPrevious, $pbWhere]) { ?>
						<div class="mb-4">
							<p class="mb-1"><strong><?php echo $e($pbTitle); ?></strong> <span class="text-secondary">(<?php echo $e($pbWhat); ?>)</span></p>
							<p class="mb-1"><code data-postback-<?php echo $e(str_replace('_', '-', $pbCred)); ?>><?php echo $e($pbValue); ?></code>
								<?php if ($pbMade !== null) { ?><span class="text-secondary">made <?php echo $e(date('M j, Y g:i a', $pbMade)); ?></span><?php } ?></p>
							<?php if ($pbPrevious !== null) { ?>
								<p class="mb-1"><span class="text-secondary">The one it replaced keeps working until</span> <?php echo $e(date('M j, Y g:i a', $pbPrevious)); ?>.</p>
							<?php } ?>
							<p class="mb-2"><?php echo $pbWhere; ?></p>
							<form method="post" action="<?php echo $e($self . '#postback-auth'); ?>"
								data-p202-confirm="<?php echo $e('Replace your ' . strtolower($pbTitle) . '? Everything that uses the old one must move to the new one before it stops working.'); ?>">
								<?php echo p202_account_token_field(); ?>
								<input type="hidden" name="postback_auth_action" value="rotate">
								<input type="hidden" name="credential" value="<?php echo $e($pbCred); ?>">
								<label class="form-label" for="grace_<?php echo $e($pbCred); ?>">The old one keeps working</label>
								<div class="input-group" style="max-width: 28rem">
									<?php echo $pbGraceSelect('grace_' . $pbCred); ?>
									<button class="btn btn-secondary" type="submit">Rotate</button>
								</div>
							</form>
						</div>
					<?php } ?>
				<?php } ?>
			</div>
		</section>
	</div>
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
								<?php foreach ($dniRows as $dni_row) { ?>
									<tr>
										<td>
											<?php if ((string)$dni_row['favIcon'] !== '') { ?><img src="<?php echo $e($dni_row['favIcon']); ?>" width="16" height="16" alt="" class="me-1"><?php } ?>
											<span title="<?php echo $e($dni_row['shortDescription']); ?>"><?php echo $e($dni_row['name'] . ' (' . $dni_row['type'] . ')'); ?></span>
											<?php if (!$dni_row['processed']) { ?>
												<div class="mt-1" id="network-<?php echo (int)$dni_row['id']; ?>">
													<div class="form-text mt-0">Processing offers…</div>
													<div class="progress" role="progressbar" aria-label="Processing <?php echo $e($dni_row['name']); ?>" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
														<div class="progress-bar" data-dni-progress="<?php echo (int)$dni_row['id']; ?>" style="width: 0%">0%</div>
													</div>
												</div>
											<?php } ?>
										</td>
										<td class="text-nowrap"><?php echo $e($dni_row['keyHint']); ?></td>
										<td><?php echo $e($dni_row['affiliateId']); ?></td>
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
					// A refused edit comes back to the edit, not to an empty add.
					$dniFormAction = $editing_dni_network ? $self . '?edit_dni_network=' . (int)$edit_dni_row['id'] . '#dni' : $self . '#dni';
					?>
					<form method="post" action="<?php echo $e($dniFormAction); ?>" data-dni-form>
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
								<label class="form-label" for="dni_network_api_key"><?php echo $editing_dni_network ? 'New API key' : 'API key'; ?></label>
								<?php // Never filled in: a saved key, or one typed into a refused submit, stays off the page. ?>
								<input type="text" name="dni_network_api_key" id="dni_network_api_key" class="form-control"<?php echo $editing_dni_network ? '' : ' required'; ?> autocomplete="off" spellcheck="false" value=""<?php echo $editing_dni_network ? ' placeholder="' . $e('Key ' . lcfirst($edit_dni_row['keyHint']) . '; leave empty to keep it') . '"' : ''; ?>>
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
						<div class="form-text">HasOffers networks need only the API key; Cake networks also ask for your affiliate ID.<?php echo $editing_dni_network ? ' The saved key is never shown: leave New API key empty to keep it, or paste a new one to replace it.' : ''; ?></div>
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
	// Connected is lpo_status; whether the optimizer gets events is its
	// webhook's status. A read that fails is said as such, never "Connected".
	$lpo_webhook = null;
	if ($lpo_connected) {
		try {
			$lpo_webhook = \Prosper202\Lpo\PairingWebhook::state(
				new \Prosper202\Ltv\MysqlWebhookRepository(new \Prosper202\Database\Connection($db)),
				(int) $_SESSION['user_id'],
				(string) ($user_row['lpo_bridge_config'] ?? '')
			);
		} catch (Throwable $lpo_status_error) {
			error_log('lpo card: the pairing webhook could not be read: ' . $lpo_status_error->getMessage());
		}
	}
	[$lpo_problem_kind, $lpo_problem] = $lpo_connected
		? \Prosper202\Lpo\PairingWebhook::notice($lpo_webhook)
		: ['', ''];
	$lpo_revive = ($lpo_webhook['state'] ?? null) === \Prosper202\Lpo\PairingWebhook::DEAD;
	?>
	<div class="col-12 col-lg-6">
		<section class="p202-panel h-100" id="lpo">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Landing Page Optimizer</h2>
				<?php if ($lpo_connected && $lpo_problem === '') { ?>
					<span class="p202-pill p202-pill--good">Connected</span>
				<?php } elseif ($lpo_connected && $lpo_problem_kind === 'bad') { ?>
					<span class="p202-pill p202-pill--bad">Not receiving events</span>
				<?php } elseif ($lpo_connected) { ?>
					<span class="p202-pill p202-pill--warn">Status unknown</span>
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
					<?php if ($lpo_problem !== '') {
						echo p202_flash($lpo_problem_kind, $lpo_problem);
					} ?>
					<?php echo $endpoint('Site key', $lpo_site_key); ?>
					<div class="p202-toolbar">
						<?php if ($lpo_revive) { ?>
							<a href="<?php echo $e($base . 'tracking202/analyze/ltv.php?view=settings'); ?>"
								class="btn btn-primary">LTV Settings</a>
						<?php } ?>
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
		// Only whether a secret is saved, and how a long one ends, reaches the page.
		$saved = SavedSecret::isSaved($user_row[$integration['field']] ?? null);
		$savedHint = SavedSecret::hint($user_row[$integration['field']] ?? null);
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
					<?php } elseif (!$saved) { ?>
						<span class="p202-pill">Not connected</span>
					<?php } elseif ($isClickbank) { ?>
						<span data-cb-status><?php echo apiint_cb_pill((bool)$cb_verified); ?></span>
					<?php } else { ?>
						<span class="p202-pill p202-pill--good">Connected</span>
					<?php } ?>
				</div>
				<div class="p202-panel__body">
					<p class="text-secondary"><?php echo $e($integration['desc']); ?><?php echo $integration['extra'] !== '' ? ' ' . $integration['extra'] . '.' : ''; ?></p><?php echo $anchor === 'ipqs' ? $ipqsNote : ''; ?>
					<?php if ($isClickbank && !$cb_crypto_ok) { ?>
						<?php echo p202_flash('bad', 'The OpenSSL PHP extension, which decrypts ClickBank’s notifications, is required for this integration. Install it, or ask your hosting provider for assistance.'); ?>
					<?php } else { ?>
						<?php if ($integration['endpoint'] !== null) {
							echo $endpoint($integration['endpoint'][0], $integration['endpoint'][1]);
						} ?>
						<p class="mb-3"><span class="text-secondary"><?php echo $e($integration['label']); ?>:</span>
							<span data-saved-secret><?php echo $e($savedHint); ?></span></p>
						<details class="p202-disclosure"<?php echo $hasError ? ' open' : ''; ?>>
							<summary><?php echo $saved ? 'Update' : 'Connect'; ?> <span class="p202-disclosure__hint"><?php echo $e($saved ? $integration['label'] : 'paste your ' . $integration['label']); ?></span></summary>
							<div class="p202-disclosure__body">
								<form method="post" action="<?php echo $e($self . '#' . $anchor); ?>">
									<input type="hidden" name="<?php echo $e($integration['flag']); ?>" value="1">
									<?php echo p202_account_token_field(); ?>
									<label class="form-label" for="<?php echo $e($integration['field']); ?>"><?php echo $e(($saved ? 'New ' : '') . $integration['label']); ?></label>
									<div class="input-group">
										<?php // Never filled in, so no secret reaches the page. ?>
										<input type="text" class="form-control<?php echo p202_account_invalid($fieldErrors, $integration['field']); ?>" id="<?php echo $e($integration['field']); ?>" name="<?php echo $e($integration['field']); ?>"<?php echo $saved ? ' placeholder="Leave empty to keep the saved one"' : ' required'; ?> autocomplete="off" spellcheck="false" value="">
										<button class="btn btn-secondary" type="submit">Save</button>
									</div>
									<?php echo p202_account_field_error($fieldErrors, $integration['field']); ?>
								</form>
								<?php if ($isClickbank && $saved) { ?>
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

	<?php
	$jvzooColumn = $user_row[JVZooKey::COLUMN] ?? null;
	$jvzooColumn = is_string($jvzooColumn) ? $jvzooColumn : null;
	$jvzooConnected = SavedSecret::isSaved($jvzooColumn) && !JVZooKey::unreadable($jvzooColumn);
	$jvzooErrors = isset($error['jvzoo_secret_key_error'])
		? [JVZooKey::COLUMN => (string) $error['jvzoo_secret_key_error']] : [];
	?>
	<div class="col-12 col-lg-6">
		<section class="p202-panel h-100" id="jvzoo">
			<div class="p202-panel__head">
				<img src="<?php echo $e($base . '202-img/icons/integrations/jvzoo.png'); ?>" width="22" height="22"
					alt="" class="rounded-1">
				<h2 class="p202-panel__title">JVZoo</h2>
				<?php echo $helpLink('jvzoo'); ?>
				<?php if ($jvzooConnected) { ?>
					<span class="p202-pill p202-pill--good">Connected</span>
				<?php } elseif (JVZooKey::unreadable($jvzooColumn)) { ?>
					<span class="p202-pill p202-pill--warn">Key unreadable</span>
				<?php } else { ?>
					<span class="p202-pill">Not connected</span>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<p class="text-secondary">Record each JVZoo sale and rebill on the click that sent the buyer, and take
					it back on a refund or chargeback, from JVZoo’s JVZIPN.</p>
				<p class="mb-1"><strong>Promoting a JVZoo product as an affiliate</strong></p>
				<ol class="ps-3">
					<li>Make the campaign’s offer URL your JVZoo affiliate link with
						<code class="text-nowrap">?tid=[[subid]]</code> added, such as
						<code>https://www.jvzoo.com/c/12345/67890/?tid=[[subid]]</code>. JVZoo sends the tid back in the
						notification, and the sale is recorded on that click at your commission.</li>
					<li>In JVZoo, under Affiliates › Approved Products, open the product’s Integrations and
						paste this URL into Affiliate IPN.</li>
				</ol>
				<?php echo $endpoint('Affiliate IPN URL', JVZooRole::AFFILIATE->url($trackingBase)); ?>
				<p class="mb-1"><strong>Selling your own product on JVZoo</strong></p>
				<ol class="ps-3">
					<li>Make the campaign’s offer URL your product’s JVZoo buy link with
						<code class="text-nowrap">?subid=[[subid]]</code> added at its end, and send buyers
						to it through the tracker (or a landing page’s outbound link), which fills it with the
						click id. JVZoo sends the buy link’s variables back in the notification.</li>
					<li>In JVZoo, edit the product and, under Advanced Settings, choose JVZIPN v1 and paste this URL as
						its JVZIPN URL. (Not the v2 URL: v2 does not sign the amount or the click.)</li>
				</ol>
				<?php echo $endpoint('Vendor JVZIPN URL', JVZooRole::VENDOR->url($trackingBase)); ?>
				<p>Both are verified with your JVZIPN secret key, which JVZoo shows under My Account ›
					Account Details › Seller Settings; store it below. A notification it does not verify is
					refused. For a subscription, set the campaign’s “When a click converts more than once”
					(under Advanced) to “Add them up”, so each rebill adds to the sale.</p>
				<p class="mb-3"><span class="text-secondary">JVZIPN secret key:</span>
					<span data-saved-secret><?php echo $e(SavedSecret::hint($jvzooColumn)); ?></span></p>
				<details class="p202-disclosure"<?php echo $jvzooErrors ? ' open' : ''; ?>>
					<summary><?php echo $jvzooConnected ? 'Update' : 'Connect'; ?>
						<span class="p202-disclosure__hint">your JVZIPN secret key</span></summary>
					<div class="p202-disclosure__body">
						<form method="post" action="<?php echo $e($self . '#jvzoo'); ?>">
							<input type="hidden" name="change_jvzoo_secret_key" value="1">
							<?php echo p202_account_token_field(); ?>
							<label class="form-label" for="jvzoo_ipn_secret_key"><?php
								echo $jvzooConnected ? 'New JVZIPN secret key' : 'JVZIPN secret key'; ?></label>
							<div class="input-group">
								<?php // Never filled in, so no secret reaches the page. ?>
								<input type="text"
									class="form-control<?php
										echo p202_account_invalid($jvzooErrors, JVZooKey::COLUMN); ?>"
									id="jvzoo_ipn_secret_key" name="jvzoo_ipn_secret_key"<?php echo $jvzooConnected
										? ' placeholder="Leave empty to keep the saved one"' : ' required'; ?>
									autocomplete="off" spellcheck="false" value="">
								<button class="btn btn-secondary" type="submit">Save</button>
							</div>
							<?php echo p202_account_field_error($jvzooErrors, JVZooKey::COLUMN); ?>
						</form>
					</div>
				</details>
				<?php $jvzooRoleless = JVZooRole::fromQuery($user_row['jvzoo_roleless_role'] ?? null)
					?? JVZooRole::AFFILIATE; ?>
				<form method="post" action="<?php echo $e($self . '#jvzoo'); ?>" class="mt-3" data-jvzoo-roleless>
					<input type="hidden" name="change_jvzoo_roleless_role" value="1">
					<?php echo p202_account_token_field(); ?>
					<fieldset>
						<legend class="form-label fs-6 fw-semibold">How should the 1.9.56 JVZIPN URL (no
							<code>?role=</code>) read your notifications?</legend>
						<p class="small text-secondary mb-2">Upgraded from Prosper202 1.9.56? Its JVZIPN URL ends
							<code>jvzoo.php</code> with no <code>?role=</code>. If you sell your own product, choose vendor
							here or paste the Vendor JVZIPN URL above into your product: read as an affiliate’s, a
							vendor’s sale is refused and JVZoo shows the error.</p>
						<?php foreach ([
							JVZooRole::AFFILIATE->value => 'I promote JVZoo products (affiliate)',
							JVZooRole::VENDOR->value => 'I sell my own product on JVZoo (vendor)',
						] as $jvzooRoleValue => $jvzooRoleLabel) { ?>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="<?php echo $e(JVZooRole::ROLELESS_COLUMN); ?>"
								id="jvzoo_roleless_<?php echo $e($jvzooRoleValue); ?>" value="<?php echo $e($jvzooRoleValue); ?>"<?php
								echo $jvzooRoleless->value === $jvzooRoleValue ? ' checked' : ''; ?>>
							<label class="form-check-label" for="jvzoo_roleless_<?php echo $e($jvzooRoleValue); ?>"><?php
								echo $e($jvzooRoleLabel); ?></label>
						</div>
						<?php } ?>
					</fieldset>
					<button class="btn btn-secondary btn-sm mt-2" type="submit">Save</button>
				</form>
			</div>
		</section>
	</div>

	<?php
	$pkColumn = $user_row[PayKickstartKeys::COLUMN] ?? null;
	$pkStored = PayKickstartKeys::stored(is_string($pkColumn) ? $pkColumn : null)['keys'];
	$pkErrors = isset($error['paykickstart']) ? ['paykickstart_ipn_secret_key' => (string) $error['paykickstart']] : [];
	?>
	<div class="col-12 col-lg-6">
		<section class="p202-panel h-100" id="paykickstart">
			<div class="p202-panel__head">
				<img src="<?php echo $e($base . '202-img/icons/integrations/paykickstart.png'); ?>" width="22" height="22" alt="" class="rounded-1">
				<h2 class="p202-panel__title">PayKickstart</h2>
				<?php if ($pkStored) { ?>
					<span class="p202-pill p202-pill--good"><?php
						echo count($pkStored) === 1 ? '1 key stored' : count($pkStored) . ' keys stored';
					?></span>
				<?php } else { ?>
					<span class="p202-pill">Not connected</span>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<p class="text-secondary">Record each sale, rebill and refund of a product you sell through
					PayKickstart on the click that sent the buyer, from PayKickstart’s product IPN.</p>
				<?php if (isset($error['paykickstart_remove'])) {
					echo p202_flash('bad', (string) $error['paykickstart_remove']);
				} ?>
				<ol class="ps-3">
					<li>Make each campaign’s offer URL its PayKickstart checkout link with
						<code class="text-nowrap">subid=[[subid]]</code> added, such as
						<code>https://app.paykickstart.com/checkout/123?subid=[[subid]]</code>. PayKickstart sends the
						subid back in the IPN, and the sale is recorded on that click. For a subscription, set the
						campaign’s “When a click converts more than once” (under Advanced) to “Add them up”, so each
						rebill adds to the sale instead of replacing it.</li>
					<li>In PayKickstart, paste this URL into the IPN Integration tab of each product.</li>
					<li>Store the IPN secret key of each PayKickstart campaign here (every campaign has its own, in
						its settings). An IPN that no stored key verifies is refused.</li>
				</ol>
				<?php echo $endpoint('IPN URL', $trackingBase . 'tracking202/static/paykickstart.php'); ?>
				<?php if ($pkStored) { ?>
					<ul class="p202-list mb-3">
						<?php foreach ($pkStored as $pkPlace => $pkKey) { ?>
							<li class="p202-list__item">
								<span class="p202-list__name"><?php
									echo $e(PayKickstartKeys::hint($pkKey) === '' ? 'Key ' . ((int) $pkPlace + 1)
										: 'Key ending ' . PayKickstartKeys::hint($pkKey)); ?></span>
								<span class="p202-list__actions"><form method="post"
									action="<?php echo $e($self . '#paykickstart'); ?>" class="d-inline"
									data-p202-confirm="Remove this key? PayKickstart IPNs signed with it are refused until it is stored again.">
									<?php echo p202_account_token_field(); ?>
									<input type="hidden" name="paykickstart_key_action" value="remove">
									<input type="hidden" name="paykickstart_key_place" value="<?php echo (int) $pkPlace + 1; ?>">
									<input type="hidden" name="paykickstart_key_token"
										value="<?php echo $e(PayKickstartKeys::removalToken($pkKey, $pkSecret)); ?>">
									<button type="submit" class="p202-list__action p202-list__action--danger">remove</button>
								</form></span>
							</li>
						<?php } ?>
					</ul>
				<?php } ?>
				<details class="p202-disclosure"<?php echo $pkErrors ? ' open' : ''; ?>>
					<summary><?php echo count($pkStored) > 0 ? 'Add a key' : 'Connect'; ?>
						<span class="p202-disclosure__hint">one for each campaign</span></summary>
					<div class="p202-disclosure__body">
						<form method="post" action="<?php echo $e($self . '#paykickstart'); ?>">
							<input type="hidden" name="paykickstart_key_action" value="add">
							<?php echo p202_account_token_field(); ?>
							<label class="form-label" for="paykickstart_ipn_secret_key">IPN secret key</label>
							<div class="input-group">
								<input type="text"
									class="form-control<?php echo p202_account_invalid($pkErrors, 'paykickstart_ipn_secret_key'); ?>"
									id="paykickstart_ipn_secret_key" name="paykickstart_ipn_secret_key"
									required autocomplete="off" spellcheck="false" value="">
								<button class="btn btn-secondary" type="submit">Save</button>
							</div>
							<?php echo p202_account_field_error($pkErrors, 'paykickstart_ipn_secret_key'); ?>
						</form>
					</div>
				</details>
				<p class="form-text mb-0 mt-3">Promoting PayKickstart products as an affiliate? PayKickstart’s
					Affiliate IPN cannot update conversions: it carries no click (PayKickstart allows no custom
					variables in it, and the tid on your affiliate link shows only in your PayKickstart account), and it
					is not signed. Leave this URL out of the Affiliate IPN setting.</p>
			</div>
		</section>
	</div>

	<?php
	$shopifyColumn = $user_row[ShopifyWebhookSecret::COLUMN] ?? null;
	$shopifyColumn = is_string($shopifyColumn) ? $shopifyColumn : null;
	$shopifyConnected = SavedSecret::isSaved($shopifyColumn) && !ShopifyWebhookSecret::unreadable($shopifyColumn);
	$shopifyErrors = isset($error['shopify_webhook_secret_error'])
		? [ShopifyWebhookSecret::COLUMN => (string) $error['shopify_webhook_secret_error']] : [];
	?>
	<div class="col-12 col-lg-6">
		<section class="p202-panel h-100" id="shopify">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Shopify</h2>
				<?php if ($shopifyConnected) { ?>
					<span class="p202-pill p202-pill--good">Connected</span>
				<?php } elseif (ShopifyWebhookSecret::unreadable($shopifyColumn)) { ?>
					<span class="p202-pill p202-pill--warn">Secret unreadable</span>
				<?php } else { ?>
					<span class="p202-pill">Not connected</span>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<p class="text-secondary">Record each Shopify order on the click that sent the buyer, at the order’s
					total, and take it back on a refund, from Shopify’s webhooks.</p>
				<p class="text-secondary">Check Setup › Inbound Log for Shopify refusals.</p>
				<ol class="ps-3">
					<li>On your store, add the click id to the cart as the line item property
						<code class="text-nowrap">t202subid</code> (or <code class="text-nowrap">_t202subid</code>, which
						checkout hides), filled from the tracker’s <code>[[subid]]</code>.</li>
					<li>In Shopify, under Settings › Notifications › Webhooks, create a JSON webhook for
						<strong>Order payment</strong>, <strong>Refund create</strong>, and <strong>Transaction creation</strong>, all to this URL.
						Transaction creation reports when a pending refund settles.
						(Order creation records only an order already paid when it is placed.)</li>
					<li>Store the secret Shopify shows there (“Your webhooks will be signed with …”) below. A
						webhook it does not verify is refused, and nothing is recorded until a secret is stored.</li>
				</ol>
				<?php echo $endpoint('Webhook URL', $trackingBase . 'tracking202/static/shopify.php'); ?>
				<p>A webhook already sent to your postback URL (<code>tracking202/static/gpb.php</code>) is handled
					the same way. Each order counts once, under its order id, whichever webhook arrives first.</p>
				<p class="mb-3"><span class="text-secondary">Webhook secret:</span>
					<span data-saved-secret><?php echo $e(SavedSecret::hint($shopifyColumn)); ?></span></p>
				<details class="p202-disclosure"<?php echo $shopifyErrors ? ' open' : ''; ?>>
					<summary><?php echo $shopifyConnected ? 'Update' : 'Connect'; ?>
						<span class="p202-disclosure__hint">your webhook secret</span></summary>
					<div class="p202-disclosure__body">
						<form method="post" action="<?php echo $e($self . '#shopify'); ?>">
							<input type="hidden" name="change_shopify_webhook_secret" value="1">
							<?php echo p202_account_token_field(); ?>
							<label class="form-label" for="shopify_webhook_secret"><?php
								echo $shopifyConnected ? 'New webhook secret' : 'Webhook secret'; ?></label>
							<div class="input-group">
								<?php // Never filled in, so no secret reaches the page. ?>
								<input type="text"
									class="form-control<?php
										echo p202_account_invalid($shopifyErrors, ShopifyWebhookSecret::COLUMN); ?>"
									id="shopify_webhook_secret" name="shopify_webhook_secret"<?php echo $shopifyConnected
										? ' placeholder="Leave empty to keep the saved one"' : ' required'; ?>
									autocomplete="off" spellcheck="false" value="">
								<button class="btn btn-secondary" type="submit">Save</button>
							</div>
							<?php echo p202_account_field_error($shopifyErrors, ShopifyWebhookSecret::COLUMN); ?>
						</form>
					</div>
				</details>
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

		/* Networks still importing their offers: poll their progress. The
		   server asks the service about this account's own networks; their
		   keys never reach the page. */
		if (!document.querySelector('[data-dni-progress]')) { return; }
		var base = <?php echo json_encode($base . '202-account/ajax/dni.php'); ?>;
		var poll = function () {
			fetch(base + '?getProgress=true', { method: 'POST', credentials: 'same-origin' })
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
