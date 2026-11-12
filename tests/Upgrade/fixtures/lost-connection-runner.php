<?php

/**
 * Runs the upgrade with its connection to the scratch database lost before
 * it starts, then draws what the pages show for an upgrade that answered
 * false (the report on the upgrade page, the log on the 1-click pages).
 * Prints "RETURNED <json>", then the two texts; "THREW <class>: <message>"
 * when anything throws. In a process of its own, as ladder-runner.php is: functions-upgrade.php loads the full
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

require $root . '/202-config/functions-ui.php';

// The server ends a connection: every statement on it after answers 2006.
// By default the upgrade's own, before it starts; with P202_LOSE=version-read
// the one the stored version is read on, so the upgrade takes its lock and
// loses the database part way.
$lost = $db;
if (getenv('P202_LOSE') === 'version-read') {
    $lost = mysqli_connect(
        (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1'),
        (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
        (string) (getenv('P202_TEST_DB_PASS') ?: ''),
        $dbName,
        (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
    );
    $stand_in::$connection = $lost;
}
$other = mysqli_connect(
    (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    $dbName,
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
$other->query('KILL ' . (int) $lost->thread_id);
$other->close();

// P202_DRAW=one-click: what a 1-click page does after its database step
// failed, on the real standalone shell; only the install's address and the
// remote wallpaper are stood in for.
if (getenv('P202_DRAW') === 'one-click') {
    require __DIR__ . '/standalone-shell-stand-ins.php';
    require $root . '/202-config/functions-standalone-ui.php';
    try {
        echo 'RETURNED ' . json_encode(UPGRADE::upgrade_databases(time())) . "\n";
        $log = p202_upgrade_failure_log(UPGRADE::failures(), _upgrade_stored_version_or_null(), '1.9.81');
        register_shutdown_function(static function (): void {
            echo "\nENDED";
        });
        p202_one_click_after_failed_database_step($log);
        echo "\nCARRIED ON";
    } catch (Throwable $e) {
        echo 'THREW ' . get_class($e) . ': ' . $e->getMessage();
    }
    exit;
}

try {
    echo 'RETURNED ' . json_encode(UPGRADE::upgrade_databases(time())) . "\n";
    echo p202_upgrade_failure_report(UPGRADE::failures(), _upgrade_stored_version_or_null(), '1.9.80') . "\n";
    echo "LOG\n" . p202_upgrade_failure_log(UPGRADE::failures(), _upgrade_stored_version_or_null(), '1.9.81');
} catch (Throwable $e) {
    echo 'THREW ' . get_class($e) . ': ' . $e->getMessage();
}
