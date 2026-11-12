<?php

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');
require_once __DIR__ . '/../202-config/functions-account-ui.php';
require_once __DIR__ . '/../202-config/functions-click-filter-ui.php';
require_once dirname(__DIR__) . '/202-config/Messaging/ConsentPolicy.class.php';
require_once __DIR__ . '/../202-config/functions-alerts-ui.php';

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

$utc = new DateTimeZone('UTC');
$dt = new DateTime('now', $utc);

$slack = false;
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username FROM 202_users AS 2u WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = _mysqli_query($db, $user_sql);
if ($user_results === false) {
    record_mysql_error($user_sql);
}
$user_row = p202FetchRow($user_results, $user_sql) ?? [];
$username = $user_row['username'];

$slack = Slack::forAccount($db, (int) ($_SESSION['user_id'] ?? 0)) ?? false;

// Account/key settings require the same permission that gates their UI; reject
// forged requests from users who lack it (the password-change flow is excluded
// because every user may change their own password).
$personalSettingsPost = ['add_rest_api_key', 'remove_rest_api_key', 'replace_rest_api_key', 'stop_ai_watcher', 'update_account_currency', 'record_earlier_currency', 'campaign_currency_notice', 'update_clickserver_api_key', 'change_user_api_key', 'change_user_stats202_app_key', 'update_p202_customer_api_key', 'update_click_filter', 'add_click_filter_entry', 'remove_click_filter_entry', 'update_money_loss_alerts', 'send_test_money_loss_alert'];
$personalSettingsGet = ['customers_api_key', 'remove_user_stats202_app_key', 'remove_user_api_key', 'test_click_filter'];
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
	$newKeyLabel = \Prosper202\User\ApiKeyStore::labelFrom($_POST['rest_api_key_label'] ?? '');
	if ($newKeyLabel === false) {
		p202_account_flash('bad', 'A key\'s name is up to ' . \Prosper202\User\ApiKeyStore::LABEL_LENGTH
			. ' characters on one line, and does not start with "AI:". Nothing was created.');
		p202_account_redirect('202-account/account.php#api-keys');
	}
	$newKey = $postedKey !== '' ? $postedKey : bin2hex(random_bytes(32));
	$keyUserId = (int)$_SESSION['user_id'];
	$keyCreated = time();
    try {
        \Prosper202\User\ApiKeyStore::insert($db, $keyUserId, $newKey, null, $newKeyLabel, $keyCreated);
    } catch (\Prosper202\Database\Exceptions\QueryException | \Api\V3\AuthException | \mysqli_sql_exception $notSaved) {
        p202_account_flash('bad', $newKeyLabel !== null && \Prosper202\User\ApiKeyStore::isUnstorableText($notSaved)
            ? \Prosper202\User\ApiKeyStore::UNSTORABLE_NAME . ' Nothing was created.'
            : 'The API key could not be saved. Nothing was created; try again.');
        p202_account_redirect('202-account/account.php#api-keys');
    }

	if ($slack)
		$slack->push('user_added_app_api_key', ['user' => $username]);

	// Stored hashed, so this is the one time the key can be shown: the next
	// render of the page shows it and forgets it.
	$_SESSION['p202_new_api_key'] = $newKey;
	p202_account_flash('ok', 'API key created. It has full access. Copy it now: it is not shown again.');
	p202_account_redirect('202-account/account.php#api-keys');
}

if (isset($_POST['remove_rest_api_key'])) {
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/account.php#api-keys');
	}
	$keyUserId = (int)$_SESSION['user_id'];
	// The page names a key by its ref (ApiKeyStore::ref()), never the key.
	$removeRef = (string)($_POST['api_key_ref'] ?? '');
	if (!\Prosper202\User\ApiKeyStore::isRef($removeRef)) {
		p202_account_flash('warn', 'That API key was not found on this account; nothing was revoked.');
		p202_account_redirect('202-account/account.php#api-keys');
	}
    // An AI connection's watchers end first; best effort, the key goes either way.
    $watchersEnded = (new \Api\V3\Support\AiWatchers())->stopBeforeDelete($db, $keyUserId, null, $removeRef);
	// scope to owner
    try {
        $keyConn = new \Prosper202\Database\Connection($db);
        [$refMatch, $refTypes, $refValues] = \Prosper202\User\ApiKeyStore::matchRef($db, $removeRef);
        $key_stmt = $keyConn->prepareWrite('DELETE FROM 202_api_keys WHERE user_id = ? AND ' . $refMatch);
        $keyConn->bind($key_stmt, 'i' . $refTypes, [$keyUserId, ...$refValues]);
        $removed = $keyConn->executeUpdate($key_stmt);
    } catch (\Prosper202\Database\Exceptions\QueryException | \Api\V3\AuthException | \mysqli_sql_exception) {
        p202_account_flash('bad', 'The API key could not be revoked. It still works; try again.');
        p202_account_redirect('202-account/account.php#api-keys');
    }

	if ($removed < 1) {
		p202_account_flash('warn', 'That API key was not found on this account; nothing was revoked.');
		p202_account_redirect('202-account/account.php#api-keys');
	}

	if ($slack)
		$slack->push('user_removed_app_api_key', ['user' => $username]);

	p202_account_flash('ok', 'API key revoked. Anything still using it is refused from now on.');
    if (!$watchersEnded) {
        p202_account_flash('warn', 'Tracking202\'s MCP server did not confirm that this key\'s watchers stopped;'
            . ' without the key they can read nothing more.');
    }
    p202_account_redirect('202-account/account.php#api-keys');
}

if (isset($_POST['replace_rest_api_key'])) {
    if (!AUTH::check_csrf_token()) {
        p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
        p202_account_redirect('202-account/account.php#api-keys');
    }
    $replaceRef = (string)($_POST['api_key_ref'] ?? '');
    if (!\Prosper202\User\ApiKeyStore::isRef($replaceRef)) {
        p202_account_flash('warn', 'That API key was not found on this account; nothing was replaced.');
        p202_account_redirect('202-account/account.php#api-keys');
    }
    $replacementKey = bin2hex(random_bytes(32));
    try {
        $replaced = \Prosper202\User\ApiKeyStore::replace(
            $db,
            (int)$_SESSION['user_id'],
            $replaceRef,
            $replacementKey,
            time()
        );
    } catch (\DomainException $refused) {
        p202_account_flash('warn', $refused->getMessage() . ' Nothing was replaced.');
        p202_account_redirect('202-account/account.php#api-keys');
    } catch (\Throwable $failed) {
        error_log('account.php: an API key could not be replaced: ' . $failed->getMessage());
        p202_account_flash('bad', 'The API key could not be replaced. Nothing was changed; try again.');
        p202_account_redirect('202-account/account.php#api-keys');
    }
    if ($replaced === null) {
        p202_account_flash('warn', 'That API key was not found on this account; nothing was replaced.');
        p202_account_redirect('202-account/account.php#api-keys');
    }

    if ($slack) {
        $slack->push('user_added_app_api_key', ['user' => $username]);
    }

    $_SESSION['p202_new_api_key'] = $replacementKey;
    p202_account_flash('ok', 'Replacement key created, with the same access. Copy it now: it is not shown again.'
        . ' The old key keeps working until ' . date('M j, Y H:i', $replaced['ends_at'])
        . ', so you have time to switch; revoke it to end it sooner.');
    p202_account_redirect('202-account/account.php#api-keys');
}

if (isset($_POST['stop_ai_watcher'])) {
    if (!AUTH::check_csrf_token()) {
        p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
        p202_account_redirect('202-account/account.php#api-keys');
    }
    $stopped = p202_account_stop_ai_watcher(
        $db,
        (int)$_SESSION['user_id'],
        (string)($_POST['api_key_ref'] ?? ''),
        (string)($_POST['watcher_id'] ?? ''),
        new \Api\V3\Support\AiWatchers()
    );
    p202_account_flash($stopped['kind'], $stopped['text']);
	p202_account_redirect('202-account/account.php#api-keys');
}

// The customer-dashboard hand-back: my.tracking202.com returns the person
// here with their key base64'd in the query string. A GET must not change
// state: the dashboard cannot carry our session token, so writing here let
// any site <img src="...account.php?customers_api_key=..."> rewrite the
// account's key. The key is decoded and held; the customer-key form below is
// filled with it, and the person saves it through update_p202_customer_api_key,
// which checks the token and validates the key with the dashboard first.
$pendingCustomerApiKey = null;
if (!empty($_GET['customers_api_key'])) {
	$decodedCustomerApiKey = base64_decode((string) $_GET['customers_api_key'], true);
	if ($decodedCustomerApiKey === false || trim($decodedCustomerApiKey) === '') {
		$keyErrors['p202_customer_api_key'] = 'That API key link was malformed. Copy your key from my.tracking202.com and paste it in the field below.';
	} else {
		$pendingCustomerApiKey = trim($decodedCustomerApiKey);
	}
}

//if they want to remove their stats202 app key on file, do so
if (!empty($_GET['remove_user_stats202_app_key'])) {
	if (!AUTH::csrf_token_matches($_REQUEST['token'] ?? null)) {
		http_response_code(403);
		die('Invalid token.');
	}
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$sql = "UPDATE 202_users SET user_stats202_app_key='' WHERE user_id='" . $mysql['user_id'] . "'";
	if (!_mysqli_query($db, $sql)) {
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
	if (!AUTH::csrf_token_matches($_REQUEST['token'] ?? null)) {
		http_response_code(403);
		die('Invalid token.');
	}
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$sql = "UPDATE 202_users SET user_api_key='' WHERE user_id='" . $mysql['user_id'] . "'";
	if (!_mysqli_query($db, $sql)) {
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

$user_result = _mysqli_query($db, $user_sql);
if ($user_result === false) {
    record_mysql_error($user_sql);
}
$user_row = p202FetchRow($user_result, $user_sql) ?? [];
$currentUserEmail = isset($user_row['user_email']) ? (string)$user_row['user_email'] : '';

/** The choices each preference offers, value => label; the form renders these and the handler admits only these. */
$keywordChoices = ['searched' => 'Pickup Searched Keyword', 'bidded' => 'Pickup Bidded Keyword'];
$bidChoices = ['0' => 'Pickup Bid from setup data', '1' => 'Pickup Bid dynamically from t202b variable'];
$refererChoices = ['browser' => 'Pickup Referer from browser', 't202ref' => 'Pickup Referer from t202ref variable'];
$privacyChoices = ['disabled' => 'Disabled', 'eu' => 'Enabled for European Traffic', 'all' => 'Enabled for All Traffic'];
$privacyHelp = 'Which visitors get no tracking cookies and a masked address. European traffic is anyone'
    . ' the location lookup does not place outside Europe.';
$sameIpChoices = ['0' => 'Off: only a subid or a cookie finds the click', '1' => 'On: guess the click from the IP address'];
$sameIpHelp = 'When a pixel names no click, credit your latest click from the visitor\'s IP address in the last 30'
    . ' days. A guess: on iPhones (iCloud Private Relay) and mobile networks many visitors share an address. Never'
    . ' for a visitor your privacy setting holds back. Conversions it finds read Same IP.';
$clickIdParamsHelp = 'For a network that cannot send the SubID back. Pass it the ad click id instead (the gclid, say)'
    . ' and name here the postback parameter it returns it in, such as aff_sub3. When a postback\'s SubID is empty,'
    . ' the conversion goes to your click carrying that id. A postback sending gclid, gbraid, wbraid, msclkid,'
    . ' fbclid or ttclid under its own name needs nothing here. Conversions it finds read Ad click id.';
$cloakChoices = ['origin' => 'Show Prosper202 Domain', 'never' => 'Show Blank Referer'];
$adChoices = ['show_all' => 'Show All Ads', 'hide_login' => 'Hide Ads On Login Screen', 'hide_all' => 'Hide All Ads'];

// The Data & communication boxes, for every user whatever their permissions:
// analytics is recorded under the signed-in user, who may always revoke it.
// A box left as the page showed it records nothing. False when one failed.
$p202RecordConsentChoices = static function () use ($db): bool {
	if (empty($_POST['consent_form'])) {
		return true;
	}
	$saved = true;
	$consentUserId = (int) $_SESSION['user_id'];
	foreach (['analytics' => 'analytics_consent', 'email_marketing' => 'email_marketing_consent'] as $consentFlag => $consentField) {
		$consentNow = !empty($_POST[$consentField]);
		if ($consentNow === (($_POST[$consentField . '_shown'] ?? '') === '1')) {
			continue;
		}
		if (!ConsentPolicy::record($db, $consentUserId, $consentFlag, $consentNow ? 'granted' : 'denied', 'settings')) {
			error_log('[Consent] failed to record ' . $consentFlag . ' consent for user ' . $consentUserId);
			$saved = false;
		}
	}
	return $saved;
};

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	$submittedEmail = isset($_POST['user_email']) ? trim((string)$_POST['user_email']) : $currentUserEmail;

	if (isset($_POST['update_profile']) && $_POST['update_profile'] == '1') {
		$originalUserEmail = $currentUserEmail;
		$emailUpdated = false;

		if (!AUTH::check_csrf_token()) {
			p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
			p202_account_redirect('202-account/account.php');
		}
		// The consent choices stand on their own: recorded whether or not the
		// rest of the form is valid or saves, so a revocation is never dropped.
		$consentSaved = $p202RecordConsentChoices();
		$consentRefused = 'Your Data & communication choice could not be saved. Try again.';
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
				$emailInUse = _mysqli_query($db, $count_sql);
				if ($emailInUse === false) {
					$error['user_email'] = 'Your email could not be checked; try again.';
				} elseif ($emailInUse->num_rows > 0) {
					$error['user_email'] = 'That email address is already being used.';
				}
			}

			// Each preference must be one of the choices the form offers. The
			// classic page stored whatever arrived; a value outside the list
			// is refused by name rather than written.
			$postedTimezone = (string)($_POST['user_timezone'] ?? '');
			if (!\Prosper202\Report\AccountZone::isZone($postedTimezone)) {
				$error['user_timezone'] = 'Choose a time zone from the list.';
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
			if (!array_key_exists((string)($_POST['user_pref_same_ip_fallback'] ?? ''), $sameIpChoices)) {
				$error['user_pref_same_ip_fallback'] = 'Choose whether a pixel may guess the click from the IP address.';
			}
            // Only when the form sent it: a page loaded before the field existed clears nothing.
            $clickIdParams = null;
            if (isset($_POST['user_pref_click_id_params'])) {
                try {
                    $clickIdParams = \Prosper202\Conversion\ClickIdParameters::accepted(
                        $_POST['user_pref_click_id_params']
                    );
                } catch (\InvalidArgumentException $clickIdParamsRefused) {
                    $error['user_pref_click_id_params'] = $clickIdParamsRefused->getMessage();
                }
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
					'user_pref_same_ip_fallback' => (string)$_POST['user_pref_same_ip_fallback'] === '1' ? 1 : 0,
				];
                if ($clickIdParams !== null) {
                    $prefSet['user_pref_click_id_params'] = $clickIdParams;
                }

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
					$pageFlashes[] = ['kind' => 'bad', 'text' => 'Your settings could not be saved. Nothing you see below has changed'
						. ($consentSaved && !empty($_POST['consent_form']) ? ' apart from your Data & communication choice' : '') . '; try again.'];
				} else {
					$_SESSION['user_pref_ad_settings'] = $mysql['user_pref_ad_settings'];
					//set the  session's user_timezone
					$_SESSION['user_timezone'] = $postedTimezone;
					//try to set non expiring cache for values that are used in redirects
					if (!empty($memcacheWorking)) {
						$tid = $mysql['user_id'];
						setCache(md5('user_id_' . $tid . systemHash()), $mysql['user_id'], 0);
						setCache(md5('user_timezone_' . $tid . systemHash()), $mysql['user_timezone'], 0);
						setCache(md5('user_keyword_searched_or_bidded_' . $tid . systemHash()), $mysql['user_keyword_searched_or_bidded'], 0);
						setCache(md5('user_referer_' . $tid . systemHash()), $mysql['user_referer'], 0);
						setCache(md5('cloak_referer_' . $tid . systemHash()), $mysql['cloak_referer'], 0);
						setCache(md5('user_pref_dynamic_bid_' . $tid . systemHash()), $mysql['user_pref_dynamic_bid'], 0);
						// Not the privacy setting: connect2.php reads it from its row.
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

					if ($consentSaved) {
						p202_account_flash('ok', 'Your settings are saved.');
					} else {
						p202_account_flash('bad', 'Your other settings are saved, but your Data & communication choice could not be. Try again.');
					}
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
				$ownEmailInUse = _mysqli_query($db, $count_sql);
				if ($ownEmailInUse === false) {
					$error['user_email'] = 'Your email could not be checked; try again.';
				} elseif ($ownEmailInUse->num_rows > 0) {
					$error['user_email'] = 'That email address is already being used.';
				}

				if (!$error) {
					$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
					$mysql['user_email'] = $db->real_escape_string($submittedEmail);
					$sql = "UPDATE 202_users SET user_email = '" . $mysql['user_email'] . "' WHERE user_id = '" . $mysql['user_id'] . "'";
					if (!_mysqli_query($db, $sql)) {
						p202_account_flash('bad', 'Your email could not be saved; try again.');
					} else {
						if ($slack && $submittedEmail !== $currentUserEmail) {
							$slack->push('user_email_changed', ['user' => $username, 'old_email' => $currentUserEmail, 'new_email' => $submittedEmail]);
						}
						if ($consentSaved) {
							p202_account_flash('ok', 'Your settings are saved.');
						} else {
							p202_account_flash('bad', 'Your email is saved, but your Data & communication choice could not be. Try again.');
						}
						p202_account_redirect('202-account/account.php');
					}
				}
			}
		}

		// Refused: say it under the field, and keep what was typed. The consent
		// choice was recorded (or not) above, and the page says which.
		if (!$consentSaved) {
			$pageFlashes[] = ['kind' => 'bad', 'text' => $consentRefused];
		}
		$profileErrors = $error;
		foreach (['user_email', 'user_timezone', 'user_keyword_searched_or_bidded', 'user_bid', 'user_referer', 'user_pref_privacy', 'user_pref_same_ip_fallback', 'user_pref_click_id_params', 'cloak_referer', 'user_pref_ad_settings', 'user_tracking_domain'] as $profileField) {
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
				static fn (string $campaignCurrency, string $payout): mixed => getForeignPayout($campaignCurrency, $postedCurrency, $payout)
			);
			$currencySaved = true;
		} catch (Throwable $failed) {
			error_log('account.php: the account currency was not saved: ' . $failed->getMessage());
		}

		if ($currencySaved) {
			$previousCurrency = \Api\V3\Controllers\UsersController::normalizeCurrency($user_row['user_account_currency'] ?? null);
			if ($previousCurrency !== $postedCurrency) {
				// The rates the campaigns' postback amounts are converted with, now rather than at the cron job's next run.
				\Prosper202\Conversion\Ledger\ExchangeRateCache::refreshQuietly(
					new \Prosper202\Database\Connection($db),
					static fn (string $from, string $to, string $amount): ?string => \Prosper202\User\ExchangeRates::convert($from, $to, $amount, 10)
				);
			}
			p202_account_flash('ok', $previousCurrency === $postedCurrency
				? 'Account currency saved.'
				: 'Account currency saved. New conversions are recorded in ' . $postedCurrency . '. The conversions already recorded stay in '
					. $previousCurrency . ', unconverted: reports say how much of a period is in each currency.');
			p202_account_redirect('202-account/account.php#currency');
		}
		$pageFlashes[] = ['kind' => 'bad', 'text' => 'The account currency could not be saved, and no campaign was re-priced; try again.'];
	}
}

// Conversions recorded before 1.9.80 kept no currency of their own: the upgrade
// labelled them with the account's currency then, or left them unrecorded when
// it could not tell (RowCurrency). The owner says which currency those before a
// date were in; a conversion recorded with its currency is never changed.
if (!empty($_POST['record_earlier_currency']) && $_POST['record_earlier_currency'] == '1') {
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/account.php#currency');
	}
	$earlierCurrency = (string) ($_POST['earlier_currency'] ?? '');
	$earlierZone = new DateTimeZone(\Prosper202\Report\AccountZone::normalize((string) ($user_row['user_timezone'] ?? '')));
	// A day's midnight in the account's zone (CLAUDE.md #26).
	$earlierDay = static function (string $field) use ($earlierZone): ?int {
		$day = trim((string) ($_POST[$field] ?? ''));
		$at = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $day) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $day, $earlierZone) : false;
		return $at !== false && $at->format('Y-m-d') === $day ? $at->getTimestamp() : null;
	};
	$earlierBefore = $earlierDay('earlier_before');
	$earlierSinceGiven = trim((string) ($_POST['earlier_since'] ?? '')) !== '';
	$earlierSince = $earlierSinceGiven ? $earlierDay('earlier_since') : null;
	if (!in_array($earlierCurrency, \Api\V3\Controllers\UsersController::SUPPORTED_CURRENCIES, true)) {
		$keyErrors['earlier_currency'] = 'Choose a currency from the list.';
	}
	if ($earlierBefore === null) {
		$keyErrors['earlier_before'] = 'Enter the date of the change, as YYYY-MM-DD.';
	}
	if ($earlierSinceGiven && $earlierSince === null) {
		$keyErrors['earlier_since'] = 'Enter a date as YYYY-MM-DD, or leave it empty.';
	} elseif ($earlierSince !== null && $earlierBefore !== null && $earlierSince >= $earlierBefore) {
		$keyErrors['earlier_since'] = 'This date must be before the date of the change.';
	}
	if (!isset($keyErrors['earlier_currency']) && !isset($keyErrors['earlier_before']) && !isset($keyErrors['earlier_since'])) {
		try {
			$recorded = \Prosper202\Conversion\Ledger\RowCurrency::recordBefore(
				new \Prosper202\Database\Connection($db), (int) $_SESSION['user_id'], $earlierCurrency, $earlierBefore, $earlierSince
			);
			// What was held back from the ad platforms for want of a currency goes out at the worker's next run.
			(new \Prosper202\Destinations\DestinationQueue(new \Prosper202\Database\Connection($db)))
				->releaseHeldForCurrency((int) $_SESSION['user_id'], time());
			p202_account_flash($recorded['left'] === 0 ? 'ok' : 'warn', ($recorded['stamped'] === 0
				? 'No conversion changed: every one in that range was already recorded in ' . $earlierCurrency . ', or was recorded with its own currency.'
				: number_format($recorded['stamped']) . ' earlier conversion' . ($recorded['stamped'] === 1 ? ' is' : 's are')
					. ' now recorded in ' . $earlierCurrency . '.')
				. ($recorded['left'] > 0 ? ' ' . number_format($recorded['left']) . ' are still to do: save again to continue.' : ''));
			p202_account_redirect('202-account/account.php#currency');
		} catch (Throwable $failed) {
			error_log('account.php: the earlier conversions were not given a currency: ' . $failed->getMessage());
			$pageFlashes[] = ['kind' => 'bad', 'text' => 'The earlier conversions could not be updated; try again.'];
		}
	}
}

// The campaigns the 1.9.80 upgrade found paid in another currency, whose
// postback amounts are converted from it since (User\CampaignCurrencyNotice):
// switched to the account's currency, or kept, on the owner's word.
if (isset($_POST['campaign_currency_notice'])) {
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/account.php#currency');
	}
	$noticeAnswer = (string) $_POST['campaign_currency_notice'];
	if ($noticeAnswer !== 'switch' && $noticeAnswer !== 'keep') {
		$pageFlashes[] = ['kind' => 'bad', 'text' => 'Choose whether to switch those campaigns to your account\'s currency or keep them as they are.'];
	} else {
		try {
			$noticeConn = new \Prosper202\Database\Connection($db);
			$noticeAccount = \Api\V3\Controllers\UsersController::normalizeCurrency($user_row['user_account_currency'] ?? null);
			if ($noticeAnswer === 'switch') {
				$switched = \Prosper202\User\CampaignCurrencyNotice::switchToAccount($noticeConn, (int) $_SESSION['user_id'], $noticeAccount);
				p202_account_flash('ok', number_format($switched) . ' campaign' . ($switched === 1 ? ' is' : 's are') . ' now paid in '
					. $noticeAccount . ': their postback amounts are recorded as sent, and their payouts stay as shown.');
			} else {
				\Prosper202\User\CampaignCurrencyNotice::keep($noticeConn, (int) $_SESSION['user_id']);
				p202_account_flash('ok', 'Those campaigns keep their currency: their postback amounts are converted into '
					. $noticeAccount . '. Change one under Setup › Campaigns at any time.');
			}
			p202_account_redirect('202-account/account.php#currency');
		} catch (Throwable $failed) {
			error_log('account.php: the campaign currency notice was not answered: ' . $failed->getMessage());
			$pageFlashes[] = ['kind' => 'bad', 'text' => 'Those campaigns could not be updated; nothing was changed. Try again.'];
		}
	}
}

// ─── Click filtering ─────────────────────────────────────────────────
// The card's forms (functions-click-filter-ui.php): saved and redirected, or refused in place.
$clickFilterPost = $canPersonal ? p202_click_filter_post($db, (int) $_SESSION['user_id'], $_POST) : ['errors' => [], 'form' => [], 'flashes' => []];
$pageFlashes = array_merge($pageFlashes, $clickFilterPost['flashes']);

// ─── Money-loss alerts ───────────────────────────────────────────────
// The card's form (functions-alerts-ui.php): saved and redirected, or refused in place.
$alertsPost = $canPersonal
    ? p202_alerts_post($db, (int) $_SESSION['user_id'], $_POST)
    : ['errors' => [], 'form' => [], 'flashes' => []];
$pageFlashes = array_merge($pageFlashes, $alertsPost['flashes']);

if (!empty($_POST['update_clickserver_api_key']) && $_POST['update_clickserver_api_key'] == '1') {

	// The ClickServers page's empty state posts here and asks to go back
	// there; only that one page is accepted as a destination.
	$clickserverReturn = ($_POST['return_to'] ?? '') === 'clickservers' ? '202-account/clickservers.php' : '202-account/account.php';
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect($clickserverReturn);
	}

	$mysql['clickserver_api_key'] = $db->real_escape_string($csKey = trim((string) $_POST['clickserver_api_key']));

	if (!preg_match('/\*/', (string) $_POST['clickserver_api_key'])) {
		if (($csKeyError = p202_check_clickserver_key($csKey)) !== null) {
			$keyErrors['clickserver_api_key'] = $csKeyError;
		}

		if (!$keyErrors || $mysql['clickserver_api_key'] == '') {

			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
			$mysql['clickserver_api_key'] = $db->real_escape_string($csKey);
			$user_sql = "	UPDATE 	`202_users`
								SET     		`clickserver_api_key`='" . $mysql['clickserver_api_key'] . "'
								WHERE  	`user_id`='" . $mysql['user_id'] . "'";
			$clickserverKeySaved = _mysqli_query($db, $user_sql);
			if (!$clickserverKeySaved) {
				p202_account_flash('bad', 'The ClickServer API key could not be saved; try again.');
			} else {
				if ($slack && $csKey != ($user_row['clickserver_api_key'] ?? '')) {
					$slack->push('user_updated_clickserver_api_key', ['user' => $username]);
				}
				p202_account_flash('ok', 'You have updated your Prosper202 ClickServer API Key.');
				p202_account_redirect($clickserverReturn);
			}
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

	if (!preg_match('/\*/', $typedKey = trim((string) $_POST['user_api_key']))) {
		if (($typedKeyError = p202_check_typed_key($typedKey)) !== null) {
			$keyErrors['user_api_key'] = $typedKeyError;
		}

		if (!$keyErrors) {

			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
			$mysql['user_api_key'] = $db->real_escape_string($typedKey);
			$user_sql = "	UPDATE 	`202_users`
								SET     		`user_api_key`='" . $mysql['user_api_key'] . "'
								WHERE  	`user_id`='" . $mysql['user_id'] . "'";
			$apiKeySaved = _mysqli_query($db, $user_sql);

			// Only a write that landed changes the session and says so: the
			// session key is the one used against Tracking202 from here on,
			// and a flash saying "updated" over an unchanged row is #1.
			if (!$apiKeySaved) {
				error_log('account.php: the Tracking202 API key was not saved: ' . $db->error);
				p202_account_flash('bad', 'The Tracking202 API key could not be saved; try again.');
			} else {
				$_SESSION['user_api_key'] = $typedKey;
				$_SESSION['user_cirrus_link'] = $typedKey;

				p202_account_flash('ok', 'You have updated your Tracking202 API Key.');
				p202_account_redirect('202-account/account.php');
			}
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
			$statsKeySaved = _mysqli_query($db, $user_sql);

			if (!$statsKeySaved) {
				error_log('account.php: the Stats202 app key was not saved: ' . $db->error);
				p202_account_flash('bad', 'The Stats202 App Key could not be saved; try again.');
			} else {
				$_SESSION['user_stats202_app_key'] = $_POST['user_stats202_app_key'];

				p202_account_flash('ok', 'You have updated your Stats202 App Key.');
				p202_account_redirect('202-account/account.php');
			}
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
		if (($customerKeyError = p202_check_customer_key($postedCustomerKey)) !== null) {
			$keyErrors['p202_customer_api_key'] = $customerKeyError;
		}
	}
	if (!isset($keyErrors['p202_customer_api_key'])) {
		if (!_mysqli_query($db, "UPDATE 202_users SET p202_customer_api_key = '" . $mysql['p202_customer_api_key'] . "' WHERE user_id = '" . $mysql['user_id'] . "'")) {
			p202_account_flash('bad', 'Your Prosper202 customer API key could not be saved; try again.');
		} else {
			p202_account_flash('ok', $postedCustomerKey === '' ? 'Your Prosper202 customer API key was removed.' : 'Your Prosper202 customer API key is saved.');
			p202_account_redirect('202-account/account.php#customer-key');
		}
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
		$stored = null;
		$verified = false;
        try {
            $passConn = new \Prosper202\Database\Connection($db);
            $verify_stmt = $passConn->prepareWrite('SELECT user_pass FROM 202_users WHERE user_id = ? LIMIT 1');
            $passConn->bind($verify_stmt, 'i', [(int) ($_SESSION['user_own_id'] ?? 0)]);
            $stored = $passConn->fetchOne($verify_stmt);
            $verified = true;
        } catch (\Prosper202\Database\Exceptions\QueryException | \mysqli_sql_exception) {
            // Said below; not counted as a wrong password.
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
				// As the sign-out: this browser's remember-me key goes too.
				AUTH::signOut();
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
		$passSaved = false;
        $passError = '';
        $generation = null;
        try {
            // The password, every remember-me key of the user (this
            // browser's too: a stolen cookie is a copy of one) and every
            // session of theirs, in one transaction.
            $generation = \Prosper202\User\PasswordChange::store(
                new \Prosper202\Database\Connection($db),
                (int) ($_SESSION['user_own_id'] ?? 0),
                $new_hash,
                false
            );
            $passSaved = true;
        } catch (\RuntimeException | \mysqli_sql_exception $e) {
            $passError = p202FailedQueryMessage(0, $e->getMessage());
        }
		if (!$passSaved) {
            prosper_log('account', 'Failed to update password: ' . $passError);
			$pageFlashes[] = ['kind' => 'bad', 'text' => 'Your password could not be changed. Your old password still works; try again.'];
		} else {
			// This session goes on, at the generation the change made; every
			// other one ends at its next request. Its key is gone with the
			// rest: the cookie would only be refused.
			AUTH::keepSessionAfterPasswordChange((int) $generation);
			AUTH::forgetRememberMeCookie();
			p202_account_flash('ok', 'Your password is changed. Every other browser signed in as you is signed out, and needs it to sign in again; this one stays signed in.');
			p202_account_redirect('202-account/account.php#password');
		}
	}
}

//update new values from the db
$user_sql = "	SELECT 	*
				 FROM   	`202_users`
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='" . $mysql['user_id'] . "'";
$user_result = _mysqli_query($db, $user_sql);
if ($user_result === false) {
    record_mysql_error($user_sql);
}
$user_row = p202FetchRow($user_result, $user_sql) ?? [];

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
/** The stored value, or what the person typed on a refused submit. */
$profileValue = static function (string $field, string $column) use ($profileForm, $user_row): string {
	return array_key_exists($field, $profileForm) ? $profileForm[$field] : (string)($user_row[$column] ?? '');
};
$clickIdParamsValue = $profileValue('user_pref_click_id_params', 'user_pref_click_id_params');
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

// Each AI connection's watchers, from Tracking202's MCP server; no AI key, no call.
$aiWatchers = p202_account_ai_watchers($apiKeys, new \Api\V3\Support\AiWatchers());
$watchedCampaigns = [];
foreach ($aiWatchers as $keyWatchers) {
    foreach ($keyWatchers ?? [] as $watcher) {
        $watchedCampaigns[] = (int)$watcher['campaignId'];
    }
}
try {
    $campaignNames = p202_account_campaign_names($db, (int)$_SESSION['user_id'], $watchedCampaigns);
} catch (Throwable $namesUnreadable) {
    error_log('Account page: watched campaigns could not be read: ' . $namesUnreadable->getMessage());
    $campaignNames = [];
}

// The click filter as stored, and an address test asked of it.
$clickFilterView = $canPersonal ? p202_click_filter_view($db, (int) $_SESSION['user_id'], $_GET) : null;
$pageFlashes = array_merge($pageFlashes, $clickFilterView['flashes'] ?? []);

// The money-loss alerts as stored, and where they would go.
$alertsView = $canPersonal ? p202_alerts_view($db, (int) $_SESSION['user_id']) : null;
$pageFlashes = array_merge($pageFlashes, $alertsView['flashes'] ?? []);

/** A stored key as its prefix around a mask: only its hash is kept (ApiKeyStore). */
$maskKey = static fn (string $prefix): string => $prefix . str_repeat("\u{2022}", 20);
// The key created on the request before this one: shown once, then forgotten.
$keysAt = get_absolute_url() . '202-account/account.php#api-keys';
$replaceConfirm = 'Replace this API key? A new key with the same access is made and shown once.'
    . ' This key keeps working for 24 hours, so you can switch without an outage.';
$keyUseNote = $apiKeys && ($apiKeys[0]['use_recorded'] ?? false)
    ? ' A key\'s last use is recorded from version 1.9.80 on, to within five minutes.'
    : '';
$newApiKey = $canPersonal && is_string($_SESSION['p202_new_api_key'] ?? null) ? $_SESSION['p202_new_api_key'] : '';
unset($_SESSION['p202_new_api_key']);
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
// Ledger rows the upgrade could give no currency (RowCurrency), and the
// account's recorded changes of currency: the Currency panel shows both.
$unrecordedConversions = $canPersonal
	? \Prosper202\Conversion\Ledger\RowCurrency::unrecorded(new \Prosper202\Database\Connection($db), (int) $_SESSION['user_id'])
	: 0;
$currencyChanges = $canPersonal
	? \Prosper202\User\CurrencyChange::history(new \Prosper202\Database\Connection($db), (int) $_SESSION['user_id'])
	: [];
$campaignCurrencyNotice = $canPersonal
	? \Prosper202\User\CampaignCurrencyNotice::listed(
		new \Prosper202\Database\Connection($db),
		(int) $_SESSION['user_id'],
		(string) ($user_row['user_account_currency'] ?? '')
	)
	: ['campaigns' => [], 'more' => 0];
$currencyChangeZone = new DateTimeZone(\Prosper202\Report\AccountZone::normalize((string) ($user_row['user_timezone'] ?? '')));
$currentTimezone = $profileValue('user_timezone', 'user_timezone');
// The select offers PHP's list, which leaves out the backward-compatible
// names a renamed zone keeps (Europe/Kiev, Europe/Kyiv since 2022). An
// account holding one had no option selected, so the browser sent the first
// in the list and saving Personal settings for any other reason moved the
// account to Africa/Abidjan. Its own zone is offered first, selected; a
// stored value that is no zone is UTC on every report, and shows as UTC.
$timezoneOptions = DateTimeZone::listIdentifiers();
if (!\Prosper202\Report\AccountZone::isZone($currentTimezone)) {
	$currentTimezone = 'UTC';
} elseif (!in_array($currentTimezone, $timezoneOptions, true)) {
	array_unshift($timezoneOptions, $currentTimezone);
}

$profileAdvancedErrors = array_intersect_key($profileErrors, array_flip(['user_keyword_searched_or_bidded', 'user_bid', 'user_referer', 'user_pref_privacy', 'user_pref_same_ip_fallback', 'user_pref_click_id_params', 'cloak_referer', 'user_pref_ad_settings', 'user_tracking_domain']));

template_top('Personal Settings');
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
        <a class="nav-link" href="#click-filtering">Click filtering</a>
        <a class="nav-link" href="#money-loss-alerts">Money-loss alerts</a>
		<a class="nav-link" href="#currency">Currency</a>
		<a class="nav-link" href="#api-keys">API keys</a>
		<a class="nav-link" href="#customer-key">Customer key</a>
		<a class="nav-link" href="#password">Password</a>
	</nav>
<?php } ?>

<?php
$extraFlashes = $pageFlashes;
if ($pendingCustomerApiKey !== null) {
	$extraFlashes[] = ['kind' => 'info', 'text' => 'Your customer API key from my.tracking202.com is filled in under Prosper202 customer API key below. Press Save key to connect it to this account; nothing is saved until you do.'];
}
foreach (['clickserver_api_key', 'user_api_key', 'user_stats202_app_key'] as $keyField) {
	if (isset($keyErrors[$keyField])) {
		$extraFlashes[] = ['kind' => 'bad', 'text' => $keyErrors[$keyField]];
	}
}
if ($profileErrors) {
    // A box under Advanced is far below where the page lands: its reason is said up here too.
    $clickIdParamsRefusal = $profileErrors['user_pref_click_id_params'] ?? '';
    $profileRefusal = 'Your settings were not saved. ';
    if ($clickIdParamsRefusal === '') {
        $profileRefusal .= 'The fields below say why.';
    } else {
        $profileRefusal .= 'Match conversions by ad click id, under Advanced: ' . $clickIdParamsRefusal
            . (count($profileErrors) > 1 ? '. The other fields below say why too.' : '.');
    }
    $extraFlashes[] = ['kind' => 'bad', 'text' => $profileRefusal];
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
						<div class="form-text">Password resets go here.</div>
						<?php echo p202_account_field_error($profileErrors, 'user_email'); ?>
					</div>

					<?php if ($canPersonal) { ?>
						<div class="row g-3 mb-3">
							<div class="col-md-7">
								<label class="form-label" for="user_timezone">Time zone <span class="text-danger">*</span></label>
								<select class="form-select<?php echo p202_account_invalid($profileErrors, 'user_timezone'); ?>" name="user_timezone" id="user_timezone">
									<?php
									foreach ($timezoneOptions as $tz) {
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
						</div>

						<details class="p202-disclosure mb-3" data-p202-remember="account-profile-advanced"<?php echo $profileAdvancedErrors ? ' open' : ''; ?>>
							<summary>Advanced <span class="p202-disclosure__hint">keyword, cost, referer, privacy, IP matching, ad click ids, ads, tracking domain</span></summary>
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
										<div class="form-text"><?php echo $e($privacyHelp); ?></div>
										<?php echo p202_account_field_error($profileErrors, 'user_pref_privacy'); ?>
									</div>
									<div class="col-md-6">
										<label class="form-label" for="user_pref_same_ip_fallback">Match conversions by IP address</label>
										<select class="form-select<?php echo p202_account_invalid($profileErrors, 'user_pref_same_ip_fallback'); ?>" name="user_pref_same_ip_fallback" id="user_pref_same_ip_fallback">
											<?php echo $renderOptions($sameIpChoices, $profileValue('user_pref_same_ip_fallback', 'user_pref_same_ip_fallback')); ?>
										</select>
										<div class="form-text"><?php echo $e($sameIpHelp); ?></div>
										<?php echo p202_account_field_error($profileErrors, 'user_pref_same_ip_fallback'); ?>
									</div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="user_pref_click_id_params">
                                            Match conversions by ad click id
                                        </label>
                                        <input type="text" id="user_pref_click_id_params"
                                            name="user_pref_click_id_params" placeholder="aff_sub3" maxlength="255"
                                            class="form-control<?php
                                            echo p202_account_invalid($profileErrors, 'user_pref_click_id_params');
                                            ?>"
                                            value="<?php echo $e($clickIdParamsValue); ?>">
                                        <div class="form-text"><?php echo $e($clickIdParamsHelp); ?></div>
                                        <?php
                                        echo p202_account_field_error($profileErrors, 'user_pref_click_id_params');
                                        ?>
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
										<input type="text" class="form-control<?php echo p202_account_invalid($profileErrors, 'user_tracking_domain'); ?>" id="user_tracking_domain" name="user_tracking_domain" placeholder="<?php echo $e(\Prosper202\Http\RequestHost::fromServer($_SERVER) ?? ''); ?>" value="<?php echo $e($profileValue('user_tracking_domain', 'user_tracking_domain')); ?>">
										<div class="form-text">Leave empty to build tracking links on this install's own domain. Start it with https:// or http:// to choose the links' scheme; without one they use the scheme you reach Prosper202 on.</div>
										<?php echo p202_account_field_error($profileErrors, 'user_tracking_domain'); ?>
									</div>
								</div>
							</div>
						</details>
					<?php } ?>

						<?php
						// Every user may change these, whatever their permissions.
						// State comes from ConsentPolicy (the only consent reader), so
						// 'unset' shows the effective default rather than the raw column.
						$analyticsChecked = ConsentPolicy::analyticsAllowed($db, (int) $_SESSION['user_id']) ? ' checked' : '';
						$emailChecked = ConsentPolicy::emailMarketingAllowed($db, (int) $_SESSION['user_id']) ? ' checked' : '';
						?>
						<fieldset class="mb-3" id="data-consent">
							<legend class="form-label">Data &amp; communication</legend>
							<input type="hidden" name="consent_form" value="1">
							<input type="hidden" name="analytics_consent_shown" value="<?php echo $analyticsChecked !== '' ? '1' : '0'; ?>">
							<input type="hidden" name="email_marketing_consent_shown" value="<?php echo $emailChecked !== '' ? '1' : '0'; ?>">
							<div class="form-check">
								<input class="form-check-input" type="checkbox" name="analytics_consent" id="analytics_consent" value="1"<?php echo $analyticsChecked; ?>>
								<label class="form-check-label" for="analytics_consent"><strong>Product analytics &amp; personalized help</strong>: lets us surface workflow tips and match
									you with specially-sourced, higher-paying offers relevant to what you promote. Your usage data,
									including traffic stats, revenue numbers, and campaign names and destination links, is sent to
									Prosper202. We never share it with third parties. <a href="<?php echo get_absolute_url(); ?>202-account/disclosure.php" target="_blank" rel="noopener">Learn more</a>.</label>
							</div>
							<div class="form-check">
								<input class="form-check-input" type="checkbox" name="email_marketing_consent" id="email_marketing_consent" value="1"<?php echo $emailChecked; ?>>
								<label class="form-check-label" for="email_marketing_consent"><strong>Money-making offers &amp; tips by email</strong>: occasional emails about higher-paying
									offers and workflow improvements. You can unsubscribe anytime.</label>
							</div>
						</fieldset>

					<div class="p202-form-actions">
						<button class="btn btn-primary" type="submit">Save settings</button>
					</div>
				</form>
			</div>
		</section>

        <?php if ($clickFilterView !== null && $clickFilterView['filter'] !== null) {
            echo p202_click_filter_card($clickFilterView['filter'], $clickFilterView['test'], $clickFilterView['errors'] + $clickFilterPost['errors'], $clickFilterPost['form'], is_string($_GET['test_ip'] ?? null) ? $_GET['test_ip'] : '');
        } ?>

        <?php if ($alertsView !== null && $alertsView['settings'] !== null) {
            echo p202_alerts_card(
                $alertsView['settings'],
                $alertsView['contact'],
                $alertsPost['errors'],
                $alertsPost['form'],
                $alertsView['recent'],
                $alertsView['links']
            );
        } ?>

		<?php if ($canPersonal) { ?>
			<section class="p202-panel mt-4" id="currency">
				<div class="p202-panel__head">
					<h2 class="p202-panel__title">Account currency</h2>
					<span class="p202-panel__sub">the currency new conversions are recorded in and totals are shown in</span>
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
						<div class="form-text">Changing it converts your campaign payouts to the new currency (a paid feature). Conversions already recorded are not converted: each keeps the currency it was recorded in, new ones are recorded in the new currency, and reports say how much of a period is in each.</div>
						<?php echo p202_account_field_error($keyErrors, 'account_currency'); ?>
					</form>
					<?php if ($campaignCurrencyNotice['campaigns'] !== []) {
						$noticeShown = $campaignCurrencyNotice['campaigns'];
						$noticeCount = count($noticeShown) + $campaignCurrencyNotice['more'];
						$noticeAccount = \Api\V3\Controllers\UsersController::normalizeCurrency($user_row['user_account_currency'] ?? null); ?>
					<div class="alert alert-warning p202-flash mt-3" role="status" id="campaign-currency-notice"><i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body">
						<p class="mb-2"><strong><?php echo $e(number_format($noticeCount)); ?> campaign<?php echo $noticeCount === 1 ? ' is' : 's are'; ?> paid in another currency than your account's.</strong>
							A postback amount on <?php echo $noticeCount === 1 ? 'it' : 'them'; ?> is converted from that currency into <?php echo $e($noticeAccount); ?>, as paid 1.9.56 did (1.9.57 to 1.9.79 recorded it as sent).
							If a change of your account's currency left <?php echo $noticeCount === 1 ? 'it' : 'them'; ?> so and the network<?php echo $noticeCount === 1 ? ' reports' : 's report'; ?> in <?php echo $e($noticeAccount); ?> now, switch <?php echo $noticeCount === 1 ? 'it' : 'them'; ?>.</p>
						<p class="mb-2"><?php echo $e(implode(', ', array_map(static fn (array $c): string => $c['name'] . ' (' . $c['currency'] . ')', $noticeShown))
							. ($campaignCurrencyNotice['more'] > 0 ? ', and ' . number_format($campaignCurrencyNotice['more']) . ' more' : '') . '.'); ?></p>
						<form method="post" class="d-inline" action="<?php echo $e(get_absolute_url() . '202-account/account.php#currency'); ?>">
							<?php echo p202_account_token_field(); ?>
							<input type="hidden" name="campaign_currency_notice" value="switch">
							<button class="btn btn-primary btn-sm" type="submit">Their networks report in <?php echo $e($noticeAccount); ?>: switch <?php echo $noticeCount === 1 ? 'it' : 'them'; ?></button>
						</form>
						<form method="post" class="d-inline ms-2" action="<?php echo $e(get_absolute_url() . '202-account/account.php#currency'); ?>">
							<?php echo p202_account_token_field(); ?>
							<input type="hidden" name="campaign_currency_notice" value="keep">
							<button class="btn btn-outline-secondary btn-sm" type="submit">Keep converting</button>
						</form>
					</div></div>
					<?php } ?>
					<?php if ($currencyChanges !== []) { ?>
					<div class="form-text mt-3">Changes of currency:
						<?php echo implode('; ', array_map(
							static fn (array $c): string => $e((new DateTimeImmutable('@' . $c['changed_at']))->setTimezone($currencyChangeZone)->format('Y-m-d')
								. ' from ' . $c['from'] . ' to ' . $c['to']),
							$currencyChanges
						)); ?>.
					</div>
					<?php } ?>
					<form method="post" class="mt-3" action="<?php echo $e(get_absolute_url() . '202-account/account.php#currency'); ?>">
						<input type="hidden" name="record_earlier_currency" value="1">
						<?php echo p202_account_token_field(); ?>
						<span class="form-label d-block" id="earlier_label"><?php echo $unrecordedConversions > 0
							? $e(number_format($unrecordedConversions)) . ' earlier conversion' . ($unrecordedConversions === 1 ? ' has' : 's have') . ' no currency recorded'
							: 'Conversions recorded before 1.9.80'; ?></span>
						<div class="input-group" role="group" aria-labelledby="earlier_label">
							<span class="input-group-text">Before</span>
							<input type="date" class="form-control<?php echo p202_account_invalid($keyErrors, 'earlier_before'); ?>" name="earlier_before" id="earlier_before" aria-label="The date of the change" required value="<?php echo $e((string) ($_POST['earlier_before'] ?? '')); ?>">
							<span class="input-group-text">they were in</span>
							<select class="form-select<?php echo p202_account_invalid($keyErrors, 'earlier_currency'); ?>" name="earlier_currency" id="earlier_currency" aria-label="Their currency" required>
								<?php // None is offered as the answer: only the owner knows which it was. ?>
								<option value="" selected disabled>Choose a currency</option>
								<?php foreach (\Api\V3\Controllers\UsersController::SUPPORTED_CURRENCIES as $code) {
									echo '<option value="' . $e($code) . '">' . $e($code) . '</option>';
								} ?>
							</select>
							<button class="btn btn-secondary" type="submit">Record</button>
						</div>
						<div class="form-text">Before 1.9.80 a conversion kept no currency of its own. The upgrade labelled each with your account's currency then<?php echo $unrecordedConversions > 0 ? ', and left these without one because it could not tell' : ''; ?>. If you changed your account currency before the upgrade, give the date of the change and the currency you counted in before it. Only those conversions change, with their refunds: one recorded with its own currency, as every conversion has been since the upgrade, keeps it.</div>
						<?php echo p202_account_field_error($keyErrors, 'earlier_before'); ?>
						<?php echo p202_account_field_error($keyErrors, 'earlier_currency'); ?>
						<details class="p202-disclosure mt-2"<?php echo isset($keyErrors['earlier_since']) ? ' open' : ''; ?>>
							<summary>Advanced <span class="p202-disclosure__hint">changed more than once</span></summary>
							<div class="p202-disclosure__body">
								<label class="form-label" for="earlier_since">From</label>
								<input type="date" class="form-control<?php echo p202_account_invalid($keyErrors, 'earlier_since'); ?>" name="earlier_since" id="earlier_since" value="<?php echo $e((string) ($_POST['earlier_since'] ?? '')); ?>">
								<div class="form-text">Only the conversions on or after this date. If you changed currency twice, record each period: the first change's date and the currency before it, with this empty; then the second change's date and the currency between the two, with the first change's date here.</div>
								<?php echo p202_account_field_error($keyErrors, 'earlier_since'); ?>
							</div>
						</details>
					</form>
				</div>
			</section>

			<section class="p202-panel mt-4" id="api-keys">
				<div class="p202-panel__head">
					<h2 class="p202-panel__title">API keys</h2>
					<span class="p202-pill"><?php echo count($apiKeys) === 1 ? '1 key' : count($apiKeys) . ' keys'; ?></span>
				</div>
				<div class="p202-panel__body">
                    <p class="form-text mt-0">For the REST API, the p202 command line and agents. Make one key per integration, so revoking one stops only that one.<?php echo $e($keyUseNote); ?></p>
					<?php if ($newApiKey !== '') { ?>
						<div class="mb-3" data-new-api-key>
							<div class="form-text mt-0 mb-1"><strong>Your new key.</strong> Copy it now: Prosper202 keeps only a fingerprint of it, so it cannot be shown again.</div>
							<div class="p202-code">
								<pre class="p202-code__value"><?php echo $e($newApiKey); ?></pre>
								<button type="button" class="btn btn-secondary btn-sm p202-copy" data-p202-copy="<?php echo $e($newApiKey); ?>">Copy</button>
							</div>
						</div>
					<?php } ?>
					<form method="post" action="<?php echo $e($keysAt); ?>" class="mb-3">
						<input type="hidden" name="add_rest_api_key" value="1">
						<?php echo p202_account_token_field(); ?>
						<label class="form-label" for="rest_api_key_label">New key</label>
						<div class="input-group">
							<input type="text" class="form-control" id="rest_api_key_label" name="rest_api_key_label" maxlength="100" autocomplete="off" placeholder="Name it for what will use it, e.g. Nightly store sync (optional)">
							<button class="btn btn-secondary" type="submit"><i class="bi bi-plus-lg"></i> Generate key</button>
						</div>
					</form>
					<?php if (!$apiKeys) { ?>
						<div class="p202-empty">
							<i class="bi bi-key p202-empty__icon"></i>
							<strong class="p202-empty__title">No API keys yet</strong>
							<div>Generate one above to connect the command line, an agent or your own scripts.</div>
						</div>
					<?php } else { ?>
						<?php foreach ($apiKeys as $index => $apiKey) {
							$keyRef = (string)$apiKey['ref'];
							[$scopeLabel, $scopeTone] = $keyScope($apiKey['scope'] ?? null);
							$keyId = 'api-key-' . $index;
                            $keyUse = p202_account_key_use($apiKey, time());
                            $keyUseHtml = '';
                            if ($keyUse['ending'] !== '') {
                                $keyUseHtml .= ' · <span class="p202-pill '
                                    . ($keyUse['ended'] ? 'p202-pill--bad' : 'p202-pill--warn')
                                    . '" data-api-key-ending>' . $e($keyUse['ending']) . '</span>';
                            }
                            if ($keyUse['used'] !== '') {
                                $keyUseHtml .= ' · <span data-api-key-last-used>' . $e($keyUse['used']) . '</span>';
                            }
							?>
							<div class="mb-3" data-api-key-row>
                                <div class="form-text mt-0 mb-1"><?php echo (string)($apiKey['label'] ?? '') !== '' ? '<strong>' . $e((string)$apiKey['label']) . '</strong> · ' : ''; ?>Created <?php echo $e(date('M j, Y', (int)$apiKey['created_at'])); ?> · <span class="p202-pill<?php echo $scopeTone; ?>"><?php echo $e($scopeLabel); ?></span><?php echo $keyUseHtml; ?></div>
								<div class="p202-code">
									<pre class="p202-code__value p202-code__value--masked" id="<?php echo $keyId; ?>"><?php echo $e($maskKey((string)$apiKey['prefix'])); ?></pre>
                                    <?php if ($keyUse['replaceable']) { ?>
                                    <form method="post" action="<?php echo $e($keysAt); ?>" class="d-inline"
                                        data-p202-confirm="<?php echo $e($replaceConfirm); ?>">
                                        <input type="hidden" name="replace_rest_api_key" value="1">
                                        <input type="hidden" name="api_key_ref" value="<?php echo $e($keyRef); ?>">
                                        <?php echo p202_account_token_field(); ?>
                                        <button class="btn btn-secondary btn-sm" type="submit">Replace…</button>
                                    </form>
                                    <?php } ?>
									<form method="post" action="<?php echo $e(get_absolute_url() . '202-account/account.php#api-keys'); ?>" class="d-inline" data-p202-confirm="Revoke this API key? Anything still using it is refused from the next request. Your other keys keep working.">
										<input type="hidden" name="remove_rest_api_key" value="1">
										<input type="hidden" name="api_key_ref" value="<?php echo $e($keyRef); ?>">
										<?php echo p202_account_token_field(); ?>
										<button class="btn btn-outline-danger btn-sm" type="submit">Revoke…</button>
									</form>
								</div>
                                <?php if (array_key_exists($keyRef, $aiWatchers)) {
                                    $keyWatchers = $aiWatchers[$keyRef];
                                    $stopAt = get_absolute_url() . '202-account/account.php#api-keys';
                                    echo p202_account_render_watchers($keyRef, $keyWatchers, $campaignNames, $stopAt);
                                } ?>
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
							<input type="text" class="form-control<?php echo p202_account_invalid($keyErrors, 'p202_customer_api_key'); ?>" id="p202_customer_api_key" name="p202_customer_api_key" autocomplete="off" spellcheck="false" value="<?php echo $e($_POST['p202_customer_api_key'] ?? $pendingCustomerApiKey ?? ($user_row['p202_customer_api_key'] ?? '')); ?>">
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
