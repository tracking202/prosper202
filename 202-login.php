<?php

declare(strict_types=1);
include_once(__DIR__ . '/202-config/connect.php');
include_once(__DIR__ . '/202-config/Mobile_Detect.php');
include_once(__DIR__ . '/vendor/autoload.php');

use UAParser\Parser;

prosper_log('login', 'Request received with method ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . ' from IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
prosper_log('login', 'Session snapshot: ' . json_encode($_SESSION));

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

$user_sql = "SET @@global.sql_mode= ''";
$user_results = $db->query($user_sql);

$detect = new Mobile_Detect;
$parser = Parser::create();
$userAgent = $detect->getUserAgent();
if ($userAgent === null || $userAgent === '') {
    $userAgent = 'Unknown/1.0';
}
$result = $parser->parse($userAgent);

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
	prosper_log('login', 'Processing login attempt for username ' . $username);

	if ($username === '') {
		$error['user'] = 'Please enter a username.';
	}

	if ($password === '') {
		$error['user'] = ($error['user'] ?? '') . ' Please enter a password.';
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

	$login_success = empty($error) ? 1 : 0;
	$login_log_stmt = $db->prepare('INSERT INTO 202_users_log (user_name, user_pass, ip_address, login_time, login_success, login_error, login_server, login_session) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
	if ($login_log_stmt) {
		$login_error_serialized = serialize($error);
		$login_server_serialized = serialize($_SERVER);
		$login_session_serialized = serialize($_SESSION);
		$redacted_password = '[filtered]';
		$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
	} else {
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
		prosper_log('login', 'Post-login session: ' . json_encode($_SESSION));

		if (isset($_POST['remember_me'])) {
			AUTH::remember_me_on_auth();
		}

		logged_in_redirect(true);
	}

	$html['user_name'] = htmlentities($username, ENT_QUOTES, 'UTF-8');
}

info_top(); ?>
<div class="row">
	<div class="main col-xs-4">
		<center><img src="202-img/prosper202.png"></center>
		<form class="form-signin form-horizontal" role="form" method="post" action="">
			<div class="form-group <?php if (isset($error['user'])) echo "has-error"; ?>">
				<?php if (isset($error['user'])) { ?>
					<div class="tooltip right in login_tooltip">
						<div class="tooltip-arrow"></div>
						<div class="tooltip-inner"><?php echo $error['user']; ?></div>
					</div>
				<?php } ?>
					<input type="text" class="form-control first" name="user_name" placeholder="Username" autocomplete="username">
					<input type="password" class="form-control middle" name="user_pass" placeholder="Password" autocomplete="current-password">
				<label class="form-control last">
					<input type="checkbox" name="remember_me"> Remember me
				</label>
				<a href="<?php echo get_absolute_url(); ?>202-lost-pass.php" class="text-info forgot-text">I forgot my password/username</a>
				<button class="btn btn-lg btn-p202 btn-block" type="submit">Sign in</button>
			</div>
		</form>
		<!-- P202_CS_Login_Page_288x200 -->
		<div id='div-gpt-ad-1398648278789-0' style='width:288px; height:200px;'>
			<script type='text/javascript'>
				googletag.cmd.push(function() {
					googletag.display('div-gpt-ad-1398648278789-0');
				});
			</script>
		</div>
	</div>
</div>
</div>

<?php if ($result->ua->family == "IE") { ?>
	<script type="text/javascript">
		$(window).load(function() {
			$('#browser_modal').modal({
				backdrop: 'static',
				show: true,
			})
		});
	</script>
	<!-- Browser detect modal-->
	<div class="modal fade" id="browser_modal">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					<h4 class="modal-title">Internet Explorer Detected</h4>
				</div>
				<div class="modal-body">
					<p>Internet Explorer is not supported by Prosper202 version 1.8 and more.</p>
					<p>Recommended browsers:</p>
					<p>
						<a href="http://www.google.com/chrome/" target="_blank">Google Chrome <img src="../202-img/chrome.png"></a>
						<a href="http://www.mozilla.org/en-US/firefox/new/‎" target="_blank">Mozilla Firefox <img src="../202-img/firefox.png"></a>
						<a href="http://www.apple.com/safari" target="_blank">Safari (Mac OS X) <img src="../202-img/safari.png"></a>
					</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal">Got it!</button>
				</div>
			</div><!-- /.modal-content -->
		</div><!-- /.modal-dialog -->
	</div><!-- /.modal -->
<?php }

info_bottom(); ?>
