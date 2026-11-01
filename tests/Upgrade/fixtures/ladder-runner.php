<?php

/**
 * Runs the upgrade ladder (UPGRADE::upgrade_databases()) against the scratch
 * database, in a process of its own: functions-upgrade.php loads the full
 * DataEngine, and the ladder reads the connection from `global $db` and the
 * DB class, which 202-config.php declares. Here DB is a stand-in that hands
 * back the scratch connection.
 *
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 * Prints "RETURNED <json>" when the ladder returns and "THREW <class>:
 * <message>" when it throws; what the ladder logs goes to stderr.
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require $root . '/202-config/mysql-error-args.php';

$dbName = (string) getenv('P202_TEST_DB_NAME');
if ($dbName === '') {
    fwrite(STDERR, "P202_TEST_DB_NAME is required\n");
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
if (!$db instanceof mysqli) {
    fwrite(STDERR, "could not connect to the scratch database\n");
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

require $root . '/202-config/version.php';
require $root . '/202-config/functions-upgrade.php';

try {
    echo 'RETURNED ' . json_encode(UPGRADE::upgrade_databases(time()));
} catch (Throwable $e) {
    echo 'THREW ' . get_class($e) . ': ' . $e->getMessage();
}
