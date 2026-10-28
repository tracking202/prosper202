<?php

/**
 * Runs one request as the web server runs it, in a process of its own, for a
 * browser that signed in as P202_SU_USER (or, with P202_SU_SESSION=none, one
 * that holds only a remember-me cookie): the session is written where PHP
 * keeps it, the app is booted (connect.php, inside a function as
 * tests/Setup/fixtures/page-runner.php boots it), and then a page is included
 * at file scope or one AUTH call is made.
 *
 * argv: <page path, relative to the repository root>
 *       | call:booted               nothing: the session's identity keys and the
 *                                   User's permission, as any page finds them
 *       | call:logged_in            AUTH::logged_in(), with connect.php's User
 *       | call:logged_in_unread     the same with no User read this request
 *       | call:remember             AUTH::logged_in_with_remember()
 *       | call:may                  who the session is signed in as, and whether
 *                                   connect.php's User has P202_SU_PERMISSION
 *       | call:sign_out             AUTH::signOut()
 *       | call:authenticate         AUTH::authenticate(P202_SU_NAME, P202_SU_PASS)
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME, P202_SU_USER, P202_SU_SESSION_DIR,
 *       P202_SU_SESSION (none: no session; anonymous: a session holding only
 *       its token, as a browser that has not signed in has), P202_SU_OWN
 *       (user_own_id, when not P202_SU_USER), P202_SU_REMEMBER (the
 *       remember_me cookie), P202_SU_GET (a JSON object: the query string),
 *       P202_SU_POST (a JSON object: a POST; "token": "SESSION" sends the
 *       session's), P202_SU_AJAX=1 (X-Requested-With, as jQuery sends it) or
 *       fetch (Sec-Fetch-Dest: empty, as a browser marks fetch()),
 *       P202_SU_NAME, P202_SU_PASS, P202_SU_PERMISSION (call:may)
 *
 * Prints what the page printed, then one line: "@@RESULT " and a JSON object
 * with the status, whether 202-access-denied.php answered, the session's id
 * and keys as the request left them, whether the remember_me cookie is still
 * in the request, the call's answer, and any uncaught throwable.
 */

declare(strict_types=1);

[, $target] = $argv + [null, ''];

$root = dirname(__DIR__, 3);
$dbName = (string) getenv('P202_TEST_DB_NAME');
$userId = (int) getenv('P202_SU_USER');
$sessionDir = (string) getenv('P202_SU_SESSION_DIR');
if ($dbName === '' || $sessionDir === '' || !is_dir($sessionDir)) {
    fwrite(STDERR, "P202_TEST_DB_NAME and an existing P202_SU_SESSION_DIR are required\n");
    exit(2);
}
$call = str_starts_with($target, 'call:') ? substr($target, 5) : null;
if ($call === null && ($target === '' || !is_file($root . '/' . $target) || str_contains($target, '..'))) {
    fwrite(STDERR, "no page '$target' under the repository root\n");
    exit(2);
}
$host = (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('P202_TEST_DB_PORT') ?: '');
$post = json_decode((string) (getenv('P202_SU_POST') ?: '{}'), true, 8, JSON_THROW_ON_ERROR);

$path = '/' . ($call === null ? $target : '202-account/index.php');
$_SERVER = array_merge($_SERVER, [
    'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80', 'SERVER_PROTOCOL' => 'HTTP/1.1',
    'REQUEST_URI' => $path, 'SCRIPT_URL' => $path, 'SCRIPT_NAME' => $path, 'PHP_SELF' => $path,
    'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'p202-session-user-runner',
    'REQUEST_METHOD' => $post === [] ? 'GET' : 'POST',
]);
if (getenv('P202_SU_AJAX') === '1') {
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
}
if (getenv('P202_SU_AJAX') === 'fetch') {
    // What a browser sends with fetch(), which sets no X-Requested-With.
    $_SERVER['HTTP_SEC_FETCH_DEST'] = 'empty';
    $_SERVER['HTTP_SEC_FETCH_MODE'] = 'cors';
}
$_GET = json_decode((string) (getenv('P202_SU_GET') ?: '{}'), true, 8, JSON_THROW_ON_ERROR);
$_POST = $post;
$_REQUEST = array_merge($_GET, $post);
$remember = (string) getenv('P202_SU_REMEMBER');
$_COOKIE = $remember === '' ? [] : ['remember_me' => $remember];

$runnerToken = 'p202sessionuserrunnertoken000000';
session_save_path($sessionDir);
if (getenv('P202_SU_SESSION') === 'anonymous') {
    session_id('p202su' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION = ['token' => $runnerToken];
    session_write_close();
} elseif (getenv('P202_SU_SESSION') !== 'none') {
    session_id('p202su' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION = [
        'user_id' => $userId,
        'user_own_id' => (int) (getenv('P202_SU_OWN') ?: $userId),
        'user_name' => 'su' . $userId,
        'user_timezone' => 'UTC',
        'session_time' => time(),
        'session_fingerprint' => hash_hmac(
            'sha256',
            'session_fingerprint|' . $_SERVER['HTTP_USER_AGENT'],
            session_id()
        ),
        'account_owner_id' => $userId,
        'valid_key' => true,
        'token' => $runnerToken,
    ];
    session_write_close();
}
if (($post['token'] ?? null) === 'SESSION') {
    $_POST['token'] = $runnerToken;
}
$signedInAs = session_id();

$answer = null;
$uncaught = null;
register_shutdown_function(static function () use (&$answer, &$uncaught, $root, $signedInAs): void {
    $denied = in_array(realpath($root . '/202-access-denied.php'), array_map('realpath', get_included_files()), true);
    // The statements the app's connection was sent, this one and the
    // runner's own included: a count to compare between two requests.
    $questions = null;
    if (class_exists('DB', false)) {
        try {
            $status = DB::getInstance()->getConnection()->query("SHOW SESSION STATUS LIKE 'Questions'");
            $questions = $status instanceof mysqli_result ? (int) ($status->fetch_row()[1] ?? -1) : null;
        } catch (\Throwable) {
            $questions = null;
        }
    }
    echo "\n@@RESULT ", json_encode([
        'questions' => $questions,
        'status' => http_response_code(),
        'denied' => $denied,
        'signed_in_as' => $signedInAs,
        'session_id' => session_id(),
        'session_keys' => array_keys($_SESSION ?? []),
        'token' => $_SESSION['token'] ?? null,
        'cookie_kept' => isset($_COOKIE['remember_me']),
        'answer' => $answer,
        'uncaught' => $uncaught,
    ]), "\n";
});
set_exception_handler(static function (\Throwable $e) use (&$uncaught): void {
    $uncaught = get_class($e) . ': ' . $e->getMessage();
    if (function_exists('p202PageUncaught')) {
        // What the web server's handler does: a 500, and the database error recorded.
        p202PageUncaught($e);
    }
});

// connect.php inside a function, so 202-config.php's own variables do not
// replace the scratch database's (tests/Setup/fixtures/page-runner.php).
$bootApp = static function (string $root): void {
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
};

$GLOBALS['dbname'] = $dbName;
$GLOBALS['dbhost'] = $port !== '' ? $host . ':' . $port : $host;
$GLOBALS['dbhostro'] = $GLOBALS['dbhost'];
$GLOBALS['dbuser'] = (string) (getenv('P202_TEST_DB_USER') ?: 'root');
$GLOBALS['dbpass'] = (string) (getenv('P202_TEST_DB_PASS') ?: '');
$GLOBALS['mchost'] = '';
$bootApp($root);

$at = DB::getInstance()->getConnection()->query('SELECT DATABASE()');
$current = $at instanceof mysqli_result ? (string) ($at->fetch_row()[0] ?? '') : '';
if ($current !== $dbName) {
    fwrite(STDERR, "the app connected to '$current', not the scratch database '$dbName'\n");
    exit(2);
}

// A statement the case runs once the app has booted, before the call: the
// one that makes the call's own read fail.
$afterBoot = (string) getenv('P202_SU_AFTER_BOOT_SQL');
if ($afterBoot !== '' && DB::getInstance()->getConnection()->query($afterBoot) === false) {
    fwrite(STDERR, 'P202_SU_AFTER_BOOT_SQL failed: ' . DB::getInstance()->getConnection()->error . "\n");
    exit(2);
}

if ($call !== null) {
    $answer = match ($call) {
        // What any page sees once connect.php has run, before it asks anything.
        'booted' => [
            'identity' => array_values(array_intersect(
                ['user_id', 'user_own_id', 'user_name', 'session_fingerprint'],
                array_keys($_SESSION)
            )),
            'may_change_settings' => $GLOBALS['userObj'] instanceof User
                && $GLOBALS['userObj']->hasPermission('access_to_personal_settings'),
        ],
        'logged_in' => AUTH::logged_in(),
        'logged_in_unread' => (static function (): bool {
            $GLOBALS['userObj'] = null;
            return AUTH::logged_in();
        })(),
        'remember' => AUTH::logged_in_with_remember(),
        'may' => [
            'signed_in_as' => (int) ($_SESSION['user_id'] ?? 0),
            'may' => $GLOBALS['userObj'] instanceof User
                && $GLOBALS['userObj']->hasPermission((string) getenv('P202_SU_PERMISSION')),
        ],
        'sign_out' => (static function (): bool {
            AUTH::signOut();
            return true;
        })(),
        'authenticate' => AUTH::authenticate(
            (string) getenv('P202_SU_NAME'),
            (string) getenv('P202_SU_PASS'),
            DB::getInstance()->getConnection()
        )['success'],
        default => throw new InvalidArgumentException("no call '$call'"),
    };
    exit(0);
}

chdir(dirname($root . '/' . $target));
include $root . '/' . $target;
