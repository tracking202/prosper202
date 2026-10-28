<?php

/**
 * Deletes one conversion as DELETE /conversions/{id} does
 * (MysqlConversionRepository::softDelete()), on a connection of its own:
 * LedgerReadsAfterTheLockIntegrationTest holds the conversion's click in
 * another and lets go once the server shows this one waiting.
 *
 * argv: <conversion id> <user id>
 * Prints this connection's id on the first line, then `ok` or the failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

[, $convId, $userId] = $argv + [null, '0', '0'];

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
    (new \Prosper202\Conversion\MysqlConversionRepository(new \Prosper202\Database\Connection($db)))
        ->softDelete((int) $convId, (int) $userId);
    echo "ok\n";
} catch (\Throwable $e) {
    echo 'failed: ', $e::class, ': ', $e->getMessage(), "\n";
}
