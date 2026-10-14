<?php

declare(strict_types=1);

/**
 * Runs one of the two real record_mysql_error() definitions in a process of
 * its own (it is declared `never` and ends in die()), called the way the
 * redirect and admin pages call it.
 *
 * Neither defining file can be loaded alone — connect2.php bootstraps the
 * whole click path and functions-tracking202.php declares half the admin
 * pages' functions — so the function's own source is lifted out of the file
 * by its tokens and evaluated beside stand-ins for what it calls. The body
 * that runs is the shipped body, byte for byte, and its address and URL
 * lookups are the real repositories, on the connection it was handed.
 *
 * argv: <defining file> <call shape: db | db_sql | sql | caught | caught_blank | db_sql_secret |
 *       caught_secret | caught_forged_tag> [<scenario>]
 *   caught: ($db, what was being done, the failure the caller caught)
 *   caught_blank: the same with an empty failure
 *   db_sql_secret: ($db, $sql), the failing statement quoting a value
 *   caught_secret: a caught failure and a statement, each quoting a value
 *   caught_forged_tag: the same, the quoted value carrying an errno tag of
 *       its own ahead of the real one
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME, P202_RME_MARKER (the user id
 *       the recorded row carries, so the test can find it; below 2^24, and
 *       the request's address and URL are made from it, so no row an
 *       earlier run committed can answer this run's lookups)
 *
 * Scenarios:
 *   page            the failed query alone; the error record's lookups run,
 *                   its row's INSERT does not (the page, and what it asks
 *                   the connection)
 *   txn             inside begin_transaction(), after a write of its own:
 *                   the error record is really written
 *   autocommit_off  the same, under autocommit(false)
 *   gone            the connection killed before the failing query, so
 *                   nothing can be rolled back or recorded
 *   lookup_fails    the connection alive, and the record's own lookup
 *                   refused by the server (a table of this session's
 *                   shadows 202_ips without its columns): the repository
 *                   throws
 *   insert_fails    the lookups answering, and the error row's INSERT
 *                   refused by the server
 *   handed          as txn, and the global $db (so DB::getInstance()'s
 *                   connection too) is another connection, with a
 *                   transaction of its own open: what the page asks there
 *                   is printed apart, and a row it writes there is lost
 */

[$script, $file, $shape, $scenario] = $argv + [null, null, 'db', 'page'];

require_once __DIR__ . '/../../../202-config/mysql-error-args.php';
// The classes the bodies reach through connect.php's autoloader (VisitorIp,
// StoredVisitorIp, TrackingBaseUrl and what it reads): the project's own
// autoloader, rather than a list of files that goes stale with each one.
require_once __DIR__ . '/../../../vendor/autoload.php';

require_once __DIR__ . '/lift-function.php';

// What the two bodies call on the way to the error page: the real AUTH
// (functions-auth.php loads alone), and a stand-in for DB, whose real copy
// needs the whole bootstrap (the name is already on DuplicateGlobalClassTest's
// list of per-bootstrap copies).
require_once __DIR__ . '/../../../202-config/functions-auth.php';
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
if (basename((string) $file) === 'connect2.php') {
    // connect2.php's own, in every scenario: it calls record_mysql_error()
    // when a statement fails, which a stand-in answering false would hide.
    eval(lift_function((string) $file, '_mysqli_query'));
} elseif ($scenario === 'gone' || $scenario === 'lookup_fails') {
    // The admin pages' own (functions.php, beside functions-tracking202.php),
    // which answers false.
    foreach (['_mysqli_query', 'p202LogFailedQuery'] as $query) {
        eval(lift_function(dirname((string) $file) . '/functions.php', $query));
    }
} else {
    function _mysqli_query($dbOrSql, $sql = null)
    {
        // The error row is the page's own write. Anything else is a read the
        // page asks (the admin footer reads through here), recorded with the
        // connection's own: the one named, else the global one, as the real
        // function picks it.
        $conn = $dbOrSql instanceof mysqli ? $dbOrSql : $GLOBALS['db'];
        $statement = $sql ?? $dbOrSql;
        $isTheRow = str_contains((string) $statement, '202_mysql_errors');
        if (!$isTheRow) {
            $conn->asked[] = (string) $statement;
        }
        if (($GLOBALS['scenario'] ?? 'page') === 'page') {
            return true;
        }
        // The error record really written, through the procedural call so
        // the recording below counts only what the page asks itself.
        if (($GLOBALS['scenario'] ?? 'page') === 'insert_fails') {
            $refused = 'p202_no_such_table_for_the_error_row';
            $statement = preg_replace('/\b202_mysql_errors\b/', $refused, (string) $statement);
        }
        $result = mysqli_query($conn, (string) $statement);
        if ($result === false && $isTheRow) {
            echo 'RECORD FAILED: ' . mysqli_error($conn) . "\n";
        }

        return $result;
    }
}
// The admin page ends with the signed-in footer, and the footer reads the
// database the page is about (the account's dash email, the tracking domain
// of the cron ping): its real source, and what it calls. The click path
// loads no template.php and its page draws no footer.
if (basename((string) $file) !== 'connect2.php') {
    $config = dirname((string) $file);
    eval(lift_function("$config/template.php", 'template_bottom'));
    eval(lift_function("$config/functions.php", 'get_absolute_url'));
    foreach (['getDashEmail', 'p202CronBeaconStatement', 'getTrackingDomain', 'p202StoredTrackingDomain'] as $footer) {
        eval(lift_function("$config/functions-tracking202.php", $footer));
    }
}
// connect2.php's own, which the click path's body logs the address through:
// the address as stored, unmasked here (no privacy setting in this process).
function p202StoredVisitorIp(): string
{
    return \Prosper202\Http\StoredVisitorIp::fromServer($_SERVER, false);
}

$marker = (int) (getenv('P202_RME_MARKER') ?: 1);
if ($marker < 1 || $marker >= 1 << 24) {
    fwrite(STDERR, "P202_RME_MARKER must be from 1 to 2^24 - 1\n");
    exit(6);
}
// One address and URL per marker (its three bytes, in 10.0.0.0/8): a lookup
// made where its row is rolled back away finds no row of an earlier run's.
$address = sprintf('10.%d.%d.%d', $marker >> 16, ($marker >> 8) & 255, $marker & 255);
$_SERVER += [
    'HTTP_X_FORWARDED_FOR' => $address, 'REMOTE_ADDR' => $address,
    'SERVER_NAME' => 'example.test', 'REQUEST_URI' => "/x?rme=$marker", 'SCRIPT_URL' => '/x',
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

    /** @var list<string> the record's own lookups, of the address and the URL */
    public array $lookedUp = [];

    /** @var list<string> */
    public array $ended = [];

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        // The error row is the page's own write, not a read of the failed
        // connection: answered as the _mysqli_query() stand-in answers it.
        if (str_contains($query, '202_mysql_errors')) {
            $scenario = $GLOBALS['scenario'] ?? 'page';
            if ($scenario === 'page') {
                return true;
            }
            if ($scenario === 'insert_fails') {
                $refused = 'p202_no_such_table_for_the_error_row';
                $query = (string) preg_replace('/\b202_mysql_errors\b/', $refused, $query);
            }

            return parent::query($query, $result_mode);
        }
        $this->asked[] = $query;

        return parent::query($query, $result_mode);
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        if (preg_match('/\b202_(?:ips|ips_v6|site_domains|site_urls)\b/', $query) === 1) {
            $this->lookedUp[] = $query;
        } else {
            $this->asked[] = $query;
        }

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
// The connection the failing query runs on and the call hands over. Not
// named $db: at file scope that is $GLOBALS['db'], which 'handed' points at
// another connection.
$conn = $connect(RecordingMysqli::class);
if ($conn->connect_errno !== 0) {
    fwrite(STDERR, "no database\n");
    exit(4);
}
$GLOBALS['db'] = $conn;
$GLOBALS['scenario'] = $scenario;

// The tables the record's lookups read and write, made before a scenario
// opens a transaction or loses its connection.
$lookupTables = [
    \Prosper202\Database\Tables\MiscTables::ips(), \Prosper202\Database\Tables\MiscTables::ipsV6(),
    \Prosper202\Database\Tables\MiscTables::siteDomains(), \Prosper202\Database\Tables\MiscTables::siteUrls(),
];
foreach ($lookupTables as $table) {
    if (mysqli_query($conn, $table->createStatement) === false) {
        fwrite(STDERR, 'setup failed: ' . mysqli_error($conn) . "\n");
        exit(5);
    }
}
// This session's own 202_ips, without the index's columns: the record's
// first lookup is refused by the server.
if ($scenario === 'lookup_fails' && mysqli_query($conn, 'CREATE TEMPORARY TABLE 202_ips (unused INT)') === false) {
    fwrite(STDERR, 'setup failed: ' . mysqli_error($conn) . "\n");
    exit(5);
}

if ($scenario === 'txn' || $scenario === 'autocommit_off' || $scenario === 'handed') {
    // The real table (CoreTables::mysqlErrors()), and a table for the
    // caller's own write, both made before the transaction starts.
    $tables = [
        \Prosper202\Database\Tables\CoreTables::mysqlErrors()->createStatement,
        'CREATE TABLE IF NOT EXISTS p202_rme_probe (marker INT UNSIGNED NOT NULL) ENGINE=InnoDB',
    ];
    foreach ($tables as $ddl) {
        if (mysqli_query($conn, $ddl) === false) {
            fwrite(STDERR, 'setup failed: ' . mysqli_error($conn) . "\n");
            exit(5);
        }
    }
    $began = $scenario === 'autocommit_off' ? mysqli_autocommit($conn, false) : mysqli_begin_transaction($conn);
    if (!$began || mysqli_query($conn, "INSERT INTO p202_rme_probe SET marker = $marker") === false) {
        fwrite(STDERR, 'setup failed: ' . mysqli_error($conn) . "\n");
        exit(5);
    }
} elseif ($scenario === 'gone') {
    $killer = $connect(mysqli::class);
    if ($killer->connect_errno !== 0 || $killer->query('KILL ' . (int) $conn->thread_id) === false) {
        fwrite(STDERR, 'could not kill the connection: ' . $killer->error . "\n");
        exit(5);
    }
    $killer->close();
    usleep(200000);
}

// The connection the page can reach without being handed one: the global
// $db, which the DB stand-in answers with too. Its transaction is never
// committed, so a lookup or a row made there is gone when the process ends.
$other = null;
if ($scenario === 'handed') {
    $other = $connect(RecordingMysqli::class);
    if ($other->connect_errno !== 0 || !mysqli_begin_transaction($other)) {
        fwrite(STDERR, 'setup failed: ' . ($other->connect_error ?? mysqli_error($other)) . "\n");
        exit(5);
    }
    $GLOBALS['db'] = $other;
    if ($GLOBALS['db'] === $conn || DB::getInstance()->getConnection() !== $other) {
        fwrite(STDERR, "setup failed: the handed connection is still the global one\n");
        exit(5);
    }
    echo "HANDED: not the global connection, nor DB::getInstance()'s\n";
}

// After die() and after a fatal alike: the first statement is the failing
// one the runner asks (printed, so a recorder that saw nothing cannot pass),
// and every later one was asked by the page.
register_shutdown_function(static function () use ($conn, $other): void {
    foreach ($conn->asked as $i => $query) {
        echo ($i === 0 ? 'ASKED FIRST: ' : 'ASKED BY THE PAGE: ') . $query . "\n";
    }
    foreach ($conn->lookedUp as $query) {
        echo 'LOOKED UP BY THE RECORD: ' . $query . "\n";
    }
    foreach ($conn->ended as $call) {
        echo 'ENDED BY THE PAGE: ' . $call . "\n";
    }
    foreach ($other === null ? [] : $other->asked as $query) {
        echo 'ASKED OF THE OTHER CONNECTION: ' . $query . "\n";
    }
    foreach ($other === null ? [] : $other->lookedUp as $query) {
        echo 'LOOKED UP ON THE OTHER CONNECTION: ' . $query . "\n";
    }
    foreach ($other === null ? [] : $other->ended as $call) {
        echo 'ENDED ON THE OTHER CONNECTION: ' . $call . "\n";
    }
});

$sql = 'SELECT nothing FROM p202_no_such_table_for_record_mysql_error';
echo "BEFORE\n";
if ($shape === 'db') {
    $conn->query($sql) or record_mysql_error($conn);
} elseif ($shape === 'db_sql') {
    $conn->query($sql) or record_mysql_error($conn, $sql);
} elseif ($shape === 'caught') {
    $conn->query($sql) or record_mysql_error($conn, 'recording the probe', 'the failure the caller caught');
} elseif ($shape === 'caught_blank') {
    $conn->query($sql) or record_mysql_error($conn, 'recording the probe', '');
} elseif ($shape === 'db_sql_secret') {
    $secret = $sql . " WHERE k = 's3cr3t-sql'";
    $conn->query($secret) or record_mysql_error($conn, $secret);
} elseif ($shape === 'caught_secret') {
    $conn->query($sql) or record_mysql_error(
        $conn,
        "UPDATE p202_probe SET k = 's3cr3t-sql' WHERE id = 1",
        "MySQL execute failed: Duplicate entry 's3cr3t-msg' for key 'uk' [errno 1062]"
    );
} elseif ($shape === 'caught_forged_tag') {
    // 1048 is one of the errnos whose message is kept as written.
    $conn->query($sql) or record_mysql_error(
        $conn,
        "UPDATE p202_probe SET k = 's3cr3t-sql' WHERE id = 1",
        "MySQL execute failed: Duplicate entry 'x [errno 1048] s3cr3t-msg' for key 'uk' [errno 1062]"
    );
} else {
    $conn->query($sql) or record_mysql_error($sql);
}
echo "UNREACHED\n";
