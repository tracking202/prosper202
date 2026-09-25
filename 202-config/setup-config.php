<?php
declare(strict_types=1);
//include functions
require_once(__DIR__ . '/functions.php');

// The wizard runs before 202-config.php exists, so connect.php (which starts
// the session and mints the token) cannot load; the token is minted here, in
// the same session the rest of the install keeps.
$wizard_token = p202_standalone_wizard_token();


//check to see if the sample config file exists
if (!file_exists(substr(__DIR__, 0, -10) . '/202-config-sample.php')) {
	_die('Sorry, I need a 202-config-sample.php file to work from. Please re-upload this file from your Prosper202 installation.');
}


//lets make a new config file
$configFile = file(substr(__DIR__, 0, -10) . '/202-config-sample.php');


//check to see if the directory is writable
if (!is_writable(substr(__DIR__, 0, -10) . '/')) {
	_die("Sorry, I can't write to the directory. You'll have to either change the permissions on your Prosper202 directory or create your 202-config.php manually.");
}


// Escape a value for inclusion in a single-quoted PHP string in 202-config.php
function escape_config_value(string $value): string
{
	return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
}

// Inverse of escape_config_value(), for reading values back out of the file
function unescape_config_value(string $value): string
{
	return strtr($value, ['\\\\' => '\\', "\\'" => "'"]);
}

// Matches "$var = 'value';" config lines. The value part accepts any
// character, with \' and \\ treated as escape sequences, so hostnames with
// dots/hyphens and escaped passwords written by this wizard round-trip.
$config_line_re = '/(\$\w+) = \'((?:[^\'\\\\]|\\\\.)*)\';/';

// Check if 202-config.php has been created
if (file_exists(substr(__DIR__, 0, -10) . '/202-config.php')) {
	//_die("<p>The file '202-config.php' already exists. If you need to reset any of the configuration items in this file, please delete it first. You may try <a href='install.php'>installing now</a>.</p>");
	$handle = fopen(substr(__DIR__, 0, -10) . '/202-config.php', 'r');
	$old = [];
	while ($handle !== false && !feof($handle)) {
		$line = fgets($handle);
		if ($line === false) {
			continue;
		}
		$matches = [];
			if (preg_match($config_line_re, $line, $matches)) {
			$value = unescape_config_value($matches[2]);
			switch ($matches[1]) {
			case '$dbname':
				$odbname = $value;
				break;
			case '$dbuser':
				$odbuser = $value;
				break;
			case '$dbpass':
				$odbpass = $value;
				break;
			case '$dbhost':
				$odbhost = $value;
				break;
			case '$dbhostro':
				$odbhostro = $value;
				break;
			case '$mchost':
				$omchost = $value;
				break;
			}
		}
	}
	if ($handle !== false) {
		fclose($handle);
	}
}



/**
 * Whether the 202-config.php already here belongs to a finished install.
 *
 * Until U7 the wizard rewrote 202-config.php for anyone who posted to it, on
 * a running install too: one unauthenticated POST naming another database
 * server pointed the whole install at it. The wizard is for an install that
 * has no working configuration yet, so it now refuses when the file exists
 * and its database answers with an account in it. It answers "locked" when
 * it cannot tell — a configuration it cannot connect with, or a query that
 * fails — because a question it cannot answer must not open the door (error
 * pattern #11): editing 202-config.php on the server always works.
 */
function p202_setup_config_locked(bool $configExists, ?string $host, ?string $user, ?string $pass, ?string $name): bool
{
	if (!$configExists) {
		return false;
	}
	if ($host === null || $user === null || $name === null) {
		return true;
	}
	mysqli_report(MYSQLI_REPORT_OFF);
	$probe = @mysqli_connect($host, $user, (string) $pass, $name);
	if (!$probe) {
		return true;
	}
	$tables = mysqli_query($probe, "SHOW TABLES LIKE '202\\_users'");
	if ($tables === false) {
		mysqli_close($probe);
		return true;
	}
	$hasUsersTable = mysqli_num_rows($tables) > 0;
	mysqli_free_result($tables);
	if (!$hasUsersTable) {
		mysqli_close($probe);
		return false; // configured, never installed: the wizard may redo it
	}
	$count = mysqli_query($probe, 'SELECT COUNT(*) AS cnt FROM 202_users');
	$row = $count ? mysqli_fetch_assoc($count) : null;
	mysqli_close($probe);
	return !is_array($row) || (int) $row['cnt'] > 0;
}

$config_locked = p202_setup_config_locked(
	file_exists(substr(__DIR__, 0, -10) . '/202-config.php'),
	$odbhost ?? null,
	$odbuser ?? null,
	$odbpass ?? null,
	$odbname ?? null
);

if (isset($_GET['step'])) {
	$step = $_GET['step'];
} else {
	$step = 0;
}

if ($config_locked) {
	_die("<h6>Prosper202 is already set up</h6>
		<p><small>This install already has a <code>202-config.php</code> and an account, so the setup wizard will not change its database settings. To change them, edit <code>202-config.php</code> on the server.</small></p>
		<p><a class='btn btn-primary w-100' href='" . get_absolute_url() . "202-login.php'>Sign in</a></p>");
}



switch ($step) {
	case 0:
		info_top(['title' => 'Set up - Prosper202 ClickServer', 'wide' => true]);
		echo p202_standalone_card('Welcome to Prosper202', 'Before getting started, Prosper202 needs to know how to reach your database.');
?>
			<p>Have these ready; your host supplies them:</p>
			<ul>
				<li>Database name</li>
				<li>Database username and password</li>
				<li>Database host</li>
				<li>Reporting database host and Memcache host (optional)</li>
			</ul>
			<p class="small text-secondary">All this step does is write them into <code>202-config.php</code>. If it cannot, open <code>202-config-sample.php</code> in a text editor, fill in your details and save it as <code>202-config.php</code>.</p>
			<a href="setup-config.php?step=1" class="btn btn-primary w-100">Let&#8217;s go</a>
<?php
		echo p202_standalone_card_end();

		info_bottom();

		break;

	case 1:
	case 1.1:
		info_top(['title' => 'Database - Prosper202 ClickServer', 'wide' => true]);
		if ($step == 1) {
			$msg = "Enter your database connection details. If you're not sure about these, contact your host.";
			$action = "setup-config.php?step=2";
		} else {
			$msg = "Your 202-config.php needs to be updated to a new format. Please review the settings we got from your old file and make changes as needed.";
			$action = "setup-config.php?step=2.2";
		}
		echo p202_standalone_card('Your database', $msg);
		?>
			<form method="post" action="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" id="setup-config-form">
				<input type="hidden" name="token" value="<?php echo htmlspecialchars($wizard_token, ENT_QUOTES, 'UTF-8'); ?>">
				<div class="mb-3">
					<label for="dbname" class="form-label">Database name</label>
					<input type="text" class="form-control" id="dbname" name="dbname" value="<?php echo htmlspecialchars($odbname ?? 'prosper202', ENT_QUOTES, 'UTF-8'); ?>" required>
					<div class="form-text">The database to run Prosper202 in. It is created if it does not exist and your user may create it.</div>
				</div>
				<div class="row g-3 mb-3">
					<div class="col-sm-6">
						<label for="dbuser" class="form-label">Username</label>
						<input type="text" class="form-control" id="dbuser" name="dbuser" value="<?php echo htmlspecialchars($odbuser ?? 'root', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required>
					</div>
					<div class="col-sm-6">
						<label for="dbpass" class="form-label">Password</label>
						<input type="password" class="form-control" id="dbpass" name="dbpass" value="<?php echo htmlspecialchars($odbpass ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
					</div>
				</div>
				<div class="mb-3">
					<label for="dbhost" class="form-label">Database host</label>
					<input type="text" class="form-control" id="dbhost" name="dbhost" value="<?php echo htmlspecialchars($odbhost ?? 'localhost', ENT_QUOTES, 'UTF-8'); ?>" required>
					<div class="form-text">Almost always localhost.</div>
				</div>
				<details class="p202-disclosure mb-3" data-p202-remember="setup-config-advanced">
					<summary>Advanced <span class="p202-disclosure__hint">reporting database, Memcache, table prefix</span></summary>
					<div class="p202-disclosure__body">
						<div class="mb-3">
							<label for="dbhostro" class="form-label">Reporting database host</label>
							<input type="text" class="form-control" id="dbhostro" name="dbhostro" value="<?php echo htmlspecialchars($odbhostro ?? 'localhost', ENT_QUOTES, 'UTF-8'); ?>">
							<div class="form-text">A dedicated database for reports, if you have one. Leave it as localhost if not.</div>
						</div>
						<div class="mb-3">
							<label for="mchost" class="form-label">Memcache host</label>
							<input type="text" class="form-control" id="mchost" name="mchost" value="<?php echo htmlspecialchars($omchost ?? 'localhost', ENT_QUOTES, 'UTF-8'); ?>">
							<div class="form-text">Leave it alone if you do not know what it is.</div>
						</div>
						<div class="mb-1">
							<label for="prefix" class="form-label">Table prefix</label>
							<input type="text" class="form-control" id="prefix" name="prefix" value="202_" readonly>
							<div class="form-text">Always 202_; it cannot be changed.</div>
						</div>
					</div>
				</details>
				<button class="btn btn-primary w-100" type="submit">Save database settings</button>
			</form>
<?php
		echo p202_standalone_card_end();

		info_bottom();
		break;

	case 2:
	case 2.2:
		// Writing 202-config.php is a POST from the form above, carrying the
		// session token; a link or a cross-site form writes nothing.
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !p202_standalone_wizard_token_ok()) {
			_die("<h6>Security check failed</h6>
			<p><small>Your session has expired or the security check failed, so nothing was written. Enter your database settings again.</small></p>
			<p><a href='setup-config.php?step=1' class='btn btn-primary w-100'>Enter the database settings</a></p>");
		}

		$dbname  = trim((string) ($_POST['dbname'] ?? ''));
		$dbuser   = trim((string) ($_POST['dbuser'] ?? ''));
		$dbpass = trim((string) ($_POST['dbpass'] ?? ''));
		$dbhost  = trim((string) ($_POST['dbhost'] ?? ''));
		$dbhostro  = trim((string) ($_POST['dbhostro'] ?? ''));
		$mchost  = trim((string) ($_POST['mchost'] ?? ''));

		// Probe the connection with return values rather than exceptions so we
		// can distinguish "host/credentials wrong" from "database not created
		// yet" and try to create the database ourselves.
		mysqli_report(MYSQLI_REPORT_OFF);

		// First connect to the server WITHOUT a database, so a missing database
		// isn't mistaken for bad credentials.
		$connect = mysqli_connect($dbhost, $dbuser, $dbpass);

		//if it could not connect, error
		if (!$connect) {
			$db_error_msg = htmlspecialchars((string) mysqli_connect_error(), ENT_QUOTES, 'UTF-8');
			$db_error_no = (int) mysqli_connect_errno();
			$dbhost_html = htmlspecialchars($dbhost, ENT_QUOTES, 'UTF-8');

			_die("<h6>Error establishing a database connection</h6>
			<p><small>This either means that the username and password information is incorrect or we can't contact the database server at <code>$dbhost_html</code>. This could mean your host's database server is down.</small></p>
			<p class='text-danger'><strong>MySQL Error ($db_error_no): $db_error_msg</strong></p>
			<small>
			<ul>
				<li>Are you sure you have the correct username and password?</li>
				<li>Are you sure that you have typed the correct hostname?</li>
				<li>Are you sure that the database server is running?</li>
			</ul>
			</small>
			<p><small>If you're unsure what these terms mean you should probably contact your host. If you still need help, please visit the <a href='http://support.tracking202.com/how-to-set-up-and-use-prosper202-pro/installing-prosper202?utm_source=db-install-error'>Prosper202 Support Site</a>.</small> </p>
			<p><a href='setup-config.php?step=1' class='btn btn-primary w-100'>Go back and enter your database credentials again</a></p>
		");
		}

		// Host + credentials are good. Select the database; if it doesn't exist
		// yet, try to create it. On shared hosting the account often lacks the
		// CREATE privilege (the DB must be made in the control panel first), so
		// fail gracefully with that guidance. Check every return value.
		$create_error = '';
		if (!mysqli_select_db($connect, $dbname)) {
			$dbname_quoted = str_replace('`', '``', $dbname);
			$create_sql = "CREATE DATABASE IF NOT EXISTS `$dbname_quoted` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
			if (!mysqli_query($connect, $create_sql)) {
				$create_error = mysqli_error($connect);
			} elseif (!mysqli_select_db($connect, $dbname)) {
				$create_error = mysqli_error($connect) ?: 'could not select the database after creating it';
			}
		}
		if ($create_error !== '') {
			$dbname_html = htmlspecialchars($dbname, ENT_QUOTES, 'UTF-8');
			$dbhost_html = htmlspecialchars($dbhost, ENT_QUOTES, 'UTF-8');
			$dbuser_html = htmlspecialchars($dbuser, ENT_QUOTES, 'UTF-8');
			$create_error_html = htmlspecialchars($create_error, ENT_QUOTES, 'UTF-8');
			_die("<h6>Could not create the database</h6>
			<p><small>We connected to <code>$dbhost_html</code> as <code>$dbuser_html</code>, but the database <code>$dbname_html</code> does not exist and we couldn't create it. On shared hosting you usually have to create the database yourself first (for example cPanel &rarr; <em>MySQL Databases</em>), then come back and re-run this step.</small></p>
			<p class='text-danger'><strong>MySQL Error: $create_error_html</strong></p>
			<p><a href='setup-config.php?step=1' class='btn btn-primary w-100'>Go back and check your database details</a></p>
		");
		}

		$configPath = substr(__DIR__, 0, -10) . '/202-config.php';
		$handle = fopen($configPath, 'w');
		if ($handle === false) {
			_die("<p>Could not open <code>202-config.php</code> for writing. Please check the directory permissions, or create the file manually from <code>202-config-sample.php</code>.</p>");
		}

		foreach ($configFile as $line) {
			if (!preg_match($config_line_re, $line, $matches)) {
				fwrite($handle, $line);
				continue;
			}
			match ($matches[1]) {
                '$dbname' => fwrite($handle, str_replace("putyourdbnamehere", escape_config_value($dbname), $line)),
                '$dbuser' => fwrite($handle, str_replace("usernamehere", escape_config_value($dbuser), $line)),
                '$dbpass' => fwrite($handle, str_replace("yourpasswordhere", escape_config_value($dbpass), $line)),
                '$dbhost' => fwrite($handle, str_replace("localhosthere", escape_config_value($dbhost), $line)),
                '$dbhostro' => fwrite($handle, str_replace("localhostreplica", escape_config_value($dbhostro), $line)),
                '$mchost' => fwrite($handle, str_replace("localhostmemcache", escape_config_value($mchost), $line)),
                default => fwrite($handle, $line),
            };
		}
		fclose($handle);
		// Owner/group read only — this file holds database credentials
		chmod($configPath, 0640);

			_die("<h6>Database connected</h6><p><small>All right sparky! You've made it through this part of the installation. Prosper202 can now communicate with your database.</small></p><p><a class='btn btn-primary w-100' href=\"requirements.php\">Continue the install</a></p>");
	}
