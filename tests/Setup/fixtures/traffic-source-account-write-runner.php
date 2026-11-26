<?php

/**
 * Puts a traffic source account on a source as POST or PUT /ppc-accounts
 * does (PpcAccountsController), on a connection of its own:
 * TrafficSourceAccountWritesIntegrationTest holds the source the way a repair
 * does in another, and acts once the server shows this one waiting.
 *
 * argv: <user id> <ppc_network_id> [<ppc_account_id> to move it; none makes one]
 * Prints this connection's id on the first line, then `ok <ppc_account_id>`,
 * `refused: …` or the failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too, and this writes with
// no sign-in (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[, $userId, $sourceId, $accountId] = $argv + [null, '0', '0', ''];

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
    $accounts = new \Api\V3\Controllers\PpcAccountsController($db, (int) $userId);
    $written = $accountId === ''
        ? $accounts->create(['ppc_account_name' => 'Written beside a repair', 'ppc_network_id' => (int) $sourceId])
        : $accounts->update((int) $accountId, ['ppc_network_id' => (int) $sourceId]);
    echo 'ok ', $written['data']['ppc_account_id'], "\n";
} catch (\Api\V3\Exception\ValidationException $e) {
    echo 'refused: ', json_encode($e->getFieldErrors() ?: $e->getMessage()), "\n";
} catch (\Throwable $e) {
    echo 'failed: ', $e::class, ': ', $e->getMessage(), "\n";
}
