<?php
declare(strict_types=1);
include_once(__DIR__ . '/202-config/connect.php');
include_once(__DIR__ . '/202-config/functions-tracking202.php');
require_once __DIR__ . '/202-config/functions-account-ui.php';

// Check if API key already exists in database
$existing_key_sql = "SELECT p202_customer_api_key FROM 202_users WHERE user_id='1' AND p202_customer_api_key IS NOT NULL AND p202_customer_api_key != ''";
$existing_key_check = $db->query($existing_key_sql);
if ($existing_key_check === false) {
    record_mysql_error($db, $existing_key_sql);
}
$has_existing_key = false;
$existing_api_key = '';
$existing_key = p202FetchRow($existing_key_check, $existing_key_sql);
if ($existing_key !== null) {
    if (!empty($existing_key['p202_customer_api_key'])) {
        $has_existing_key = true;
        $existing_api_key = $existing_key['p202_customer_api_key'];

    }
}

// Process API key submission
$error = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['api_key'])) {
	// This page answers with nobody signed in (AUTH sends a failed license
	// check here), and it writes the install's license key, so the session
	// token is the only thing a cross-site form cannot supply. It asked for
	// none until U7 (error pattern #5); PreLoginPostRequiresTokenTest pins it
	// with the installer, the upgrader and the sign-in page.
	$csrf_ok = AUTH::check_csrf_token();
	$api_key = trim((string) $_POST['api_key']);
	if (!$csrf_ok) {
		$error = 'Your session has expired. Please reload the page and try again.';
	} elseif ($api_key === '') {
		$error = 'Please enter your API key.';
	}
	if ($csrf_ok && $api_key !== '') {
		// Validate the API key
		$validation_result = api_key_validate($api_key, $licence);
		p202_record_key_answer($api_key, $licence);
		$validation_data = json_decode((string) $validation_result, true);

		if (isset($validation_data['msg']) && $validation_data['msg'] === 'Key valid') {
			// Save the API key
			$mysql['p202_customer_api_key'] = $db->real_escape_string($api_key);

			// Always update user_id=1 as that's what AUTH checks
			$mysql['user_id'] = '1';

            // Check if user exists
            $user_check_sql = "SELECT user_id FROM 202_users WHERE user_id='1'";
            $user_check = $db->query($user_check_sql);
            if ($user_check === false) {
                record_mysql_error($db, $user_check_sql);
            }
            if ($user_check->num_rows > 0) {
                $key_update_sql = "UPDATE 202_users SET p202_customer_api_key = '" . $mysql['p202_customer_api_key'] . "' WHERE user_id = '" . $mysql['user_id'] . "'";
                // A failed save is said beside the form and logged, never shown
                // as a success (error pattern #1).
                if ($db->query($key_update_sql) === false) {
                    p202LogFailedQuery($db, $key_update_sql, ['file' => __FILE__, 'line' => __LINE__]);
                    $error = 'The key is valid, but it could not be saved. Please try again.';
                } else {
                    $success = true;
                    // Set session variable to indicate valid key
                    $_SESSION['valid_key'] = true;
                }
            } else {
                $error = 'No user found. Please complete the installation process first.';
            }
		} else {
			$error = $validation_result === false ? P202_ACCOUNT_KEY_UNCHECKED : 'Invalid API key; check it and retry.';
		}
	}
}

$base = get_absolute_url();
info_top(['title' => 'License key needed - Prosper202 ClickServer']);

if ($success) {
	echo p202_standalone_card('License key saved', 'Your Prosper202 ClickServer license key is valid and saved.'); ?>
	<a href="<?php echo htmlspecialchars($base, ENT_QUOTES, 'UTF-8'); ?>202-login.php" class="btn btn-primary w-100">Sign in</a>
	<p class="small text-secondary mt-3 mb-0">If Tracking202 cannot be reached later, a license confirmed in the last 30 days still counts.</p>
<?php
	echo p202_standalone_card_end();
} elseif (($_GET['licence'] ?? '') === 'unconfirmed' && $error === '') {
    // The sign-in gate's refusal: no answer from Tracking202 and no recent valid one.
    echo p202_standalone_card(
        'Your license could not be checked',
        'This install\'s license could not be checked with Tracking202, so Prosper202 stays locked until it '
        . 'can be. Make sure this server can reach my.tracking202.com over HTTPS.'
    );
    echo '<p class="small mb-3">For help, contact <a href="mailto:support@tracking202.com">support@tracking202.com</a>'
        . ' or the support chat.</p>';
    echo '<a href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '202-login.php" class="btn btn-primary w-100">'
        . 'Try again</a>';
    echo p202_standalone_card_end();
} else {
	echo p202_standalone_card('Your license key is missing or expired', 'Prosper202 ClickServer needs a valid license key (your API key from my.tracking202.com) to run.');
	if ($has_existing_key) { ?>
		<div class="alert alert-warning p202-flash" role="status"><i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body">A key is saved (<code><?php echo htmlspecialchars(substr((string) $existing_api_key, 0, 8) . '…' . substr((string) $existing_api_key, -4), ENT_QUOTES, 'UTF-8'); ?></code>) but it does not validate: it may have expired, or the license service may be unreachable. Enter a new key to replace it.</div></div>
	<?php }
	if ($error !== '') { ?>
		<div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i><div class="p202-flash__body"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div></div>
	<?php } ?>
		<form method="post" action="" id="api-key-form">
			<input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
			<div class="mb-3">
				<label class="form-label" for="api_key">License key</label>
				<input type="text" class="form-control font-monospace" id="api_key" name="api_key" autocomplete="off" spellcheck="false" required autofocus>
				<div class="form-text">Checked with the license service before it is saved.</div>
			</div>
			<button class="btn btn-primary w-100" type="submit">Save license key</button>
		</form>
		<p class="mt-3 mb-0 text-center small">No key yet? <a href="https://my.tracking202.com/api/customers/login?redirect=get-api" target="_blank" rel="noopener">Get your API key</a></p>
<?php
	echo p202_standalone_card_end();
}
// The license service's cookie for this install's address (an image, as
// before). The service is who this URL is for, so it is the stored address
// (or the server's own name), as on the account home: never the Host header
// this request chose (CLAUDE.md #16; RequestHostSourceTest).
?>
	<img src="https://my.tracking202.com/api/v2/dni/deeplink/cookie/set/<?php echo htmlspecialchars(base64_encode(p202TrackingBaseUrl()), ENT_QUOTES, 'UTF-8'); ?>" alt="" width="1" height="1" class="d-block">
<?php
info_bottom();
