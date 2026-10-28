<?php

/**
 * Runs the installer's key step, 202-config/get_apikey.php, against the
 * database named in P202_TEST_DB_NAME as a browser returning from the licence
 * portal requests it, in a process of its own, and prints what the page
 * printed. No session and no account: the state a fresh install is in.
 *
 * argv: [function:p202TrackingBaseUrl] prints that function's answer, or
 *       `THREW: <message>`, in place of the page.
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME, P202_TEST_KEY
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[, $mode] = $argv + [null, ''];

$root = dirname(__DIR__, 3);
$dbName = (string) getenv('P202_TEST_DB_NAME');
if ($dbName === '') {
    fwrite(STDERR, "P202_TEST_DB_NAME is required\n");
    exit(2);
}
$host = (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('P202_TEST_DB_PORT') ?: '');
$page = '/202-config/get_apikey.php';
$get = ['customers_api_key' => (string) getenv('P202_TEST_KEY')];

// The server's own name and the Host header differ, so the page shows which
// one it handed the licence service.
$_SERVER = array_merge($_SERVER, [
    'SERVER_NAME' => 'install.example', 'HTTP_HOST' => 'request.example', 'SERVER_PORT' => '80',
    'SERVER_PROTOCOL' => 'HTTP/1.1', 'DOCUMENT_ROOT' => $root,
    'REQUEST_URI' => $page . '?' . http_build_query($get),
    'SCRIPT_URL' => $page, 'SCRIPT_NAME' => $page, 'PHP_SELF' => $page,
    'SCRIPT_FILENAME' => $root . $page,
    'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'p202-key-step-runner',
    'REQUEST_METHOD' => 'GET',
]);
$_GET = $get;
$_POST = [];
$_REQUEST = $get;
$_COOKIE = [];

$GLOBALS['dbname'] = $dbName;
$GLOBALS['dbhost'] = $port !== '' ? $host . ':' . $port : $host;
$GLOBALS['dbhostro'] = $GLOBALS['dbhost'];
$GLOBALS['dbuser'] = (string) (getenv('P202_TEST_DB_USER') ?: 'root');
$GLOBALS['dbpass'] = (string) (getenv('P202_TEST_DB_PASS') ?: '');
$GLOBALS['mchost'] = '';
// connect.php in a scope of its own: see tests/Setup/fixtures/page-runner.php.
(static function (string $root): void {
    $prev = error_reporting(0);
    ob_start();
    require_once $root . '/202-config/connect.php';
    ob_end_clean();
    error_reporting($prev);
    $config = ['dbname', 'dbhost', 'dbhostro', 'dbuser', 'dbpass', 'mchost', 'root'];
    foreach (get_defined_vars() as $name => $value) {
        if (!in_array($name, $config, true)) {
            $GLOBALS[$name] = $value;
        }
    }
})($root);

$at = DB::getInstance()->getConnection()->query('SELECT DATABASE()');
$current = $at instanceof mysqli_result ? (string) ($at->fetch_row()[0] ?? '') : '';
if ($current !== $dbName) {
    fwrite(STDERR, "the app connected to '$current', not the scratch database '$dbName'\n");
    exit(2);
}

if ($mode === 'function:p202TrackingBaseUrl') {
    try {
        echo p202TrackingBaseUrl(), "\n";
    } catch (\Throwable $e) {
        echo 'THREW: ', $e->getMessage(), "\n";
    }
    exit(0);
}
if ($mode !== '') {
    fwrite(STDERR, "no mode '$mode'\n");
    exit(2);
}

chdir($root . '/202-config');
include $root . $page;
