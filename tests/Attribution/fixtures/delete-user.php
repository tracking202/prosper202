<?php

declare(strict_types=1);

/**
 * One side of UserDeletionPurgesAttributionTest's worker race: delete a user
 * through the real UserDataPurge::deleteUser(), in a process of its own, so
 * the test can hold the attribution worker's side of the lock meanwhile.
 *
 * argv: <user id> <export directory>
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 * stdout: "deleted" on success; an exception's message otherwise (exit 1)
 */

require __DIR__ . '/../../../vendor/autoload.php';

[, $userId, $exportDir] = $argv + [null, '0', ''];

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = mysqli_connect(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
$db->query('SET SESSION innodb_lock_wait_timeout = 20');

try {
    (new \Prosper202\User\UserDataPurge($db, new \Prosper202\Attribution\ExportFiles((string) $exportDir)))->deleteUser((int) $userId);
    echo "deleted\n";
} catch (\Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
