<?php

/**
 * Brings one click's visits to the re-click rule as the 1.9.79 backfill
 * does (MysqlClickRepository::repairVisits()), on a connection of its own,
 * with @p202_test_repair_waits set: ReClickBackfillIntegrationTest's trigger
 * on 202_click_latest_visits holds the repair there, its rows rewritten and
 * its transaction open, until the test lets go.
 *
 * argv: <click id>
 * Prints this connection's id on the first line, then the outcome or the
 * failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

[, $clickId] = $argv + [null, '0'];

require __DIR__ . '/../../../vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
// A test that never lets go fails here rather than hanging.
$db->query('SET SESSION innodb_lock_wait_timeout = 20');
$db->query('SET @p202_test_repair_waits = 1');
echo $db->thread_id, "\n";

try {
    $repair = (new \Prosper202\Click\MysqlClickRepository(new \Prosper202\Database\Connection($db)))
        ->repairVisits((int) $clickId);
    echo $repair['outcome'], "\n";
} catch (\Throwable $e) {
    echo 'failed: ', $e::class, ': ', $e->getMessage(), "\n";
}
