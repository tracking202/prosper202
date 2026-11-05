<?php

/**
 * Loads a 202-config.php as connect.php does and reads one table through the
 * primary and the read connection it opens, so a test can see which database
 * a served checkout would write to before it sends a request.
 *
 * argv: <config path> <probe table>
 * Prints {"primary": <token or error>, "read": <token or error>} as JSON.
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../vendor/autoload.php';

$config = (string) ($argv[1] ?? '');
$table = (string) ($argv[2] ?? '');
if ($config === '' || preg_match('/^\w+$/', $table) !== 1) {
    fwrite(STDERR, "usage: served-config-probe.php <config> <table>\n");
    exit(2);
}

$read = static function (mixed $connection) use ($table): string {
    if (!$connection instanceof mysqli || $connection->connect_errno !== 0) {
        return 'error: no connection';
    }
    try {
        $result = $connection->query("SELECT token FROM `$table`");
        $row = $result instanceof mysqli_result ? $result->fetch_row() : null;

        return is_array($row) ? (string) $row[0] : 'error: no row';
    } catch (Throwable $e) {
        return 'error: ' . $e->getMessage();
    }
};

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    include $config;
    $database = DB::getInstance();
    $found = [
        'primary' => $read($database->getConnection()),
        'read' => $read(\Prosper202\Database\ConnectionFactory::readConnectionOf($database)),
    ];
} catch (Throwable $e) {
    $found = ['primary' => 'error: ' . $e->getMessage(), 'read' => 'error: ' . $e->getMessage()];
}
echo json_encode($found), "\n";
