<?php
include_once(str_repeat("../", 1) . '202-config/connect.php');
include_once(str_repeat("../", 1) . '202-config/functions-timeframe.php');
include_once(str_repeat("../", 1) . '202-config/functions-db.php');
include_once(str_repeat("../", 1) . '202-config/functions-indexes.php');
include_once(str_repeat("../", 1) . '202-config/functions-icons.php');
include_once(str_repeat("../", 1) . '202-config/functions-api.php');
include_once(str_repeat("../", 1) . '202-config/functions-utils.php');
include_once(str_repeat("../", 1) . '202-config/functions-empty.php');
require_once __DIR__ . '/../202-config/functions-account-ui.php';

AUTH::require_user();

/*
 * Account › Settings (administration), on the v2 shell.
 *
 * The page the account menu shows only to users with access_to_settings, and
 * now the page itself asks the same question: the classic page answered
 * anyone signed in, including the click-data deletion form.
 *
 * Every form posts the session token under `token`, and every handler checks
 * it before writing and says so when it refuses. The AutoCron and MaxMind
 * switches were AJAX posts from the classic shell's scripts; they are plain
 * forms now, posting the same fields (`autocron` 1/0, `maxmind` true/false).
 */

if (!isset($userObj) || !$userObj->hasPermission('access_to_settings')) {
	header('location: ' . get_absolute_url() . '202-account/');
	exit;
}

$slack = false;
$mysql['user_own_id'] = $db->real_escape_string((string) $_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url, maxmind_isp, user_time_register, 2up.user_auto_database_optimization_days FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();
$username = $user_row['username'];
$user_time_register = $user_row['user_time_register'];

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

/** Settings this page changes, and the field errors of a refused submit. */
$fieldErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/administration.php');
	}
}

if (isset($_POST['autocron'])) {
	$autocron = false;
	if ($_POST['autocron'] == true) {
		$endpoint = 'register';
		$sql = "SELECT * FROM 202_cronjob_logs";
		$result = _mysqli_query($sql);
		if ($result && $result->num_rows > 0) {
			$row = $result->fetch_assoc();

			$last_five_minutes = time() - 300;

			if ($row['last_execution_time'] < $last_five_minutes) {
				$autocron = true;
			}
		} else {
			$autocron = true;
		}
	} else {
		$endpoint = 'deregister';
		$autocron = true;
	}

	if (!$autocron) {
		p202_account_flash('info', 'Your own cron job ran in the last five minutes, so AutoCron is not needed and was left off.');
		p202_account_redirect('202-account/administration.php#autocron');
	}

	$cron = callAutoCron($endpoint);

	if (is_array($cron) && ($cron['status'] ?? null) === 'success') {
		$mysql['auto_cron'] = $db->real_escape_string($_POST['autocron'] == true ? '1' : '0');
		$mysql['user_id'] = $db->real_escape_string((string) $_SESSION['user_id']);
		$sql = "UPDATE 202_users_pref SET auto_cron = '" . $mysql['auto_cron'] . "' WHERE user_id = '" . $mysql['user_id'] . "'";
		if (_mysqli_query($sql)) {
			p202_account_flash('ok', $_POST['autocron'] == true ? 'AutoCron is on.' : 'AutoCron is off.');
		} else {
			p202_account_flash('bad', 'AutoCron answered, but the setting could not be saved here; try again.');
		}
	} else {
		p202_account_flash('bad', 'The AutoCron service could not be reached, so the setting is unchanged. Try again in a few minutes.');
	}
	p202_account_redirect('202-account/administration.php#autocron');
}

if (isset($_POST['maxmind'])) {
	if ($_POST['maxmind'] == "true") {
		// Same resolution as getisp() in connect2.php: P202_GEO_DIR (persistent
		// volume on container deployments) or the in-docroot default.
		$geo_dir = getenv('P202_GEO_DIR') ?: substr(__DIR__, 0, -12) . '/202-config/geo';
		if (file_exists($geo_dir . '/GeoIP2-ISP.mmdb') || file_exists($geo_dir . '/GeoIPISP.dat')) {
			$mysql['user_id'] = $db->real_escape_string((string) $_SESSION['user_id']);
			$sql = "UPDATE 202_users_pref SET maxmind_isp='1' WHERE user_id='" . $mysql['user_id'] . "'";
			if (_mysqli_query($sql)) {
				if ($slack)
					$slack->push('maxmind_isp_changed', ['user' => $username, 'type' => 'Activated']);
				p202_account_flash('ok', 'ISP and carrier lookup is on. Live traffic picks it up within five minutes.');
			} else {
				p202_account_flash('bad', 'ISP and carrier lookup could not be turned on; try again.');
			}
		} else {
			p202_account_flash('bad', "The ISP database file isn't there, so lookup stays off. Upload GeoIP2-ISP.mmdb (or legacy GeoIPISP.dat) to "
				. (getenv('P202_GEO_DIR') ?: '/202-config/geo') . ', then turn it on.');
		}
	}

	if ($_POST['maxmind'] == "false") {
		$mysql['user_id'] = $db->real_escape_string((string) $_SESSION['user_id']);
		$sql = "UPDATE 202_users_pref SET maxmind_isp='0' WHERE user_id='" . $mysql['user_id'] . "'";
		if (_mysqli_query($sql)) {
			if ($slack)
				$slack->push('maxmind_isp_changed', ['user' => $username, 'type' => 'Deactivated']);
			p202_account_flash('ok', 'ISP and carrier lookup is off.');
		} else {
			p202_account_flash('bad', 'ISP and carrier lookup could not be turned off; try again.');
		}
	}

	p202_account_redirect('202-account/administration.php#maxmind');
}

if (isset($_POST['database_management'])) {
	// The date comes from a native date input (YYYY-MM-DD); the classic
	// datepicker posted DD-MM-YYYY, which is still read. An empty or
	// unreadable date is refused: strtotime() of the classic concatenation
	// with no date in it resolved to today, so a blank submit (the classic
	// label said "leave blank to reset") queued every click before today for
	// deletion.
	$postedDate = trim((string) $_POST['database_management']);
	$eraseDate = DateTime::createFromFormat('!Y-m-d', $postedDate) ?: DateTime::createFromFormat('!d-m-Y', $postedDate);
	if ($postedDate === '' || $eraseDate === false) {
		$fieldErrors['database_management'] = 'Pick the date: click data from before it is deleted.';
	} else {
		$click_timestamp = strtotime($eraseDate->format('Y-m-d') . ' 00:00:00 ' . date('T'));
		$clickid_sql = "SELECT click_id AS click_id FROM 202_clicks WHERE click_time <=" . (int) $click_timestamp . " ORDER BY click_id DESC LIMIT 1";

		$clickid_result = _mysqli_query($clickid_sql);
		if ($clickid_result === false) {
			$fieldErrors['database_management'] = 'The clicks could not be read just now, so nothing is scheduled for deletion. Try again.';
		} else {
			$clickid_row = $clickid_result->fetch_assoc();

			// Make sure we have a valid click_id before continuing
			if (isset($clickid_row['click_id'])) {
				$mysql['user_delete_data_clickid'] = $db->real_escape_string((string) $clickid_row['click_id']);

				$sql = "UPDATE 202_users_pref SET user_delete_data_clickid = '" . $mysql['user_delete_data_clickid'] . "' WHERE user_id = '" . $mysql['user_own_id'] . "'";
				if ($db->query($sql)) {
					if ($slack)
						$slack->push('click_data_deleted', ['user' => $username, 'date' => $postedDate]);
					p202_account_flash('ok', 'Click data from before ' . $eraseDate->format('M j, Y') . ' is scheduled for deletion. The cron job removes it in batches.');
				} else {
					p202_account_flash('bad', 'The deletion could not be scheduled; nothing will be removed. Try again.');
				}
			} else {
				p202_account_flash('info', 'There are no clicks from before ' . $eraseDate->format('M j, Y') . ', so nothing is deleted.');
			}
			p202_account_redirect('202-account/administration.php#database');
		}
	}
}

if (isset($_POST['auto_database_management'])) {
	$postedDays = trim((string) $_POST['auto_database_management']);
	if ($postedDays === '') {
		$postedDays = '0';
	}
	if (!ctype_digit($postedDays) || (int) $postedDays > 36500) {
		$fieldErrors['auto_database_management'] = 'Enter a whole number of days, or 0 to keep all click data.';
	} else {
		$mysql['auto_database_management'] = $db->real_escape_string((string) (int) $postedDays);
		$sql = "UPDATE 202_users_pref SET user_auto_database_optimization_days = '" . $mysql['auto_database_management'] . "' WHERE user_id = '" . $mysql['user_own_id'] . "'";
		if ($db->query($sql)) {
			p202_account_flash('ok', (int) $postedDays === 0
				? 'Automatic deletion is off; click data is kept.'
				: 'Click data older than ' . (int) $postedDays . ' days is deleted automatically from now on.');
			p202_account_redirect('202-account/administration.php#database');
		}
		$fieldErrors['auto_database_management'] = 'The setting could not be saved; try again.';
	}
}

$cron_log_query = "SELECT last_execution_time FROM 202_cronjob_logs";
$cron_log_result = $db->query($cron_log_query);
$last_cron_ran = $cron_log_result && $cron_log_result->num_rows > 0;
if ($last_cron_ran) {
	$cron_log_row = $cron_log_result->fetch_assoc();
	$last_cron_job_execution_time = CronJobLastExecution('@' . $cron_log_row['last_execution_time']);
} else {
	$last_cron_job_execution_time = 'never';
}

$de_query = "SELECT count(*) as total, sum(processed) as done FROM 202_dataengine_job";
$de_result = $db->query($de_query);
$de_row = $de_result ? $de_result->fetch_assoc() : null;

if ($de_row && $de_row['total'] != 0) {
	$de_total = $de_row['total'];
	$de_done = $de_row['done'];
	$de_ratio = @round(($de_done / $de_total) * 100, 2);
} else {
	$de_total = '0';
	$de_done = '0';
	$de_ratio = '0';
}

$de_minutes = @round($de_total - $de_done, 0);

$d = floor($de_minutes / 1440);
$h = floor(($de_minutes - $d * 1440) / 60);
$m = $de_minutes - ($d * 1440) - ($h * 60);

$click_sql = "SELECT `AUTO_INCREMENT`-1 as clicks FROM  INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '202_clicks_counter';";
$click_row = memcache_mysql_fetch_assoc($click_sql);
$clicks = $click_row['clicks'] ?? null;

$mysql['user_id'] = $db->real_escape_string((string) $_SESSION['user_id']);
$pref_result = _mysqli_query("SELECT * FROM 202_users_pref WHERE user_id='" . $mysql['user_id'] . "'");
$pref_row = $pref_result ? $pref_result->fetch_assoc() : null;
if (!is_array($pref_row)) {
	$pref_row = [];
}

/** When the pending deletion marker points, as a date; null when none is set. */
function p202_admin_erase_date(): ?string
{
	global $db, $mysql;

	$sql = "SELECT click_time FROM `202_clicks` WHERE click_id <= (SELECT user_delete_data_clickid FROM `202_users_pref` WHERE user_id='" . $mysql['user_own_id'] . "') ORDER BY click_id DESC LIMIT 1";

	$result = $db->query($sql);
	$row = $result ? $result->fetch_array(MYSQLI_ASSOC) : null;
	return $row ? date('Y-m-d', (int) $row['click_time']) : null;
}

function database_size()
{
	global $db;
	define('GIG', 1000000000);
	$MB = 1024 * 1024;
	$GB = 1024 * 1024 * 1024;
	$decimals = 2;

	$sql = $db->query("SHOW TABLE STATUS");
	if (!$sql) {
		return 'unknown';
	}

	$size = 0;
	while ($row = $sql->fetch_array(MYSQLI_ASSOC)) {
		$size += $row["Data_length"] + $row["Index_length"];
	}

	if ($size > GIG) {
		$mbytes = number_format($size / ($GB), $decimals) . " GB";
	} else {
		$mbytes = number_format($size / ($MB), $decimals) . " MB";
	}

	return $mbytes;
}

function CronJobLastExecution($datetime, $full = false)
{
	$now = new DateTime;
	$ago = new DateTime($datetime);
	$diff = $now->diff($ago);
	$week = ['w' => (int) floor($diff->d / 7)];
	$diff->d -= $week['w'] * 7;
	$diff = (object) array_merge((array)$diff, $week);
	$string = [
		'y' => 'year',
		'm' => 'month',
		'w' => 'week',
		'd' => 'day',
		'h' => 'hour',
		'i' => 'minute',
		's' => 'second',
	];
	foreach ($string as $k => &$v) {
		if ($diff->$k) {
			$v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
		} else {
			unset($string[$k]);
		}
	}

	if (!$full) $string = array_slice($string, 0, 1);
	return $string ? implode(', ', $string) . ' ago' : 'just now';
}

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$self = get_absolute_url() . '202-account/administration.php';
$eraseDate = p202_admin_erase_date();
$autoDays = (string) ($_POST['auto_database_management'] ?? ($pref_row['user_auto_database_optimization_days'] ?? '0'));
$autoCronOn = !empty($pref_row['auto_cron']);
$maxmindOn = !empty($pref_row['maxmind_isp']);
$geoDirShown = getenv('P202_GEO_DIR') ?: getTrackingDomain() . get_absolute_url() . '202-config/geo/';
$yesNo = static fn (bool $on, string $yes = 'Yes', string $no = 'No'): string => $on
	? '<span class="p202-pill p202-pill--good">' . $yes . '</span>'
	: '<span class="p202-pill p202-pill--warn">' . $no . '</span>';

$user_log_result = _mysqli_query("SELECT * FROM 202_users_log ORDER BY login_id DESC LIMIT 50");

template_top('Administration');
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-gear"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Settings</h1>
		<p class="p202-page-header__desc">How this install is running, how long it keeps click data, and who has tried to sign in.</p>
	</div>
</div>

<?php
$extra = [];
if ($fieldErrors) {
	$extra[] = ['kind' => 'bad', 'text' => 'That was not saved. The field below says why.'];
}
echo p202_account_render_flashes($extra);
?>

<div class="p202-tiles">
	<div class="p202-tile"><div class="p202-tile__label">Clicks recorded</div><div class="p202-tile__value"><?php echo $clicks === null ? '–' : $e(number_format((float) $clicks)); ?></div><div class="p202-tile__sub">to date</div></div>
	<div class="p202-tile"><div class="p202-tile__label">Database size</div><div class="p202-tile__value"><?php echo $e(database_size()); ?></div></div>
	<div class="p202-tile<?php echo $last_cron_ran ? '' : ' is-bad'; ?>"><div class="p202-tile__label">Cron job last ran</div><div class="p202-tile__value"><?php echo $e($last_cron_job_execution_time); ?></div></div>
	<div class="p202-tile"><div class="p202-tile__label">DataEngine conversion</div><div class="p202-tile__value"><?php echo $e($de_ratio); ?>%</div><div class="p202-tile__sub"><?php echo $e($de_done . ' of ' . $de_total . ' tasks · ' . "{$d}d {$h}h {$m}m" . ' left'); ?></div></div>
</div>

<div class="row g-4 mt-0">
	<div class="col-12 col-lg-6">
		<section class="p202-panel h-100" id="system">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">System</h2>
				<span class="p202-panel__sub">versions and caches</span>
			</div>
			<div class="p202-panel__body">
				<div class="p202-strip">
					<div class="p202-strip__row"><span class="p202-strip__label">Prosper202</span><span class="p202-strip__value"><?php echo $e($version); ?></span></div>
					<div class="p202-strip__row"><span class="p202-strip__label">PHP</span><span class="p202-strip__value"><?php echo $e(phpversion()); ?></span></div>
					<div class="p202-strip__row"><span class="p202-strip__label">MySQL</span><span class="p202-strip__value"><?php echo $e(getMYSQLVersion($db)); ?></span></div>
					<div class="p202-strip__row"><span class="p202-strip__label">PHP safe mode</span><span class="p202-strip__value"><?php echo @ini_get('safe_mode') ? 'On: ask your host to turn it off' : 'Off'; ?></span><span class="p202-strip__aside"><?php echo $yesNo(!@ini_get('safe_mode'), 'Off', 'On'); ?></span></div>
					<div class="p202-strip__row"><span class="p202-strip__label">Memcache installed</span><span class="p202-strip__value">speeds up redirects</span><span class="p202-strip__aside"><?php echo $yesNo((bool) $memcacheInstalled); ?></span></div>
					<div class="p202-strip__row"><span class="p202-strip__label">Memcache running</span><span class="p202-strip__value">check 202-config.php if installed but not running</span><span class="p202-strip__aside"><?php echo $yesNo((bool) $memcacheWorking); ?></span></div>
					<div class="p202-strip__row"><span class="p202-strip__label">BlazerCache</span><span class="p202-strip__value">redirects survive a MySQL outage; user agents parse faster</span><span class="p202-strip__aside"><?php echo $yesNo((bool) $memcacheWorking, 'Yes', 'Needs Memcache'); ?></span></div>
					<div class="p202-strip__row"><span class="p202-strip__label">Keyword preference</span><span class="p202-strip__value">pick up the <?php echo $e(strtolower((string) ($pref_row['user_keyword_searched_or_bidded'] ?? ''))); ?> keyword</span><span class="p202-strip__aside"><a class="btn btn-secondary btn-sm" href="<?php echo $e(get_absolute_url() . '202-account/account.php#profile'); ?>">Change</a></span></div>
				</div>
			</div>
		</section>
	</div>
	<div class="col-12 col-lg-6">
		<section class="p202-panel h-100" id="php-limits">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">PHP limits</h2>
				<span class="p202-panel__sub">what uploads and long reports can use</span>
			</div>
			<div class="p202-panel__body">
				<div class="p202-strip">
					<?php foreach (['post_max_size', 'upload_max_filesize', 'max_input_time', 'max_execution_time'] as $iniKey) { ?>
						<div class="p202-strip__row"><span class="p202-strip__label"><?php echo $e($iniKey); ?></span><span class="p202-strip__value"><?php echo $e((string) ini_get($iniKey)); ?></span></div>
					<?php } ?>
				</div>
			</div>
		</section>
	</div>

	<div class="col-12 col-lg-6">
		<section class="p202-panel h-100" id="database">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Click data</h2>
				<span class="p202-panel__sub">setup data is never deleted here</span>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo $e($self . '#database'); ?>">
					<?php echo p202_account_token_field(); ?>
					<label class="form-label" for="auto_erase_clicks_date">Delete click data older than</label>
					<div class="input-group">
						<input type="number" class="form-control<?php echo p202_account_invalid($fieldErrors, 'auto_database_management'); ?>" id="auto_erase_clicks_date" name="auto_database_management" min="0" max="36500" step="1" value="<?php echo $e($autoDays); ?>">
						<span class="input-group-text">days</span>
						<button class="btn btn-primary" type="submit">Save</button>
					</div>
					<div class="form-text">0 keeps every click. The cron job deletes older clicks each night.</div>
					<?php echo p202_account_field_error($fieldErrors, 'auto_database_management'); ?>
				</form>

				<details class="p202-disclosure mt-3"<?php echo isset($fieldErrors['database_management']) ? ' open' : ''; ?>>
					<summary>Advanced <span class="p202-disclosure__hint">delete click data before a date, once</span></summary>
					<div class="p202-disclosure__body">
						<form method="post" action="<?php echo $e($self . '#database'); ?>" id="erase_clicks_form" data-p202-confirm="Are you sure you want to delete all your click data from before this date? Setup data (campaigns, trackers, landing pages) is kept. This cannot be undone.">
							<?php echo p202_account_token_field(); ?>
							<label class="form-label" for="erase_clicks_date">Delete click data from before</label>
							<div class="input-group">
								<input type="date" class="form-control<?php echo p202_account_invalid($fieldErrors, 'database_management'); ?>" id="erase_clicks_date" name="database_management" required max="<?php echo $e(date('Y-m-d')); ?>" value="<?php echo $e($eraseDate ?? ''); ?>">
								<button class="btn btn-outline-danger" type="submit">Delete data…</button>
							</div>
							<div class="form-text"><?php echo $eraseDate === null ? 'Nothing is scheduled for deletion.' : 'Currently set: clicks from before ' . $e(date('M j, Y', (int) strtotime($eraseDate))) . ' are deleted.'; ?></div>
							<?php echo p202_account_field_error($fieldErrors, 'database_management'); ?>
						</form>
					</div>
				</details>
			</div>
		</section>
	</div>

	<div class="col-12 col-lg-6">
		<section class="p202-panel mb-4" id="autocron">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">AutoCron</h2>
				<?php echo $yesNo($autoCronOn, 'On', 'Off'); ?>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo $e($self . '#autocron'); ?>">
					<?php echo p202_account_token_field(); ?>
					<div class="d-flex gap-3 mb-2" role="radiogroup" aria-label="AutoCron">
						<div class="form-check"><input class="form-check-input" type="radio" name="autocron" id="autocron_on" value="1"<?php echo $autoCronOn ? ' checked' : ''; ?>><label class="form-check-label" for="autocron_on">On</label></div>
						<div class="form-check"><input class="form-check-input" type="radio" name="autocron" id="autocron_off" value="0"<?php echo $autoCronOn ? '' : ' checked'; ?>><label class="form-check-label" for="autocron_off">Off</label></div>
					</div>
					<div class="form-text mb-2">Prosper202's hosted service calls this install's cron every few minutes, for installs without their own cron job.</div>
					<button class="btn btn-secondary btn-sm" type="submit">Save</button>
				</form>
			</div>
		</section>

		<section class="p202-panel" id="maxmind">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">ISP and carrier lookup</h2>
				<?php echo $yesNo($maxmindOn, 'On', 'Off'); ?>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo $e($self . '#maxmind'); ?>">
					<?php echo p202_account_token_field(); ?>
					<div class="d-flex gap-3 mb-2" role="radiogroup" aria-label="ISP and carrier lookup">
						<div class="form-check"><input class="form-check-input" type="radio" name="maxmind" id="maxmind_on" value="true"<?php echo $maxmindOn ? ' checked' : ''; ?>><label class="form-check-label" for="maxmind_on">On</label></div>
						<div class="form-check"><input class="form-check-input" type="radio" name="maxmind" id="maxmind_off" value="false"<?php echo $maxmindOn ? '' : ' checked'; ?>><label class="form-check-label" for="maxmind_off">Off</label></div>
					</div>
					<div class="form-text mb-2">Needs the <a href="http://click202.com/tracking202/redirect/dl.php?t202id=9159015&amp;t202kw=p202setup" target="_blank" rel="noopener">MaxMind ISP database</a> (GeoIP2-ISP.mmdb, or legacy GeoIPISP.dat) in <code><?php echo $e($geoDirShown); ?></code>. Live traffic picks up a change within five minutes.</div>
					<button class="btn btn-secondary btn-sm" type="submit">Save</button>
				</form>
			</div>
		</section>
	</div>

	<div class="col-12">
		<section class="p202-panel" id="logins">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Last 50 sign-in attempts</h2>
				<span class="p202-panel__sub">newest first</span>
			</div>
			<div class="p202-panel__body">
				<?php if (!$user_log_result) { ?>
					<?php echo p202_flash('bad', 'The sign-in log could not be read just now. Reload the page to see it.'); ?>
				<?php } elseif ($user_log_result->num_rows === 0) { ?>
					<div class="p202-empty">
						<i class="bi bi-shield-check p202-empty__icon"></i>
						<strong class="p202-empty__title">No sign-in attempts yet</strong>
						<div>Every sign-in to this install, successful or not, is listed here.</div>
					</div>
				<?php } else { ?>
					<div class="p202-table-wrap">
						<table class="table p202-table">
							<thead><tr><th>Time</th><th>Username</th><th>IP address</th><th>Attempt</th></tr></thead>
							<tbody>
								<?php while ($user_log_row = $user_log_result->fetch_assoc()) {
									$ip = (string) $user_log_row['ip_address']; ?>
									<tr>
										<td class="text-nowrap"><?php echo $e(date('M d, y \a\t g:ia', (int) $user_log_row['login_time'])); ?></td>
										<td><?php echo $e($user_log_row['user_name']); ?></td>
										<td><?php echo $e($ip); ?> · <a target="_blank" rel="noopener" href="https://whois.arin.net/ui/query.do?q=<?php echo rawurlencode($ip); ?>">ARIN</a> · <a target="_blank" rel="noopener" href="https://apps.db.ripe.net/search/query.html?searchtext=<?php echo rawurlencode($ip); ?>&amp;sources=RIPE_NCC">RIPE</a></td>
										<td><?php echo $user_log_row['login_success'] == 0 ? '<span class="p202-pill p202-pill--bad">Failed</span>' : '<span class="p202-pill p202-pill--good">Passed</span>'; ?></td>
									</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>
				<?php } ?>
			</div>
		</section>
	</div>
</div>

<?php template_bottom();
