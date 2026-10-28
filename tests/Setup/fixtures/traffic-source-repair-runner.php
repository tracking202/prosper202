<?php

/**
 * Plans and repairs one traffic source as POST
 * /system/traffic-source-owners/repair does (TrafficSourceOwners::plan(),
 * then repair()), on a connection of its own: TrafficSourceOwnersIntegrationTest
 * holds a user row the way UserDataPurge::deleteUser() does in another, and
 * deletes the user once the server shows this one waiting.
 *
 * argv: <ppc_network_id> <acting user id>
 * Prints this connection's id on the first line, then `ok`, `refused: …` or
 * the failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too, and this repairs with
// no sign-in (TrafficSourceRepairRunnerIsNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[, $sourceId, $actor] = $argv + [null, '0', '0'];

require __DIR__ . '/../../../vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
$db->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
// A test that never lets go fails here rather than hanging.
$db->query('SET SESSION innodb_lock_wait_timeout = 20');
echo $db->thread_id, "\n";

try {
    $owners = new \Prosper202\Setup\TrafficSourceOwners(new \Prosper202\Database\Connection($db));
    $plan = $owners->plan((int) $sourceId, null);
    $owners->repair((int) $sourceId, $plan['from'], $plan['to'], null, (int) $actor);
    echo "ok\n";
} catch (\Prosper202\Setup\TrafficSourceRepairRefused $e) {
    echo 'refused: ', $e->getMessage(), "\n";
} catch (\Throwable $e) {
    echo 'failed: ', $e::class, ': ', $e->getMessage(), "\n";
}
