<?php
declare(strict_types=1);
//include mysql settings
include_once(__DIR__ . '/connect.php');
include_once(__DIR__ . '/functions-install.php');
if (!isset($db) || !($db instanceof mysqli)) {
	_die('Database connection unavailable.');
}

// Detect XHR/AJAX submissions so we can answer with JSON instead of a full page.
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
	&& strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Non-critical issues collected during install. They're surfaced as a gentle note
// on the success screen instead of blocking the install — all are safe to fix later.
$install_warnings = [];

// Whether the last account-creation failure looks transient and worth a retry.
$retryable = false;

/**
 * Emit a JSON response for the AJAX install flow and stop. Any buffered output is
 * discarded first so the body is always valid JSON. A json_encode() failure is
 * surfaced explicitly rather than sending an empty body (CLAUDE.md #4).
 *
 * @param array<string,mixed> $payload
 */
function install_json(array $payload): never
{
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	header('Content-Type: application/json; charset=utf-8');
	$json = json_encode($payload);
	if ($json === false) {
		http_response_code(500);
		echo '{"success":false,"retryable":true,"errors":{"general":"<div class=\"error\">The server hit an unexpected error encoding its response. Please try again.</div>"}}';
		exit;
	}
	echo $json;
	exit;
}

/**
 * Render just the post-install success panel (the .main block), so it can be
 * swapped into the page over AJAX or printed directly on the no-JS path.
 * $warnings are developer-authored, safe HTML strings.
 *
 * @param array<string,string> $html
 * @param list<string>         $warnings
 */
function render_install_success(array $html, array $warnings): void
{
	$base = get_absolute_url();
	?>
	<div class="main col-xs-7 install">
		<center><img src="<?php echo $base; ?>202-img/prosper202.png"></center>
		<h6>Success!</h6>
		<small>Prosper202 has been installed. Now you can <a href="<?php echo $base; ?>202-login.php">log in</a>.</small><br></br>
		<div class="row" style="margin-bottom: 10px;">
			<div class="col-xs-3"><span class="label label-default">Username:</span></div>
			<div class="col-xs-9"><span class="label label-primary"><?php echo $html['user_name']; ?></span></div>
		</div>
		<div class="row" style="margin-bottom: 10px;">
			<div class="col-xs-3"><span class="label label-default">Login address:</span></div>
			<div class="col-xs-9"><small><?php printf('<a href="%s202-login.php">%s202-login.php</a>', $base, $_SERVER['SERVER_NAME'] . $base); ?></small></div>
		</div>
		<?php if ($warnings) { ?>
			<div style="margin: 12px 0; padding: 8px 12px; border: 1px solid #faebcc; background: #fcf8e3; color: #8a6d3b; border-radius: 4px; font-size: 12px;">
				<strong>You're all set — a couple of optional steps need a quick follow-up:</strong>
				<ul style="margin: 6px 0 0 18px;">
					<?php foreach ($warnings as $w) { echo '<li>' . $w . '</li>'; } ?>
				</ul>
			</div>
		<?php } ?>
		<p><small>Were you expecting more steps? Sorry thats it!</small></p>
	</div>
	<?php
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
	'user_api' => ''
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
				'general' => '<div class="error">Prosper202 is already installed. <a href="' . get_absolute_url() . '202-login.php">Log in now</a>.</div>',
			],
		]);
	}

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
				throw new \RuntimeException('Failed to prepare statement: ' . $db->error);
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

			$db->begin_transaction();
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
				$stmt->close();
				throw new \RuntimeException('Failed to insert user: ' . $db->error);
			}
			$user_id = (int) $db->insert_id;
			$stmt->close();

			// INSERT IGNORE yields insert_id 0 when the row already exists;
			// look up the existing id so the rest of setup can proceed.
			if ($user_id === 0) {
				$stmt = $prepare("SELECT user_id FROM 202_users WHERE user_name=? LIMIT 1");
				$stmt->bind_param('s', $user_name);
				if (!$stmt->execute()) {
					$stmt->close();
					throw new \RuntimeException('Failed to look up user: ' . $db->error);
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
				$stmt->close();
				throw new \RuntimeException('Failed to insert user preferences: ' . $db->error);
			}
			$stmt->close();

			$stmt = $prepare("INSERT IGNORE INTO `202_user_role` (`user_id`, `role_id`) VALUES (?, 1)");
			$stmt->bind_param('i', $user_id);
			if (!$stmt->execute()) {
				$stmt->close();
				throw new \RuntimeException('Failed to insert user role: ' . $db->error);
			}
			$stmt->close();

			$db->commit();
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
					. 'or follow the <a href="http://support.tracking202.com/" target="_blank">manual install guide</a>, then try again.</div>';
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
						$stmt->close();
						throw new \RuntimeException('auto_cron flag update failed: ' . $db->error);
					}
					$stmt->close();
				}
			} catch (\Throwable $e) {
				error_log('Prosper202 install: auto cron setup failed: ' . $e->getMessage());
				$install_warnings[] = 'Automatic cron setup didn\'t complete. Tracking still works — you can enable it later from <strong>Settings &rarr; Cron</strong>.';
			}

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
			render_install_success($html, $install_warnings);
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
		<small>Need Extra Help? Check out our <a href="http://support.tracking202.com/" target="_blank">ReadMe documentation</a>.</small>

		<h6>Create your account</h6>
		<small>Please provide the following information. Don't worry, you can always change these settings later.</small>
		<br><br>
		<div id="install-general-error"><?php echo $error['general'] ?? ''; ?></div>
		<form method="post" action="" class="form-horizontal" role="form" id="install-prosper202">

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
				var BASE = <?php echo json_encode(get_absolute_url()); ?>;
				var MAX_ATTEMPTS = 3; // 1 try + 2 auto-retries on transient/network failures
				var form = document.getElementById('install-prosper202');
				if (!form) { return; }
				var submitBtn = form.querySelector('button[type=submit]');

				function setHtml(id, html) {
					var el = document.getElementById(id);
					if (el) { el.innerHTML = html || ''; }
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
					if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
						errors.user_email = 'Please enter a valid email address';
					}
					var nameErr = '';
					if (name === '') {
						nameErr += 'You must type in your desired username. ';
					} else {
						if (!/^[a-zA-Z0-9]+$/.test(name)) { nameErr += 'Your username may only contain alphanumeric characters. '; }
						if (name.length < 4 || name.length > 20) { nameErr += 'Your username must be between 4 and 20 characters long. '; }
					}
					if (nameErr) { errors.user_name = nameErr.trim(); }
					var passErr = '';
					if (!pass) { passErr += 'You must type in your desired password. '; }
					if (!verify) { passErr += 'You must verify your password. '; }
					if (pass && pass.length < 6) { passErr += 'Your password must be at least 6 characters long. '; }
					if (pass && pass.length > 35) { passErr += 'Your password must be no more than 35 characters long. '; }
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
							setTimeout(function () { attempt(n + 1); }, Math.pow(2, n) * 1000);
							return;
						}
						busy(false);
						clearErrors();
						showErrors(res.errors || {});
					}).catch(function () {
						// Network failure or a non-JSON response (e.g. a server error page).
						if (n < MAX_ATTEMPTS) {
							setTimeout(function () { attempt(n + 1); }, Math.pow(2, n) * 1000);
							return;
						}
						busy(false);
						setHtml('install-general-error', '<div class="error">We\'re having trouble reaching the server. '
							+ 'Check your connection and try again. If it keeps happening you can '
							+ '<a href="' + BASE + '202-config/setup-config.php">re-check your database settings</a> '
							+ 'or follow the <a href="http://support.tracking202.com/" target="_blank">manual install guide</a>.</div>');
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
	render_install_success($html, $install_warnings);
	info_bottom();
}
