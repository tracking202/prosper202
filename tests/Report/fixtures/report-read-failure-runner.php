<?php

declare(strict_types=1);

/**
 * Boots the app against the scratch database, makes one table unreadable
 * for this run, and calls one report reader, in a process of its own:
 * record_mysql_error() is declared `never` and ends in die().
 *
 * The table is made unreadable the way a busy server does it: a second
 * connection holds a WRITE lock on it and the app's connection gives up
 * after lock_wait_timeout (1 second) with "Lock wait timeout exceeded",
 * which under the app's mysqli_report(MYSQLI_REPORT_STRICT) is a query()
 * that returns false. The lock goes with the process.
 *
 * argv: <reader> <table to lock, or "none">
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME, P202_TEST_REPORT_USER, and
 *       P202_TEST_CHART_CAMPAIGN (the chart line's campaign; 0, all, by default)
 * Prints "RETURNED <json>" when the reader returns and "THREW <class>:
 * <message>" when it throws; the error page is whatever record_mysql_error()
 * printed before it died.
 */

[, $reader, $lock] = $argv + [null, '', 'none'];

$root = dirname(__DIR__, 3);
$dbName = (string) getenv('P202_TEST_DB_NAME');
$userId = (int) getenv('P202_TEST_REPORT_USER');
if ($dbName === '' || $userId <= 0) {
    fwrite(STDERR, "P202_TEST_DB_NAME and P202_TEST_REPORT_USER are required\n");
    exit(2);
}
$host = (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('P202_TEST_DB_PORT') ?: '');

/**
 * connect.php inside a function: 202-config.php assigns its own database
 * name, and at file scope that would be the global the DB class connects
 * with. Here it stays local; the DB class reads the globals set below.
 */
function boot_app(string $root): void
{
    $prev = error_reporting(0);
    ob_start();
    require_once $root . '/202-config/connect.php';
    require_once $root . '/202-config/class-dataengine.php';
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
$_SERVER += ['SERVER_NAME' => 'localhost', 'REQUEST_URI' => '/report-read-failure', 'SCRIPT_URL' => '/report-read-failure', 'REMOTE_ADDR' => '127.0.0.1'];
boot_app($root);

$db = DB::getInstance()->getConnection();
$at = $db->query('SELECT DATABASE()');
$current = $at instanceof mysqli_result ? (string) ($at->fetch_row()[0] ?? '') : '';
if ($current !== $dbName) {
    // Never lock a table of a database this run was not pointed at.
    fwrite(STDERR, "the app connected to '$current', not the scratch database '$dbName'\n");
    exit(2);
}

$_SESSION['user_id'] = $userId;
$_SESSION['user_own_id'] = $userId;
// No 'publisher' key: nothing in the app sets one, so a signed-in account
// reads its own rows. (Set to false, the engine read every account's.)
$_SESSION['user_timezone'] = 'UTC';
$_POST = [];

if ($lock !== 'none') {
    if (!preg_match('/^202_[a-z0-9_]+$/D', $lock)) {
        fwrite(STDERR, "not a table name: $lock\n");
        exit(2);
    }
    $locker = new mysqli($host, $GLOBALS['dbuser'], $GLOBALS['dbpass'], $dbName, $port !== '' ? (int) $port : 3306);
    if (!$locker->query("LOCK TABLES `$lock` WRITE")) {
        fwrite(STDERR, "could not lock $lock: {$locker->error}\n");
        exit(2);
    }
    if (!$db->query('SET SESSION lock_wait_timeout = 1')) {
        fwrite(STDERR, "could not shorten the lock wait: {$db->error}\n");
        exit(2);
    }
}

$now = time();
try {
    $result = match ($reader) {
        // The Visitors list: its count and its read of the filters. query()'s
        // fragments name the table 2c, as the Visitors page's command does.
        'visitors' => query('SELECT 2c.click_id FROM 202_dataengine 2c ', '2c'),
        // Every report page's window.
        'window' => grab_timeframe(),
        // The engine's pagination count, as countReportGroups() runs it.
        'engine-count' => (static function (): int {
            $method = new ReflectionMethod(DataEngine::class, 'runCountQuery');
            return $method->invoke(new DataEngine(), 'SELECT COUNT(*) AS cnt FROM 202_dataengine');
        })(),
        'engine-variable' => (new DataEngine())->getReportData('variable', $now - 86400, $now + 86400, false),
        'engine-per-ppc' => (new DataEngine())->getReportData('alp_per_ppc', $now - 86400, $now + 86400, false),
        'engine-chart' => (new DataEngine())->getChart(
            $now - 86400,
            $now + 86400,
            [['campaign_id' => (string) (getenv('P202_TEST_CHART_CAMPAIGN') ?: '0'), 'value_type' => 'clicks']],
            'days',
            'M d',
            [new DateTime('@' . $now)]
        ),
        default => throw new InvalidArgumentException("no reader named '$reader'"),
    };
} catch (Throwable $e) {
    echo 'THREW ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(0);
}
echo 'RETURNED ', json_encode($result, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
