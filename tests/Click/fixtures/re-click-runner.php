<?php

/**
 * Re-clicks one click as rtr.php?lpr= does (MysqlClickRepository::
 * recordReClick()), through redirector link source 13 at 0.75, on a
 * connection of its own: ReClickFirstVisitLockIntegrationTest holds the
 * click's lock in another and lets this go once the server shows it waiting.
 *
 * argv: <click id> <user id> <click time>
 * Prints this connection's id on the first line, then `ok` or the failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

[, $clickId, $userId, $clickTime] = $argv + [null, '0', '0', '0'];

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
    (new \Prosper202\Click\MysqlClickRepository(new \Prosper202\Database\Connection($db)))->recordReClick(
        new \Prosper202\Click\ClickRecord(
            clickId: (int) $clickId,
            userId: (int) $userId,
            clickTime: (int) $clickTime,
            affCampaignId: 14,
            ppcAccountId: 13,
            clickCpc: '0.75',
            clickPayout: '1.25',
            keywordId: 6,
            ipId: 23,
            recordsCampaignRows: false,
            clickOut: 1,
            rotator: new \Prosper202\Click\RotatorRoute(17, 18, 19),
        ),
        static fn (): int => 52
    );
    echo "ok\n";
} catch (\Throwable $e) {
    echo 'failed: ', $e::class, ': ', $e->getMessage(), "\n";
}
