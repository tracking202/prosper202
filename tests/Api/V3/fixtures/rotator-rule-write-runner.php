<?php

/**
 * One write of a redirector's rule, as PUT and DELETE
 * /rotators/{id}/rules/{ruleId} make it (RotatorsController::updateRule(),
 * deleteRule()), on a connection of its own: RotatorRuleSetIntegrationTest
 * removes the rule in another and lets go once the server shows this one
 * waiting.
 *
 * argv: <update|delete> <user id> <rotator id> <rule id> [<JSON body>]
 * Prints this connection's id on the first line, then `ok` or the failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

[, $op, $userId, $rotatorId, $ruleId, $body] = $argv + [null, '', '0', '0', '0', '{}'];

require __DIR__ . '/../../../../vendor/autoload.php';

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
    $rotators = new \Api\V3\Controllers\RotatorsController($db, (int) $userId);
    if ($op === 'update') {
        $payload = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        $rotators->updateRule((int) $rotatorId, (int) $ruleId, $payload);
    } elseif ($op === 'delete') {
        $rotators->deleteRule((int) $rotatorId, (int) $ruleId);
    } else {
        throw new \InvalidArgumentException('Unknown write: ' . $op);
    }
    echo "ok\n";
} catch (\Throwable $e) {
    echo 'failed: ', $e::class, ': ', $e->getMessage(), "\n";
}
