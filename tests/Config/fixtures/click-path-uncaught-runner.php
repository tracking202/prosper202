<?php

/**
 * Throws past everything, with connect2.php's own p202ClickPathUncaught() as
 * the exception handler, in a process of its own: the handler ends the
 * request, and record_mysql_error() (a stand-in here) ends it in exit.
 *
 * argv: <query | other | other_after_output | query_after_redirect | other_after_redirect>
 *   query                 a QueryException, as the repositories throw one
 *   other                 any other throw, its message a detail not to show
 *   other_after_output    the same once the page has sent its headers
 *   query_after_redirect  a QueryException once Location is set and nothing
 *                         sent, as dl.php records its click after redirecting
 *   other_after_redirect  any other throw there
 *
 * Prints what reached record_mysql_error(), and at shutdown the response
 * code the request ends with.
 */

declare(strict_types=1);

[, $scenario] = $argv + [null, 'query'];

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/lift-function.php';

eval(lift_function(__DIR__ . '/../../../202-config/connect2.php', 'p202ClickPathUncaught'));
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
});

set_exception_handler('p202ClickPathUncaught');

if (str_ends_with($scenario, '_after_redirect')) {
    header('Location: https://offer.example/');
}
if (str_starts_with($scenario, 'query')) {
    throw new \Prosper202\Database\Exceptions\QueryException(
        "MySQL execute failed: Data too long for column 'site_url_address' at row 1 [errno 1406]"
    );
}
if ($scenario === 'other_after_output') {
    echo "PAGE STARTED\n";
}

throw new \RuntimeException('a detail the visitor is not shown');
