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

// The license key reaches the installer from the fetch-a-key step via the
// user_api cookie that step sets (the portal returns the key to
// get_apikey.php in its query string — that is pre-existing). The cookie is
// no longer *required* to render the form: the key is validated when the form
// is submitted (below, with the same service the fetch-a-key page used), so a
// visitor who lands here directly sees the form rather than being bounced to
// a page that, on a fresh install, could not hand them a usable key. On a
// submit the posted field wins, so a validation error re-renders with the key
// the user sent.
if (isset($_POST['user_api'])) {
	$html['user_api'] = htmlentities((string) $_POST['user_api'], ENT_QUOTES, 'UTF-8');
} elseif (isset($_COOKIE['user_api'])) {
	$html['user_api'] = htmlentities((string) $_COOKIE['user_api'], ENT_QUOTES, 'UTF-8');
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

	// Validate the license key here, server-side, with the same service the
	// fetch-a-key page used. This is why the installer no longer depends on a
	// pre-login AJAX check that could not run before an account existed. Only
	// an explicit "Key valid" proceeds: an invalid key, or a service we cannot
	// reach, fails closed exactly as that page did (CLAUDE.md #11). Skipped
	// when the CSRF check already failed, so a forged POST does no network
	// work. The key is read only from the posted field and sent only to the
	// service — never placed in a redirect, header or log line.
	$license_ok = false;
	$typed_license_key = install_typed_license_key($_POST);
	if ($csrf_ok) {
		$license_ok = install_license_key_is_valid(api_key_validate($typed_license_key, $typed_licence));
		if (!$license_ok && ($error['general'] ?? '') === '') {
			$error['general'] = '<div class="error">We couldn\'t confirm that license key with my.tracking202.com. <a href="' . get_absolute_url() . '202-config/get_apikey.php">Fetch your key again</a>, then submit once more.</div>';
		}
	}

	//if the CSRF check passed, the license key is valid and no validation error occurred, create the account
	if ($csrf_ok && $license_ok && empty($error['user_email']) && empty($error['user_name']) && empty($error['user_pass'])) {

		//no error, so now set up the mysql database structures and the account
		$installer = new INSTALL();

		// Raw, unescaped values — these are bound as parameters below, so prepared
		// statements handle the escaping. No manual real_escape_string() needed.
		$user_email         = (string) ($_POST['user_email'] ?? '');
		$user_name          = (string) ($_POST['user_name'] ?? '');
		$user_timezone      = (string) ($_POST['user_timezone'] ?? '');
		$user_api           = $typed_license_key;
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
		try {
			// DDL implicitly commits, so all table creation/seeding must happen
			// before the account's transaction (InstallAccount::create()).
			$installer->install_databases();

			$user_id = \Prosper202\User\InstallAccount::create(new \Prosper202\Database\Connection($db), [
				'email' => $user_email,
				'name' => $user_name,
				'pass_hash' => $user_pass,
				'timezone' => $user_timezone,
				'registered' => $user_time_register,
				'install_hash' => $hash,
				'user_hash' => $user_hash,
				'customer_key' => $user_api,
			]);
		} catch (\Throwable $e) {
			$user_id = 0;
			error_log('Prosper202 install: account creation failed: ' . $e->getMessage());

			// Transient DB conditions are worth an automatic retry; anything else
			// gets an actionable message pointing at the manual fallbacks.
			if (\Prosper202\User\InstallAccount::isTransient($e)) {
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
			// The licence answer that let the install go ahead, for the grace
			// period; recorded now that its table exists.
			\Prosper202\License\ShellAccessCache::recordLicence(
				new \Prosper202\License\MysqlLicenceAnswerStore($db),
				$typed_license_key,
				$typed_licence ?? null
			);

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
	// Re-render with the key the user just submitted (it is a hidden field),
	// falling back to the cookie, so a validation error does not blank it.
	if (isset($_POST['user_api'])) {
		$html['user_api'] = htmlentities((string) $_POST['user_api'], ENT_QUOTES, 'UTF-8');
	} elseif (isset($_COOKIE['user_api'])) {
		$html['user_api'] = htmlentities((string) $_COOKIE['user_api'], ENT_QUOTES, 'UTF-8');
	}
	// Don't store password in HTML for security reasons

	// AJAX submissions get JSON: either the rendered success panel to swap in, or
	// the field/general errors plus whether a retry is worth attempting.
	if ($isAjax) {
		if ($success) {
			ob_start();
			$base_url = install_request_base_url($_SERVER, get_absolute_url());
			render_install_success($html, $install_warnings, get_absolute_url(), \Prosper202\Click\TrackingBaseUrl::requestHost($_SERVER), $base_url);
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

	// Database floors: database_version_check() in version.php
	$dbCheck = database_version_check((string) $db->server_info);
	$mysqlversion = $dbCheck['version'];
	if ($dbCheck['error'] !== null) {
		$version_error['mysqlversion'] = $dbCheck['error'];
	}

	$html['mysqlversion'] = htmlentities((string) $mysqlversion, ENT_QUOTES, 'UTF-8');

	if (!function_exists('curl_version')) {
		$version_error['curl'] = 'Prosper202 requires CURL to be installed.';
	}

	if ($version_error) {
		header("Location: " . get_absolute_url() . "202-config/requirements.php");
		exit;
	}

	info_top(['title' => 'Install - Prosper202 ClickServer', 'wide' => true]); ?>
	<div id="install-panel">
	<?php echo p202_standalone_card('Create your account', 'The last step of the five-minute install. You can change all of this later.'); ?>
		<div id="install-general-error"><?php if (($error['general'] ?? '') !== '') { ?><div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i><div class="p202-flash__body"><?php echo $error['general']; ?></div></div><?php } ?></div>
		<form method="post" action="" id="install-prosper202">

			<input type="hidden" name="token" value="<?php echo htmlentities((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
			<input type="hidden" id="user_api" name="user_api" value="<?php echo $html['user_api']; ?>">

			<div class="mb-3">
				<label for="user_email" class="form-label">Your email</label>
				<input type="email" class="form-control<?php echo $error['user_email'] ? ' is-invalid' : ''; ?>" id="user_email" name="user_email" value="<?php echo $html['user_email']; ?>" autocomplete="email" required>
				<div class="form-text">Where password resets and the daily report go.</div>
				<div class="invalid-feedback d-block js-field-error" id="error-user_email"><?php echo $error['user_email']; ?></div>
			</div>

			<div class="mb-3">
				<label for="user_timezone" class="form-label">Time zone</label>
				<?php
				// The browser's own zone is chosen below when the list has it;
				// until then, what was posted, the server's, or Los Angeles.
				$user_timezone = '';
				if (isset($_POST['user_timezone'])) {
				    $user_timezone = $_POST['user_timezone'];
				} elseif (isset($_SERVER['TZ'])) {
				    $user_timezone = $_SERVER['TZ'];
				} else {
				    $user_timezone = 'America/Los_Angeles';
				}

				$utc = new DateTimeZone('UTC');
				$dt = new DateTime('now', $utc);

				echo '<select class="form-select" name="user_timezone" id="user_timezone">';
				foreach (DateTimeZone::listIdentifiers() as $tz) {
					$current_tz = new DateTimeZone($tz);
					$offset =  $current_tz->getOffset($dt);
					$transition =  $current_tz->getTransitions($dt->getTimestamp(), $dt->getTimestamp());
					$abbr = $transition[0]['abbr'];

					$selected = ($tz === $user_timezone) ? ' selected' : '';
					echo '<option value="' . htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($tz . ' [' . $abbr . ' ' . formatOffset($offset) . ']', ENT_QUOTES, 'UTF-8') . '</option>';
				}
				echo '</select>';
				?>
				<div class="form-text" id="user_timezone_hint">Reports count days in this zone.</div>
				<script>
					// Choose the browser's own time zone when the list has it, and say so.
					(function() {
						try {
							var userTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
							var timezoneSelector = document.getElementById('user_timezone');
							if (timezoneSelector && userTimezone) {
								for (var i = 0; i < timezoneSelector.options.length; i++) {
									if (timezoneSelector.options[i].value === userTimezone) {
										timezoneSelector.selectedIndex = i;
										document.getElementById('user_timezone_hint').textContent = 'Reports count days in this zone. Chosen from your browser; change it if this server reports for somewhere else.';
										break;
									}
								}
							}
						} catch (e) {
							// No Intl support: the server's choice stands.
						}
					})();
				</script>
			</div>

			<div class="mb-3">
				<label for="user_name" class="form-label">Username</label>
				<input type="text" class="form-control<?php echo $error['user_name'] ? ' is-invalid' : ''; ?>" id="user_name" name="user_name" value="<?php echo $html['user_name']; ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
				<div class="form-text">Letters and numbers, <?php echo (int) $rules['username_min']; ?> to <?php echo (int) $rules['username_max']; ?> characters.</div>
				<div class="invalid-feedback d-block js-field-error" id="error-user_name"><?php echo $error['user_name']; ?></div>
			</div>

			<div class="row g-3 mb-3">
				<div class="col-sm-6">
					<label for="user_pass" class="form-label">Password</label>
					<input type="password" class="form-control<?php echo $error['user_pass'] ? ' is-invalid' : ''; ?>" id="user_pass" name="user_pass" autocomplete="new-password" required>
				</div>
				<div class="col-sm-6">
					<label for="verify_user_pass" class="form-label">Type it again</label>
					<input type="password" class="form-control<?php echo $error['user_pass'] ? ' is-invalid' : ''; ?>" id="verify_user_pass" name="verify_user_pass" autocomplete="new-password" required>
				</div>
				<?php // Password errors render once, under both password fields ?>
				<div class="col-12 mt-1">
					<div class="form-text mt-0">At least <?php echo (int) $rules['password_min']; ?> characters.</div>
					<div class="invalid-feedback d-block js-field-error" id="error-user_pass"><?php echo $error['user_pass']; ?></div>
				</div>
			</div>

			<button class="btn btn-primary btn-lg w-100" type="submit">Install Prosper202 ClickServer</button>
			<p class="small text-secondary mt-3 mb-0">Need help? Read the <a href="http://support.tracking202.com/" target="_blank" rel="noopener noreferrer">install guide</a>.</p>
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
				// A flash in the kit's shape; html is the server's own markup or text this script wrote.
				function flash(kind, icon, html) {
					return '<div class="alert alert-' + kind + ' p202-flash" role="' + (kind === 'danger' ? 'alert' : 'status') + '"><i class="bi ' + icon + '"></i><div class="p202-flash__body">' + html + '</div></div>';
				}
				// Neutral (non-error) status shown in the general-error slot while a retry is pending.
				function showRetrying(nextAttempt) {
					setHtml('install-general-error', flash('info', 'bi-arrow-repeat', 'Connection issue — retrying… (attempt ' + nextAttempt + ' of ' + MAX_ATTEMPTS + ')'));
				}
				// The fields a message belongs to: a password message marks both password fields.
				var FIELDS = { user_email: ['user_email'], user_name: ['user_name'], user_pass: ['user_pass', 'verify_user_pass'] };
				function markField(name, on) {
					(FIELDS[name] || []).forEach(function (f) {
						var input = form.querySelector('[name="' + f + '"]');
						if (input) { input.classList.toggle('is-invalid', !!on); }
					});
				}
				function clearErrors() {
					setHtml('install-general-error', '');
					['user_email', 'user_name', 'user_pass'].forEach(function (f) { setHtml('error-' + f, ''); markField(f, false); });
				}
				// Server messages already carry <div class="error"> markup; wrap plain client text to match.
				function wrap(msg) { return msg.indexOf('<div') !== -1 ? msg : '<div class="error">' + msg + '</div>'; }

				function showErrors(errors) {
					if (errors.general) { setHtml('install-general-error', flash('danger', 'bi-x-circle', wrap(errors.general))); }
					['user_email', 'user_name', 'user_pass'].forEach(function (f) {
						if (errors[f]) { setHtml('error-' + f, wrap(errors[f])); markField(f, true); }
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
							var panel = document.getElementById('install-panel');
							if (panel && res.html) {
								panel.outerHTML = res.html;
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
						setHtml('install-general-error', flash('danger', 'bi-x-circle', 'We\'re having trouble reaching the server. '
							+ 'Check your connection and try again. If it keeps happening you can '
							+ '<a href="' + BASE + '202-config/setup-config.php">re-check your database settings</a> '
							+ 'or follow the <a href="http://support.tracking202.com/" target="_blank" rel="noopener noreferrer">manual install guide</a>.'));
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
	<?php echo p202_standalone_card_end(); ?>
	</div>
<?php info_bottom();
}


//if success is equal to true, and this campaign did complete
if ($success) {
	info_top(['title' => 'Installed - Prosper202 ClickServer', 'wide' => true]);
	$base_url = install_request_base_url($_SERVER, get_absolute_url());
	render_install_success($html, $install_warnings, get_absolute_url(), \Prosper202\Click\TrackingBaseUrl::requestHost($_SERVER), $base_url);
	info_bottom();
}
