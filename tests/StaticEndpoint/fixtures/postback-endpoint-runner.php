<?php

declare(strict_types=1);

/**
 * Runs one conversion endpoint (tracking202/static/<name>.php) as the web
 * server runs it, in a process of its own, against the scratch database,
 * and prints `STATUS <code>` and then the body it answered.
 *
 * The endpoint is included at file scope once 202-config.php's DB class is
 * there, connected to the scratch database: the checkout's own 202-config.php
 * when it has one (tests/run-integration-suites.sh writes one), loaded inside
 * a function so its variables do not replace the scratch database's, as
 * page-runner.php does, and refused unless it connects there; otherwise a
 * stand-in for its DB class. connect2.php includes the config file only once,
 * so its own include is then a no-op. The request is the raw query string,
 * set as the server sets QUERY_STRING and parsed into $_GET as PHP parses it.
 *
 * argv: <pb | gpb | px | gpx | upx>
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME, P202_TEST_QUERY (the raw
 *       query string), P202_TEST_COOKIES (a JSON object)
 */

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[, $endpoint] = $argv + [null, ''];
$root = dirname(__DIR__, 3);
if (!in_array($endpoint, ['pb', 'gpb', 'px', 'gpx', 'upx'], true)) {
    fwrite(STDERR, "no endpoint '$endpoint'\n");
    exit(2);
}

$query = (string) getenv('P202_TEST_QUERY');
$path = '/tracking202/static/' . $endpoint . '.php';
$_SERVER = array_merge($_SERVER, [
    'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80', 'SERVER_PROTOCOL' => 'HTTP/1.1',
    'REQUEST_METHOD' => 'GET', 'QUERY_STRING' => $query, 'REQUEST_URI' => $path . ($query === '' ? '' : '?' . $query),
    'SCRIPT_NAME' => $path, 'PHP_SELF' => $path, 'REMOTE_ADDR' => '192.0.2.10',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) p202-endpoint-runner',
]);
parse_str($query, $get);
$_GET = $get;
$_COOKIE = json_decode((string) (getenv('P202_TEST_COOKIES') ?: '{}'), true, 4, JSON_THROW_ON_ERROR);
$_REQUEST = $_GET;

$host = (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('P202_TEST_DB_PORT') ?: '');
$dbhost = $port !== '' ? $host . ':' . $port : $host;
$dbhostro = $dbhost;
$dbuser = (string) (getenv('P202_TEST_DB_USER') ?: 'root');
$dbpass = (string) (getenv('P202_TEST_DB_PASS') ?: '');
$dbname = (string) getenv('P202_TEST_DB_NAME');
$mchost = '';

if (is_file($root . '/202-config.php')) {
    (static function (string $config): void {
        ob_start();
        require_once $config;
        ob_end_clean();
    })($root . '/202-config.php');
    $at = DB::getInstance()->getConnection()->query('SELECT DATABASE()');
    $current = $at instanceof mysqli_result ? (string) ($at->fetch_row()[0] ?? '') : '';
    if ($dbname === '' || $current !== $dbname) {
        fwrite(STDERR, "this checkout's 202-config.php connected to '$current', not the scratch database '$dbname'\n");
        exit(2);
    }
} else {
    class DB
    {
        private static ?self $instance = null;
        private \mysqli $connection;

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        private function __construct()
        {
            [$host, $port] = explode(':', $GLOBALS['dbhost']) + [1 => '3306'];
            $this->connection = new \mysqli($host, $GLOBALS['dbuser'], $GLOBALS['dbpass'], $GLOBALS['dbname'], (int) $port);
        }

        public function getConnection(): \mysqli
        {
            return $this->connection;
        }

        public function getConnectionro(): \mysqli
        {
            return $this->connection;
        }
    }
}

ob_start();
register_shutdown_function(static function (): void {
    $body = '';
    while (ob_get_level() > 0) {
        $body = (string) ob_get_clean() . $body;
    }
    $code = http_response_code();
    fwrite(STDOUT, 'STATUS ' . (is_int($code) ? $code : 200) . "\n" . $body);
});
chdir($root . '/tracking202/static');
require $root . '/tracking202/static/' . $endpoint . '.php';
