<?php

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');
require_once __DIR__ . '/../202-config/functions-account-ui.php';

AUTH::require_user();

/*
 * Account › Personal settings, on the v2 shell.
 *
 * Every form keeps the field names it had on the classic page, and every
 * handler checks the session token (`token`) before it writes: a refusal
 * redirects back with P202_ACCOUNT_TOKEN_REFUSED and writes nothing. A
 * successful write redirects back to the page (post-redirect-get) with a
 * flash; a refused one renders in place with the server's sentence under the
 * field it names. Passwords are never echoed back into a field.
 */

$canPersonal = $userObj->hasPermission('access_to_personal_settings');

// Initialize variables to prevent undefined variable warnings
$error = [];
/** @var array<string, string> field name => sentence, for the profile form */
$profileErrors = [];
/** @var array<string, string> field name => sentence, for the password form */
$passErrors = [];
/** @var array<string, string> field name => sentence, for the key and currency forms */
$keyErrors = [];
/** @var list<array{kind: string, text: string}> said on this render (a refused submit) */
$pageFlashes = [];
/** @var array<string, string> what the person typed, shown again after a refused profile submit */
$profileForm = [];
$html = [];
$mysql = [];
$change_p202_customer_api_key = false;

$utc = new DateTimeZone('UTC');
$dt = new DateTime('now', $utc);

$slack = false;
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();
$username = $user_row['username'];

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

// Account/key settings require the same permission that gates their UI; reject
// forged requests from users who lack it (the password-change flow is excluded
// because every user may change their own password).
$personalSettingsPost = ['add_rest_api_key', 'remove_rest_api_key', 'update_account_currency', 'update_clickserver_api_key', 'change_user_api_key', 'change_user_stats202_app_key', 'update_p202_customer_api_key'];
$personalSettingsGet = ['customers_api_key', 'remove_user_stats202_app_key', 'remove_user_api_key'];
$wantsPersonalSettingsAction = false;
foreach ($personalSettingsPost as $personalSettingsAction) {
	if (isset($_POST[$personalSettingsAction])) {
		$wantsPersonalSettingsAction = true;
		break;
	}
}
if (!$wantsPersonalSettingsAction) {
	foreach ($personalSettingsGet as $personalSettingsAction) {
		if (!empty($_GET[$personalSettingsAction])) {
			$wantsPersonalSettingsAction = true;
			break;
		}
	}
}
if ($wantsPersonalSettingsAction && !$canPersonal) {
	http_response_code(403);
	die('You do not have permission to change these settings.');
}

// ─── REST API keys ───────────────────────────────────────────────────

if (isset($_POST['add_rest_api_key'])) {
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/account.php#api-keys');
	}

	// The key is minted here. The classic page generated it in the browser
	// with Math.random() and posted it as rest_api_key; a posted key is still
	// accepted (same field, same meaning) but only in the shape the API can
	// authenticate, and an empty one means "make me one".
	$postedKey = trim((string)($_POST['rest_api_key'] ?? ''));
	if ($postedKey !== '' && !preg_match('/^[A-Za-z0-9]{32,128}$/', $postedKey)) {
		p202_account_flash('bad', 'An API key is 32 to 128 letters and digits. Leave it out and one is generated for you.');
		p202_account_redirect('202-account/account.php#api-keys');
	}
	$newKey = $postedKey !== '' ? $postedKey : bin2hex(random_bytes(32));
	$keyUserId = (int)$_SESSION['user_id'];
	$keyCreated = time();
	$key_stmt = $db->prepare('INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (?, ?, ?)');
	if ($key_stmt === false) {
		p202_account_flash('bad', 'The API key could not be saved. Nothing was created; try again.');
		p202_account_redirect('202-account/account.php#api-keys');
	}
	$key_stmt->bind_param('isi', $keyUserId, $newKey, $keyCreated);
	if (!$key_stmt->execute()) {
		$key_stmt->close();
		p202_account_flash('bad', 'The API key could not be saved. Nothing was created; try again.');
		p202_account_redirect('202-account/account.php#api-keys');
	}
	$key_stmt->close();

	if ($slack)
		$slack->push('user_added_app_api_key', ['user' => $username]);

	p202_account_flash('ok', 'API key created. It has full access; reveal or copy it below.');
	p202_account_redirect('202-account/account.php#api-keys');
}

if (isset($_POST['remove_rest_api_key'])) {
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/account.php#api-keys');
	}
	$keyUserId = (int)$_SESSION['user_id'];
	$removeKey = (string)($_POST['rest_api_key'] ?? '');
	// scope to owner
	$key_stmt = $db->prepare('DELETE FROM 202_api_keys WHERE api_key = ? AND user_id = ?');
	if ($key_stmt === false) {
		p202_account_flash('bad', 'The API key could not be revoked. It still works; try again.');
		p202_account_redirect('202-account/account.php#api-keys');
	}
	$key_stmt->bind_param('si', $removeKey, $keyUserId);
	if (!$key_stmt->execute()) {
		$key_stmt->close();
		p202_account_flash('bad', 'The API key could not be revoked. It still works; try again.');
		p202_account_redirect('202-account/account.php#api-keys');
	}
	$removed = $key_stmt->affected_rows;
	$key_stmt->close();

	if ($removed < 1) {
		p202_account_flash('warn', 'That API key was not found on this account; nothing was revoked.');
		p202_account_redirect('202-account/account.php#api-keys');
	}

	if ($slack)
		$slack->push('user_removed_app_api_key', ['user' => $username]);

	p202_account_flash('ok', 'API key revoked. Anything still using it is refused from now on.');
	p202_account_redirect('202-account/account.php#api-keys');
}

// The customer-dashboard hand-back: my.tracking202.com returns the person
// here with their key in the query string. It is validated against the
// dashboard before it is stored.
if (!empty($_GET['customers_api_key'])) {
	$mysql['p202_customer_api_key'] = $db->real_escape_string(base64_decode((string) $_GET['customers_api_key']));
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
	$validate = validateCustomersApiKey($mysql['p202_customer_api_key']);
	if ($validate['code'] != 200) {
		$keyErrors['p202_customer_api_key'] = 'API key is not valid. Check your key and try again!';
	}
	if (!$keyErrors) {
		if ($db->query("UPDATE 202_users SET p202_customer_api_key = '" . $mysql['p202_customer_api_key'] . "' WHERE user_id = '" . $mysql['user_id'] . "'")) {
			$change_p202_customer_api_key = true;
		} else {
			error_log('account.php: the customer API key was not saved: ' . $db->error);
			$keyErrors['p202_customer_api_key'] = 'The key is valid but could not be saved; try again.';
		}
	}
}

//if they want to remove their stats202 app key on file, do so
if (!empty($_GET['remove_user_stats202_app_key'])) {
	if (!hash_equals((string)($_SESSION['token'] ?? ''), (string)($_REQUEST['token'] ?? ''))) {
		http_response_code(403);
		die('Invalid token.');
	}
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$sql = "UPDATE 202_users SET user_stats202_app_key='' WHERE user_id='" . $mysql['user_id'] . "'";
	if (!$db->query($sql)) {
		error_log('account.php: the Stats202 app key was not removed: ' . $db->error);
		p202_account_flash('bad', 'The Stats202 App Key could not be removed; try again.');
		p202_account_redirect('202-account/account.php');
	}
	$_SESSION['user_stats202_app_key'] = '';
	header('location: ' . get_absolute_url() . '202-account/account.php');
	die();
}

//if they want to remove their user api key on file, do so
if (!empty($_GET['remove_user_api_key'])) {
	if (!hash_equals((string)($_SESSION['token'] ?? ''), (string)($_REQUEST['token'] ?? ''))) {
		http_response_code(403);
		die('Invalid token.');
	}
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$sql = "UPDATE 202_users SET user_api_key='' WHERE user_id='" . $mysql['user_id'] . "'";
	if (!$db->query($sql)) {
		error_log('account.php: the Tracking202 API key was not removed: ' . $db->error);
		p202_account_flash('bad', 'The Tracking202 API key could not be removed; try again.');
		p202_account_redirect('202-account/account.php');
	}
	$_SESSION['user_api_key'] = '';
	$_SESSION['user_cirrus_link'] = '';
	header('location: ' . get_absolute_url() . '202-account/account.php');
	die();
}

//get all of the user data
if (!$canPersonal) {
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
	$user_sql = "SELECT 	user_email
				 FROM   	`202_users`
				 WHERE  	`user_id`='" . $mysql['user_id'] . "'";
} else {
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$user_sql = "SELECT 	*
				 FROM   	`202_users`
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='" . $mysql['user_id'] . "'";
}

$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();
$currentUserEmail = isset($user_row['user_email']) ? (string)$user_row['user_email'] : '';

/** The choices each preference offers, value => label; the form renders these and the handler admits only these. */
$dailyEmailChoices = ['' => 'Never'];
for ($hour = 0; $hour < 24; $hour++) {
	$dailyEmailChoices[sprintf('%02d', $hour)] = date('g A', mktime($hour, 0, 0, 1, 1, 2000));
}
$keywordChoices = ['searched' => 'Pickup Searched Keyword', 'bidded' => 'Pickup Bidded Keyword'];
$bidChoices = ['0' => 'Pickup Bid from setup data', '1' => 'Pickup Bid dynamically from t202b variable'];
$refererChoices = ['browser' => 'Pickup Referer from browser', 't202ref' => 'Pickup Referer from t202ref variable'];
$privacyChoices = ['disabled' => 'Disabled', 'eu' => 'Enabled for European Traffic', 'all' => 'Enabled for All Traffic'];
$cloakChoices = ['origin' => 'Show Prosper202 Domain', 'never' => 'Show Blank Referer'];
$adChoices = ['show_all' => 'Show All Ads', 'hide_login' => 'Hide Ads On Login Screen', 'hide_all' => 'Hide All Ads'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	$submittedEmail = isset($_POST['user_email']) ? trim((string)$_POST['user_email']) : $currentUserEmail;

	if (isset($_POST['update_profile']) && $_POST['update_profile'] == '1') {
		$originalUserEmail = $currentUserEmail;
		$emailUpdated = false;

		if (!AUTH::check_csrf_token()) {
			p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
			p202_account_redirect('202-account/account.php');
		}
		if (check_email_address($submittedEmail) == false) {
			$error['user_email'] = 'Please enter a valid email address.';
		}

		if ($canPersonal) {
			//check user_email
			if (!isset($error['user_email'])) {
				$mysql['user_email'] = $db->real_escape_string($submittedEmail);
				$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
				$count_sql = "	SELECT 	*
							  	FROM  		`202_users`
							  	WHERE 	`user_email` = '" . $mysql['user_email'] . "'
								AND   		`user_id`!='" . $mysql['user_id'] . "'
								AND user_deleted != 1";
				$count_result = $db->query($count_sql);
				if ($count_result->num_rows > 0) {
					$error['user_email'] = 'That email address is already being used.';
				}
			}

			// Each preference must be one of the choices the form offers. The
			// classic page stored whatever arrived; a value outside the list
			// is refused by name rather than written.
			$postedTimezone = (string)($_POST['user_timezone'] ?? '');
			if (!in_array($postedTimezone, DateTimeZone::listIdentifiers(), true)) {
				$error['user_timezone'] = 'Choose a time zone from the list.';
			}
			if (!array_key_exists((string)($_POST['user_daily_email'] ?? ''), $dailyEmailChoices)) {
				$error['user_daily_email'] = 'Choose when the daily email is sent, or Never.';
			}
			if (!array_key_exists((string)($_POST['user_keyword_searched_or_bidded'] ?? ''), $keywordChoices)) {
				$error['user_keyword_searched_or_bidded'] = 'You must select your keyword preference.';
			}
			if (!array_key_exists((string)($_POST['user_referer'] ?? ''), $refererChoices)) {
				$error['user_referer'] = 'You must select your referer preference.';
			}
			if (!array_key_exists((string)($_POST['user_bid'] ?? ''), $bidChoices)) {
				$error['user_bid'] = 'You must select your cost data preference.';
			}
			if (!array_key_exists((string)($_POST['user_pref_privacy'] ?? ''), $privacyChoices)) {
				$error['user_pref_privacy'] = 'You must select your privacy setting.';
			}
			if (!array_key_exists((string)($_POST['cloak_referer'] ?? ''), $cloakChoices)) {
				$error['cloak_referer'] = 'You must select how cloaked links show the referer.';
			}
			if (!array_key_exists((string)($_POST['user_pref_ad_settings'] ?? ''), $adChoices)) {
				$error['user_pref_ad_settings'] = 'You must select where ads are shown.';
			}

			if (!$error) {

				$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
				$mysql['user_timezone'] = $db->real_escape_string($postedTimezone);
				$mysql['user_daily_email'] = $db->real_escape_string((string)$_POST['user_daily_email']);
				$mysql['user_keyword_searched_or_bidded'] = $db->real_escape_string((string)$_POST['user_keyword_searched_or_bidded']);
				$mysql['user_referer'] = $db->real_escape_string((string)$_POST['user_referer']);
				$mysql['cloak_referer'] = $db->real_escape_string((string)$_POST['cloak_referer']);
				$mysql['user_pref_ad_settings'] = $db->real_escape_string((string)$_POST['user_pref_ad_settings']);
				$mysql['user_pref_dynamic_bid'] = $db->real_escape_string((string)$_POST['user_bid']);
				$mysql['user_tracking_domain'] = $db->real_escape_string(trim((string)($_POST['user_tracking_domain'] ?? '')));
				$mysql['user_pref_privacy'] = $db->real_escape_string((string)$_POST['user_pref_privacy']);

				// cache_time is written only when the form sends it. No form
				// on this page has sent user_cached_reports for several
				// releases, and writing '' into the integer column failed the
				// whole preference update under strict SQL mode.
				$prefSet = [
					'user_keyword_searched_or_bidded' => (string)$_POST['user_keyword_searched_or_bidded'],
					'user_pref_referer_data' => (string)$_POST['user_referer'],
					'user_tracking_domain' => trim((string)($_POST['user_tracking_domain'] ?? '')),
				];
				if (isset($_POST['user_cached_reports'])) {
					$prefSet['cache_time'] = (string)(int)$_POST['user_cached_reports'];
				}
				$prefSet += [
					'user_pref_cloak_referer' => (string)$_POST['cloak_referer'],
					'user_pref_dynamic_bid' => (int)$_POST['user_bid'],
					'user_pref_ad_settings' => (string)$_POST['user_pref_ad_settings'],
					'user_pref_privacy' => (string)$_POST['user_pref_privacy'],
					'user_daily_email' => (string)$_POST['user_daily_email'],
				];

				// The account row and the preferences row are one save: both
				// land or neither does, so "nothing has changed" is true when
				// the page says it; the session is only told after the commit.
				try {
					p202_account_save_profile(new \Prosper202\Database\Connection($db), (int)$_SESSION['user_id'], $submittedEmail, $postedTimezone, $prefSet);
					$profileSaved = true;
				} catch (Throwable $saveFailed) {
					error_log('Account profile save failed, nothing written: ' . $saveFailed->getMessage());
					$profileSaved = false;
				}
				if (!$profileSaved) {
					$pageFlashes[] = ['kind' => 'bad', 'text' => 'Your settings could not be saved. Nothing you see below has changed; try again.'];
				} else {
					$_SESSION['user_pref_ad_settings'] = $mysql['user_pref_ad_settings'];
					//set the  session's user_timezone
					$_SESSION['user_timezone'] = $postedTimezone;
					registerDailyEmail($mysql['user_daily_email'], $mysql['user_timezone'], $user_row['install_hash'] ?? '');

					//try to set non expiring cache for values that are used in redirects
					if (!empty($memcacheWorking)) {
						$tid = $mysql['user_id'];
						setCache(md5('user_id_' . $tid . systemHash()), $mysql['user_id'], 0);
						setCache(md5('user_timezone_' . $tid . systemHash()), $mysql['user_timezone'], 0);
						setCache(md5('user_keyword_searched_or_bidded_' . $tid . systemHash()), $mysql['user_keyword_searched_or_bidded'], 0);
						setCache(md5('user_referer_' . $tid . systemHash()), $mysql['user_referer'], 0);
						setCache(md5('cloak_referer_' . $tid . systemHash()), $mysql['cloak_referer'], 0);
						setCache(md5('user_pref_dynamic_bid_' . $tid . systemHash()), $mysql['user_pref_dynamic_bid'], 0);
						setCache(md5('user_pref_privacy_' . $tid . systemHash()), $mysql['user_pref_privacy'], 0);
					}

					$emailUpdated = ($originalUserEmail !== $submittedEmail);

					if ($slack) {
						if ($_POST['user_timezone'] != $user_row['user_timezone']) {
							$slack->push('user_time_zone_changed', ['user' => $username, 'old_zone' => $user_row['user_timezone'], 'new_zone' => $_POST['user_timezone']]);
						}

						if ($_POST['user_keyword_searched_or_bidded'] != $user_row['user_keyword_searched_or_bidded']) {

							if ($user_row['user_keyword_searched_or_bidded'] == 'bidded') {
								$from_type = 'Pickup Bidded Keyword';
							} else {
								$from_type = 'Pickup Searched Keyword';
							}

							if ($_POST['user_keyword_searched_or_bidded'] == 'bidded') {
								$to_type = 'Pickup Bidded Keyword';
							} else {
								$to_type = 'Pickup Searched Keyword';
							}

							$slack->push('user_keyword_preference_changed', ['user' => $username, 'old_pref' => $from_type, 'new_pref' => $to_type]);
						}

						if ($_POST['user_referer'] != $user_row['user_pref_referer_data']) {

							if ($user_row['user_pref_referer_data'] == 't202ref') {
								$from_type = 'Pickup Referer from t202ref variable';
							} else {
								$from_type = 'Pickup Referer from browser';
							}

							if ($_POST['user_referer'] == 't202ref') {
								$to_type = 'Pickup Referer from t202ref variable';
							} else {
								$to_type = 'Pickup Referer from browser';
							}

							$slack->push('user_referer_changed', ['user' => $username, 'old_pref' => $from_type, 'new_pref' => $to_type]);
						}

						if ($_POST['cloak_referer'] != $user_row['user_pref_cloak_referer']) {

							if ($user_row['user_pref_cloak_referer'] == 'origin') {
								$from_type = 'Show Prosper202 Domain';
							} else {
								$from_type = 'Show Blank Referer';
							}

							if ($_POST['cloak_referer'] == 'origin') {
								$to_type = 'Show Prosper202 Domain';
							} else {
								$to_type = 'Show Blank Referer';
							}

							$slack->push('user_pref_cloak_referer_changed', ['user' => $username, 'old_pref' => $from_type, 'new_pref' => $to_type]);
						}

						if ($emailUpdated) {
							$slack->push('user_email_changed', ['user' => $username, 'old_email' => $originalUserEmail, 'new_email' => $submittedEmail]);
						}
					}

					p202_account_flash('ok', 'Your settings are saved.');
					p202_account_redirect('202-account/account.php');
				}
			}
		} else {
			if (!isset($error['user_email'])) {
				$mysql['user_email'] = $db->real_escape_string($submittedEmail);
				$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
				$count_sql = "	SELECT 	*
								  	FROM  		`202_users`
								  	WHERE 	`user_email` = '" . $mysql['user_email'] . "'
								  	AND   		`user_id`!='" . $mysql['user_id'] . "'";
				$count_result = $db->query($count_sql);
				if ($count_result->num_rows > 0) {
					$error['user_email'] = 'That email address is already being used.';
				}

				if (!$error) {
					$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
					$mysql['user_email'] = $db->real_escape_string($submittedEmail);
					$sql = "UPDATE 202_users SET user_email = '" . $mysql['user_email'] . "' WHERE user_id = '" . $mysql['user_id'] . "'";
					if (!$db->query($sql)) {
						$pageFlashes[] = ['kind' => 'bad', 'text' => 'Your email could not be saved; try again.'];
					} else {
						if ($slack && $submittedEmail !== $currentUserEmail) {
							$slack->push('user_email_changed', ['user' => $username, 'old_email' => $currentUserEmail, 'new_email' => $submittedEmail]);
						}
						p202_account_flash('ok', 'Your settings are saved.');
						p202_account_redirect('202-account/account.php');
					}
				}
			}
		}

		// Refused: say it under the field, and keep what was typed.
		$profileErrors = $error;
		foreach (['user_email', 'user_timezone', 'user_daily_email', 'user_keyword_searched_or_bidded', 'user_bid', 'user_referer', 'user_pref_privacy', 'cloak_referer', 'user_pref_ad_settings', 'user_tracking_domain'] as $profileField) {
			if (isset($_POST[$profileField])) {
				$profileForm[$profileField] = (string)$_POST[$profileField];
			}
		}
	}
}

if (!empty($_POST['update_account_currency']) && $_POST['update_account_currency'] == '1') {

	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/account.php#currency');
	}
	$postedCurrency = (string)($_POST['account_currency'] ?? '');
	// The list this page offers is the list the API admits
	// (tests/User/AccountCurrencyTest pins the two together); a value outside
	// it would render as an unknown code on every page that shows money.
	if (!in_array($postedCurrency, \Api\V3\Controllers\UsersController::SUPPORTED_CURRENCIES, true)) {
		$keyErrors['account_currency'] = 'Choose a currency from the list.';
	} else {
		// The currency and every campaign it re-prices land together
		// (the helper in functions-account-ui.php says why and how).
		$currencySaved = false;
		try {
			p202_account_save_currency(
				new \Prosper202\Database\Connection($db),
				(int) $_SESSION['user_id'],
				$postedCurrency,
				(string) ($user_row['user_account_currency'] ?? ''),
				static fn (string $campaignCurrency, string $payout): mixed => getForeignPayout($postedCurrency, $campaignCurrency, $payout)
			);
			$currencySaved = true;
		} catch (Throwable $failed) {
			error_log('account.php: the account currency was not saved: ' . $failed->getMessage());
		}

		if ($currencySaved) {
			p202_account_flash('ok', 'Account currency saved.');
			p202_account_redirect('202-account/account.php#currency');
		}
		$pageFlashes[] = ['kind' => 'bad', 'text' => 'The account currency could not be saved, and no campaign was re-priced; try again.'];
	}
}

if (!empty($_POST['update_clickserver_api_key']) && $_POST['update_clickserver_api_key'] == '1') {

	// The ClickServers page's empty state posts here and asks to go back
	// there; only that one page is accepted as a destination.
	$clickserverReturn = ($_POST['return_to'] ?? '') === 'clickservers' ? '202-account/clickservers.php' : '202-account/account.php';
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect($clickserverReturn);
	}

	$mysql['clickserver_api_key'] = $db->real_escape_string((string)$_POST['clickserver_api_key']);

	if (!preg_match('/\*/', (string) $_POST['clickserver_api_key'])) {
		if (!clickserver_api_key_validate($mysql['clickserver_api_key']) && $mysql['clickserver_api_key'] != '') {
			$keyErrors['clickserver_api_key'] = 'This API Key appears invalid.';
		}

		if (!$keyErrors || $mysql['clickserver_api_key'] == '') {

			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
			$mysql['clickserver_api_key'] = $db->real_escape_string((string)$_POST['clickserver_api_key']);
			$user_sql = "	UPDATE 	`202_users`
								SET     		`clickserver_api_key`='" . $mysql['clickserver_api_key'] . "'
								WHERE  	`user_id`='" . $mysql['user_id'] . "'";
			$user_result = $db->query($user_sql);

			if ($slack) {
				if ($_POST['clickserver_api_key'] != ($user_row['clickserver_api_key'] ?? '')) {
					$slack->push('user_updated_clickserver_api_key', ['user' => $username]);
				}
			}

			if ($user_result) {
				p202_account_flash('ok', 'You have updated your Prosper202 ClickServer API Key.');
				p202_account_redirect($clickserverReturn);
			}
			$pageFlashes[] = ['kind' => 'bad', 'text' => 'The ClickServer API key could not be saved; try again.'];
		} elseif ($clickserverReturn !== '202-account/account.php') {
			p202_account_flash('bad', $keyErrors['clickserver_api_key']);
			p202_account_redirect($clickserverReturn);
		}
	}
}

if (!empty($_POST['change_user_api_key']) && $_POST['change_user_api_key'] == '1') {

	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/account.php');
	}

	if (!preg_match('/\*/', (string) $_POST['user_api_key'])) {
		if (!AUTH::is_valid_api_key($_POST['user_api_key'])) {
			$keyErrors['user_api_key'] = 'This API Key appears invalid.';
		}

		if (!$keyErrors) {

			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
			$mysql['user_api_key'] = $db->real_escape_string((string)$_POST['user_api_key']);
			$user_sql = "	UPDATE 	`202_users`
								SET     		`user_api_key`='" . $mysql['user_api_key'] . "'
								WHERE  	`user_id`='" . $mysql['user_id'] . "'";
			$user_result = $db->query($user_sql);

			// Only a write that landed changes the session and says so: the
			// session key is the one used against Tracking202 from here on,
			// and a flash saying "updated" over an unchanged row is #1.
			if ($user_result) {
				$_SESSION['user_api_key'] = $_POST['user_api_key'];
				$_SESSION['user_cirrus_link'] = $_POST['user_api_key'];

				p202_account_flash('ok', 'You have updated your Tracking202 API Key.');
				p202_account_redirect('202-account/account.php');
			}
			error_log('account.php: the Tracking202 API key was not saved: ' . $db->error);
			$pageFlashes[] = ['kind' => 'bad', 'text' => 'The Tracking202 API key could not be saved; try again.'];
		}
	}
}

if (!empty($_POST['change_user_stats202_app_key']) && $_POST['change_user_stats202_app_key'] == '1') {
	// The classic handler wrote this key with no token check at all, unlike
	// every sibling on this page (error pattern #5).
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/account.php');
	}
	if (!preg_match('/\*/', (string) $_POST['user_stats202_app_key'])) {
		// Replace the undefined method with a more direct validation approach
		$app_key = $_POST['user_stats202_app_key'];
		$api_key = $_SESSION['user_api_key'] ?? '';

		// Basic validation - you may need to adjust this based on actual requirements
		if (empty($app_key) || strlen((string) $app_key) < 10 || empty($api_key)) {
			$keyErrors['user_stats202_app_key'] = 'This Tracking202 API Key & Stats202 App Key combination appears invalid.';
		}

		if (!$keyErrors) {

			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
			$mysql['user_stats202_app_key'] = $db->real_escape_string((string)$_POST['user_stats202_app_key']);
			$user_sql = "	UPDATE 	`202_users`
								SET     		`user_stats202_app_key`='" . $mysql['user_stats202_app_key'] . "'
								WHERE  	`user_id`='" . $mysql['user_id'] . "'";
			$user_result = $db->query($user_sql);

			if ($user_result) {
				$_SESSION['user_stats202_app_key'] = $_POST['user_stats202_app_key'];

				p202_account_flash('ok', 'You have updated your Stats202 App Key.');
				p202_account_redirect('202-account/account.php');
			}
			error_log('account.php: the Stats202 app key was not saved: ' . $db->error);
			$pageFlashes[] = ['kind' => 'bad', 'text' => 'The Stats202 App Key could not be saved; try again.'];
		}
	}
}

if (!empty($_POST['update_p202_customer_api_key']) && $_POST['update_p202_customer_api_key'] == '1') {
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/account.php#customer-key');
	}
	$postedCustomerKey = trim((string)($_POST['p202_customer_api_key'] ?? ''));
	$mysql['p202_customer_api_key'] = $db->real_escape_string($postedCustomerKey);
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
	if ($postedCustomerKey !== '') {
		$validate = validateCustomersApiKey($postedCustomerKey);
		if ($validate['code'] != 200) {
			$keyErrors['p202_customer_api_key'] = 'API key is not valid. Check your key and try again!';
		}
	}
	if (!isset($keyErrors['p202_customer_api_key'])) {
		if ($db->query("UPDATE 202_users SET p202_customer_api_key = '" . $mysql['p202_customer_api_key'] . "' WHERE user_id = '" . $mysql['user_id'] . "'")) {
			p202_account_flash('ok', $postedCustomerKey === '' ? 'Your Prosper202 customer API key was removed.' : 'Your Prosper202 customer API key is saved.');
			p202_account_redirect('202-account/account.php#customer-key');
		}
		$pageFlashes[] = ['kind' => 'bad', 'text' => 'Your Prosper202 customer API key could not be saved; try again.'];
	}
}

if (!empty($_POST['change_user_pass']) && $_POST['change_user_pass'] == '1') {

	//check token, and new user_pass
	if (!AUTH::check_csrf_token()) {
		// The failure counter below only runs for a request with a valid
		// token, so a forged cross-site POST can never force-logout anyone.
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/account.php#password');
	}
	$newPass = (string)($_POST['new_user_pass'] ?? '');
	$retypePass = (string)($_POST['retype_new_user_pass'] ?? '');
	if ($newPass == '') {
		$passErrors['new_user_pass'] = 'You must type in your desired password.';
	} elseif ((strlen($newPass) < 8) or (strlen($newPass) > 72)) {
		// Cap at 72 bytes: PASSWORD_DEFAULT is bcrypt, which only hashes the first 72
		// bytes. Allowing more would silently ignore the tail (any suffix past byte 72
		// would also authenticate).
		$passErrors['new_user_pass'] = 'Your password must be between 8 and 72 characters long.';
	}
	if ($retypePass == '') {
		$passErrors['retype_new_user_pass'] = 'You must type your new password again to verify it.';
	} elseif ($newPass !== $retypePass) {
		$passErrors['retype_new_user_pass'] = 'Your password did not match, please try again.';
	}

	//check to to see if old user_pass is correct
	if (!isset($_POST['user_pass']) || empty($_POST['user_pass'])) {
		$passErrors['user_pass'] = 'You must enter your current password.';
	} else {
		$verify_stmt = $db->prepare('SELECT user_pass FROM 202_users WHERE user_id = ? LIMIT 1');
		$stored = null;
		$verified = false;
		if ($verify_stmt) {
			$current_user_id = (int) ($_SESSION['user_own_id'] ?? 0);
			$verify_stmt->bind_param('i', $current_user_id);
			if ($verify_stmt->execute()) {
				$result = $verify_stmt->get_result();
				if ($result !== false) {
					$stored = $result->fetch_assoc();
					$verified = true;
				}
			}
			$verify_stmt->close();
		}
		if (!$verified) {
			// Could not read the stored hash: say so, and do not count it as
			// a wrong password (it is not one).
			$passErrors['user_pass'] = 'Unable to verify your current password at this time.';
		} elseif (!$stored || !verify_user_pass((string) $_POST['user_pass'], (string) ($stored['user_pass'] ?? ''))['valid']) {
			$passErrors['user_pass'] = 'Your old password was typed incorrectly.';

			// Count wrong current-password attempts within this session (the
			// token was checked above, so a forged cross-site POST can't
			// force-logout the victim). Too many almost always means someone
			// is poking at a session they shouldn't have, so tear it down and
			// force a fresh login rather than letting them keep guessing
			// toward an account takeover.
			$_SESSION['pw_change_fails'] = (int) ($_SESSION['pw_change_fails'] ?? 0) + 1;
			if ($_SESSION['pw_change_fails'] >= AUTH::MAX_PASSWORD_REAUTH_FAILS) {
				session_destroy();
				$secure = function_exists('getSecureStatus')
					? getSecureStatus()
					: (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
				setcookie('remember_me', '', ['expires' => 1, 'path' => '/', 'domain' => AUTH::cookie_domain(), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
				unset($_COOKIE['remember_me']);
				header('location: ' . get_absolute_url() . '202-login.php');
				exit;
			}
		} else {
			// Correct current password — this is the legitimate owner, so
			// clear the failure counter.
			unset($_SESSION['pw_change_fails']);
		}
	}

	//if no user_pass errors
	if (!$passErrors) {
		$new_hash = hash_user_pass($newPass);
		$update_stmt = $db->prepare('UPDATE 202_users SET user_pass = ? WHERE user_id = ?');
		$passSaved = false;
		if ($update_stmt) {
			$current_user_id = (int) ($_SESSION['user_own_id'] ?? 0);
			$update_stmt->bind_param('si', $new_hash, $current_user_id);
			$passSaved = $update_stmt->execute();
			$update_stmt->close();
		}
		if (!$passSaved) {
			prosper_log('account', 'Failed to update password: ' . $db->error);
			$pageFlashes[] = ['kind' => 'bad', 'text' => 'Your password could not be changed. Your old password still works; try again.'];
		} else {
			p202_account_flash('ok', 'Your password is changed.');
			p202_account_redirect('202-account/account.php#password');
		}
	}
}

//update new values from the db
$user_sql = "	SELECT 	*
				 FROM   	`202_users`
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='" . $mysql['user_id'] . "'";
$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
/** The stored value, or what the person typed on a refused submit. */
$profileValue = static function (string $field, string $column) use ($profileForm, $user_row): string {
	return array_key_exists($field, $profileForm) ? $profileForm[$field] : (string)($user_row[$column] ?? '');
};
$selected = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
$renderOptions = static function (array $choices, string $current) use ($e, $selected): string {
	$out = '';
	foreach ($choices as $value => $label) {
		$out .= '<option value="' . $e($value) . '"' . $selected((string)$value, $current) . '>' . $e($label) . '</option>';
	}
	return $out;
};

$apiKeys = [];
if ($canPersonal) {
	try {
		// Works with or without the scope column: see p202_account_api_keys in functions-account-ui.php.
		$apiKeys = p202_account_api_keys($db, (int)$_SESSION['user_id']);
	} catch (Throwable $keysUnreadable) {
		// An unreadable list must not render as "no keys yet".
		error_log('Account page: API keys could not be read: ' . $keysUnreadable->getMessage());
		$apiKeys = [];
		$pageFlashes[] = ['kind' => 'bad', 'text' => 'Your API keys could not be read just now. Reload the page to see them.'];
	}
}

/** A key shown as its first and last four characters around a mask. */
$maskKey = static fn (string $key): string => strlen($key) > 12
	? substr($key, 0, 4) . str_repeat("\u{2022}", 20) . substr($key, -4)
	: str_repeat("\u{2022}", 20);
/** What a key may do, in words: the same reading api/v3/Auth.php applies. */
$keyScope = static function (mixed $raw): array {
	$scopes = \Api\V3\Auth::parseScopes((string)($raw ?? ''));
	if (in_array('*', $scopes, true)) {
		return ['full access', ''];
	}
	if (in_array(\Api\V3\Auth::MALFORMED_SCOPE, $scopes, true)) {
		return ['scope unreadable · refused everywhere', ' p202-pill--bad'];
	}
	return [implode(', ', $scopes), ' p202-pill--accent'];
};

$currencyValue = (string)($_POST['account_currency'] ?? ($user_row['user_account_currency'] ?? 'USD'));
$sel = static fn (string $code): string => $currencyValue === $code ? ' selected' : '';
$currentTimezone = $profileValue('user_timezone', 'user_timezone');

$profileAdvancedErrors = array_intersect_key($profileErrors, array_flip(['user_keyword_searched_or_bidded', 'user_bid', 'user_referer', 'user_pref_privacy', 'cloak_referer', 'user_pref_ad_settings', 'user_tracking_domain']));

template_top('Personal Settings', ['ui' => 'v2']);
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-person-gear"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Personal settings</h1>
		<p class="p202-page-header__desc">Your email, time zone and tracking preferences<?php echo $canPersonal ? ', the account currency, API keys' : ''; ?> and your password.</p>
	</div>
</div>

<?php if ($canPersonal) { ?>
	<nav class="nav p202-tabs p202-tabs--compact" aria-label="Personal settings sections">
		<a class="nav-link" href="#profile">Profile</a>
		<a class="nav-link" href="#currency">Currency</a>
		<a class="nav-link" href="#api-keys">API keys</a>
		<a class="nav-link" href="#customer-key">Customer key</a>
		<a class="nav-link" href="#password">Password</a>
	</nav>
<?php } ?>

<?php
$extraFlashes = $pageFlashes;
if ($change_p202_customer_api_key) {
	$extraFlashes[] = ['kind' => 'ok', 'text' => 'Your Prosper202 customer API key is saved.'];
}
foreach (['clickserver_api_key', 'user_api_key', 'user_stats202_app_key'] as $keyField) {
	if (isset($keyErrors[$keyField])) {
		$extraFlashes[] = ['kind' => 'bad', 'text' => $keyErrors[$keyField]];
	}
}
if ($profileErrors) {
	$extraFlashes[] = ['kind' => 'bad', 'text' => 'Your settings were not saved. The fields below say why.'];
}
echo p202_account_render_flashes($extraFlashes);
?>

<div class="row g-4">
	<div class="col-12 col-xl-8">

		<section class="p202-panel" id="profile">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Profile</h2>
				<span class="p202-panel__sub">how Prosper202 reaches you, and the clock your reports use</span>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo $e(get_absolute_url() . '202-account/account.php'); ?>">
					<input type="hidden" name="update_profile" value="1">
					<?php echo p202_account_token_field(); ?>

					<div class="mb-3">
						<label class="form-label" for="user_email">Email <span class="text-danger">*</span></label>
						<input type="email" class="form-control<?php echo p202_account_invalid($profileErrors, 'user_email'); ?>" id="user_email" name="user_email" required autocomplete="email" value="<?php echo $e($profileValue('user_email', 'user_email')); ?>">
						<div class="form-text">Password resets and the daily report go here.</div>
						<?php echo p202_account_field_error($profileErrors, 'user_email'); ?>
					</div>

					<?php if ($canPersonal) { ?>
						<div class="row g-3 mb-3">
							<div class="col-md-7">
								<label class="form-label" for="user_timezone">Time zone <span class="text-danger">*</span></label>
								<select class="form-select<?php echo p202_account_invalid($profileErrors, 'user_timezone'); ?>" name="user_timezone" id="user_timezone">
									<?php
									foreach (DateTimeZone::listIdentifiers() as $tz) {
										$current_tz = new DateTimeZone($tz);
										$offset = $current_tz->getOffset($dt);
										$transition = $current_tz->getTransitions($dt->getTimestamp(), $dt->getTimestamp());
										$abbr = $transition[0]['abbr'] ?? '';
										echo '<option value="' . $e($tz) . '"' . $selected($tz, $currentTimezone) . '>' . $e($tz . ' [' . $abbr . ' ' . formatOffset($offset) . ']') . '</option>';
									}
									?>
								</select>
								<div class="form-text">Every report's days start and end in this zone.</div>
								<?php echo p202_account_field_error($profileErrors, 'user_timezone'); ?>
							</div>
							<div class="col-md-5">
								<label class="form-label" for="user_daily_email">Daily email report</label>
								<select class="form-select<?php echo p202_account_invalid($profileErrors, 'user_daily_email'); ?>" id="user_daily_email" name="user_daily_email">
									<?php echo $renderOptions($dailyEmailChoices, $profileValue('user_daily_email', 'user_daily_email')); ?>
								</select>
								<div class="form-text">Yesterday's numbers, sent at this hour in your time zone.</div>
								<?php echo p202_account_field_error($profileErrors, 'user_daily_email'); ?>
							</div>
						</div>

						<details class="p202-disclosure mb-3" data-p202-remember="account-profile-advanced"<?php echo $profileAdvancedErrors ? ' open' : ''; ?>>
							<summary>Advanced <span class="p202-disclosure__hint">keyword, cost, referer, privacy, ads, tracking domain</span></summary>
							<div class="p202-disclosure__body">
								<div class="row g-3">
									<div class="col-md-6">
										<label class="form-label" for="user_keyword_searched_or_bidded">Keyword preference</label>
										<select class="form-select<?php echo p202_account_invalid($profileErrors, 'user_keyword_searched_or_bidded'); ?>" name="user_keyword_searched_or_bidded" id="user_keyword_searched_or_bidded">
											<?php echo $renderOptions($keywordChoices, $profileValue('user_keyword_searched_or_bidded', 'user_keyword_searched_or_bidded')); ?>
										</select>
										<div class="form-text">Which keyword a click is recorded under.</div>
										<?php echo p202_account_field_error($profileErrors, 'user_keyword_searched_or_bidded'); ?>
									</div>
									<div class="col-md-6">
										<label class="form-label" for="user_bid">Cost data preference</label>
										<select class="form-select<?php echo p202_account_invalid($profileErrors, 'user_bid'); ?>" name="user_bid" id="user_bid">
											<?php echo $renderOptions($bidChoices, $profileValue('user_bid', 'user_pref_dynamic_bid')); ?>
										</select>
										<div class="form-text">Where a click's cost comes from.</div>
										<?php echo p202_account_field_error($profileErrors, 'user_bid'); ?>
									</div>
									<div class="col-md-6">
										<label class="form-label" for="user_referer">Referer preference</label>
										<select class="form-select<?php echo p202_account_invalid($profileErrors, 'user_referer'); ?>" name="user_referer" id="user_referer">
											<?php echo $renderOptions($refererChoices, $profileValue('user_referer', 'user_pref_referer_data')); ?>
										</select>
										<div class="form-text">Where a click's referring page is read from.</div>
										<?php echo p202_account_field_error($profileErrors, 'user_referer'); ?>
									</div>
									<div class="col-md-6">
										<label class="form-label" for="user_pref_privacy">GDPR &amp; privacy</label>
										<select class="form-select<?php echo p202_account_invalid($profileErrors, 'user_pref_privacy'); ?>" name="user_pref_privacy" id="user_pref_privacy">
											<?php echo $renderOptions($privacyChoices, $profileValue('user_pref_privacy', 'user_pref_privacy')); ?>
										</select>
										<div class="form-text">Which visitors get privacy handling of their data.</div>
										<?php echo p202_account_field_error($profileErrors, 'user_pref_privacy'); ?>
									</div>
									<div class="col-md-6">
										<label class="form-label" for="cloak_referer">Cloaked referer</label>
										<select class="form-select<?php echo p202_account_invalid($profileErrors, 'cloak_referer'); ?>" name="cloak_referer" id="cloak_referer">
											<?php echo $renderOptions($cloakChoices, $profileValue('cloak_referer', 'user_pref_cloak_referer')); ?>
										</select>
										<div class="form-text">What an offer sees as the referer on a cloaked link.</div>
										<?php echo p202_account_field_error($profileErrors, 'cloak_referer'); ?>
									</div>
									<div class="col-md-6">
										<label class="form-label" for="user_pref_ad_settings">Ad settings</label>
										<select class="form-select<?php echo p202_account_invalid($profileErrors, 'user_pref_ad_settings'); ?>" name="user_pref_ad_settings" id="user_pref_ad_settings">
											<?php echo $renderOptions($adChoices, $profileValue('user_pref_ad_settings', 'user_pref_ad_settings')); ?>
										</select>
										<div class="form-text">Where Prosper202 shows its offers panel.</div>
										<?php echo p202_account_field_error($profileErrors, 'user_pref_ad_settings'); ?>
									</div>
									<div class="col-12">
										<label class="form-label" for="user_tracking_domain">Tracking domain</label>
										<input type="text" class="form-control<?php echo p202_account_invalid($profileErrors, 'user_tracking_domain'); ?>" id="user_tracking_domain" name="user_tracking_domain" placeholder="<?php echo $e((string)($_SERVER['HTTP_HOST'] ?? '')); ?>" value="<?php echo $e($profileValue('user_tracking_domain', 'user_tracking_domain')); ?>">
										<div class="form-text">Leave empty to build tracking links on this install's own domain.</div>
										<?php echo p202_account_field_error($profileErrors, 'user_tracking_domain'); ?>
									</div>
								</div>
							</div>
						</details>
					<?php } ?>

					<div class="p202-form-actions">
						<button class="btn btn-primary" type="submit">Save settings</button>
					</div>
				</form>
			</div>
		</section>

		<?php if ($canPersonal) { ?>
			<section class="p202-panel mt-4" id="currency">
				<div class="p202-panel__head">
					<h2 class="p202-panel__title">Account currency</h2>
					<span class="p202-panel__sub">the currency every amount is shown in</span>
				</div>
				<div class="p202-panel__body">
					<form method="post" action="<?php echo $e(get_absolute_url() . '202-account/account.php#currency'); ?>">
						<input type="hidden" name="update_account_currency" value="1">
						<?php echo p202_account_token_field(); ?>
						<label class="form-label" for="account_currency">Currency</label>
						<div class="input-group">
							<select class="form-select<?php echo p202_account_invalid($keyErrors, 'account_currency'); ?>" name="account_currency" id="account_currency">
								<option value="USD"<?php echo $sel('USD'); ?>>U.S. Dollar</option>
								<option value="AUD"<?php echo $sel('AUD'); ?>>Australian Dollar</option>
								<option value="BRL"<?php echo $sel('BRL'); ?>>Brazilian Real</option>
								<option value="CAD"<?php echo $sel('CAD'); ?>>Canadian Dollar</option>
								<option value="CZK"<?php echo $sel('CZK'); ?>>Czech Koruna</option>
								<option value="DKK"<?php echo $sel('DKK'); ?>>Danish Krone</option>
								<option value="EUR"<?php echo $sel('EUR'); ?>>Euro</option>
								<option value="HKD"<?php echo $sel('HKD'); ?>>Hong Kong Dollar</option>
								<option value="HUF"<?php echo $sel('HUF'); ?>>Hungarian Forint</option>
								<option value="ILS"<?php echo $sel('ILS'); ?>>Israeli New Sheqel</option>
								<option value="JPY"<?php echo $sel('JPY'); ?>>Japanese Yen</option>
								<option value="MYR"<?php echo $sel('MYR'); ?>>Malaysian Ringgit</option>
								<option value="MXN"<?php echo $sel('MXN'); ?>>Mexican Peso</option>
								<option value="NOK"<?php echo $sel('NOK'); ?>>Norwegian Krone</option>
								<option value="NZD"<?php echo $sel('NZD'); ?>>New Zealand Dollar</option>
								<option value="PHP"<?php echo $sel('PHP'); ?>>Philippine Peso</option>
								<option value="PLN"<?php echo $sel('PLN'); ?>>Polish Zloty</option>
								<option value="GBP"<?php echo $sel('GBP'); ?>>Pound Sterling</option>
								<option value="SGD"<?php echo $sel('SGD'); ?>>Singapore Dollar</option>
								<option value="SEK"<?php echo $sel('SEK'); ?>>Swedish Krona</option>
								<option value="CHF"<?php echo $sel('CHF'); ?>>Swiss Franc</option>
								<option value="TWD"<?php echo $sel('TWD'); ?>>Taiwan New Dollar</option>
								<option value="THB"<?php echo $sel('THB'); ?>>Thai Baht</option>
								<option value="TRY"<?php echo $sel('TRY'); ?>>Turkish Lira</option>
								<option value="CNY"<?php echo $sel('CNY'); ?>>Chinese Yuan</option>
								<option value="INR"<?php echo $sel('INR'); ?>>Indian Rupee</option>
								<option value="RUB"<?php echo $sel('RUB'); ?>>Russian ruble</option>
							</select>
							<button class="btn btn-secondary" type="submit">Save currency</button>
						</div>
						<div class="form-text">Changing it converts your campaign payouts to the new currency (a paid feature).</div>
						<?php echo p202_account_field_error($keyErrors, 'account_currency'); ?>
					</form>
				</div>
			</section>

			<section class="p202-panel mt-4" id="api-keys">
				<div class="p202-panel__head">
					<h2 class="p202-panel__title">API keys</h2>
					<span class="p202-pill"><?php echo count($apiKeys) === 1 ? '1 key' : count($apiKeys) . ' keys'; ?></span>
					<?php if ($apiKeys) { ?>
						<div class="p202-panel__aside">
							<form method="post" action="<?php echo $e(get_absolute_url() . '202-account/account.php#api-keys'); ?>">
								<input type="hidden" name="add_rest_api_key" value="1">
								<?php echo p202_account_token_field(); ?>
								<button class="btn btn-secondary btn-sm" type="submit"><i class="bi bi-plus-lg"></i> Generate key</button>
							</form>
						</div>
					<?php } ?>
				</div>
				<div class="p202-panel__body">
					<p class="form-text mt-0">For the REST API, the p202 command line and agents. Make one key per integration, so revoking one stops only that one.</p>
					<?php if (!$apiKeys) { ?>
						<div class="p202-empty">
							<i class="bi bi-key p202-empty__icon"></i>
							<strong class="p202-empty__title">No API keys yet</strong>
							<div>Generate one to connect the command line, an agent or your own scripts.</div>
							<div class="p202-empty__action">
								<form method="post" action="<?php echo $e(get_absolute_url() . '202-account/account.php#api-keys'); ?>">
									<input type="hidden" name="add_rest_api_key" value="1">
									<?php echo p202_account_token_field(); ?>
									<button class="btn btn-secondary btn-sm" type="submit">Generate an API key</button>
								</form>
							</div>
						</div>
					<?php } else { ?>
						<?php foreach ($apiKeys as $index => $apiKey) {
							$keyValue = (string)$apiKey['api_key'];
							[$scopeLabel, $scopeTone] = $keyScope($apiKey['scope'] ?? null);
							$keyId = 'api-key-' . $index;
							?>
							<div class="mb-3" data-api-key-row>
								<div class="form-text mt-0 mb-1">Created <?php echo $e(date('M j, Y', (int)$apiKey['created_at'])); ?> · <span class="p202-pill<?php echo $scopeTone; ?>"><?php echo $e($scopeLabel); ?></span></div>
								<div class="p202-code">
									<pre class="p202-code__value p202-code__value--masked" id="<?php echo $keyId; ?>" data-p202-value="<?php echo $e($keyValue); ?>"><?php echo $e($maskKey($keyValue)); ?></pre>
									<button type="button" class="btn btn-secondary btn-sm" data-p202-reveal="#<?php echo $keyId; ?>">Reveal</button>
									<button type="button" class="btn btn-secondary btn-sm p202-copy" data-p202-copy="<?php echo $e($keyValue); ?>">Copy</button>
									<form method="post" action="<?php echo $e(get_absolute_url() . '202-account/account.php#api-keys'); ?>" class="d-inline" data-p202-confirm="Revoke this API key? Anything still using it is refused from the next request. Your other keys keep working.">
										<input type="hidden" name="remove_rest_api_key" value="1">
										<input type="hidden" name="rest_api_key" value="<?php echo $e($keyValue); ?>">
										<?php echo p202_account_token_field(); ?>
										<button class="btn btn-outline-danger btn-sm" type="submit">Revoke…</button>
									</form>
								</div>
							</div>
						<?php } ?>
					<?php } ?>
				</div>
			</section>

			<section class="p202-panel mt-4" id="customer-key">
				<div class="p202-panel__head">
					<h2 class="p202-panel__title">Prosper202 customer API key</h2>
					<span class="p202-panel__sub">for paid features, upgrades and the Landing Page Optimizer</span>
				</div>
				<div class="p202-panel__body">
					<form method="post" action="<?php echo $e(get_absolute_url() . '202-account/account.php#customer-key'); ?>">
						<input type="hidden" name="update_p202_customer_api_key" value="1">
						<?php echo p202_account_token_field(); ?>
						<label class="form-label" for="p202_customer_api_key">Customer API key</label>
						<div class="input-group">
							<input type="text" class="form-control<?php echo p202_account_invalid($keyErrors, 'p202_customer_api_key'); ?>" id="p202_customer_api_key" name="p202_customer_api_key" autocomplete="off" spellcheck="false" value="<?php echo $e($_POST['p202_customer_api_key'] ?? ($user_row['p202_customer_api_key'] ?? '')); ?>">
							<button class="btn btn-secondary" type="submit">Save key</button>
						</div>
						<div class="form-text">From your <a href="https://my.tracking202.com/api/customers/register" target="_blank" rel="noopener">Prosper202 customer dashboard</a>. It is checked with the dashboard before it is saved; save it empty to remove it.</div>
						<?php echo p202_account_field_error($keyErrors, 'p202_customer_api_key'); ?>
					</form>
				</div>
			</section>
		<?php } ?>

		<section class="p202-panel mt-4" id="password">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Change password</h2>
				<span class="p202-panel__sub">you stay signed in here</span>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo $e(get_absolute_url() . '202-account/account.php#password'); ?>">
					<input type="hidden" name="change_user_pass" value="1">
					<?php echo p202_account_token_field(); ?>
					<div class="mb-3">
						<label class="form-label" for="user_pass">Current password</label>
						<input type="password" class="form-control<?php echo p202_account_invalid($passErrors, 'user_pass'); ?>" id="user_pass" name="user_pass" required autocomplete="current-password">
						<?php echo p202_account_field_error($passErrors, 'user_pass'); ?>
					</div>
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label" for="new_user_pass">New password</label>
							<input type="password" class="form-control<?php echo p202_account_invalid($passErrors, 'new_user_pass'); ?>" id="new_user_pass" name="new_user_pass" required minlength="8" autocomplete="new-password">
							<div class="form-text">8 to 72 characters.</div>
							<?php echo p202_account_field_error($passErrors, 'new_user_pass'); ?>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="retype_new_user_pass">Retype new password</label>
							<input type="password" class="form-control<?php echo p202_account_invalid($passErrors, 'retype_new_user_pass'); ?>" id="retype_new_user_pass" name="retype_new_user_pass" required minlength="8" autocomplete="new-password">
							<?php echo p202_account_field_error($passErrors, 'retype_new_user_pass'); ?>
						</div>
					</div>
					<div class="p202-form-actions">
						<button class="btn btn-secondary" type="submit">Change password</button>
					</div>
				</form>
			</div>
		</section>

	</div>
</div>
<?php template_bottom();
