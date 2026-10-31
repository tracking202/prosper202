<?php

/**
 * The other side of a real deadlock, for BulkUpsertLockConflictIntegrationTest:
 * holds row 1 of p202_bulk_locks, says "locked", waits $argv[1] ms, then asks
 * for row 2, which the bulk-upsert's trigger holds while it waits for row 1.
 * It has written more than the bulk-upsert has, so the server rolls the
 * bulk-upsert's transaction back, and this one gets row 2 ("won"). It rolls
 * itself back at the end, so it leaves nothing behind.
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = mysqli_connect(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) getenv('P202_TEST_DB_NAME'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
if (!$db->begin_transaction()) {
    fwrite(STDERR, 'begin failed: ' . $db->error . "\n");
    exit(1);
}
for ($i = 0; $i < 300; $i++) {
    $db->query('INSERT INTO p202_bulk_weight VALUES (1)');
}
$db->query('UPDATE p202_bulk_locks SET v = v + 1 WHERE id = 1');
echo "locked\n";
fflush(STDOUT);
usleep((int) ($argv[1] ?? 1000) * 1000);
try {
    $db->query('UPDATE p202_bulk_locks SET v = v + 1 WHERE id = 2');
    echo "won\n";
} catch (mysqli_sql_exception $e) {
    echo 'refused ' . $e->getCode() . "\n";
}
$db->rollback();
$db->close();
