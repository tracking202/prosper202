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
	
	// If we're not in a safe context (e.g., called from early AUTH check), use simple redirect
	if (!$safe_context) {
		//die('redirecting to account...');
		prosper_log('login', 'Using simple redirect due to unsafe context.');
		//print url
		printf("Redirect URL: %s\n", get_absolute_url() . '202-account');
		//die("redurect url printed...");
		header('location: ' . get_absolute_url() . '202-account');
		//exit;
	}
	//die('redirecting to account...2');
	// Due to Mobile_Detect issues causing fatal errors, use simple redirect for now
	// TODO: Fix Mobile_Detect integration for mobile/tablet detection
	prosper_log('login', 'Redirecting to account dashboard.');
	header('location: ' . get_absolute_url() . '202-account');
	exit;
	
	if (false && $isMobile) { // Disable complex mobile logic for now
		//redirect to mini stats
		$dni_success = false;
		if (isset($_GET['redirect'])) {
			$urlQuery = parse_url(urldecode((string) $_GET['redirect']));
			parse_str($urlQuery['query'], $vars);
			if (isset($vars['dl_dni']) && isset($vars['dl_offer_id']) && isset($vars['ddlci'])) {
				$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
				$mysql['dni'] = $db->real_escape_string($vars['dl_dni']);
				$dni_sql = "SELECT dni.id, dni.networkId, dni.apiKey, dni.affiliateId, 2u.install_hash, 2af.aff_network_id FROM 202_dni_networks AS dni LEFT JOIN 202_users AS 2u USING (user_id) LEFT JOIN 202_aff_networks AS 2af ON (2af.dni_network_id = dni.id) WHERE dni.user_id = '" . $mysql['user_id'] . "' AND dni.networkId = '" . $mysql['dni'] . "' LIMIT 1";
				$dni_result = _mysqli_query($dni_sql);
				if ($dni_result->num_rows > 0) {
					$dni_row = $dni_result->fetch_assoc();
					$offerData = setupDniOffer($dni_row['install_hash'], $dni_row['networkId'], $dni_row['apiKey'], $dni_row['affiliateId'], 'USD', $vars['dl_offer_id'], $vars['ddlci']);
					$data = json_decode((string) $offerData, true);

					if (!empty($data)) {
						$mysql['aff_network_id'] = $db->real_escape_string((string)$dni_row['aff_network_id']);
						$mysql['aff_campaign_name'] = $db->real_escape_string($data['name']);
						$mysql['aff_campaign_url'] = $db->real_escape_string($data['trk_url']);
						$mysql['aff_campaign_payout'] = $db->real_escape_string((string)$data['payout']);
						$mysql['aff_campaign_time'] = time();
						$affSql = "INSERT INTO 202_aff_campaigns 
								   SET 
								   user_id = '" . $mysql['user_id'] . "',
								   aff_network_id = '" . $mysql['aff_network_id'] . "',
								   aff_campaign_name = '" . $mysql['aff_campaign_name'] . "',
								   aff_campaign_url = '" . $mysql['aff_campaign_url'] . "',
								   aff_campaign_payout = '" . $mysql['aff_campaign_payout'] . "',
								   aff_campaign_time = '" . $mysql['aff_campaign_time'] . "'";
						$db->query($affSql);
						$aff_campaign_id = $db->insert_id;
						$aff_campaign_id_public = random_int(1, 9) . $aff_campaign_id . random_int(1, 9);
						$aff_campaign_sql = "UPDATE 202_aff_campaigns SET aff_campaign_id_public = '" . $aff_campaign_id_public . "' WHERE aff_campaign_id = '" . $aff_campaign_id . "'";
						$db->query($aff_campaign_sql);
						setupDniOfferTrack($dni_row['install_hash'], $dni_row['networkId'], $dni_row['apiKey'], $dni_row['affiliateId'], $vars['dl_offer_id'], $vars['ddlci']);
						$dni_success = true;
					}
				}
			}
		}
		header('location: ' . get_absolute_url() . '202-Mobile/mini-stats/?dni=' . $dni_success);
		prosper_log('login', 'Redirecting authenticated mobile user to mini-stats.');
		exit;
	} else {

	if (isset($_GET['redirect'])) {
		$target = urldecode((string) $_GET['redirect']);
		prosper_log('login', 'Redirecting authenticated user to ' . $target);
		header('location: ' . $target);
		die();
	}

		//redirect to account screen
		$redirect_url = get_absolute_url() . '202-account';
		prosper_log('login', 'About to redirect to: ' . $redirect_url);
		header('location: ' . $redirect_url);
		prosper_log('login', 'Redirecting authenticated user to account dashboard.');
		exit;
	}
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
