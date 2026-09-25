<?php

declare(strict_types=1);
ignore_user_abort(true);
set_time_limit(0);
//include mysql settings
include_once(__DIR__ . '/connect.php');
include_once(__DIR__ . '/class-dataengine.php');
include_once(__DIR__ . '/functions-upgrade.php');
include_once(__DIR__ . '/functions-install-helpers.php');

$version = defined('PROSPER202_VERSION') ? PROSPER202_VERSION : PROSPER202::prosper202_version();
if (!isset($db) || !($db instanceof mysqli)) {
    _die('Database connection unavailable.');
}

$partition_support = 0;
$partitionSql = "SELECT COUNT(*) as partition_support FROM INFORMATION_SCHEMA.PARTITIONS LIMIT 1";
$partitionRow = memcache_mysql_fetch_assoc($partitionSql);
if (is_array($partitionRow) && (int)($partitionRow['partition_support'] ?? 0) > 0) {
    $partition_support = 1;
}
$memcacheInstalled = isset($memcacheInstalled) ? (bool)$memcacheInstalled : false;

//check to see if this is already installed, if so don't do anything
if (upgrade_needed() == false) {
    header('location: ' . get_absolute_url() . '202-login.php');
    _die("<h6>Already Upgraded</h6>
			   <small>Your Prosper202 version $version is already upgraded. <a href='" . get_absolute_url() . "202-login.php'>log in</a></small>");
}

// Initialize version error tracking array
$version_error = [];

if (!php_version_supported()) {
    $version_error['phpversion'] = 'Prosper202 requires PHP ' . PROSPER202_MIN_PHP_VERSION . ', or newer.';
}

// Get Database version
$mysqlversion = $db->server_info;
if (preg_match('/-(10\..+)-MariaDB/i', (string) $mysqlversion, $match)) {
    // Support For MariaDB
    $mysqlversion = $match[1];
    $dbwording = "MariaDB >= 10.0.12";
    if ((version_compare($mysqlversion, '10.0.12') < 0)) {
        $version_error['mysqlversion'] = 'Prosper202 requires MariaDB 10.0.12, or newer.';
    }
} else {
    $dbwording = "MySQL >= 5.6";
    if ((version_compare($mysqlversion, '5.6') < 0)) {
        $version_error['mysqlversion'] = 'Prosper202 requires MySQL 5.6, or newer.';
    }
}

$html['mysqlversion'] = htmlentities((string) $mysqlversion, ENT_QUOTES, 'UTF-8');

if (!function_exists('curl_version')) {
    $version_error['curl'] = 'Prosper202 requires CURL to be installed.';
}

if (!function_exists('xml_parser_create')) {
    $version_error['xml_parser_create'] = 'Prosper202 requires XML parser to be installed.';
}

if (!empty($version_error)) {
    // header("Location: /202-config/requirements.php");
    info_top(['title' => 'Upgrade - Prosper202 ClickServer', 'wide' => true]);
    $partners = json_decode((string) getData('https://my.tracking202.com/api/v2/hostings'), true);
    $tone = static fn (bool $ok, string $whenNot = 'bad'): string => $ok ? 'good' : $whenNot;
    echo p202_standalone_card('This server does not meet the requirements', 'Prosper202 cannot upgrade here until the items marked below are fixed. An Official Hosting Partner runs it without changes.');
    echo p202_standalone_requirements([
        ['PHP >= ' . PROSPER202_MIN_PHP_VERSION, phpversion(), $tone(empty($version_error['phpversion']))],
        [$dbwording, $mysqlversion, $tone(empty($version_error['mysqlversion']))],
        ['CURL', empty($version_error['curl']) ? 'Installed' : $version_error['curl'], $tone(empty($version_error['curl']))],
        ['xml_parser_create()', empty($version_error['xml_parser_create']) ? 'Installed' : $version_error['xml_parser_create'], $tone(empty($version_error['xml_parser_create']))],
        ['MySQL partitioning', $partition_support == 0 ? 'Missing' : 'Enabled', $tone($partition_support != 0, 'warn')],
        ['PHP Memcache or Memcached', $memcacheInstalled ? 'Installed' : 'Missing', $tone($memcacheInstalled, 'warn'), 'Recommended'],
        ['PHP ZipArchive', class_exists('ZipArchive') ? 'Installed' : 'Missing', $tone(class_exists('ZipArchive'), 'warn'), 'Required for 1-Click Upgrade'],
        ['PHP OpenSSL', extension_loaded('openssl') ? 'Installed' : 'Missing', $tone(extension_loaded('openssl'), 'warn'), 'Required for Enhanced Account Security and ClickBank sales notifications'],
    ]);
    echo p202_standalone_card_end();
    echo p202_standalone_card('Prosper202 Official Hosting Partners', 'Hosting that runs every Prosper202 requirement out of the box.');
    echo p202_standalone_partners($partners);
    echo p202_standalone_card_end();
        info_bottom();
        die();
    } //end error check



        $time_from = '';
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {

        // Initialize upgrade result variables
        $success = false;

        // CSRF, before any work. This page runs with no login, so the session
        // token connect.php mints on every request is what a cross-site form
        // cannot supply. Same check as install.php; a failed check is the error.
        $csrf_error = !install_csrf_ok((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''));
        $error = $csrf_error;

        if (!$error && version_compare(PROSPER202::prosper202_version(), '1.9.3', '<')) {

            $date = DateTime::createFromFormat('d-m-Y', (string) ($_POST['date_from'] ?? ''));
            if (!$date) {
                $error = true;
            } else {
                $time_from = strtotime($date->format("d-m-Y"));
            }
        } else {
            $time_from = '';
        }

        if (!$error) {
            if (UPGRADE::upgrade_databases($time_from) == true) {
                // Clear all PHP caches
                clear_php_caches();
                $success = true;
            } else {
                $error = true;
            }
        }
    }

    //only show install setup, if it, of course, isn't installed already.
    info_top();

    // Initialize upgrade result variables for display
    $error = $error ?? false;
    $success = $success ?? false;
    $csrf_error = $csrf_error ?? false;

    if (version_compare(PROSPER202::prosper202_version(), $version) > 0) {
        $task_202 = "Downgrade";
        $task_202_2 = "Downgrading";
    } else {
        $task_202 = "Upgrade";
        $task_202_2 = "Upgrading";
    }

    ?>
    <div id="upgrade-panel">
        <?php if ($csrf_error == true) {
            echo p202_standalone_card('Security check failed', 'Nothing was changed.'); ?>
            <div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-shield-exclamation"></i><div class="p202-flash__body">Your session has expired or the security check failed. Please refresh the page and submit again.</div></div>
            <a class="btn btn-primary w-100" href="<?php echo get_absolute_url(); ?>202-config/upgrade.php">Start the <?php echo strtolower($task_202); ?> again</a>
        <?php echo p202_standalone_card_end();
        } elseif ($error == true) {
            echo p202_standalone_card('An error occured', 'The ' . strtolower($task_202) . ' did not finish.'); ?>
            <div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i><div class="p202-flash__body">An unexpected error occured while you were trying to <?php echo strtolower($task_202); ?>. Please try again, or if you keep encountering problems review our <a href="http://support.tracking202.com">support docs</a>.</div></div>
            <a class="btn btn-primary w-100" href="<?php echo get_absolute_url(); ?>202-config/upgrade.php">Try again</a>
        <?php echo p202_standalone_card_end();
        } elseif ($success == true) {
            unset($_SESSION['user_id']);
            //('location: '.get_absolute_url().'202-account/signout.php');
            echo p202_standalone_card('Success!', 'Prosper202 ' . strtolower($task_202) . ' completed.'); ?>
            <p>Your database is at version <span class="p202-pill p202-pill--good"><?php echo htmlspecialchars((string) $version, ENT_QUOTES, 'UTF-8'); ?></span>. Sign in again to carry on.</p>
            <a class="btn btn-primary w-100" href="<?php echo get_absolute_url(); ?>202-account/signout.php">Sign in</a>
        <?php echo p202_standalone_card_end();
        } else {
            echo p202_standalone_card($task_202 . ' to Prosper202 ' . $version, 'This could take a while, depending on the last time you updated.'); ?>
            <p><?php echo $task_202_2; ?> from <span class="p202-pill"><?php echo htmlspecialchars((string) PROSPER202::prosper202_version(), ENT_QUOTES, 'UTF-8'); ?></span> to <span class="p202-pill p202-pill--accent"><?php echo htmlspecialchars((string) $version, ENT_QUOTES, 'UTF-8'); ?></span>. Press the button below to begin.</p>
            <?php $change_logs = changelog();
            $pending_logs = [];
            if (!empty($change_logs)) {
                foreach ($change_logs as $logs) {
                    if (version_compare(PROSPER202::prosper202_version(), $logs['version'], '<')) {
                        $pending_logs[] = $logs;
                    }
                }
            }
            if ($pending_logs !== []) { ?>
                <details class="p202-disclosure mb-3" data-p202-remember="upgrade-changelog">
                    <summary>What's new <span class="p202-disclosure__hint"><?php echo count($pending_logs); ?> release<?php echo count($pending_logs) === 1 ? '' : 's'; ?></span></summary>
                    <div class="p202-disclosure__body">
                        <?php foreach ($pending_logs as $logs) { ?>
                            <h2 class="h6 mt-2">v<?php echo htmlspecialchars((string) $logs['version'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <ul class="small">
                                <?php foreach ($logs['logs'] as $log) { ?>
                                    <li><?php echo $log; ?></li>
                                <?php } ?>
                            </ul>
                        <?php } ?>
                    </div>
                </details>
            <?php } ?>
            <form method="post" id="upgrade-form" action="">
                <input type="hidden" name="token" value="<?php
                    echo htmlentities((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8');
                ?>">
                <?php if (version_compare(PROSPER202::prosper202_version(), '1.9.3', '<')) {
                    $first_click_sql = "select DATE_FORMAT(FROM_UNIXTIME(min(click_time)),'%d-%m-%Y') as first_click_time from 202_clicks";
                    $first_click_row = memcache_mysql_fetch_assoc($first_click_sql);

                ?>
                    <div class="mb-3">
                        <label class="form-label" for="date_from">Process clicks for the new Data Engine from</label>
                        <input type="text" class="form-control" id="date_from" name="date_from" placeholder="dd-mm-yyyy" pattern="\d{2}-\d{2}-\d{4}" value="<?php echo htmlspecialchars((string) ($first_click_row['first_click_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-text">Day-month-year. It starts at your first click.</div>
                    </div>
                <?php } ?>
                <?php if (version_compare(PROSPER202::prosper202_version(), PROSPER202_VERSION, '<')) { ?>
                    <fieldset class="mb-3">
                        <legend class="form-label">Move old landing page URLs to HTTPS?</legend>
                        <div class="form-text mt-0 mb-2">Modern browsers require landing pages to be served over HTTPS, or your tracking won't work. Yes is the usual answer.</div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="lp_ssl" id="lp_ssl_yes" value="1" checked>
                            <label class="form-check-label" for="lp_ssl_yes">Yes, upgrade them to HTTPS</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="lp_ssl" id="lp_ssl_no" value="0">
                            <label class="form-check-label" for="lp_ssl_no">No, leave them as they are</label>
                        </div>
                    </fieldset>
                <?php } ?>

                <button class="btn btn-primary btn-lg w-100" id="upgrade-submit" type="submit"><?php echo $task_202; ?> Prosper202</button>
            </form>
        <?php echo p202_standalone_card_end(); ?>
    </div>

    <script>
        // One press: the upgrade runs once, however impatient the click.
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('upgrade-form');
            if (form) {
                form.addEventListener('submit', function () {
                    document.getElementById('upgrade-submit').disabled = true;
                });
            }
        });
    </script>
<?php }
        info_bottom();
