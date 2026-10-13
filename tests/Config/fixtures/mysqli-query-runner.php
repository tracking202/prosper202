<?php

/**
 * Runs the admin pages' real _mysqli_query() (202-config/functions.php, with
 * the p202LogFailedQuery() it logs through) against a real database, in a
 * process of its own: functions.php cannot be loaded alone, and a global
 * _mysqli_query() defined in a test process would stand in for the stubs
 * other suites define.
 *
 * Prints one line per statement: OK, or FALSE when it answered false. What
 * it logged is in the process's error_log.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

require_once __DIR__ . '/lift-function.php';

$functions = __DIR__ . '/../../../202-config/functions.php';
eval(lift_function($functions, '_mysqli_query'));
eval(lift_function($functions, 'p202LogFailedQuery'));

mysqli_report(MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
$GLOBALS['db'] = $db;

$answer = static fn (mixed $result): string => ($result === false ? 'FALSE' : 'OK') . "\n";

$secret = 'UPDATE p202_no_such_table_for_mysqli_query SET secret_key = "s3cr3t-dq"'
    . " WHERE user_id = 's3cr3t-sq'";
$escaped = "SELECT 1 FROM p202_no_such_table_for_mysqli_query WHERE name = 'O\\'Brien s3cr3t-esc'"
    . ' AND x = "a\\"b s3cr3t-esc2"';
echo 'secret ', $answer(_mysqli_query($db, $secret));
echo 'escaped ', $answer(_mysqli_query($escaped));
echo 'success ', $answer(_mysqli_query($db, 'SELECT 1'));
echo 'line ', __LINE__ + 1, "\n";
echo 'caller ', $answer(_mysqli_query('SELECT * FROM p202_no_such_table_for_mysqli_query'));
