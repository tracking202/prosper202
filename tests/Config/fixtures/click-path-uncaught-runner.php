<?php

/**
 * Throws past everything, with connect2.php's own p202ClickPathUncaught() as
 * the exception handler, in a process of its own: the handler ends the
 * request, and record_mysql_error() (a stand-in here) ends it in exit.
 *
 * argv: <query | query_in_repository | other | other_after_output | query_after_redirect | other_after_redirect>
 *   query                 a QueryException, as the repositories throw one
 *   query_in_repository   one thrown inside a real repository call, whose
 *                         call here is printed at shutdown as CALLED AT
 *   other                 any other throw, its message a detail not to show
 *   other_after_output    the same once the page has sent its headers
 *   query_after_redirect  a QueryException once Location is set and nothing
 *                         sent, as dl.php records its click after redirecting
 *   other_after_redirect  any other throw there
 *   secret                any other throw, chained to a QueryException and a
 *                         mysqli_sql_exception that quote a value, thrown
 *                         from a call that was handed one
 *
 * Prints what reached record_mysql_error(), and at shutdown the response
 * code the request ends with.
 */

declare(strict_types=1);

[, $scenario] = $argv + [null, 'query'];

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../../202-config/mysql-error-args.php';
require __DIR__ . '/lift-function.php';

eval(lift_function(__DIR__ . '/../../../202-config/connect2.php', 'p202ClickPathUncaught'));
eval(lift_function(__DIR__ . '/../../../202-config/functions-shared.php', 'p202UncaughtAt'));
eval(lift_function(__DIR__ . '/../../../202-config/functions-shared.php', 'p202UncaughtLogLine'));
// Evaluated, as the other runners declare their stand-ins.
eval(<<<'PHP'
    function record_mysql_error($dbOrSql, $sql = null, ?string $error = null): never
    {
        echo 'RECORDED: ', json_encode([
            'the click path connection' => $dbOrSql === $GLOBALS['db'],
            'sql' => $sql,
            'error' => $error,
        ], JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }
    PHP);

// The click path's connection, as connect2.php leaves it in $db.
$db = (new ReflectionClass(mysqli::class))->newInstanceWithoutConstructor();

register_shutdown_function(static function (): void {
    echo 'STATUS: ', var_export(http_response_code(), true), "\n";
    if (isset($GLOBALS['calledAt'])) {
        echo 'CALLED AT: ', $GLOBALS['calledAt'], "\n";
    }
});

set_exception_handler('p202ClickPathUncaught');

if ($scenario === 'query_in_repository') {
    $refusing = new class () extends mysqli {
        public function __construct()
        {
        }

        public function prepare(string $query): mysqli_stmt|false
        {
            return false;
        }
    };
    $keywords = new \Prosper202\Repository\Mysql\MysqlTrackingRepository(
        new \Prosper202\Database\Connection($refusing)
    );
    $calledAt = __LINE__ + 1;
    $keywords->findOrCreateKeyword('a keyword');
}
if (str_ends_with($scenario, '_after_redirect')) {
    header('Location: https://offer.example/');
}
if (str_starts_with($scenario, 'query')) {
    throw new \Prosper202\Database\Exceptions\QueryException(
        "MySQL execute failed: Data too long for column 'site_url_address' at row 1 [errno 1406]"
    );
}
if ($scenario === 'secret') {
    $lookUp = static function (string $value): never {
        $server = new \mysqli_sql_exception("Incorrect integer value: 's3cr3t-sql' for column 'n' at row 1", 1366);
        throw new \RuntimeException('the lookup failed', 0, new \Prosper202\Database\Exceptions\QueryException(
            "MySQL execute failed: Duplicate entry 's3cr3t-msg' for key 'uk' [errno 1062]",
            0,
            $server
        ));
    };
    $lookUp('s3cr3t-arg');
}
if ($scenario === 'other_after_output') {
    echo "PAGE STARTED\n";
}

throw new \RuntimeException('a detail the visitor is not shown');
