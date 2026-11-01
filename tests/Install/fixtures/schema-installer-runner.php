<?php

/**
 * Installs the schema on the scratch database with the installer's own
 * session statements refused (the sql_mode SET and the 202_ips_v6 collation
 * ALTER), as a server that denied them would, in a process of its own: the
 * global _mysqli_query() defined here would stand in for other suites'.
 *
 * Prints SUCCESS or FAILED, then one ERROR line per error the result holds.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../vendor/autoload.php';

// Evaluated, as the integration suites declare their stand-ins.
eval(<<<'PHP'
    function _mysqli_query($db, $sql = null)
    {
        $statement = (string) $sql;
        if (preg_match('/^\s*(SET session sql_mode|ALTER TABLE `202_ips_v6`)/i', $statement) === 1) {
            $statement = 'SELECT nothing FROM p202_no_such_table_for_schema_installer';
        }

        return $db->query($statement);
    }
    PHP);

// As connect.php reports: a failed statement answers false.
mysqli_report(MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
// The mode the installer would have set, so the tables are created as they
// are on an install where its own SET works.
$db->query("SET SESSION sql_mode = ''");

$result = (new \Prosper202\Database\SchemaInstaller($db))->install();
echo $result->success ? "SUCCESS\n" : "FAILED\n";
foreach ($result->errors as $error) {
    echo 'ERROR ', $error, "\n";
}
