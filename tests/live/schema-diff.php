<?php

declare(strict_types=1);

/**
 * Compare every table of two databases with SHOW CREATE TABLE, through
 * Tests\Upgrade\SchemaDiff (which says what is normalised away and why).
 *
 *   php tests/live/schema-diff.php <upgraded-db> <fresh-db>
 *
 * Connection from P202_DB_HOST (127.0.0.1), P202_DB_PORT (3306),
 * P202_DB_USER (root), P202_DB_PASS (empty).
 *
 * Exit 0: the same. Exit 1: they differ (each difference printed). Exit 2:
 * something could not be read — never reported as "the same" (CLAUDE.md #11).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Tests\Upgrade\SchemaDiff;

if ($argc !== 3) {
    fwrite(STDERR, "usage: php tests/live/schema-diff.php <upgraded-db> <fresh-db>\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('P202_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('P202_DB_PORT') ?: 3306);
$user = getenv('P202_DB_USER') ?: 'root';
$pass = (string) getenv('P202_DB_PASS');

/**
 * @return array<string, string> table => SHOW CREATE TABLE
 */
function p202_schema_of(string $host, int $port, string $user, string $pass, string $db): array
{
    $conn = @new mysqli($host, $user, $pass, $db, $port);
    if ($conn->connect_errno !== 0) {
        fwrite(STDERR, "cannot connect to $db: {$conn->connect_error}\n");
        exit(2);
    }

    $tables = $conn->query('SHOW FULL TABLES');
    if (!($tables instanceof mysqli_result)) {
        fwrite(STDERR, "SHOW FULL TABLES failed on $db: {$conn->error}\n");
        exit(2);
    }
    $names = [];
    while (($row = $tables->fetch_row()) !== null) {
        // Views are not tables; the installer creates none, and a view would
        // print CREATE VIEW, which the comparison does not read.
        if (($row[1] ?? '') !== 'BASE TABLE') {
            fwrite(STDERR, "$db.{$row[0]} is a {$row[1]}; only base tables are compared\n");
            exit(2);
        }
        $names[] = (string) $row[0];
    }
    $tables->free();

    $schema = [];
    foreach ($names as $name) {
        $result = $conn->query('SHOW CREATE TABLE `' . str_replace('`', '``', $name) . '`');
        if (!($result instanceof mysqli_result)) {
            fwrite(STDERR, "SHOW CREATE TABLE $db.$name failed: {$conn->error}\n");
            exit(2);
        }
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || !isset($row[1])) {
            fwrite(STDERR, "SHOW CREATE TABLE $db.$name returned no statement\n");
            exit(2);
        }
        $schema[$name] = (string) $row[1];
    }
    $conn->close();

    return $schema;
}

$upgraded = p202_schema_of($host, $port, $user, $pass, $argv[1]);
$fresh = p202_schema_of($host, $port, $user, $pass, $argv[2]);

if ($fresh === []) {
    fwrite(STDERR, "{$argv[2]} has no tables; a comparison against nothing proves nothing\n");
    exit(2);
}

try {
    $differences = SchemaDiff::compare($upgraded, $fresh);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'could not read a statement: ' . $e->getMessage() . "\n");
    exit(2);
}

printf("compared %d tables after the upgrade with %d in a fresh install\n", count($upgraded), count($fresh));
foreach ($differences as $difference) {
    echo $difference, "\n";
}
printf("%d difference(s)\n", count($differences));

exit($differences === [] ? 0 : 1);
