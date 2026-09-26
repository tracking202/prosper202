<?php

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');
include_once(str_repeat("../", 1) . '202-config/functions-upgrade.php');
include_once(str_repeat("../", 1) . '202-config/class-dataengine.php');

AUTH::require_user();

// Replacing the install's files is an administrator's act: the same
// permission as Settings (administration.php), not merely being signed in
// (#165).
if (!isset($userObj) || !$userObj->hasPermission('access_to_settings')) {
	header('location: ' . get_absolute_url() . '202-account/');
	exit;
}

// On managed deployments (Coolify, or any Docker image built from git) the
// 1-click upgrade would write into the ephemeral container filesystem and be
// silently reverted on the next redeploy — refuse before touching anything.
die_if_auto_upgrade_disabled();

ini_set('memory_limit', '-1');
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT install_hash, p202_customer_api_key FROM 202_users WHERE user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

$missing_api_key = true;
if (!empty($user_row['p202_customer_api_key'])) {
	$missing_api_key = false;
} else {
	$missing_api_key = true;
}

$json = @getData('https://my.tracking202.com/api/v2/premium-p202/version');
$array = json_decode((string) $json, true);
// An unreadable answer is "no update known", never an update to nothing.
$latest_version = is_array($array) ? (string) ($array['version'] ?? '') : '';
$update_needed = $latest_version !== '' && version_compare($version, $latest_version) == '-1';

$installlog = '';
$upgrade_done = false;
$FilesUpdated = null;
$time_from = '';
$token_refused = false;

if (($_POST['start_upgrade'] ?? '') === '1' && !hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
	// The classic page ignored a bad token in silence and showed the form
	// again, which reads as "nothing happened, try again".
	$token_refused = true;
}

if (($_POST['start_upgrade'] ?? '') === '1' && hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {

	$GetUpdate = @getData('https://my.tracking202.com/api/v2/premium-p202/download/' . $user_row['install_hash'] . '/' . $user_row['p202_customer_api_key']);
	$installlog = "Downloading new update...\n";
	$checkError = json_decode((string) $GetUpdate, true);
	if (json_last_error() == JSON_ERROR_NONE) {
		$installlog .= (is_array($checkError) ? (string) ($checkError['msg'] ?? 'The download was refused') : 'The download was refused') . "...\n";
		$FilesUpdated = false;
		$GetUpdate = false;
	}

	if ($GetUpdate) {

		if (temp_exists()) {
			$installlog .= "Created /202-config/temp/ directory.\n";
			$downloadUpdate = @file_put_contents(substr(__DIR__, 0, -12) . '/202-config/temp/prosper202_' . $latest_version . '.zip', $GetUpdate);
			if ($downloadUpdate) {
				$installlog .= "Update downloaded and saved!\n";

				$zip = @zip_open(substr(__DIR__, 0, -12) . '/202-config/temp/prosper202_' . $latest_version . '.zip');

				if ($zip) {
					$installlog .= "\nUpdate process started...\n";
					$installlog .= "\n-------------------------------------------------------------------------------------\n";

					// Resolve the install root once; every entry must stay inside it.
					$basePath = realpath(substr(__DIR__, 0, -12));
					if ($basePath === false) {
						$installlog .= "Unable to resolve update destination path. Operation aborted.";
						$FilesUpdated = false;
						@zip_close($zip);
					} else {
						while ($zip_entry = @zip_read($zip)) {
							$thisFileName = zip_entry_name($zip_entry);

							if (str_ends_with($thisFileName, '/')) {
								// Validate and confine the directory path before creating it.
								$targetDirectory = resolve_update_target_path($basePath, $thisFileName, true);
								if ($targetDirectory === false) {
									$installlog .= "Skipped invalid update directory path: /" . $thisFileName . "\n";
								} elseif (is_dir($targetDirectory)) {
									$installlog .= "Directory: /" . $thisFileName . "......updated\n";
								} else {
									$installlog .= "Directory: /" . $thisFileName . "......created\n";
								}
							} else {
								$contents = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));

								// Confined to the install, and never through a symlink.
								$targetFile = resolve_update_write_path($basePath, $thisFileName);
								if ($targetFile === false) {
									$installlog .= "Skipped unsafe update file path: /" . $thisFileName . "\n";
									continue;
								}
								$status = file_exists($targetFile) ? "updated" : "created";

								if ($updateThis = @fopen($targetFile, 'wb')) {
									fwrite($updateThis, $contents);
									fclose($updateThis);
									unset($contents);

									$installlog .= "File: " . $thisFileName . "......" . $status . "\n";
								} else {
									$installlog .= "Can't update file:" . $thisFileName . "! Operation aborted";
								}
							}
							$FilesUpdated = true;
						}
						@zip_close($zip);
					}
				}
			} else {
				$installlog .= "Can't save new update! Operation aborted. Make sure PHP has write permissions!";
				$FilesUpdated = false;
			}
		} else {
			$installlog .= "Can't create /202-config/temp/ directory! Operation aborted.";
			$FilesUpdated = false;
		}
	} else {
		$installlog .= "Can't download new update : \nOperation aborted.";
		$FilesUpdated = false;
	}

	if ($FilesUpdated == true) {
		// Include functions.php for the clear_php_caches function
		require_once(str_repeat("../", 1) . '202-config/functions.php');
		// Clear all PHP caches
		clear_php_caches();
		include_once(str_repeat("../", 1) . '202-config/functions-upgrade.php');

		$installlog .= "-------------------------------------------------------------------------------------\n";
		$installlog .= "\nUpgrading database...\n";

		if (UPGRADE::upgrade_databases($time_from) == true) {
			$installlog .= "Upgrade done!\n";
			$version = $latest_version;
			$upgrade_done = true;
		} else {
			$installlog .= "Database upgrade failed! Please try again!\n";
			$upgrade_done = false;
		}
	}
}

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
/** The premium offer's words come from the Prosper202 service; they are shown as text. */
$details = is_array($_SESSION['premium_p202_details'] ?? null) ? $_SESSION['premium_p202_details'] : [];
$detail = static fn (string $key): string => trim(strip_tags((string) ($details[$key] ?? '')));
$submitted = ($_POST['start_upgrade'] ?? '') === '1';

template_top('1-Click Upgrade');
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-stars"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title"><?php echo $detail('headline') !== '' ? $e($detail('headline')) : '1-click upgrade'; ?></h1>
		<p class="p202-page-header__desc"><?php echo $detail('body') !== '' ? $e($detail('body')) : 'Upgrade this install to the latest Prosper202 in place.'; ?></p>
	</div>
</div>

<?php if ($token_refused) {
	echo p202_flash('bad', 'This form expired or did not come from this page, so nothing was upgraded. Reload the page and try again.');
} ?>

<?php if ($missing_api_key == true) { ?>
	<div class="p202-empty mb-4">
		<i class="bi bi-key p202-empty__icon"></i>
		<strong class="p202-empty__title">Your Prosper202 customer API key is missing</strong>
		<div>Sign up at the Prosper202 customer dashboard, add your billing information, then save the key in Personal settings.</div>
		<div class="p202-empty__action">
			<?php if ($detail('register-link') !== '') { ?>
				<a class="btn btn-secondary btn-sm" href="<?php echo $e($detail('register-link')); ?>" target="_blank" rel="noopener"><?php echo $e($detail('register-button-text') !== '' ? $detail('register-button-text') : 'Sign up'); ?></a>
			<?php } ?>
			<a class="btn btn-primary btn-sm" href="<?php echo $e(get_absolute_url() . '202-account/account.php#customer-key'); ?>">Save the key</a>
		</div>
	</div>
<?php } elseif ($update_needed != true) { ?>
	<div class="p202-empty">
		<i class="bi bi-check2-circle p202-empty__icon"></i>
		<strong class="p202-empty__title">Already upgraded</strong>
		<div>Your Prosper202 version <?php echo $e($version); ?> is the latest.</div>
		<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="<?php echo $e(get_absolute_url() . '202-account/'); ?>">Back to Home</a></div>
	</div>
<?php } elseif (!function_exists('zip_open')) { ?>
	<?php echo p202_flash('bad', 'PHP zip module missing. The 1-click upgrade needs PHP compiled with zip support (the --enable-zip configure option); upgrade manually until it is.'); ?>
<?php } ?>

<?php if ($missing_api_key == true || ($update_needed == true && function_exists('zip_open'))) { ?>
	<div class="row g-4">
		<?php if ($missing_api_key != true) { ?>
			<div class="col-12 col-lg-7">
				<section class="p202-panel">
					<div class="p202-panel__head">
						<h2 class="p202-panel__title">Prosper202 <?php echo $e($latest_version); ?> is available</h2>
						<span class="p202-pill">you have <?php echo $e($version); ?></span>
					</div>
					<div class="p202-panel__body">
						<?php if ($detail('release-date') !== '') { ?>
							<p class="form-text mt-0">Released <?php echo $e($detail('release-date')); ?>.</p>
						<?php } ?>
						<?php if ($submitted && !$token_refused) { ?>
							<label class="form-label" for="upgrade-log">What happened</label>
							<textarea id="upgrade-log" rows="8" class="form-control font-monospace mb-3" readonly><?php echo $e($installlog); ?></textarea>
						<?php } ?>

						<?php if ($upgrade_done !== true && !($submitted && !$token_refused && $FilesUpdated === false)) { ?>
							<form method="post" action="">
								<input type="hidden" name="start_upgrade" value="1">
								<input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
								<?php echo p202_flash('warn', 'Back up your database before upgrading, and make sure PHP can write to the install directory.'); ?>
								<div class="p202-form-actions">
									<button class="btn btn-primary" type="submit"><?php echo $e($detail('order-button-text') !== '' ? $detail('order-button-text') : 'Upgrade Prosper202'); ?></button>
								</div>
							</form>
						<?php } elseif ($upgrade_done === true) { ?>
							<?php echo p202_flash('ok', 'Prosper202 has been upgraded. Sign in again to use the new version.'); ?>
							<a class="btn btn-primary" href="<?php echo $e(get_absolute_url() . '202-account/signout.php'); ?>">Sign in again</a>
						<?php } else { ?>
							<?php echo p202_flash('bad', 'Prosper202 was unable to upgrade. Make sure PHP has permission to change files; your old version keeps working.'); ?>
							<div class="p202-toolbar">
								<a class="btn btn-secondary" href="http://support.tracking202.com" target="_blank" rel="noopener">Get help</a>
								<a class="btn btn-secondary" href="<?php echo $e(get_absolute_url() . '202-account/signout.php'); ?>">Continue with the old version</a>
							</div>
						<?php } ?>
					</div>
				</section>
			</div>
		<?php } ?>
		<div class="col-12 col-lg-5">
			<section class="p202-panel">
				<div class="p202-panel__head"><h2 class="p202-panel__title">What changed</h2></div>
				<div class="p202-panel__body">
					<?php foreach (changelogPremium() as $key => $logs) { ?>
						<details class="p202-disclosure mb-2">
							<summary>v<?php echo $e($key); ?></summary>
							<div class="p202-disclosure__body">
								<ul class="mb-0">
									<?php foreach ($logs as $entry) { ?>
										<li><?php echo $e($entry); ?></li>
									<?php } ?>
								</ul>
							</div>
						</details>
					<?php } ?>
				</div>
			</section>
		</div>
	</div>
<?php } ?>

<?php template_bottom();
