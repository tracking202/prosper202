<?php

/**
 * 202-cronjobs/sync-messaging.php itself, on the scratch database, for
 * OneAnalyticsDeliveryAtATimeIntegrationTest:
 * `php sync-messaging-runner.php [<go file>]`.
 *
 * Waits for the go file when one is named (so several runs start in the same
 * instant), then runs the script, which prints what it did and ends the
 * process with its own exit status. Exit 2 is this runner's: it could not
 * start, or the script ran on another database than the scratch one.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME, MESSAGING_API_URL (connect.php
 * reads it)
 */

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$scratch = (string) getenv('P202_TEST_DB_NAME');
if ($scratch === '' || (string) getenv('MESSAGING_API_URL') === '') {
    fwrite(STDERR, "P202_TEST_DB_NAME and MESSAGING_API_URL are required\n");
    exit(2);
}

// The DB class 202-config.php defines connects with these globals; the
// script runs inside a function below, so 202-config.php's own values land
// in that function's scope and these stand.
$host = (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('P202_TEST_DB_PORT') ?: '');
$dbname = $scratch;
$dbhost = $port !== '' ? $host . ':' . $port : $host;
$dbhostro = $dbhost;
$dbuser = (string) (getenv('P202_TEST_DB_USER') ?: 'root');
$dbpass = (string) (getenv('P202_TEST_DB_PASS') ?: '');
$mchost = '';

register_shutdown_function(static function () use ($scratch): void {
    if (!class_exists('DB', false)) {
        return;
    }
    $at = DB::getInstance()->getConnection()->query('SELECT DATABASE()');
    $ran = $at instanceof mysqli_result ? (string) ($at->fetch_row()[0] ?? '') : '';
    if ($ran !== $scratch) {
        fwrite(STDERR, "the script ran on '$ran', not the scratch database '$scratch'\n");
        exit(2);
    }
});

$go = $argv[1] ?? null;
if ($go !== null) {
    $deadline = microtime(true) + 10;
    while (!file_exists($go)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "no go\n");
            exit(2);
        }
        usleep(500);
    }
}

(static function () use ($root): void {
    require $root . '/202-cronjobs/sync-messaging.php';
})();
fwrite(STDERR, "sync-messaging.php returned without ending the process\n");
exit(2);
