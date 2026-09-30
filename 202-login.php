<?php

declare(strict_types=1);
include_once(__DIR__ . '/202-config/connect.php');
include_once(__DIR__ . '/vendor/autoload.php');

prosper_log('login', 'Request received with method ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . ' from IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// Initialize variables to prevent undefined variable warnings
$error = [];
$html = [];
$mysql = [];
$selected = [];
$add_success = false;
$delete_success = false;

// Check if the application is installed
if (!is_installed()) {
    // Redirect to setup if not installed
    header('Location: ' . get_absolute_url() . '202-config/setup-config.php');
    exit;
}

function logged_in_redirect($safe_context = false)
{
	prosper_log('login', 'User already authenticated, preparing redirect.');

	// Honor the redirect parameter if present — only allow local paths to prevent open redirect
	if (isset($_GET['redirect'])) {
		$target = urldecode((string) $_GET['redirect']);
		if ($target !== '' && $target[0] === '/') {
			prosper_log('login', 'Redirecting authenticated user to ' . $target);
			header('location: ' . $target);
			exit;
		}
	}

	// Default: redirect to account dashboard
	prosper_log('login', 'Redirecting to account dashboard.');
	header('location: ' . get_absolute_url() . '202-account');
	exit;
}

if (AUTH::logged_in() || AUTH::remember_me_on_logged_out()) {
	//die('already logged in, redirecting...');
	logged_in_redirect();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	$error = [];
	$slack = null;
	$username_raw = (string)($_POST['user_name'] ?? '');
	$password = (string)($_POST['user_pass'] ?? '');
	$username = trim($username_raw);
	// Validated, trust-aware client IP (HTTP_X_FORWARDED_FOR normalized by
	// connect.php, falling back to REMOTE_ADDR). Keying the throttle on the raw
	// REMOTE_ADDR would be the shared proxy address behind a CDN and lock out
	// every user behind it; AUTH::client_ip() also rejects spoofed/overlong
	// forwarded values so they can't break the varchar(255) audit-log insert.
	$login_ip = AUTH::client_ip();
	$rate_limited = false;
	prosper_log('login', 'Processing login attempt for username ' . $username);

	// CSRF: the form embeds the session token; a cross-site POST cannot read it.
	$csrf_ok = AUTH::check_csrf_token();
	if (!$csrf_ok) {
		$error['user'] = 'Your session has expired. Please reload the page and try again.';
		prosper_log('login', 'Rejected login with missing/invalid CSRF token for username ' . $username);
	}

	if (!$error && $username === '') {
		$error['user'] = 'Please enter a username.';
	}

	if (!$error && $password === '') {
		$error['user'] = ($error['user'] ?? '') . ' Please enter a password.';
	}

	// Brute-force throttle: stop checking credentials once an IP or account has
	// piled up failures. Fail open if the throttle query itself errors.
	if (!$error) {
		try {
			$rate_limited = AUTH::is_rate_limited($db, $username, $login_ip);
		} catch (RuntimeException $exception) {
			prosper_log('login', 'Rate limit check failed: ' . $exception->getMessage());
		}
		if ($rate_limited) {
			$error['user'] = 'Too many failed login attempts. Please wait a few minutes and try again.';
			prosper_log('login', 'Throttled login attempt for username ' . $username . ' from IP ' . $login_ip);
		}
	}

	$login_result = null;
	if (!$error) {
		try {
			$login_result = AUTH::authenticate($username, $password, $db);
		} catch (RuntimeException $exception) {
			$error['user'] = 'We were unable to process your login. Please try again later.';
			prosper_log('login', 'Login exception for username ' . $username . ': ' . $exception->getMessage());
		}
	}

	$user_row = $login_result['user'] ?? null;

	if (!$error && ($login_result['success'] ?? false) === false) {
		$error['user'] = 'Your username or password is incorrect.';
		prosper_log('login', 'Invalid credentials for username ' . $username);
	}

	if ($error && $user_row && !empty($user_row['user_slack_incoming_webhook'])) {
		$slack = new Slack($user_row['user_slack_incoming_webhook']);
		$slack->push('failed_login', ['username' => $username, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
	}

	// Don't record throttled/CSRF-rejected attempts: no credentials were checked,
	// and logging them would extend the rolling window and keep a legitimate user
	// locked out indefinitely.
	$login_success = empty($error) ? 1 : 0;
	$should_log_attempt = !$rate_limited && $csrf_ok;
	$login_log_stmt = $should_log_attempt
		? $db->prepare('INSERT INTO 202_users_log (user_name, user_pass, ip_address, login_time, login_success, login_error, login_server, login_session) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
		: false;
	if ($login_log_stmt) {
		$login_error_serialized = serialize($error);
		$login_server_serialized = AUTH::login_audit_snapshot();
		$login_session_serialized = ''; // never persist session contents (API keys, tokens) at rest
		$redacted_password = '[filtered]';
		$ip_address = $login_ip; // same client IP the throttle keys on, so counts line up
		$login_time = time();
		$login_log_stmt->bind_param(
			'sssiisss',
			$username,
			$redacted_password,
			$ip_address,
			$login_time,
			$login_success,
			$login_error_serialized,
			$login_server_serialized,
			$login_session_serialized
		);
		$login_log_stmt->execute();
		$login_log_stmt->close();
	} elseif ($should_log_attempt) {
		prosper_log('login', 'Unable to prepare login log statement: ' . $db->error);
	}

	if (empty($error) && $user_row) {
		AUTH::delete_old_auth_hash();

		$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '0.0.0.0';
		$ip_id = (int) INDEXES::get_ip_id($ip);
		$survey_data = getSurveyData($user_row['install_hash']);
		$modal_status = ($survey_data['modal'] ?? false) ? 0 : 1;
		$vip_perks_status = ($survey_data['vip_perks'] ?? false) ? 1 : 0;

		$update_stmt = $db->prepare('UPDATE 202_users SET user_last_login_ip_id = ?, modal_status = ?, vip_perks_status = ? WHERE user_id = ?');
		if ($update_stmt) {
			$user_id = (int) $user_row['user_id'];
			$update_stmt->bind_param('iiii', $ip_id, $modal_status, $vip_perks_status, $user_id);
			$update_stmt->execute();
			$update_stmt->close();
		}

		$mod_sql = "SHOW COLUMNS FROM 202_landing_pages LIKE 'leave_behind_page_url'";
		$mod_row = memcache_mysql_fetch_assoc($mod_sql);
		$user_row['user_mods_lb'] = ($mod_row && (int) ($user_row['user_mods_lb'] ?? 0) === 1) ? 1 : 0;

		AUTH::begin_user_session($user_row);
		$_SESSION['user_mods_lb'] = $user_row['user_mods_lb'];
		prosper_log('login', 'Login succeeded for user_id ' . (int) $user_row['user_id']);

		if (isset($_POST['remember_me'])) {
			AUTH::remember_me_on_auth();
		}

		logged_in_redirect(true);
	}

	$html['user_name'] = htmlentities($username, ENT_QUOTES, 'UTF-8');
}

info_top(['title' => 'Sign in - Prosper202 ClickServer', 'ads' => true]);
echo p202_standalone_card('Sign in', 'to your Prosper202 ClickServer'); ?>
		<?php if (isset($error['user'])) { ?>
			<div class="alert alert-danger p202-flash" role="alert" id="login-error"><i class="bi bi-x-circle"></i><div class="p202-flash__body"><?php echo htmlspecialchars(trim((string) $error['user']), ENT_QUOTES, 'UTF-8'); ?></div></div>
		<?php } ?>
		<form method="post" action="" id="login-form">
			<input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
			<div class="mb-3">
				<label class="form-label" for="user_name">Username</label>
				<input type="text" class="form-control" id="user_name" name="user_name" value="<?php echo $html['user_name'] ?? ''; ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required<?php echo isset($html['user_name']) ? '' : ' autofocus'; ?>>
			</div>
			<div class="mb-3">
				<label class="form-label" for="user_pass">Password</label>
				<input type="password" class="form-control" id="user_pass" name="user_pass" autocomplete="current-password" required<?php echo isset($html['user_name']) ? ' autofocus' : ''; ?>>
			</div>
			<div class="form-check mb-3">
				<input class="form-check-input" type="checkbox" id="remember_me" name="remember_me">
				<label class="form-check-label" for="remember_me">Keep me signed in on this browser</label>
			</div>
			<button class="btn btn-primary w-100" type="submit">Sign in</button>
		</form>
		<p class="mt-3 mb-0 text-center small"><a href="<?php echo htmlspecialchars(get_absolute_url(), ENT_QUOTES, 'UTF-8'); ?>202-lost-pass.php">Forgot your password or username?</a></p>
<?php echo p202_standalone_card_end(); ?>
		<!-- P202_CS_Login_Page_288x200 -->
		<div class="p202-standalone__ad" id="div-gpt-ad-1398648278789-0">
			<script>
				googletag.cmd.push(function() {
					googletag.display('div-gpt-ad-1398648278789-0');
				});
			</script>
		</div>
<?php info_bottom();
