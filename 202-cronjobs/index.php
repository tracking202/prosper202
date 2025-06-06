<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();

// Initialize variables
$lockFile = __DIR__ . '/cron.lock';
$logFile = __DIR__ . '/../202-config/cronjob.log';

try {
    // Check for lock file to prevent concurrent runs
    if (file_exists($lockFile)) {
        $lockTime = filemtime($lockFile);
        // If lock is older than 10 minutes, remove it (stale lock)
        if (time() - $lockTime > 600) {
            unlink($lockFile);
        } else {
            error_log("Cron job already running. Lock file exists: " . $lockFile);
            exit("Cron job already running\n");
        }
    }

    // Create lock file
    touch($lockFile);

    include_once(__DIR__ . '/../202-config/connect.php');
    include_once(__DIR__ . '/../202-config/class-dataengine.php');

    set_time_limit(0);
    ignore_user_abort(true);

    // Get database connection
    try {
        $db = getDatabaseConnection();
    } catch (Exception $e) {
        error_log("Cron job: " . $e->getMessage());
        throw new Exception("Database connection failed");
    }

    if (RunSecondsCronjob() == true) {
        if (RunHourlyCronJob() == true) {
            RunDailyCronjob();
        }
        AutoOptimizeDatabase();
        ClearOldClicks();
    } else {
        AutoOptimizeDatabase();
        ClearOldClicks();
    }

    // Remove lock file on successful completion
    unlink($lockFile);
} catch (Exception $e) {
    $errorMsg = date('Y-m-d H:i:s') . " - Cronjob Error: " . $e->getMessage() . "\n";
    echo "Error: " . $e->getMessage();
    error_log($errorMsg, 3, $logFile);

    // Remove lock file on error
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

/**
 * Helper function for consistent output flushing
 */
function flushOutput()
{
    ob_flush();
    flush();
}

function RunDailyCronjob()
{
    try {
        try {
            $db = getDatabaseConnection();
        } catch (Exception $e) {
            error_log("RunDailyCronjob: Database connection failed - " . $e->getMessage());
            return false;
        }

        //check to run the daily cronjob
        $now = time();

        $today_day = date('j', $now);
        $today_month = date('n', $now);
        $today_year = date('Y', $now);

        //the click_time is recorded in the middle of the day
        $cronjob_time = mktime(12, 0, 0, (int)$today_month, (int)$today_day, (int)$today_year);
        $mysql['cronjob_time'] = $db->real_escape_string((string)$cronjob_time);
        $mysql['cronjob_type'] = $db->real_escape_string('daily');

        //check to make sure this cronjob doesn't already exist
        $check_sql = "SELECT  *  FROM 202_cronjobs WHERE cronjob_type='" . $mysql['cronjob_type'] . "' AND cronjob_time='" . $mysql['cronjob_time'] . "'";
        $check_result = $db->query($check_sql);

        if ($check_result === false) {
            error_log("RunDailyCronjob: Query failed - " . $db->error);
            return false;
        }

        $check_count = $check_result->num_rows;

        if ($check_count == 0) {
            echo 'Processing Daily Jobs...';
            flushOutput();

            //if a cronjob hasn't run today, record it now.
            $insert_sql = "INSERT INTO 202_cronjobs SET cronjob_type='" . $mysql['cronjob_type'] . "', cronjob_time='" . $mysql['cronjob_time'] . "'";
            $insert_result = $db->query($insert_sql);

            /* -------- THIS CLEARS OUT THE CLICK SPY MEMORY TABLE --------- */
            //this function runs everyday at midnight to clear out the temp clicks_memory table
            $from = $now - 86400;

            //this makes it so we only have the most recent last 24 hour stuff, anything older, kill it.
            //we want to keep our SPY TABLE, low
            $click_sql = "DELETE FROM 202_clicks_spy WHERE click_time < $from";
            $click_result = $db->query($click_sql);

            //clear the last 24 hour ip addresses
            $last_ip_sql = "DELETE FROM 202_last_ips WHERE time < $from";
            $last_ip_result = $db->query($last_ip_sql);
            $last_ip_affected_rows = $db->affected_rows;

            /* -------- NOW DELETE ALL THE OLD CRONJOB ENTRIES STUFF --------- */
            $mysql['cronjob_time'] = (int)$mysql['cronjob_time'] - 86400;
            $delete_sql = "DELETE FROM 202_cronjobs WHERE cronjob_time < " . $mysql['cronjob_time'] . "";
            $delete_result = $db->query($delete_sql);

            // Log the execution
            $log_sql = "REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (1, " . $now . ")";
            $log_result = $db->query($log_sql);

            echo 'Done';
            flush();
            return true;
        } else {
            return false;
        }
    } catch (Exception $e) {
        error_log("RunDailyCronjob Exception: " . $e->getMessage());
        return false;
    }
}

function RunHourlyCronJob()
{
    try {
        try {
            $db = getDatabaseConnection();
        } catch (Exception $e) {
            error_log("RunHourlyCronJob: Database connection failed - " . $e->getMessage());
            return false;
        }

        //check to run the hourly cronjob
        $now = time();

        $today_day = date('j', $now);
        $today_month = date('n', $now);
        $today_year = date('Y', $now);
        $today_hour = date('G', $now);

        //the click_time is recorded at the start of the hour
        $cronjob_time = mktime((int)$today_hour, 0, 0, (int)$today_month, (int)$today_day, (int)$today_year);
        $mysql['cronjob_time'] = $db->real_escape_string((string)$cronjob_time);
        $mysql['cronjob_type'] = $db->real_escape_string('hourly');

        //check to make sure this cronjob doesn't already exist (support both 'hour' and 'hourly' for backwards compatibility)
        $check_sql = "SELECT  *  FROM 202_cronjobs WHERE (cronjob_type='hour' OR cronjob_type='" . $mysql['cronjob_type'] . "') AND cronjob_time='" . $mysql['cronjob_time'] . "'";
        $check_result = $db->query($check_sql);

        if ($check_result === false) {
            error_log("RunHourlyCronJob: Query failed - " . $db->error);
            return false;
        }

        $check_count = $check_result->num_rows;

        if ($check_count == 0) {
            echo 'Processing Hourly Jobs...';
            flushOutput();

            //if a cronjob hasn't run this hour, record it now.
            $insert_sql = "INSERT INTO 202_cronjobs SET cronjob_type='" . $mysql['cronjob_type'] . "', cronjob_time='" . $mysql['cronjob_time'] . "'";
            $insert_result = $db->query($insert_sql);

            // Log the execution
            $log_sql = "REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (1, " . $now . ")";
            $log_result = $db->query($log_sql);

            echo 'Done<br>';
            ob_flush();
            flush();
            return true;
        } else {
            return false;
        }
    } catch (Exception $e) {
        error_log("RunHourlyCronJob Exception: " . $e->getMessage());
        return false;
    }
}

function RunSecondsCronjob()
{
    try {
        try {
            $db = getDatabaseConnection();
        } catch (Exception $e) {
            error_log("RunSecondsCronjob: Database connection failed - " . $e->getMessage());
            return false;
        }

        //check to run the 1minute cronjob
        $now = time();

        $everySeconds = 60; // Changed to 60 seconds for performance

        $today_second = date('s', $now);
        $today_minute = date('i', $now);
        $today_hour = date('G', $now);
        $today_day = date('j', $now);
        $today_month = date('n', $now);
        $today_year = date('Y', $now);

        $today_second = (int)ceil((int)$today_second / $everySeconds);
        if ($today_second == 0) $today_second++;

        //record cronjob time
        $cronjob_time = mktime((int)$today_hour, (int)$today_minute, (int)$today_second, (int)$today_month, (int)$today_day, (int)$today_year);

        $mysql['cronjob_time'] = $db->real_escape_string((string)$cronjob_time);
        $mysql['cronjob_type'] = $db->real_escape_string('second');

        //check to make sure this cronjob doesn't already exist
        $check_sql = "SELECT  *  FROM 202_cronjobs WHERE cronjob_type='" . $mysql['cronjob_type'] . "' AND cronjob_time='" . $mysql['cronjob_time'] . "'";
        $check_result = $db->query($check_sql);

        if ($check_result === false) {
            error_log("RunSecondsCronjob: Query failed - " . $db->error);
            return false;
        }

        $check_count = $check_result->num_rows;

        if ($check_count == 0) {
            echo 'Processing Seconds Jobs...';
            flushOutput();

            //if a cronjob hasn't run, record it now.
            $insert_sql = "INSERT INTO 202_cronjobs SET cronjob_type='" . $mysql['cronjob_type'] . "', cronjob_time='" . $mysql['cronjob_time'] . "'";
            $insert_result = $db->query($insert_sql);

            /* -------- THIS RUNS THE DELAYED QUERIES --------- */
            $delayed_sql = "
                SELECT delayed_sql
                FROM 202_delayed_sqls
                WHERE delayed_time <=" . $now . "
            ";
            $delayed_result = $db->query($delayed_sql);

            if ($delayed_result !== false) {
                while ($delayed_row = $delayed_result->fetch_assoc()) {
                    //run each sql
                    $update_sql = $delayed_row['delayed_sql'];
                    $update_result = $db->query($update_sql);

                    if ($update_result === false) {
                        error_log("Delayed SQL failed: " . $update_sql . " - Error: " . $db->error);
                    }
                }
            }

            //delete all old delayed sqls
            $delayed_sql = "DELETE FROM 202_delayed_sqls WHERE delayed_time <=" . $now;
            $delayed_result = $db->query($delayed_sql);

            // Log the execution
            $log_sql = "REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (1, " . $now . ")";
            $log_result = $db->query($log_sql);

            // Process DataEngine tasks
            try {
                $de = new DataEngine();
                if ($de->isDatabaseConnected()) {
                    $de->processDirtyHours();
                    $de->processClickUpgrade();
                }
            } catch (Exception $e) {
                error_log("DataEngine processing failed: " . $e->getMessage());
            }

            echo 'Done<br>';
            ob_flush();
            flush();
            return true;
        } else {
            return false;
        }
    } catch (Exception $e) {
        error_log("RunSecondsCronjob Exception: " . $e->getMessage());
        return false;
    }
}

function AutoOptimizeDatabase()
{
    try {
        try {
            $db = getDatabaseConnection();
        } catch (Exception $e) {
            error_log("AutoOptimizeDatabase: Database connection failed - " . $e->getMessage());
            return;
        }

        $sql = "SELECT user_auto_database_optimization_days FROM 202_users_pref where user_id = 1";
        $result = $db->query($sql);

        if (!$result) {
            echo "Error querying user preferences for auto optimization<br>";
            error_log("AutoOptimizeDatabase: Query failed - " . $db->error);
            return;
        }

        $row = $result->fetch_assoc();

        if (!empty($row['user_auto_database_optimization_days'])) {
            $date_to = date('Y-m-d', strtotime('-1 days', strtotime(date("Y-m-d"))));
            $date_to = $date_to . ' 23:59:59';

            $date_from = date('Y-m-d', strtotime('-' . $row['user_auto_database_optimization_days'] . ' days', strtotime($date_to)));
            $date_from = $date_from . ' 23:59:59';
            $to = strtotime($date_from);

            echo " Processing Auto DB Delete -";
            flush();
            ob_flush();

            // Get the oldest click_id based on the date range
            $click_sql = "SELECT MIN(click_id) as min_click_id FROM 202_clicks WHERE click_time < " . $to;
            $click_result = $db->query($click_sql);

            if ($click_result && $click_result->num_rows > 0) {
                $click_row = $click_result->fetch_assoc();
                $min_click_id = $click_row['min_click_id'];

                if (!empty($min_click_id) && is_numeric($min_click_id)) {
                    $tables = explode(',', '202_clicks,202_clicks_advance,202_clicks_record,202_clicks_site,202_clicks_spy,202_clicks_tracking,202_dataengine,202_google,202_bing,202_clicks_variable');

                    foreach ($tables as $table) {
                        $table = trim($table);
                        $delete_sql = "DELETE FROM `$table` WHERE click_id < " . (int)$min_click_id . " LIMIT 10000";
                        $result = $db->query($delete_sql);

                        if ($result === false) {
                            error_log("AutoOptimizeDatabase: Failed to delete from $table - " . $db->error);
                        }
                    }
                }
            }

            echo 'Done Processing Batch<br>';
            ob_flush();
            flush();
        }
    } catch (Exception $e) {
        error_log("AutoOptimizeDatabase Exception: " . $e->getMessage());
    }
}

function ClearOldClicks()
{
    try {
        try {
            $db = getDatabaseConnection();
        } catch (Exception $e) {
            error_log("ClearOldClicks: Database connection failed - " . $e->getMessage());
            return;
        }

        // For cron job, we don't have session, so use user_id = 1 (admin)
        $user_id = 1;
        $mysql['user_own_id'] = $db->real_escape_string((string)$user_id);

        $sql = "SELECT user_delete_data_clickid from 202_users_pref WHERE user_id = '" . $mysql['user_own_id'] . "'";
        $result = $db->query($sql);

        if (!$result) {
            echo "Error querying user preferences<br>";
            error_log("ClearOldClicks: Query failed - " . $db->error);
            return;
        }

        $row = $result->fetch_assoc();

        if ($result->num_rows > 0 && !empty($row['user_delete_data_clickid'])) {
            echo " Processing Clear Old Clicks...";
            $mysql['click_id'] = $db->real_escape_string((string)$row['user_delete_data_clickid']);

            $tables = explode(',', '202_clicks,202_clicks_advance,202_clicks_record,202_clicks_site,202_clicks_spy,202_clicks_tracking,202_dataengine,202_google,202_bing,202_clicks_variable');
            if (!empty($mysql['click_id']) && is_numeric($mysql['click_id'])) {
                foreach ($tables as $table) {
                    $table = trim($table);
                    $click_sql = "DELETE FROM `$table` WHERE click_id < " . (int)$mysql['click_id'] . " LIMIT 5000";
                    $result = $db->query($click_sql);

                    if ($result === false) {
                        error_log("ClearOldClicks: Failed to delete from $table - " . $db->error);
                    }
                }
            }

            // Initialize Slack notification if configured
            try {
                $slack_sql = "SELECT user_slack_incoming_webhook FROM 202_users_pref WHERE user_id = 1 AND user_slack_incoming_webhook != ''";
                $slack_result = $db->query($slack_sql);

                if ($slack_result && $slack_result->num_rows > 0) {
                    $slack_row = $slack_result->fetch_assoc();
                    if (!empty($slack_row['user_slack_incoming_webhook'])) {
                        // Only initialize Slack if webhook is configured
                        if (class_exists('Slack')) {
                            $slack = new Slack($slack_row['user_slack_incoming_webhook']);
                            $slack->push('click_data_deleted', array('user' => 'cron', 'date' => date('Y-m-d')));
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("ClearOldClicks: Slack notification failed - " . $e->getMessage());
            }

            echo 'Done Processing Batch<br>';
            ob_flush();
            flush();
        }
    } catch (Exception $e) {
        error_log("ClearOldClicks Exception: " . $e->getMessage());
    }
}

// End the output buffer properly
if (ob_get_level()) {
    ob_end_flush();
}
