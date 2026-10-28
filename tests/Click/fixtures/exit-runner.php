<?php

/**
 * Sends one click on through a rotator route as offrtr.php does
 * (MysqlClickRepository::recordExit()), on a connection of its own, with
 * @p202_test_exit_waits set: ReClickFirstVisitLockIntegrationTest's trigger
 * on 202_clicks_rotator holds this exit there, once its route is written,
 * until the test lets go.
 *
 * argv: <click id> <user id> <rotator id> <rule id> <redirect id>
 * Prints this connection's id on the first line, then `ok`, `refused` or
 * the failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

[, $clickId, $userId, $rotatorId, $ruleId, $redirectId] = $argv + [null, '0', '0', '0', '0', '0'];

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
$db->query('SET @p202_test_exit_waits = 1');
echo $db->thread_id, "\n";

try {
    $recorded = (new \Prosper202\Click\MysqlClickRepository(new \Prosper202\Database\Connection($db)))->recordExit(
        new \Prosper202\Click\ClickExit(
            (int) $userId,
            (int) $clickId,
            rotator: new \Prosper202\Click\RotatorRoute((int) $rotatorId, (int) $ruleId, (int) $redirectId),
            clickedOut: false,
        )
    );
    echo $recorded ? "ok\n" : "refused\n";
} catch (\Throwable $e) {
    echo 'failed: ', $e::class, ': ', $e->getMessage(), "\n";
}
