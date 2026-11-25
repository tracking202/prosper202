<?php

/**
 * Delivers one ClickBank notification as cb202.php does (Receiver), on a
 * connection of its own: ClickBankInsIntegrationTest holds the click's lock
 * in another, with a sale written and not yet committed, and lets go once
 * the server shows this one waiting.
 *
 * argv: <file holding the notification's body>
 * Prints this connection's id on the first line, then the outcome as JSON.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[, $bodyFile] = $argv + [null, ''];

require __DIR__ . '/../../../vendor/autoload.php';
if (!function_exists('_mysqli_query')) {
    eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
}
require_once __DIR__ . '/../../../202-config/static-endpoint-helpers.php';

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

$peer = '203.0.113.9';
$request = new \Prosper202\PaymentNotification\InboundRequest(
    'POST',
    'application/json',
    [],
    $peer,
    $peer,
    'ClickBank',
    (string) file_get_contents((string) $bodyFile)
);
$outcome = (new \Prosper202\PaymentNotification\Receiver(
    new \Prosper202\PaymentNotification\ClickBank\ClickBankAdapter(),
    $db
))->receive($request);
echo json_encode(['kind' => $outcome->kind->name, 'message' => $outcome->message], JSON_THROW_ON_ERROR), "\n";
