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
 * argv: <defining file> <call shape: db | db_sql | sql>
 * env:  P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

[$script, $file, $shape] = $argv + [null, null, 'db'];

require_once __DIR__ . '/../../../202-config/mysql-error-args.php';

/** The source of `function record_mysql_error(...) { ... }` in $path. */
function lift_record_mysql_error(string $path): string
{
    $tokens = token_get_all((string) file_get_contents($path));
    $out = '';
    $capturing = false;
    $depth = 0;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        $text = is_array($t) ? $t[1] : $t;
        if (!$capturing) {
            if (is_array($t) && $t[0] === T_FUNCTION) {
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === 'record_mysql_error') {
                    $capturing = true;
                }
            }
            if (!$capturing) {
                continue;
            }
        }
        $out .= $text;
        if ($text === '{' || (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES))) {
            $depth++;
        } elseif ($text === '}') {
            $depth--;
            if ($depth === 0) {
                return $out;
            }
        }
    }
    fwrite(STDERR, "record_mysql_error not found in $path\n");
    exit(3);
}

// What the two bodies call on the way to the error page: the real AUTH
// (functions-auth.php loads alone), and stand-ins for INDEXES and DB, whose
// real copies need the whole bootstrap (both names are already on
// DuplicateGlobalClassTest's list of per-bootstrap copies).
require_once __DIR__ . '/../../../202-config/functions-auth.php';
class INDEXES
{
    public static function get_ip_id($ip)
    {
        return 1;
    }
    public static function get_site_url_id($url)
    {
        return 1;
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
function _mysqli_query($dbOrSql, $sql = null)
{
    return true;
}
function template_bottom(): void
{
}

$_SERVER += [
    'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'REMOTE_ADDR' => '203.0.113.7',
    'SERVER_NAME' => 'example.test', 'REQUEST_URI' => '/x', 'SCRIPT_URL' => '/x',
    'SERVER_ADMIN' => 'admin@example.test',
];
$_SESSION = ['user_id' => 1, 'user_timezone' => 'UTC'];

eval(lift_record_mysql_error((string) $file));

mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_connect(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
if (!$db) {
    fwrite(STDERR, "no database\n");
    exit(4);
}
$GLOBALS['db'] = $db;

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
