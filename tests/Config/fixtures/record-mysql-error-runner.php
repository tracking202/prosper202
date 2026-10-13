<?php

declare(strict_types=1);

/**
 * Runs one of the two real record_mysql_error() definitions in a process of
 * its own (it is declared `never` and ends in die()), called the way the
 * redirect and admin pages call it.
 *
 * Neither defining file can be loaded alone — connect2.php bootstraps the
 * whole click path and functions-tracking202.php declares INDEXES, which the
 * function calls — so the function's own source is lifted out of the file
 * by its tokens and evaluated beside stand-ins for what it calls. The body
 * that runs is the shipped body, byte for byte.
 *
 * argv: <defining file> <call shape: db | db_sql | sql> [<scenario>]
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME, P202_RME_MARKER (the user id
 *       the recorded row carries, so the test can find it)
 *
 * Scenarios:
 *   page            the failed query alone; the error record's statement
 *                   is not run (the page, and what it asks the connection)
 *   txn             inside begin_transaction(), after a write of its own:
 *                   the error record is really written
 *   autocommit_off  the same, under autocommit(false)
 *   gone            the connection killed before the failing query, so
 *                   nothing can be rolled back or recorded
 *   lookup_fails    the connection alive, and the record's own lookup
 *                   failing through the defining file's real
 *                   _mysqli_query(), which on the click path calls
 *                   record_mysql_error() again
 *   insert_fails    the lookups answering, and the error row's INSERT
 *                   refused by the server
 */

[$script, $file, $shape, $scenario] = $argv + [null, null, 'db', 'page'];

require_once __DIR__ . '/../../../202-config/mysql-error-args.php';
// The classes the bodies reach through connect.php's autoloader (VisitorIp,
// StoredVisitorIp, TrackingBaseUrl and what it reads): the project's own
// autoloader, rather than a list of files that goes stale with each one.
require_once __DIR__ . '/../../../vendor/autoload.php';

require_once __DIR__ . '/lift-function.php';

// What the two bodies call on the way to the error page: the real AUTH
// (functions-auth.php loads alone), and stand-ins for INDEXES and DB, whose
// real copies need the whole bootstrap (both names are already on
// DuplicateGlobalClassTest's list of per-bootstrap copies).
require_once __DIR__ . '/../../../202-config/functions-auth.php';
class INDEXES
{
    public static function get_ip_id($ip)
    {
        return self::lookup();
    }
    public static function get_site_url_id($url)
    {
        return self::lookup();
    }

    /**
     * Both real lookups read through _mysqli_query() and dereference what
     * it answers, so the scenarios that use the real _mysqli_query() do the
     * same. The others answer 1 without asking the database.
     */
    private static function lookup()
    {
        $scenario = $GLOBALS['scenario'] ?? 'page';
        if ($scenario !== 'gone' && $scenario !== 'lookup_fails') {
            return 1;
        }
        $result = _mysqli_query(
            $scenario === 'gone' ? 'SELECT 1 AS id' : 'SELECT id FROM p202_no_such_lookup_for_record_mysql_error'
        );

        return $result->fetch_assoc()['id'] ?? 0;
    }
}
class DB
{
    public static function getInstance(): self
    {
        return new self();
    }
    public function getConnection()
    {
        return $GLOBALS['db'] ?? null;
    }
}
if ($scenario === 'gone' || $scenario === 'lookup_fails') {
    // The defining file's own _mysqli_query(): connect2.php's calls
    // record_mysql_error() when a statement fails, functions.php's (the
    // admin pages', beside functions-tracking202.php) answers false.
    $queryFile = basename((string) $file) === 'connect2.php'
        ? (string) $file
        : dirname((string) $file) . '/functions.php';
    eval(lift_function($queryFile, '_mysqli_query'));
} else {
    function _mysqli_query($dbOrSql, $sql = null)
    {
        if (($GLOBALS['scenario'] ?? 'page') === 'page') {
            return true;
        }
        // The error record really written, through the procedural call so
        // the recording below counts only what the page asks itself.
        $statement = $sql ?? $dbOrSql;
        if (($GLOBALS['scenario'] ?? 'page') === 'insert_fails') {
            $refused = 'p202_no_such_table_for_the_error_row';
            $statement = preg_replace('/\b202_mysql_errors\b/', $refused, (string) $statement);
        }
        $result = mysqli_query($GLOBALS['db'], (string) $statement);
        if ($result === false) {
            echo 'RECORD FAILED: ' . mysqli_error($GLOBALS['db']) . "\n";
        }

        return $result;
    }
}
function template_bottom(): void
{
}
// connect2.php's own, which the click path's body logs the address through:
// the address as stored, unmasked here (no privacy setting in this process).
function p202StoredVisitorIp(): string
{
    return \Prosper202\Http\StoredVisitorIp::fromServer($_SERVER, false);
}

$marker = (int) (getenv('P202_RME_MARKER') ?: 1);
$_SERVER += [
    'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'REMOTE_ADDR' => '203.0.113.7',
    'SERVER_NAME' => 'example.test', 'REQUEST_URI' => '/x', 'SCRIPT_URL' => '/x',
    'SERVER_ADMIN' => 'admin@example.test',
];
$_SESSION = ['user_id' => $marker, 'user_timezone' => 'UTC'];

eval(lift_function((string) $file, 'record_mysql_error'));

/**
 * A connection that records every statement it is asked through its own
 * methods (a procedural mysqli_*() call bypasses these), so the test can
 * hold the error page to asking the failed connection nothing more. A read
 * made there throws on the same failure that brought the page up (the
 * account's time zone was read once, through AUTH::set_timezone()) and
 * replaces the page with a fatal, and only a database that happens to lack
 * the table read could show that: the recording shows it on any database.
 * How the page ends the connection's transaction is recorded apart.
 */
final class RecordingMysqli extends mysqli
{
    /** @var list<string> */
    public array $asked = [];

    /** @var list<string> */
    public array $ended = [];

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->asked[] = $query;

        return parent::query($query, $result_mode);
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        $this->asked[] = $query;

        return parent::prepare($query);
    }

    public function real_query(string $query): bool
    {
        $this->asked[] = $query;

        return parent::real_query($query);
    }

    public function multi_query(string $query): bool
    {
        $this->asked[] = $query;

        return parent::multi_query($query);
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        $this->ended[] = 'rollback';

        return parent::rollback($flags, $name);
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $this->ended[] = 'commit';

        return parent::commit($flags, $name);
    }

    public function autocommit(bool $enable): bool
    {
        $this->ended[] = 'autocommit ' . ($enable ? 'on' : 'off');

        return parent::autocommit($enable);
    }
}

$connect = static function (string $class): mysqli {
    return new $class(
        (string) getenv('P202_TEST_DB_HOST'),
        (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
        (string) (getenv('P202_TEST_DB_PASS') ?: ''),
        (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
        (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
    );
};

mysqli_report(MYSQLI_REPORT_OFF);
$db = $connect(RecordingMysqli::class);
if ($db->connect_errno !== 0) {
    fwrite(STDERR, "no database\n");
    exit(4);
}
$GLOBALS['db'] = $db;
$GLOBALS['scenario'] = $scenario;

if ($scenario === 'txn' || $scenario === 'autocommit_off') {
    // The real table (CoreTables::mysqlErrors()), and a table for the
    // caller's own write, both made before the transaction starts.
    $tables = [
        \Prosper202\Database\Tables\CoreTables::mysqlErrors()->createStatement,
        'CREATE TABLE IF NOT EXISTS p202_rme_probe (marker INT UNSIGNED NOT NULL) ENGINE=InnoDB',
    ];
    foreach ($tables as $ddl) {
        if (mysqli_query($db, $ddl) === false) {
            fwrite(STDERR, 'setup failed: ' . mysqli_error($db) . "\n");
            exit(5);
        }
    }
    $began = $scenario === 'txn' ? mysqli_begin_transaction($db) : mysqli_autocommit($db, false);
    if (!$began || mysqli_query($db, "INSERT INTO p202_rme_probe SET marker = $marker") === false) {
        fwrite(STDERR, 'setup failed: ' . mysqli_error($db) . "\n");
        exit(5);
    }
} elseif ($scenario === 'gone') {
    $killer = $connect(mysqli::class);
    if ($killer->connect_errno !== 0 || $killer->query('KILL ' . (int) $db->thread_id) === false) {
        fwrite(STDERR, 'could not kill the connection: ' . $killer->error . "\n");
        exit(5);
    }
    $killer->close();
    usleep(200000);
}

// After die() and after a fatal alike: the first statement is the failing
// one the runner asks (printed, so a recorder that saw nothing cannot pass),
// and every later one was asked by the page.
register_shutdown_function(static function () use ($db): void {
    foreach ($db->asked as $i => $query) {
        echo ($i === 0 ? 'ASKED FIRST: ' : 'ASKED BY THE PAGE: ') . $query . "\n";
    }
    foreach ($db->ended as $call) {
        echo 'ENDED BY THE PAGE: ' . $call . "\n";
    }
});

$sql = 'SELECT nothing FROM p202_no_such_table_for_record_mysql_error';
echo "BEFORE\n";
if ($shape === 'db') {
    $db->query($sql) or record_mysql_error($db);
} elseif ($shape === 'db_sql') {
    $db->query($sql) or record_mysql_error($db, $sql);
} else {
    $db->query($sql) or record_mysql_error($sql);
}
echo "UNREACHED\n";
