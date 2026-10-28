<?php

/**
 * One LTV maintenance run on a connection of its own, for
 * LtvMaintenanceIntegrationTest's race with a renewal.
 *
 * argv: <job>[,<job>…]
 * Prints the report as JSON (null when the lock was held), or the failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Prosper202\Database\Connection;
use Prosper202\Ltv\LtvMaintenance;

mysqli_report(MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);

try {
    $jobs = array_values(array_filter(
        explode(',', (string) ($argv[1] ?? '')),
        static fn (string $j): bool => $j !== ''
    ));
    echo json_encode(LtvMaintenance::runExclusive(new Connection($db), time() + 60, $jobs)), "\n";
} catch (Throwable $e) {
    echo 'failed: ', $e::class, ': ', $e->getMessage(), "\n";
    exit(1);
}
