<?php

/**
 * Runs AUTH's remember-me key reads against a real database with the admin
 * pages' real _mysqli_query() (lifted from functions.php), in a process of
 * its own, with one statement made to fail the way a lost or locked
 * connection fails it.
 *
 * argv: <scenario>
 *   read_fails      the SELECT of the stored secret key fails
 *   write_fails     no key is stored yet, and the UPDATE storing one fails
 *   cookie_check    remember_me_on_logged_out() with a cookie for the user,
 *                   the SELECT of the secret key failing
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME, P202_RMK_USER (the user id)
 *
 * Prints what the call answered (KEY <key>, THREW <message>, ANSWERED <x>).
 */

declare(strict_types=1);

[$script, $scenario] = $argv + [null, 'read_fails'];

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../Config/fixtures/lift-function.php';
require_once __DIR__ . '/../../../202-config/functions-auth.php';

$functions = __DIR__ . '/../../../202-config/functions.php';
$real = lift_function($functions, '_mysqli_query');
eval(str_replace('function _mysqli_query(', 'function p202_real_mysqli_query(', $real));
eval(lift_function($functions, 'p202LogFailedQuery'));
eval(lift_function($functions, 'p202FailedQueryMessage'));

// The real _mysqli_query(), with the statement this scenario fails replaced
// by one the server refuses; and the DB singleton connect.php's config
// declares. Evaluated, as the integration suites declare theirs.
eval(<<<'PHP'
    function _mysqli_query($dbOrSql, $sql = null)
    {
        $statement = (string) ($sql ?? $dbOrSql);
        $fails = $GLOBALS['fails'];
        if ($fails !== '' && preg_match($fails, $statement) === 1) {
            $statement = 'SELECT nothing FROM p202_no_such_table_for_remember_me';
        }

        return $sql === null ? p202_real_mysqli_query($statement) : p202_real_mysqli_query($dbOrSql, $statement);
    }

    class DB
    {
        public static function getInstance(): self
        {
            return new self();
        }
        public function getConnection()
        {
            return $GLOBALS['db'];
        }
    }
    PHP);

mysqli_report(MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
$GLOBALS['db'] = $db;
$userId = (int) getenv('P202_RMK_USER');

$GLOBALS['fails'] = match ($scenario) {
    'read_fails', 'cookie_check' => '/^\s*SELECT\s+secret_key\b/i',
    'write_fails' => '/^\s*UPDATE\s+202_users\s+SET\s+secret_key\b/i',
    default => '',
};

if ($scenario === 'cookie_check') {
    $_SESSION = [];
    $_COOKIE['remember_me'] = $userId . '-somekey-somehash';
    try {
        $answer = AUTH::remember_me_on_logged_out();
        echo 'ANSWERED ', var_export($answer, true), "\n";
    } catch (\Throwable $e) {
        echo 'THREW ', get_class($e), ': ', $e->getMessage(), "\n";
    }
    exit(0);
}

try {
    $key = AUTH::get_user_secret_key($userId);
    echo 'KEY ', $key, "\n";
} catch (\RuntimeException $e) {
    echo 'THREW ', $e->getMessage(), "\n";
}
