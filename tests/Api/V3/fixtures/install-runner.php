<?php

/**
 * Installs into the scratch database the way 202-config/install.php does
 * once its form is accepted: INSTALL::install_databases() (schema, seed, the
 * install's secrets), the first account, and its REST API key. The licence
 * form is not taken; nothing here calls out.
 *
 * In a process of its own, as tests/Upgrade/fixtures/ladder-runner.php runs
 * the ladder: functions-install.php loads the upgrade's functions, and the
 * installer reads the connection from the DB class, which 202-config.php
 * declares. Here DB is a stand-in that hands back the scratch connection.
 *
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 * Prints the API key; what goes wrong goes to stderr, with a non-zero exit.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
require $root . '/202-config/mysql-error-args.php';
require $root . '/202-config/functions-shared.php';

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
$db->query("SET SESSION sql_mode = ''");

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

require $root . '/202-config/version.php';
require $root . '/202-config/functions-tracking202.php';
require $root . '/202-config/functions-auth.php';
require $root . '/202-config/functions-install.php';

try {
    (new INSTALL())->install_databases();
    $userId = \Prosper202\User\InstallAccount::create(new \Prosper202\Database\Connection($db), [
        'email' => 'owner@example.com',
        'name' => 'owner',
        'pass_hash' => hash_user_pass('reinstall-test-pass'),
        'timezone' => 'UTC',
        'registered' => time(),
        'install_hash' => bin2hex(random_bytes(16)),
        'user_hash' => '',
        'customer_key' => 'test-key',
    ]);
    $key = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (?, ?, ?)');
    if ($stmt === false) {
        throw new RuntimeException('the API key insert could not be prepared: ' . $db->error);
    }
    $now = time();
    $stmt->bind_param('isi', $userId, $key, $now);
    if (!$stmt->execute()) {
        throw new RuntimeException('the API key was not stored: ' . $stmt->error);
    }
    $stmt->close();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

echo $key;
