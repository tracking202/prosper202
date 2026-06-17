<?php
declare(strict_types=1);
//include mysql settings
include_once(__DIR__ . '/connect.php');
include_once(__DIR__ . '/functions-install.php');
include_once(__DIR__ . '/functions-install-helpers.php');
if (!isset($db) || !($db instanceof mysqli)) {
	_die('Database connection unavailable.');
}

// connect.php already computes this with identical logic; reuse it so AJAX
// detection stays consistent with the rest of the app. We answer AJAX with JSON.
$isAjax = $_is_ajax ?? false;

// Non-critical issues collected during install. They're surfaced as a gentle note
// on the success screen instead of blocking the install — all are safe to fix later.
$install_warnings = [];

// Whether the last account-creation failure looks transient and worth a retry.
$retryable = false;

// Field rules, shared by the server-side validation and the client-side JS
// (injected via json_encode) so the two can't drift. See functions-install-helpers.php.
$rules = install_default_rules();

/**
 * Emit a JSON response for the AJAX install flow and stop. Any buffered output is
 * discarded first so the body is always valid JSON. The payload is encoded via the
 * unit-tested install_encode_response(), which surfaces a json_encode() failure
 * explicitly rather than sending an empty body (CLAUDE.md #4).
 *
 * @param array<string,mixed> $payload
 */
function install_json(array $payload): never
{
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	header('Content-Type: application/json; charset=utf-8');
	$encoded = install_encode_response($payload);
	if (!$encoded['ok']) {
		http_response_code(500);
	}
	echo $encoded['body'];
	exit;
}

// Buffer everything for AJAX requests so stray output never corrupts the JSON body.
if ($isAjax) {
	ob_start();
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

	if ($isAjax) {
		install_json([
			'success'   => false,
			'retryable' => false,
			'errors'    => [
				'general' => '<div class="error">Prosper202 is already installed — your account is ready. <a href="' . get_absolute_url() . '202-login.php">Log in to continue</a>.</div>',
			],
		]);
	}

	_die("<h6>Already Installed</h6>
			  <small>Prosper202 is already installed — your account is ready. <a href='" . get_absolute_url() . "202-login.php'>Log in to continue</a>.<br>Reinstalling? Clear your old database tables first.</small>");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	// CSRF: the form embeds the app-wide session token (set in connect.php and
	// persisted across AJAX via withWritableSession). Reject any POST whose token
	// doesn't match the session before doing any work.
	$expected_token = (string) ($_SESSION['token'] ?? '');
	$csrf_ok = install_csrf_ok($expected_token, (string) ($_POST['token'] ?? ''));

	if (!$csrf_ok) {
		$error['general'] = '<div class="error">Your session has expired or the security check failed. Please refresh the page and submit again.</div>';
		if ($isAjax) {
			install_json([
				'success'   => false,
				'retryable' => false,
				'errors'    => ['general' => $error['general']],
			]);
		}
	}

	// Validate the account fields. The rule checks live in a pure, unit-tested
	// helper (functions-install-helpers.php); $error['general'] from the CSRF check
	// above is preserved.
	$fieldErrors = install_validate_account($_POST, $rules);
	$error['user_email'] = $fieldErrors['user_email'];
	$error['user_name']  = $fieldErrors['user_name'];
	$error['user_pass']  = $fieldErrors['user_pass'];

	//if the CSRF check passed and no validation error occurred, create the account
	if ($csrf_ok && empty($error['user_email']) && empty($error['user_name']) && empty($error['user_pass'])) {

		//no error, so now set up the mysql database structures and the account
		$installer = new INSTALL();

		// Raw, unescaped values — these are bound as parameters below, so prepared
		// statements handle the escaping. No manual real_escape_string() needed.
		$user_email         = (string) ($_POST['user_email'] ?? '');
		$user_name          = (string) ($_POST['user_name'] ?? '');
		$user_timezone      = (string) ($_POST['user_timezone'] ?? '');
		$user_api           = (string) ($_POST['user_api'] ?? '');
		$user_time_register = time();

		// hash_user_pass() (password_hash/bcrypt) is always available via
		// functions-auth.php; fresh installs never use the legacy md5 path.
		$user_pass = hash_user_pass((string) $_POST['user_pass']);

		$hash = md5(uniqid((string) random_int(0, mt_getrandmax()), TRUE));
		$user_hash = ''; // reserved for future use

		// prepare-or-throw so every statement's return value is checked (CLAUDE.md #1)
		$prepare = static function (string $sql) use ($db): \mysqli_stmt {
			$stmt = $db->prepare($sql);
			if ($stmt === false) {
				throw new \RuntimeException('Failed to prepare statement: ' . $db->error, (int) $db->errno);
			}
			return $stmt;
		};

		// Build the schema, then create the user / preference / role rows atomically
		// so a mid-way failure can't leave a half-built account behind.
		$inTransaction = false;
		try {
			// DDL implicitly commits, so all table creation/seeding must happen
			// before we open our transaction.
			$installer->install_databases();

			if (!$db->begin_transaction()) {
				throw new \RuntimeException('Failed to start transaction: ' . $db->error, (int) $db->errno);
			}
			$inTransaction = true;

			$stmt = $prepare(
				"INSERT IGNORE INTO 202_users
					SET user_email=?, user_name=?, user_pass=?, user_timezone=?,
						user_time_register=?, install_hash=?, user_hash=?, p202_customer_api_key=?"
			);
			$stmt->bind_param(
				'ssssisss',
				$user_email,
				$user_name,
				$user_pass,
				$user_timezone,
				$user_time_register,
				$hash,
				$user_hash,
				$user_api
			);
			if (!$stmt->execute()) {
				// Capture the real driver errno (STRICT-only report mode means
				// execute() returns false instead of throwing) so the catch below
				// can tell a transient failure from a permanent one.
				$errno = (int) $stmt->errno;
				$stmtError = $stmt->error;
				$stmt->close();
				throw new \RuntimeException('Failed to insert user: ' . $stmtError, $errno);
			}
			$user_id = (int) $db->insert_id;
			// Whether THIS request created the user, vs. INSERT IGNORE finding an
			// existing row in the lookup below. Only a newly-created account gets a
			// default chart: 202_charts has no unique key, so inserting one for an
			// already-existing user (e.g. two concurrent same-username installs) would
			// duplicate it. The pref/role rows are INSERT IGNORE, so they're already safe.
			$user_created = $user_id > 0;
			$stmt->close();

			// INSERT IGNORE yields insert_id 0 when the row already exists;
			// look up the existing id so the rest of setup can proceed.
			if ($user_id === 0) {
				$stmt = $prepare("SELECT user_id FROM 202_users WHERE user_name=? LIMIT 1");
				$stmt->bind_param('s', $user_name);
				if (!$stmt->execute()) {
					$errno = (int) $stmt->errno;
					$stmtError = $stmt->error;
					$stmt->close();
					throw new \RuntimeException('Failed to look up user: ' . $stmtError, $errno);
				}
				$lookup = $stmt->get_result();
				if ($lookup && $row = $lookup->fetch_assoc()) {
					$user_id = (int) $row['user_id'];
				}
				$stmt->close();
			}

			if ($user_id <= 0) {
				throw new \RuntimeException('Could not determine a valid user_id');
			}

			$stmt = $prepare("INSERT IGNORE INTO 202_users_pref SET user_id=?");
			$stmt->bind_param('i', $user_id);
			if (!$stmt->execute()) {
				$errno = (int) $stmt->errno;
				$stmtError = $stmt->error;
				$stmt->close();
				throw new \RuntimeException('Failed to insert user preferences: ' . $stmtError, $errno);
			}
			$stmt->close();

			$stmt = $prepare("INSERT IGNORE INTO `202_user_role` (`user_id`, `role_id`) VALUES (?, 1)");
			$stmt->bind_param('i', $user_id);
			if (!$stmt->execute()) {
				$errno = (int) $stmt->errno;
				$stmtError = $stmt->error;
				$stmt->close();
				throw new \RuntimeException('Failed to insert user role: ' . $stmtError, $errno);
			}
			$stmt->close();

			if ($user_created) {
				// Default dashboard chart for the new account, keyed on the committed
				// $user_id. A rolled-back retry consumes the AUTO_INCREMENT id, so the
				// account isn't necessarily user 1; account_overview.php joins charts on
				// the user's own id, so a hard-coded id would leave the account chartless.
				$chart_data = 'a:3:{i:0;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:6:"clicks";}i:1;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:9:"click_out";}i:2;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:5:"leads";}}';
				$chart_range = 'days';
				$stmt = $prepare("INSERT INTO `202_charts` (`user_id`, `data`, `chart_time_range`) VALUES (?, ?, ?)");
				$stmt->bind_param('iss', $user_id, $chart_data, $chart_range);
				if (!$stmt->execute()) {
					$errno = (int) $stmt->errno;
					$stmtError = $stmt->error;
					$stmt->close();
					throw new \RuntimeException('Failed to insert default chart: ' . $stmtError, $errno);
				}
				$stmt->close();
			}

			if (!$db->commit()) {
				throw new \RuntimeException('Failed to commit transaction: ' . $db->error, (int) $db->errno);
			}
			$inTransaction = false;
		} catch (\Throwable $e) {
			if ($inTransaction) {
				$db->rollback();
			}
			$user_id = 0;
			error_log('Prosper202 install: account creation failed: ' . $e->getMessage());

			// Transient DB conditions are worth an automatic retry; anything else
			// gets an actionable message pointing at the manual fallbacks.
			$transientCodes = [1205 /* lock wait timeout */, 1213 /* deadlock */, 2006 /* server gone away */, 2013 /* lost connection */];
			if (in_array((int) $e->getCode(), $transientCodes, true)) {
				$retryable = true;
				$error['general'] = '<div class="error">The database was briefly unavailable while creating your account. This is usually temporary — please try again.</div>';
			} else {
				$retryable = false;
				$error['general'] = '<div class="error">We couldn\'t finish creating your account. You can '
					. '<a href="' . get_absolute_url() . '202-config/setup-config.php">re-check your database settings</a> '
					. 'or follow the <a href="http://support.tracking202.com/" target="_blank" rel="noopener noreferrer">manual install guide</a>, then try again.</div>';
			}
		}

		// Only continue post-setup once the account is committed. Every step here is
		// non-critical: the account already works, so failures become a friendly note
		// (CLAUDE.md-style explicit handling) instead of blocking a successful install.
		if ($user_id > 0) {
			// auto_cron registration calls an external service; treat any failure as optional.
			try {
				$cron = callAutoCron('register');
				if (is_array($cron) && ($cron['status'] ?? null) === 'success') {
					$stmt = $prepare("UPDATE 202_users_pref SET auto_cron = '1' WHERE user_id = ?");
					$stmt->bind_param('i', $user_id);
					if (!$stmt->execute()) {
						$errno = (int) $stmt->errno;
						$stmtError = $stmt->error;
						$stmt->close();
						throw new \RuntimeException('auto_cron flag update failed (' . $errno . '): ' . $stmtError);
					}
					$stmt->close();
				}
			} catch (\Throwable $e) {
				error_log('Prosper202 install: auto cron setup failed: ' . $e->getMessage());
				$install_warnings[] = 'Automatic cron setup didn\'t complete. Tracking still works — you can enable it later from <strong>Settings &rarr; Cron</strong>.';
			}

			// Generate a REST API v3 key for the new admin so the CLI and the
			// Claude onboarding agent can authenticate right away. This is the
			// local 202_api_keys Bearer key, distinct from the my.tracking202
			// install key (p202_customer_api_key) collected above. Best-effort:
			// failing to persist a key just means it isn't shown, not a failed install.
			$rest_api_key = bin2hex(random_bytes(32));
			$apikey_stmt = $db->prepare("INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (?, ?, ?)");
			if ($apikey_stmt === false) {
				$rest_api_key = '';
			} else {
				$apikey_created_at = time();
				// $user_id is already a real int in this flow (cast on insert and lookup).
				$apikey_stmt->bind_param('isi', $user_id, $rest_api_key, $apikey_created_at);
				if (!$apikey_stmt->execute()) {
					// Don't surface a key we failed to persist.
					$rest_api_key = '';
				}
				$apikey_stmt->close();
			}
			$html['rest_api_key'] = htmlentities($rest_api_key, ENT_QUOTES, 'UTF-8');
			$html['user_id'] = $user_id;

			// Daily email reports register with an external service; optional.
			try {
				registerDailyEmail('07', $user_timezone, $hash);
			} catch (\Throwable $e) {
				error_log('Prosper202 install: registerDailyEmail failed: ' . $e->getMessage());
				$install_warnings[] = 'We couldn\'t register daily email reports just now. This is optional and can be set up later from your dashboard.';
			}

			// Partitioning depends on MySQL features and is purely a performance step.
			try {
				$installer->install_database_partitions();
			} catch (\Throwable $e) {
				error_log('Prosper202 install: install_database_partitions failed: ' . $e->getMessage());
				$install_warnings[] = 'Database partitioning was skipped (your MySQL build may not support it). Tracking works normally; high-volume tuning can be added later.';
			}

			//if this worked, show them the success screen
			$success = true;
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

	// AJAX submissions get JSON: either the rendered success panel to swap in, or
	// the field/general errors plus whether a retry is worth attempting.
	if ($isAjax) {
		if ($success) {
			ob_start();
			$base_url = install_request_base_url($_SERVER, get_absolute_url());
			render_install_success($html, $install_warnings, get_absolute_url(), (string) ($_SERVER['SERVER_NAME'] ?? ''), $base_url);
			$panel = ob_get_clean();
			install_json([
				'success'  => true,
				'html'     => $panel,
				'warnings' => $install_warnings,
			]);
		}

		install_json([
			'success'   => false,
			'retryable' => $retryable,
			'errors'    => [
				'user_email' => $error['user_email'] ?? '',
				'user_name'  => $error['user_name'] ?? '',
				'user_pass'  => $error['user_pass'] ?? '',
				'general'    => $error['general'] ?? '',
			],
		]);
	}
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
		<small>Need Extra Help? Check out our <a href="http://support.tracking202.com/" target="_blank" rel="noopener noreferrer">ReadMe documentation</a>.</small>

		<h6>Create your account</h6>
		<small>Please provide the following information. Don't worry, you can always change these settings later.</small>
		<br><br>
		<div id="install-general-error"><?php echo $error['general'] ?? ''; ?></div>
		<form method="post" action="" class="form-horizontal" role="form" id="install-prosper202">

			<input type="hidden" name="token" value="<?php echo htmlentities((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
			<input type="hidden" class="form-control input-sm" id="user_api" name="user_api" value="<?php echo $html['user_api']; ?>">

			<div class="form-group <?php if ($error['user_email']) echo "has-error"; ?>">
				<label for="user_email" class="col-xs-4 control-label"><strong>Your Email:</strong></label>
				<div class="col-xs-8">
					<input type="text" class="form-control input-sm" id="user_email" name="user_email" value="<?php echo $html['user_email']; ?>">
					<div class="js-field-error" id="error-user_email"><?php echo $error['user_email']; ?></div>
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

						$selected = ($tz === $user_timezone) ? ' selected' : '';
						echo '<option value="' . $tz . '"' . $selected . '>' . $tz . ' [' . $abbr . ' ' . formatOffset($offset) . ']</option>';
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
					<div class="js-field-error" id="error-user_name"><?php echo $error['user_name']; ?></div>
				</div>
			</div>

			<div class="form-group <?php if ($error['user_pass']) echo "has-error"; ?>">
				<label for="user_pass" class="col-xs-4 control-label"><strong>Password:</strong></label>
				<div class="col-xs-8">
					<input type="password" class="form-control input-sm" id="user_pass" name="user_pass">
					<?php // Password errors render once, here at the top password field ?>
					<div class="js-field-error" id="error-user_pass"><?php echo $error['user_pass']; ?></div>
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
			(function () {
				var BASE = <?php echo json_encode(get_absolute_url()) ?: '""'; ?>;
				// Field rules come from the server ($rules) so client and server can't drift.
				var RULES = <?php echo json_encode($rules) ?: '{}'; ?>;
				var MAX_ATTEMPTS = 3; // 1 try + 2 auto-retries on transient/network failures
				var form = document.getElementById('install-prosper202');
				if (!form) { return; }
				var submitBtn = form.querySelector('button[type=submit]');

				function setHtml(id, html) {
					var el = document.getElementById(id);
					if (el) { el.innerHTML = html || ''; }
				}
				// Neutral (non-error) status shown in the general-error slot while a retry is pending.
				function showRetrying(nextAttempt) {
					setHtml('install-general-error', '<div style="margin-top:5px;padding:5px 10px;border-radius:4px;'
						+ 'font-size:12px;color:#31708f;background-color:#d9edf7;border:1px solid #bce8f1;">'
						+ 'Connection issue — retrying… (attempt ' + nextAttempt + ' of ' + MAX_ATTEMPTS + ')</div>');
				}
				function markGroup(name, on) {
					var input = form.querySelector('[name="' + name + '"]');
					if (!input) { return; }
					var group = input.closest('.form-group');
					if (group) { group.classList.toggle('has-error', !!on); }
				}
				function clearErrors() {
					setHtml('install-general-error', '');
					['user_email', 'user_name', 'user_pass'].forEach(function (f) { setHtml('error-' + f, ''); });
					['user_email', 'user_name', 'user_pass', 'verify_user_pass'].forEach(function (f) { markGroup(f, false); });
				}
				// Server messages already carry <div class="error"> markup; wrap plain client text to match.
				function wrap(msg) { return msg.indexOf('<div') !== -1 ? msg : '<div class="error">' + msg + '</div>'; }

				function showErrors(errors) {
					if (errors.general) { setHtml('install-general-error', wrap(errors.general)); }
					['user_email', 'user_name', 'user_pass'].forEach(function (f) {
						if (errors[f]) { setHtml('error-' + f, wrap(errors[f])); markGroup(f, true); }
					});
				}

				// Mirror the server-side validation so users get instant feedback before any round-trip.
				function clientValidate() {
					var errors = {};
					var email = (form.user_email.value || '').trim();
					var name = form.user_name.value || '';
					var pass = form.user_pass.value || '';
					var verify = form.verify_user_pass.value || '';
					// Permissive subset of the server's filter_var check: reject only input
					// that's obviously not an address, so we never block a value the server
					// would accept. The server stays the authoritative validator.
					if (!/^[^\s@]+@[^\s@]+$/.test(email)) {
						errors.user_email = 'Please enter a valid email address';
					}
					var nameErr = '';
					if (name === '') {
						nameErr += 'You must type in your desired username. ';
					} else {
						if (!/^[a-zA-Z0-9]+$/.test(name)) { nameErr += 'Your username may only contain alphanumeric characters. '; }
						if (name.length < RULES.username_min || name.length > RULES.username_max) { nameErr += 'Your username must be between ' + RULES.username_min + ' and ' + RULES.username_max + ' characters long. '; }
					}
					if (nameErr) { errors.user_name = nameErr.trim(); }
					var passErr = '';
					if (!pass) { passErr += 'You must type in your desired password. '; }
					if (!verify) { passErr += 'You must verify your password. '; }
					if (pass && pass.length < RULES.password_min) { passErr += 'Your password must be at least ' + RULES.password_min + ' characters long. '; }
					if (pass && pass.length > RULES.password_max) { passErr += 'Your password must be no more than ' + RULES.password_max + ' characters long. '; }
					if (pass && verify && pass !== verify) { passErr += 'Your passwords did not match, please try again. '; }
					if (passErr) { errors.user_pass = passErr.trim(); }
					return errors;
				}

				function busy(on) {
					submitBtn.disabled = on;
					document.body.style.cursor = on ? 'wait' : '';
					form.dataset.submitting = on ? '1' : '';
				}

				function postOnce() {
					return fetch(form.action || window.location.href, {
						method: 'POST',
						headers: { 'X-Requested-With': 'XMLHttpRequest' },
						body: new FormData(form),
						credentials: 'same-origin'
					}).then(function (resp) {
						var ct = resp.headers.get('content-type') || '';
						if (ct.indexOf('application/json') === -1) { throw new Error('unexpected-response'); }
						return resp.json();
					});
				}

				function attempt(n) {
					postOnce().then(function (res) {
						if (res.success) {
							var main = document.querySelector('.main.install') || document.querySelector('.main');
							if (main && res.html) {
								main.outerHTML = res.html;
								window.scrollTo(0, 0);
							} else {
								window.location.reload();
							}
							return;
						}
						if (res.retryable && n < MAX_ATTEMPTS) {
							showRetrying(n + 1);
							setTimeout(function () { attempt(n + 1); }, Math.pow(2, n) * 1000);
							return;
						}
						busy(false);
						clearErrors();
						showErrors(res.errors || {});
					}).catch(function () {
						// Network failure or a non-JSON response (e.g. a server error page).
						if (n < MAX_ATTEMPTS) {
							showRetrying(n + 1);
							setTimeout(function () { attempt(n + 1); }, Math.pow(2, n) * 1000);
							return;
						}
						busy(false);
						setHtml('install-general-error', '<div class="error">We\'re having trouble reaching the server. '
							+ 'Check your connection and try again. If it keeps happening you can '
							+ '<a href="' + BASE + '202-config/setup-config.php">re-check your database settings</a> '
							+ 'or follow the <a href="http://support.tracking202.com/" target="_blank" rel="noopener noreferrer">manual install guide</a>.</div>');
					});
				}

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (form.dataset.submitting === '1') { return; }
					clearErrors();
					var errors = clientValidate();
					if (Object.keys(errors).length) { showErrors(errors); return; }
					busy(true);
					attempt(1);
				});
			})();
			</script>
		</form>
	</div>
<?php info_bottom();
}


//if success is equal to true, and this campaign did complete
if ($success) {
	info_top();
	$base_url = install_request_base_url($_SERVER, get_absolute_url());
	render_install_success($html, $install_warnings, get_absolute_url(), (string) ($_SERVER['SERVER_NAME'] ?? ''), $base_url);
	info_bottom();
}
