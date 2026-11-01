<?php

/**
 * Runs one of the data engine's rollups against the scratch database, in a
 * process of its own: the full engine (class-dataengine.php) and the slim
 * one the click path loads (class-dataengine-slim.php) both declare
 * DataEngine, and both read the connection from `global $db` and the DB
 * class, which 202-config.php declares. Here DB is a stand-in that hands
 * back the scratch connection, under the click path's report mode and
 * sql_mode.
 *
 * argv: <full|slim> click <click id>
 *       full window <from> <to> [<params>]
 *       full dirty-hours
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME; P202_TEST_LOCK_WAIT (seconds,
 *       for innodb_lock_wait_timeout) when set
 * Prints "RETURNED <json>" when the call returns and "THREW <class>:
 * <message>" when it throws; what the engine logs goes to stderr.
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[, $engine, $action] = $argv + [null, '', ''];

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require $root . '/202-config/mysql-error-args.php';

$dbName = (string) getenv('P202_TEST_DB_NAME');
if ($dbName === '' || !in_array($engine, ['full', 'slim'], true)) {
    fwrite(STDERR, "P202_TEST_DB_NAME and an engine (full or slim) are required\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_STRICT);
$db = mysqli_connect(
    (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    $dbName,
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
if (!$db instanceof mysqli || $db->query("SET SESSION sql_mode = ''") === false) {
    fwrite(STDERR, "could not connect to the scratch database\n");
    exit(2);
}
$wait = getenv('P202_TEST_LOCK_WAIT');
if ($wait !== false && $wait !== '' && $db->query('SET SESSION innodb_lock_wait_timeout = ' . (int) $wait) === false) {
    fwrite(STDERR, "could not set the lock wait\n");
    exit(2);
}

$stand_in = new class {
    public static ?mysqli $connection = null;

    public static function getInstance(): self
    {
        return new self();
    }

    public function getConnection(): ?mysqli
    {
        return self::$connection;
    }
};
$stand_in::$connection = $db;
class_alias($stand_in::class, 'DB');
$GLOBALS['db'] = $db;
$_SESSION = ['user_timezone' => 'UTC'];

require $root . '/202-config/class-dataengine' . ($engine === 'slim' ? '-slim' : '') . '.php';

try {
    $de = new DataEngine();
    $result = match ($action) {
        'click' => $de->setDirtyHour((string) ($argv[3] ?? '')),
        'window' => $de->getSummary((int) ($argv[3] ?? 0), (int) ($argv[4] ?? 0), (string) ($argv[5] ?? '')) !== '',
        'dirty-hours' => (static function (DataEngine $de): bool {
            $de->processDirtyHours();

            return true;
        })($de),
        default => throw new InvalidArgumentException('unknown action ' . $action),
    };
    echo 'RETURNED ' . json_encode($result);
} catch (Throwable $e) {
    echo 'THREW ' . get_class($e) . ': ' . $e->getMessage();
}
