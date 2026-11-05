<?php

declare(strict_types=1);

/**
 * Runs tracking202/static/gpb.php once against the scratch database, in a
 * process of its own, as the web server runs it, and prints its response:
 * the status code on the first line, then the body.
 *
 * connect2.php is loaded inside a function first (202-config.php assigns its
 * own database name; there it stays local and the DB class reads the globals
 * set below); gpb.php's own include of it is then a no-op.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME,
 *      P202_TEST_GET / P202_TEST_POST (JSON objects: $_GET and $_POST as PHP
 *      fills them; a POST makes the request a POST)
 */

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 3);
$dbName = (string) getenv('P202_TEST_DB_NAME');
if ($dbName === '' || !is_file($root . '/202-config.php')) {
    fwrite(STDERR, "P202_TEST_DB_NAME and a 202-config.php are required\n");
    exit(2);
}
$host = (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('P202_TEST_DB_PORT') ?: '');
$get = json_decode((string) (getenv('P202_TEST_GET') ?: '{}'), true, 8, JSON_THROW_ON_ERROR);
$post = json_decode((string) (getenv('P202_TEST_POST') ?: '{}'), true, 8, JSON_THROW_ON_ERROR);

$path = '/tracking202/static/gpb.php';
$_SERVER = array_merge($_SERVER, [
    'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80', 'SERVER_PROTOCOL' => 'HTTP/1.1',
    'REQUEST_URI' => $path . ($get === [] ? '' : '?' . http_build_query($get)),
    'SCRIPT_URL' => $path, 'SCRIPT_NAME' => $path, 'PHP_SELF' => $path,
    'REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'p202-gpb-runner',
    'REQUEST_METHOD' => $post === [] ? 'GET' : 'POST',
]);
$_GET = $get;
$_POST = $post;
$_REQUEST = $get + $post;

function boot_click_path(string $root): void
{
    $prev = error_reporting(0);
    ob_start();
    require_once $root . '/202-config/connect2.php';
    ob_end_clean();
    error_reporting($prev);
    $config = ['dbname', 'dbhost', 'dbhostro', 'dbuser', 'dbpass', 'mchost', 'root'];
    foreach (get_defined_vars() as $name => $value) {
        if (!in_array($name, $config, true)) {
            $GLOBALS[$name] = $value;
        }
    }
}

$GLOBALS['dbname'] = $dbName;
$GLOBALS['dbhost'] = $port !== '' ? $host . ':' . $port : $host;
$GLOBALS['dbhostro'] = $GLOBALS['dbhost'];
$GLOBALS['dbuser'] = (string) (getenv('P202_TEST_DB_USER') ?: 'root');
$GLOBALS['dbpass'] = (string) (getenv('P202_TEST_DB_PASS') ?: '');
$GLOBALS['mchost'] = '';
boot_click_path($root);

$at = $db instanceof mysqli ? $db->query('SELECT DATABASE()') : false;
$current = $at instanceof mysqli_result ? (string) ($at->fetch_row()[0] ?? '') : '';
if ($current !== $dbName) {
    fwrite(STDERR, "the click path connected to '$current', not the scratch database '$dbName'\n");
    exit(2);
}

ob_start();
register_shutdown_function(static function (): void {
    $body = (string) ob_get_clean();
    echo http_response_code() ?: 200, "\n", $body;
});
include $root . $path;
