<?php

declare(strict_types=1);

/**
 * Runs one legacy page as a signed-in account against the scratch database,
 * in a process of its own, and prints what the page printed.
 *
 * The page is included at file scope, as the web server runs it, after the
 * app has been booted (connect.php, inside a function so 202-config.php's
 * own variables do not replace the scratch database's) and a session has
 * been written for the account: the fields AUTH::require_user() checks
 * (user, fingerprint, time) and a licence already validated this session.
 * The page's own include of connect.php is then a no-op.
 *
 * argv: <page path, relative to the repository root> | function:<name>
 *   A function: name is one of the page helpers below, called with the
 *   booted app and its result printed as JSON.
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME, P202_TEST_REPORT_USER,
 *       P202_TEST_SESSION_DIR (where the session file is written),
 *       P202_TEST_GET / P202_TEST_POST (JSON objects; a POST makes the
 *       request a POST), P202_TEST_AJAX=1 (sends X-Requested-With)
 * Prints the page's output; a page that dies prints what it printed first.
 */

[, $page] = $argv + [null, ''];

$root = dirname(__DIR__, 3);
$dbName = (string) getenv('P202_TEST_DB_NAME');
$userId = (int) getenv('P202_TEST_REPORT_USER');
$sessionDir = (string) getenv('P202_TEST_SESSION_DIR');
if ($dbName === '' || $userId <= 0 || $sessionDir === '' || !is_dir($sessionDir)) {
    fwrite(STDERR, "P202_TEST_DB_NAME, P202_TEST_REPORT_USER and an existing P202_TEST_SESSION_DIR are required\n");
    exit(2);
}
$isFunction = str_starts_with($page, 'function:');
if (!$isFunction && ($page === '' || !is_file($root . '/' . $page) || str_contains($page, '..'))) {
    fwrite(STDERR, "no page '$page' under the repository root\n");
    exit(2);
}
$host = (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('P202_TEST_DB_PORT') ?: '');
$get = json_decode((string) (getenv('P202_TEST_GET') ?: '{}'), true, 8, JSON_THROW_ON_ERROR);
$post = json_decode((string) (getenv('P202_TEST_POST') ?: '{}'), true, 8, JSON_THROW_ON_ERROR);

// The request.
$path = '/' . ($isFunction ? 'tracking202/setup/runner.php' : $page);
$_SERVER = array_merge($_SERVER, [
    'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80', 'SERVER_PROTOCOL' => 'HTTP/1.1',
    'REQUEST_URI' => $path . ($get === [] ? '' : '?' . http_build_query($get)),
    'SCRIPT_URL' => $path, 'SCRIPT_NAME' => $path, 'PHP_SELF' => $path,
    'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'p202-page-runner',
    'REQUEST_METHOD' => $post === [] ? 'GET' : 'POST',
]);
if (getenv('P202_TEST_AJAX') === '1') {
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
}
$_GET = $get;
$_POST = $post;
$_REQUEST = $get + $post;

// The account's session, written before the app starts it.
session_save_path($sessionDir);
session_id('p202pagerunner' . bin2hex(random_bytes(8)));
session_start();
$_SESSION = [
    'user_id' => $userId,
    'user_own_id' => $userId,
    'user_name' => 'page' . $userId,
    'user_timezone' => 'UTC',
    'session_time' => time(),
    'session_fingerprint' => hash_hmac('sha256', 'session_fingerprint|' . $_SERVER['HTTP_USER_AGENT'], session_id()),
    'valid_key' => true,
    'token' => 'p202pagerunnertoken',
];
session_write_close();
if (isset($post['token']) && $post['token'] === 'SESSION') {
    $_POST['token'] = 'p202pagerunnertoken';
}

/** connect.php inside a function: see report-read-failure-runner.php. */
function boot_app(string $root): void
{
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
}

$GLOBALS['dbname'] = $dbName;
$GLOBALS['dbhost'] = $port !== '' ? $host . ':' . $port : $host;
$GLOBALS['dbhostro'] = $GLOBALS['dbhost'];
$GLOBALS['dbuser'] = (string) (getenv('P202_TEST_DB_USER') ?: 'root');
$GLOBALS['dbpass'] = (string) (getenv('P202_TEST_DB_PASS') ?: '');
$GLOBALS['mchost'] = '';
boot_app($root);

$at = DB::getInstance()->getConnection()->query('SELECT DATABASE()');
$current = $at instanceof mysqli_result ? (string) ($at->fetch_row()[0] ?? '') : '';
if ($current !== $dbName) {
    fwrite(STDERR, "the app connected to '$current', not the scratch database '$dbName'\n");
    exit(2);
}
if (!AUTH::logged_in()) {
    fwrite(STDERR, "the runner's session is not signed in\n");
    exit(2);
}

if ($isFunction) {
    $result = match (substr($page, 9)) {
        // Update › CPC's traffic source menus.
        'p202_update_traffic_lists' => (static function () use ($root, $userId): array {
            require_once $root . '/tracking202/update/_includes/update_ui.php';
            return p202_update_traffic_lists(DB::getInstance()->getConnection(), $userId);
        })(),
        default => throw new InvalidArgumentException("no function '$page'"),
    };
    echo json_encode($result, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
    exit(0);
}

chdir(dirname($root . '/' . $page));
include $root . '/' . $page;
