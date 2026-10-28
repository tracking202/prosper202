<?php

/**
 * Runs the legacy API's reportQuery() (api/legacy/functions.php) against the
 * scratch database, in a process of its own: the file declares global
 * functions, and connect.php, which the endpoints load first, needs a
 * 202-config.php. For ReClickCountsOnceIntegrationTest.
 *
 * argv: <type> <id column> <name column> <user id> <from> <to>
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 * Prints the report as JSON.
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[, $type, $id, $name, $user, $from, $to] = $argv + array_fill(0, 7, '');

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
// What a failed statement's answer logs through (legacy_api_database_error()).
require $root . '/202-config/mysql-error-args.php';
require $root . '/api/legacy/functions.php';

mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) getenv('P202_TEST_DB_USER'),
    (string) getenv('P202_TEST_DB_PASS'),
    (string) getenv('P202_TEST_DB_NAME'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
if ($db->connect_errno !== 0) {
    fwrite(STDERR, 'connect failed: ' . $db->connect_error . "\n");
    exit(2);
}
// The legacy API's sql_mode (connect2.php): the server's own default carries
// ONLY_FULL_GROUP_BY on MySQL 8.0, which the report statements predate.
if ($db->query("SET SESSION sql_mode = ''") === false) {
    fwrite(STDERR, 'could not set the sql_mode: ' . $db->error . "\n");
    exit(2);
}

echo json_encode(reportQuery($db, $type, $id, $name, (int) $user, (int) $from, (int) $to), JSON_THROW_ON_ERROR);
