<?php

declare(strict_types=1);

/**
 * One side of IdentityRaceIntegrationTest: link a click to a customer id
 * through the real ClickIdentity::attach(), in a process of its own so two
 * of them can race, at the isolation level asked for.
 *
 * argv: <click id> <customer id> <RC|RR>
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME, P202_TEST_ERROR_LOG
 * stdout: {"click": <id>, "key": <visitor key or null>}
 */

require __DIR__ . '/../../../vendor/autoload.php';

[, $clickId, $customer, $isolation] = $argv + [null, '0', '', 'RC'];

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
$db = mysqli_connect(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
$db->query($isolation === 'RR'
    ? 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ'
    : 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$log = getenv('P202_TEST_ERROR_LOG');
if ($log !== false && $log !== '') {
    ini_set('error_log', $log);
}

$key = \Prosper202\Identity\ClickIdentity::trustedCustomer((string) $customer)
    ->attach(new \Prosper202\Database\Connection($db), 1, (int) $clickId, time());

echo json_encode(['click' => (int) $clickId, 'key' => $key]), "\n";
