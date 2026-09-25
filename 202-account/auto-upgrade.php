<?php

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');
include_once(str_repeat("../", 1) . '202-config/functions-upgrade.php');
include_once(str_repeat("../", 1) . '202-config/class-dataengine.php');

AUTH::require_user();

// On managed deployments (Coolify, or any Docker image built from git) the
// 1-click upgrade would write into the ephemeral container filesystem and be
// silently reverted on the next redeploy — refuse before touching anything.
die_if_auto_upgrade_disabled();

ini_set('memory_limit', '-1');

// Initialize state used by the upgrade flow and rendering below.
$log = '';
$error = false;
$upgrade_done = false;

function get_safe_upgrade_path(string $basePath, string $zipEntryName)
{
	$entry = str_replace('\\', '/', $zipEntryName);
	$entry = ltrim($entry, '/');
	$entry = rtrim($entry, '/');

	if ($entry === '' || str_contains($entry, "\0")) {
		return false;
	}

	$parts = explode('/', $entry);
	foreach ($parts as $part) {
		if ($part === '' || $part === '.' || $part === '..') {
			return false;
		}
	}

	return rtrim($basePath, '/') . '/' . implode('/', $parts);
}

$update_needed = false;
$latest_version = $version;
$download_link = '';
$download_link_is_valid = false;

$rss_xml = getUrl('https://my.tracking202.com/clickserver/currentversion/paid/', 'GET', 10);
if ($rss_xml === '') {
	$rss_xml = null;
}

if ($rss_xml !== null) {
	$rss_feed = @simplexml_load_string($rss_xml);
	if ($rss_feed !== false && isset($rss_feed->channel->item)) {
		$item = $rss_feed->channel->item[0];
		$latest_version = (string) $item->title;
		if (!preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', $latest_version)) {
			$update_needed = false;
		} else {
			$download_link = (string) $item->link;
			$parsed_download_link = parse_url($download_link);
			$download_link_is_valid =
				is_array($parsed_download_link)
				&& isset($parsed_download_link['scheme'], $parsed_download_link['host'])
				&& strtolower($parsed_download_link['scheme']) === 'https'
				&& strtolower($parsed_download_link['host']) === 'my.tracking202.com';
			if (version_compare($version, $latest_version) < 0) {
				$update_needed = true;
			}
		}
	}
}


if (($_POST['start_upgrade'] ?? '') === '1') {

	// validate token
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		$log .= "You must use our forms to submit data.\n";
		$error = true;
	}

	if (!$error && version_compare(PROSPER202::prosper202_version(), '1.9.3', '<')) {

		// A native date input posts YYYY-MM-DD; the classic datepicker posted
		// DD-MM-YYYY, which is still read.
		$date = DateTime::createFromFormat('!Y-m-d', (string) ($_POST['date_from'] ?? ''))
			?: DateTime::createFromFormat('!d-m-Y', (string) ($_POST['date_from'] ?? ''));
		if (!$date) {
			$log .= "Select date for Data Engine!\n";
			$error = true;
		} else {
			$time_from = strtotime($date->format("d-m-Y"));
		}
	} else {
		$time_from = '';
	}

	//Do check for landing page upgrade to ssl 
	$ssl_upgrade = '1'; //default to upgrade ssl

	if (version_compare(PROSPER202::prosper202_version(), PROSPER202_VERSION, '<')) {
		if (isset($_POST['lp_ssl']) && $_POST['lp_ssl'] == 0) {
			$ssl_upgrade = '0';  //set to no upgrade if users doesn't want it
		}
	}

	if (!$error) {
		$FilesUpdated = null;
		if (empty($download_link_is_valid) || $download_link_is_valid !== true) {
			$log .= "Invalid upgrade package URL. Operation aborted.\n";
			$FilesUpdated = false;
		}

		if ($FilesUpdated !== false) {
			$GetUpdate = @getData($download_link);
			$log = "Downloading new update...\n";

			if ($GetUpdate) {

				if (temp_exists()) {
					$log .= "Created /202-config/temp/ directory.\n";
					$downloadUpdate = @file_put_contents(substr(__DIR__, 0, -12) . '/202-config/temp/prosper202_' . $latest_version . '.zip', $GetUpdate);
					if ($downloadUpdate) {
						$log .= "Update downloaded and saved!\n";

						$zip = @zip_open(substr(__DIR__, 0, -12) . '/202-config/temp/prosper202_' . $latest_version . '.zip');

						if ($zip) {
							$log .= "\nUpdate process started...\n";
							$log .= "\n-------------------------------------------------------------------------------------\n";
							$basePath = rtrim(substr(__DIR__, 0, -12), '/');

							while ($zip_entry = @zip_read($zip)) {
								$thisFileName = zip_entry_name($zip_entry);
								$safePath = get_safe_upgrade_path($basePath, $thisFileName);

								if ($safePath === false) {
									$log .= "Skipped unsafe path in archive: " . $thisFileName . "\n";
									continue;
								}

								if (str_ends_with($thisFileName, '/')) {
									if (is_dir($safePath)) {
										$log .= "Directory: /" . $thisFileName . "......updated\n";
									} else {
										if (@mkdir($safePath, 0755, true)) {
											$log .= "Directory: /" . $thisFileName . "......created\n";
										} else {
											$log .= "Can't create /" . $thisFileName . " directory - skipping\n";
											continue;
										}
									}
								} else {
									$contents = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));

									if (file_exists($safePath)) {
										$status = "updated";
									} else {
										$status = "created";
									}

									$targetDir = dirname($safePath);
									if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
										$log .= "Can't create directory for file: " . $thisFileName . " - skipping this file\n";
										continue;
									}

									if ($updateThis = @fopen($safePath, 'wb')) {
										fwrite($updateThis, $contents);
										fclose($updateThis);
										unset($contents);

										$log .= "File: " . $thisFileName . "......" . $status . "\n";
									} else {
										$log .= "Can't update file:" . $thisFileName . "! Operation aborted";
									}
								}
								$FilesUpdated = true;
							}
							@zip_close($zip);
						}
					} else {
						$log .= "Can't save new update! Operation aborted. Make sure PHP has write permissions!";
						$FilesUpdated = false;
					}
				} else {
					$log .= "Can't create /202-config/temp/ directory! Operation aborted.";
					$FilesUpdated = false;
				}
			} else {
				$log .= "Can't download new update from link: " . $download_link . " \nOperation aborted.";
				$FilesUpdated = false;
			}
		}

		if ($FilesUpdated == true) {
			// Include functions.php for the clear_php_caches function
			require_once(str_repeat("../", 1) . '202-config/functions.php');
			// Clear all PHP caches
			clear_php_caches();
			include_once(str_repeat("../", 1) . '202-config/functions-upgrade.php');

			$log .= "-------------------------------------------------------------------------------------\n";
			$log .= "\nUpgrading database...\n";

			if (UPGRADE::upgrade_databases($time_from) == true) {
				$log .= "Upgrade done!\n";
				$version = $latest_version;
				$upgrade_done = true;
			} else {
				$log .= "Database upgrade failed! Please try again!\n";
				$upgrade_done = false;
			}
		}
	}
}

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$submitted = ($_POST['start_upgrade'] ?? '') === '1';

template_top('1-Click Upgrade', ['ui' => 'v2']);
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-cloud-arrow-down"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">1-click upgrade</h1>
		<p class="p202-page-header__desc">Download the latest Prosper202 and upgrade this install in place, files and database.</p>
	</div>
</div>

<?php if ($update_needed != true) { ?>
	<div class="p202-empty">
		<i class="bi bi-check2-circle p202-empty__icon"></i>
		<strong class="p202-empty__title">Already upgraded</strong>
		<div>Your Prosper202 version <?php echo $e($version); ?> is the latest.</div>
		<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="<?php echo $e(get_absolute_url() . '202-account/'); ?>">Back to Home</a></div>
	</div>
<?php } elseif (!function_exists('zip_open')) { ?>
	<?php echo p202_flash('bad', 'PHP zip module missing. The 1-click upgrade needs PHP compiled with zip support (the --enable-zip configure option); upgrade manually until it is.'); ?>
<?php } else { ?>
	<div class="row g-4">
		<div class="col-12 col-lg-7">
			<section class="p202-panel">
				<div class="p202-panel__head">
					<h2 class="p202-panel__title">Prosper202 <?php echo $e($latest_version); ?> is available</h2>
					<span class="p202-pill">you have <?php echo $e($version); ?></span>
				</div>
				<div class="p202-panel__body">
					<?php if ($submitted) { ?>
						<label class="form-label" for="upgrade-log">What happened</label>
						<textarea id="upgrade-log" rows="8" class="form-control font-monospace mb-3" readonly><?php echo htmlspecialchars($log, ENT_QUOTES, 'UTF-8'); ?></textarea>
					<?php } ?>

					<?php if ($upgrade_done != true) { ?>
						<p>Upgrade automatically here, or <a href="https://my.tracking202.com/clickserver/download/latest/paid" target="_blank" rel="noopener">download the latest version</a> and upgrade by hand.</p>
						<form method="post" action="">
							<input type="hidden" name="start_upgrade" value="1">
							<input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
							<?php if (version_compare(PROSPER202::prosper202_version(), '1.9.3', '<')) { ?>
								<div class="mb-3">
									<label class="form-label" for="date_from">Process clicks for the new Data Engine from</label>
									<input type="date" class="form-control" id="date_from" name="date_from" required max="<?php echo $e(date('Y-m-d')); ?>">
								</div>
							<?php } ?>
							<?php if (version_compare(PROSPER202::prosper202_version(), PROSPER202_VERSION, '<')) { ?>
								<div class="mb-3">
									<span class="form-label d-block" id="lp_ssl_label">Upgrade old landing page URLs to HTTPS?</span>
									<div class="d-flex gap-3" role="radiogroup" aria-labelledby="lp_ssl_label">
										<div class="form-check"><input class="form-check-input" type="radio" name="lp_ssl" id="lp_ssl_yes" value="1" checked><label class="form-check-label" for="lp_ssl_yes">Yes</label></div>
										<div class="form-check"><input class="form-check-input" type="radio" name="lp_ssl" id="lp_ssl_no" value="0"><label class="form-check-label" for="lp_ssl_no">No</label></div>
									</div>
									<div class="form-text">Yes is the default: modern browsers require landing pages over HTTPS, or tracking stops working.</div>
								</div>
							<?php } ?>
							<?php echo p202_flash('warn', 'Back up your database before upgrading, and make sure PHP can write to the install directory.'); ?>
							<div class="p202-form-actions">
								<button class="btn btn-primary" type="submit">Upgrade Prosper202</button>
							</div>
						</form>
					<?php } else {
						unset($_SESSION['user_id']); ?>
						<?php echo p202_flash('ok', 'Prosper202 has been upgraded. Sign in again to use the new version.'); ?>
						<a class="btn btn-primary" href="<?php echo $e(get_absolute_url() . '202-account/signout.php'); ?>">Sign in again</a>
					<?php } ?>
				</div>
			</section>
		</div>
		<div class="col-12 col-lg-5">
			<section class="p202-panel">
				<div class="p202-panel__head"><h2 class="p202-panel__title">What changed</h2></div>
				<div class="p202-panel__body">
					<?php foreach (changelog() as $logs) {
						if ($logs['version'] >= $version) { ?>
							<details class="p202-disclosure mb-2">
								<summary>v<?php echo $e($logs['version']); ?></summary>
								<div class="p202-disclosure__body">
									<ul class="mb-0">
										<?php foreach ($logs['logs'] as $entry) { ?>
											<li><?php echo $e($entry); ?></li>
										<?php } ?>
									</ul>
								</div>
							</details>
					<?php }
					} ?>
				</div>
			</section>
		</div>
	</div>
<?php } ?>

<?php template_bottom();
