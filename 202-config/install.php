<?php
declare(strict_types=1);
//include mysql settings
include_once(__DIR__ . '/connect.php');
include_once(__DIR__ . '/functions-install.php');
if (!isset($db) || !($db instanceof mysqli)) {
	_die('Database connection unavailable.');
}

// Initialize the success variable to false by default
$success = false;

// Initialize $html array elements to prevent undefined array key warnings
$html = [
	'user_email' => '',
	'user_name' => '',
	'user_api' => '',
	'rest_api_key' => '',
	'user_id' => 0
];

// Initialize $error array elements to prevent undefined array key warnings
$error = [
	'user_email' => '',
	'user_name' => '',
	'user_pass' => ''
];

if (isset($_COOKIE['user_api'])) {
	$html['user_api'] = htmlentities((string) $_COOKIE['user_api'], ENT_QUOTES, 'UTF-8');
} else {
	header("Location: " . get_absolute_url() . "202-config/get_apikey.php");
	exit;
}

//check to see if this is already installed, if so don't do anything
if (is_installed() == true) {

	_die("<h6>Already Installed</h6>
			  <small>You appear to have already installed Prosper202. To reinstall please clear your old database tables first. <a href='" . get_absolute_url() . "202-login.php'>Login Now</a></small>");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	//check email
	if (check_email_address($_POST['user_email']) == false) {
		$error['user_email'] = '<div class="error">Please enter a valid email address</div>';
	}

	//check username
	if ($_POST['user_name'] == '') {
		$error['user_name'] = '<div class="error">You must type in your desired username</div>';
	}
	if (!ctype_alnum((string) $_POST['user_name'])) {
		$error['user_name'] .= '<div class="error">Your username may only contain alphanumeric characters</div>';
	}
	if ((strlen((string) $_POST['user_name']) < 4) or (strlen((string) $_POST['user_name']) > 20)) {
		$error['user_name'] .= '<div class="error">Your username must be between 4 and 20 characters long</div>';
	}

	// Check if password field is empty
	if (!isset($_POST['user_pass']) || empty($_POST['user_pass'])) {
		$error['user_pass'] = '<div class="error">You must type in your desired password</div>';
	}

		// Check if password verification field is empty
		if (!isset($_POST['verify_user_pass']) || empty($_POST['verify_user_pass'])) {
			$error['user_pass'] .= '<div class="error">You must verify your password</div>';
		}

		// Check password length only if password was provided
		if (isset($_POST['user_pass']) && !empty($_POST['user_pass'])) {
			$pass_length = strlen((string) $_POST['user_pass']);
			if ($pass_length < 6) {
				$error['user_pass'] .= '<div class="error">Your password must be at least 6 characters long</div>';
			} elseif ($pass_length > 35) {
				$error['user_pass'] .= '<div class="error">Your password must be no more than 35 characters long</div>';
			}
		}

		// Check if passwords match only if both were provided
		if (isset($_POST['user_pass']) && isset($_POST['verify_user_pass']) && $_POST['user_pass'] != $_POST['verify_user_pass']) {
			$error['user_pass'] .= '<div class="error">Your passwords did not match, please try again</div>';
		}

	//if no error occurred, let's create the user account
	if (empty($error['user_email']) && empty($error['user_name']) && empty($error['user_pass'])) {

		//no error, so now setup all of the mysql database structures
		$installer = new INSTALL();
		$installer->install_databases();

		$mysql['user_email'] = $db->real_escape_string($_POST['user_email'] ?? '');
		$mysql['user_name'] = $db->real_escape_string($_POST['user_name'] ?? '');
		$mysql['user_timezone'] = $db->real_escape_string($_POST['user_timezone'] ?? '');
		$mysql['p202_customer_api_key'] = $db->real_escape_string($_POST['user_api'] ?? '');
		$mysql['user_time_register'] = $db->real_escape_string((string) time());

		//md5 the user pass with salt
	 	$hasher = function_exists('hash_user_pass') ? 'hash_user_pass' : 'salt_user_pass';
	 	$user_pass = $hasher($_POST['user_pass']);
		$mysql['user_pass'] = $db->real_escape_string($user_pass);      

 		$hash = md5(uniqid((string) random_int(0, mt_getrandmax()), TRUE));
		// $user_hash = intercomHash($hash); // Removed intercomHash call
		$user_hash = ''; // Default empty value

		//insert this user
		$user_sql = "INSERT IGNORE INTO 202_users
					SET	user_email='" . $mysql['user_email'] . "',
						user_name='" . $mysql['user_name'] . "',
						user_pass='" . $mysql['user_pass'] . "',
						user_timezone='" . $mysql['user_timezone'] . "',
						user_time_register='" . $mysql['user_time_register'] . "',
						install_hash='" . $hash . "',
						user_hash='" . $user_hash . "',
						p202_customer_api_key='" . $mysql['p202_customer_api_key'] . "'";
		$user_result = _mysqli_query($user_sql);

		// Get the user_id of the newly inserted user or the existing user with the same username
		$user_id = $db->insert_id;

		// If insert_id is 0 (because the user already existed due to INSERT IGNORE), get the user_id manually
		if ($user_id == 0) {
			$check_sql = "SELECT user_id FROM 202_users WHERE user_name='" . $mysql['user_name'] . "'";
			$check_result = _mysqli_query($check_sql);
			if ($check_result && $check_result->num_rows > 0) {
				$check_row = $check_result->fetch_assoc();
				$user_id = $check_row['user_id'];
			}
		}

		// Only proceed if we have a valid user_id
		if ($user_id > 0) {
			$mysql['user_id'] = $db->real_escape_string((string) $user_id);

			// Update user preference table - use INSERT IGNORE to handle duplicates
			$user_sql = "INSERT IGNORE INTO 202_users_pref SET user_id='" . $mysql['user_id'] . "'";
			$user_result = _mysqli_query($user_sql);

			// Insert user role - use the actual user_id, not a hardcoded value
			$role_sql = "INSERT IGNORE INTO `202_user_role` (`user_id`, `role_id`) VALUES ('" . $mysql['user_id'] . "', 1)";
			$role_result = _mysqli_query($role_sql);

			$cron = callAutoCron('register');

			if (is_array($cron) && ($cron['status'] ?? null) === 'success') {
				$sql = "UPDATE 202_users_pref SET auto_cron = '1' WHERE user_id = '" . $mysql['user_id'] . "'";
				$result = _mysqli_query($sql);
			}

			// Generate a REST API v3 key for the new admin so the CLI and the
			// Claude onboarding agent can authenticate right away. This is the
			// local 202_api_keys Bearer key, distinct from the my.tracking202
			// install key (p202_customer_api_key) collected above.
			$rest_api_key = bin2hex(random_bytes(32));
			$apikey_stmt = $db->prepare("INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (?, ?, ?)");
			if ($apikey_stmt === false) {
				$rest_api_key = '';
			} else {
				// $user_id is a string when read back via SELECT on the
				// INSERT IGNORE existing-user path; bind it as a real int.
				$apikey_user_id = (int) $user_id;
				$apikey_created_at = time();
				$apikey_stmt->bind_param('isi', $apikey_user_id, $rest_api_key, $apikey_created_at);
				if (!$apikey_stmt->execute()) {
					// Don't surface a key we failed to persist.
					$rest_api_key = '';
				}
				$apikey_stmt->close();
			}
			$html['rest_api_key'] = htmlentities($rest_api_key, ENT_QUOTES, 'UTF-8');
			$html['user_id'] = (int) $user_id;

			// Add null check before accessing array offset on line 120
				if (isset($mysql['user_timezone'])) {
					registerDailyEmail('07', $mysql['user_timezone'], $hash);
				}

			// create partitions after everything else is setup
			$installer->install_database_partitions();

			//if this worked, show them the success screen
			$success = true;
		} else {
			// If we couldn't get a valid user_id, show an error
			$error['general'] = '<div class="error">Failed to create user account. Please try again with a different username.</div>';
		}
	}

	// Always set the HTML values from POST data when it exists
	if (isset($_POST['user_email'])) {
		$html['user_email'] = htmlentities($_POST['user_email'], ENT_QUOTES, 'UTF-8');
	}
	if (isset($_POST['user_name'])) {
		$html['user_name'] = htmlentities((string) $_POST['user_name'], ENT_QUOTES, 'UTF-8');
	}
	$html['user_api'] = htmlentities((string) $_COOKIE['user_api'], ENT_QUOTES, 'UTF-8');
	// Don't store password in HTML for security reasons
}




//only show install setup, if it, of course, isn't install already.

if (!$success) {
	// Initialize $version_error array
	$version_error = [];

	// Same floors as requirements.php — keep the two in sync
	if (!php_version_supported()) {
		$version_error['phpversion'] = 'Prosper202 requires PHP ' . PROSPER202_MIN_PHP_VERSION . ', or newer.';
	}

	// Get Database version
	$mysqlversion = $db->server_info;
	if (preg_match('/-(10\..+)-MariaDB/i', (string) $mysqlversion, $match)) {
		// Support For MariaDB
		$mysqlversion = $match[1];
		if ((version_compare($mysqlversion, '10.6') < 0)) {
			$version_error['mysqlversion'] = 'Prosper202 requires MariaDB 10.6, or newer.';
		}
	} else {
		if ((version_compare($mysqlversion, '8.0') < 0)) {
			$version_error['mysqlversion'] = 'Prosper202 requires MySQL 8.0, or newer.';
		}
	}

	$html['mysqlversion'] = htmlentities((string) $mysqlversion, ENT_QUOTES, 'UTF-8');

	if (!function_exists('curl_version')) {
		$version_error['curl'] = 'Prosper202 requires CURL to be installed.';
	}

	if ($version_error) {
		header("Location: " . get_absolute_url() . "202-config/requirements.php");
		exit;
	}

	info_top(); ?>
	<style>
		.error {
			color: #a94442;
			background-color: #f2dede;
			border: 1px solid #ebccd1;
			padding: 5px 10px;
			border-radius: 4px;
			margin-top: 5px;
			font-size: 12px;
		}
	</style>
	<div class="main col-xs-7 install">
		<center><img src="<?php echo get_absolute_url(); ?>202-img/prosper202.png"></center>
		<h6>Welcome</h6>
		<small>Welcome to the five minute Prosper202 installation process! Just fill in the information below, and you'll be on your way to using the most powerful internet marketing applications in the world.</small>
		<br><br>
		<small>Need Extra Help? Check out our <a href="http://support.tracking202.com/" target="_blank">ReadMe documentation</a>.</small>

		<h6>Create your account</h6>
		<small>Please provide the following information. Don't worry, you can always change these settings later.</small>
		<br><br>
		<?php if (isset($error['general'])) echo $error['general']; ?>
		<form method="post" action="" class="form-horizontal" role="form" id="install-prosper202">

			<input type="hidden" class="form-control input-sm" id="user_api" name="user_api" value="<?php echo $html['user_api']; ?>">

			<div class="form-group <?php if ($error['user_email']) echo "has-error"; ?>">
				<label for="user_email" class="col-xs-4 control-label"><strong>Your Email:</strong></label>
				<div class="col-xs-8">
					<input type="text" class="form-control input-sm" id="user_email" name="user_email" value="<?php echo $html['user_email']; ?>">
					<?php if ($error['user_email']) echo $error['user_email']; ?>
				</div>
			</div>

			<div class="form-group">
				<label for="user_timezone" class="col-xs-4 control-label"><strong>Time Zone:</strong></label>
				<div class="col-xs-8">
					<?php



					// Try to detect user's timezone
					$user_timezone = '';
					if (isset($_POST['user_timezone'])) {
					    $user_timezone = $_POST['user_timezone'];
					} elseif (isset($_SERVER['TZ'])) {
					    $user_timezone = $_SERVER['TZ'];
					} else {
					    // Default to a common timezone if detection fails
					    $user_timezone = 'America/Los_Angeles';
					}

					$utc = new DateTimeZone('UTC');
					$dt = new DateTime('now', $utc);

					echo '<select class="form-control input-sm" name="user_timezone" id="user_timezone">';
					foreach (DateTimeZone::listIdentifiers() as $tz) {
						$current_tz = new DateTimeZone($tz);
						$offset =  $current_tz->getOffset($dt);
						$transition =  $current_tz->getTransitions($dt->getTimestamp(), $dt->getTimestamp());
						$abbr = $transition[0]['abbr'];

						echo '<option value="' . $tz . '">' . $tz . ' [' . $abbr . ' ' . formatOffset($offset) . ']</option>';
					}
					echo '</select>';
					?>
					<script type="text/javascript">
						// Function to detect user's timezone and select it in dropdown
						(function() {
							try {
								// Get user's timezone using Intl API
								const userTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
								console.log("Detected timezone: " + userTimezone);

								// Find and select the user's timezone in the dropdown
								const timezoneSelector = document.getElementById('user_timezone');
								if (timezoneSelector) {
									for (let i = 0; i < timezoneSelector.options.length; i++) {
										if (timezoneSelector.options[i].value === userTimezone) {
											timezoneSelector.selectedIndex = i;
											break;
										}
									}
								}
							} catch (e) {
								console.error("Error detecting timezone: " + e.message);
							}
						})();
					</script>
				</div>
			</div>

			<div class="form-group <?php if ($error['user_name']) echo "has-error"; ?>">
				<label for="user_name" class="col-xs-4 control-label"><strong>Username:</strong></label>
				<div class="col-xs-8">
					<input type="text" class="form-control input-sm" id="user_name" name="user_name" value="<?php echo $html['user_name']; ?>">
					<?php if ($error['user_name']) echo $error['user_name']; ?>
				</div>
			</div>

			<div class="form-group <?php if ($error['user_pass']) echo "has-error"; ?>">
				<label for="user_pass" class="col-xs-4 control-label"><strong>Password:</strong></label>
				<div class="col-xs-8">
					<input type="password" class="form-control input-sm" id="user_pass" name="user_pass">
					<?php
					// Only show password errors once, at the top password field
					if ($error['user_pass']) echo $error['user_pass'];
					?>
				</div>
			</div>

			<div class="form-group <?php if ($error['user_pass']) echo "has-error"; ?>">
				<label for="verify_user_pass" class="col-xs-4 control-label"><strong>Verify Password:</strong></label>
				<div class="col-xs-8">
					<input type="password" class="form-control input-sm" id="verify_user_pass" name="verify_user_pass">
				</div>
			</div>

			<button class="btn btn-lg btn-p202 btn-block" type="submit">Install Prosper202 ClickServer<span class="fui-check-inverted pull-right"></span></button>
			<script type="text/javascript">
				$('form#install-prosper202').submit(function() {
					$(this).find(':input[type=submit]').prop('disabled', true);
					$('body').css('cursor', 'wait');
				});
			</script>
		</form>
	</div>
<?php info_bottom();
}


//if success is equal to true, and this campaign did complete
if ($success) {

	info_top(); ?>
	<div class="main col-xs-7 install">
		<center><img src="<?php echo get_absolute_url(); ?>202-img/prosper202.png"></center>
		<h6>Success!</h6>
		<small>Prosper202 has been installed. Now you can <a href="<?php echo get_absolute_url(); ?>202-login.php">log in</a>.</small><br></br>
		<div class="row" style="margin-bottom: 10px;">
			<div class="col-xs-3"><span class="label label-default">Username:</span></div>
			<div class="col-xs-9"><span class="label label-primary"><?php echo $html['user_name']; ?></span></div>
		</div>
		<div class="row" style="margin-bottom: 10px;">
			<div class="col-xs-3"><span class="label label-default">Login address:</span></div>
			<div class="col-xs-9"><small><?php printf('<a href="%s202-login.php">%s202-login.php</a>', get_absolute_url(), $_SERVER['SERVER_NAME'] . get_absolute_url()); ?></small></div>
		</div>

		<?php
		// Build the absolute base URL for the cron line and CLI connection hints.
		$scheme = (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on') ? 'https' : 'http';
		$base_url = $scheme . '://' . $_SERVER['SERVER_NAME'] . get_absolute_url();
		$cron_line = '* * * * * curl -s "' . $base_url . '202-cronjobs/index.php" >/dev/null 2>&1';
		?>

		<h6 style="margin-top: 20px;">Keep background jobs running (cron)</h6>
		<small>Reports, attribution and emails run on a schedule. If you used Docker this is already handled by the <code>cron</code> service. Otherwise, add this one line to your crontab (<code>crontab -e</code>):</small>
		<pre style="white-space: pre-wrap; word-break: break-all; font-size: 11px;"><?php echo htmlspecialchars($cron_line, ENT_QUOTES, 'UTF-8'); ?></pre>

		<?php if ($html['rest_api_key'] !== '') { ?>
		<h6 style="margin-top: 20px;">Connect the CLI / Claude onboarding (optional)</h6>
		<small>Use this REST API key with the <code>p202</code> CLI or the Claude <code>/onboard-prosper202</code> skill to finish setup hands-free. <strong>Copy it now</strong> — for security it isn't shown again (you can always generate a new one under Account &rarr; REST API Keys).</small>
		<div class="row" style="margin-top: 8px; margin-bottom: 6px;">
			<div class="col-xs-3"><span class="label label-default">API URL:</span></div>
			<div class="col-xs-9"><small><code><?php echo htmlspecialchars($base_url . 'api/v3', ENT_QUOTES, 'UTF-8'); ?></code></small></div>
		</div>
		<div class="row" style="margin-bottom: 6px;">
			<div class="col-xs-3"><span class="label label-default">User ID:</span></div>
			<div class="col-xs-9"><small><code><?php echo (int) $html['user_id']; ?></code></small></div>
		</div>
		<div class="row" style="margin-bottom: 6px;">
			<div class="col-xs-3"><span class="label label-default">API key:</span></div>
			<div class="col-xs-9"><small><code style="word-break: break-all;"><?php echo $html['rest_api_key']; ?></code></small></div>
		</div>
		<?php } ?>

		<p style="margin-top: 15px;"><small>Were you expecting more steps? Sorry thats it!</small></p>
	</div>
<?php info_bottom();
}
