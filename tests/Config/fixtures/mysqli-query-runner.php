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
eval(lift_function($functions, 'p202FailedQueryMessage'));

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
$unclosed = "SELECT 1 FROM p202_no_such_table_for_mysqli_query WHERE name = 's3cr3t-open";
echo 'unclosed ', $answer(_mysqli_query($db, $unclosed));
echo 'success ', $answer(_mysqli_query($db, 'SELECT 1'));
echo 'line ', __LINE__ + 1, "\n";
echo 'caller ', $answer(_mysqli_query('SELECT * FROM p202_no_such_table_for_mysqli_query'));

// Messages that repeat a value, its quote unescaped: a duplicate entry, and
// a value a column refuses under a strict sql_mode.
$setup = static function (string $sql) use ($db): void {
    if ($db->query($sql) === false) {
        throw new RuntimeException($db->error . ' in ' . $sql);
    }
};
$setup('CREATE TEMPORARY TABLE p202_mysqli_query_values (k VARCHAR(40) PRIMARY KEY, n INT NULL)');
// Escaped as real_escape_string() escapes it.
$duplicate = "INSERT INTO p202_mysqli_query_values (k) VALUES ('O\\'Brien s3cr3t-dup')";
$setup($duplicate);
echo 'duplicate ', $answer(_mysqli_query($db, $duplicate));
$setup("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$refused = "INSERT INTO p202_mysqli_query_values (k, n) VALUES ('x', 'O\\'Brien s3cr3t-int')";
echo 'refused ', $answer(_mysqli_query($db, $refused));
// Under ANSI_QUOTES a double-quoted value is a column name to the server.
$setup("SET SESSION sql_mode = 'ANSI_QUOTES'");
$ansi = 'SELECT k FROM p202_mysqli_query_values WHERE k = "s3cr3t-ansi"';
echo 'ansi ', $answer(_mysqli_query($db, $ansi));
$setup("SET SESSION sql_mode = ''");
// A value long enough that a backtracking pattern gives up on it.
$long = "UPDATE p202_no_such_table_for_mysqli_query SET v = '" . str_repeat('a', 200000) . "'"
    . " WHERE secret_key = 's3cr3t-long'";
echo 'long ', $answer(_mysqli_query($db, $long));
// A quote left open whose value ends in a backslash.
$backslash = "SELECT 1 FROM p202_no_such_table_for_mysqli_query WHERE name = 's3cr3t-bs\\";
echo 'backslash ', $answer(_mysqli_query($db, $backslash));
// Under NO_BACKSLASH_ESCAPES, real_escape_string() leaves a backslash in
// place: the value is whole to the server, and read with escapes it runs on
// into the next one.
$setup("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");
$nbe = "SELECT 1 FROM p202_no_such_table_for_mysqli_query WHERE name = '"
    . $db->real_escape_string('s3cr3t-nbe\\') . "' AND secret_key = 's3cr3t-nbe2'";
echo 'nbe ', $answer(_mysqli_query($db, $nbe));
$setup("SET SESSION sql_mode = ''");
// A statement past the line's 500 bytes, cut inside a two-byte character.
$wide = 'SELECT 1 FROM p202_no_such_table_for_mysqli_query /* ';
$wide .= (497 - strlen($wide)) % 2 === 0 ? 'x' : '';
echo 'wide ', $answer(_mysqli_query($db, $wide . str_repeat('é', 300) . ' */'));
