<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * A throw nothing on the click path caught. The lookups used to run their
 * SQL through _mysqli_query(), whose failure ended at record_mysql_error():
 * the error page, and the failure recorded. The repositories that replaced
 * them throw a QueryException instead, which nothing on dl.php, rtr.php or
 * the landing-page recorders catches, so it was a PHP fatal there. connect2.php
 * registers p202ClickPathUncaught() for every endpoint that loads it: a
 * QueryException goes to record_mysql_error() with its message, anything
 * else to the error log, and the request answers 500 either way, with
 * nothing about the failure shown.
 *
 * The handler's shipped source runs in a child process
 * (fixtures/click-path-uncaught-runner.php), as an uncaught throw ends it.
 */
final class ClickPathUncaughtTest extends TestCase
{
    private const CONNECT2 = __DIR__ . '/../../202-config/connect2.php';

    public function testADatabaseFailureEndsAtTheErrorPageWithItsMessage(): void
    {
        [$stdout, $stderr, $logged] = self::runHandler('query');

        self::assertMatchesRegularExpression(
            '/^RECORDED: \\{"the click path connection":true,'
                . '"sql":"uncaught at click-path-uncaught-runner\\.php:\\d+","error":"MySQL execute failed:'
                . ' Data too long for column \'site_url_address\' at row 1 \\[errno 1406\\]"\\}$/m',
            $stdout,
            'recorded with its message and where it was thrown: ' . $stdout . $stderr . $logged
        );
        self::assertStringContainsString("STATUS: 500\n", $stdout, 'the request answers 500');
        self::assertSame('', $stderr);
    }

    /**
     * Every database failure is thrown from the same few lines of
     * Connection; the record names the line that called into the database
     * layer, which says which lookup or write failed.
     */
    public function testTheRecordNamesTheLineThatReachedTheDatabaseLayer(): void
    {
        [$stdout, $stderr, $logged] = self::runHandler('query_in_repository');

        self::assertMatchesRegularExpression('/^CALLED AT: (\d+)$/m', $stdout, $stdout . $logged);
        preg_match('/^CALLED AT: (\d+)$/m', $stdout, $called);
        self::assertStringContainsString(
            '"sql":"uncaught at click-path-uncaught-runner.php:' . $called[1]
                . '","error":"Failed to prepare MySQL statement',
            $stdout,
            $stdout . $stderr . $logged
        );
        self::assertStringContainsString("STATUS: 500\n", $stdout);
        self::assertSame('', $stderr);
    }

    /**
     * dl.php sets Location and then records the click; where the server has
     * no fastcgi_finish_request() nothing is sent yet. A failure there loses
     * the click, and the visitor still goes to the offer.
     */
    public function testAQueuedRedirectStandsWhenTheClickFailsAfterIt(): void
    {
        [$stdout, $stderr, $logged] = self::runHandler('query_after_redirect');
        self::assertStringContainsString('RECORDED: ', $stdout, 'the database failure is still recorded');
        self::assertStringContainsString("STATUS: 302\n", $stdout, 'the redirect stands: ' . $stdout . $logged);
        self::assertSame('', $stderr);

        [$stdout, $stderr, $logged] = self::runHandler('other_after_redirect');
        self::assertSame("STATUS: 302\n", $stdout, 'the redirect stands: ' . $logged);
        self::assertStringContainsString('Uncaught on the click path: RuntimeException: ', $logged);
        self::assertSame('', $stderr);
    }

    public function testAnyOtherThrowIsLoggedAndAnswered500WithNothingShown(): void
    {
        [$stdout, $stderr, $logged] = self::runHandler('other');

        self::assertStringNotContainsString('RECORDED', $stdout, 'not a database failure');
        self::assertStringNotContainsString('a detail the visitor is not shown', $stdout . $stderr);
        self::assertStringContainsString(
            'Uncaught on the click path: RuntimeException: a detail the visitor is not shown',
            $logged
        );
        self::assertSame("STATUS: 500\n", $stdout);
        self::assertSame('', $stderr);
    }

    /**
     * The log line holds no value a database failure's message or a frame's
     * arguments carried: the server's words repeat what a statement quoted,
     * and a statement handed down the stack is an argument. It logged the
     * throwable as PHP prints it, with both.
     */
    public function testTheLogLineHoldsNoQuotedValueAndNoArgument(): void
    {
        [$stdout, $stderr, $logged] = self::runHandler('secret');

        self::assertSame("STATUS: 500\n", $stdout);
        self::assertSame('', $stderr);
        self::assertStringContainsString('Uncaught on the click path: RuntimeException: the lookup failed', $logged);
        self::assertStringContainsString(
            'Prosper202\\Database\\Exceptions\\QueryException: '
                . "MySQL execute failed: Duplicate entry ? for key 'uk' [errno 1062]",
            $logged
        );
        self::assertStringContainsString('mysqli_sql_exception: Incorrect integer value: ? at row 1', $logged);
        self::assertStringContainsString('{closure}()', $logged, 'the frames are listed');
        self::assertStringNotContainsString('s3cr3t', $logged);
    }

    /** The signed-in pages' handler (connect.php) writes the same line. */
    public function testBothBootstrapsHandlersLogThroughTheRedactedLine(): void
    {
        require_once __DIR__ . '/fixtures/lift-function.php';
        $handlers = [
            self::CONNECT2 => 'p202ClickPathUncaught',
            __DIR__ . '/../../202-config/connect.php' => 'p202PageUncaught',
        ];
        foreach ($handlers as $file => $handler) {
            $body = lift_function($file, $handler);
            self::assertSame(1, substr_count($body, 'error_log('), "$handler logs once");
            self::assertSame(1, preg_match('/error_log\(p202UncaughtLogLine\(\'[^\']+\', \$e\)\);/', $body), $body);
        }
    }

    public function testAThrowAfterTheHeadersWereSentIsStillLogged(): void
    {
        [$stdout, $stderr, $logged] = self::runHandler('other_after_output');

        self::assertSame("PAGE STARTED\nSTATUS: false\n", $stdout, 'the status already sent stands');
        self::assertSame('', $stderr, 'no warning about headers already sent');
        self::assertStringContainsString('Uncaught on the click path: RuntimeException: ', $logged);
    }

    /**
     * Registered once, outside any function, and before the bootstrap first
     * reaches the database, so it holds from the moment connect2.php is
     * loaded, whichever endpoint loaded it.
     */
    public function testConnect2RegistersTheHandlerAtItsTopLevel(): void
    {
        $tokens = \PhpToken::tokenize((string) file_get_contents(self::CONNECT2));
        $code = array_values(array_filter(
            $tokens,
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        $registrations = [];
        $registeredAt = null;
        $firstDatabase = null;
        $depth = 0;
        foreach ($code as $i => $t) {
            if ($firstDatabase === null && $t->is(T_STRING) && $t->text === 'getInstance') {
                $firstDatabase = $i;
            }
            if ($t->text === '{' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($t->text === '}') {
                $depth--;
            } elseif ($t->is(T_STRING) && strcasecmp($t->text, 'set_exception_handler') === 0) {
                $registeredAt ??= $i;
                $registrations[] = [
                    $depth,
                    implode('', array_map(static fn (\PhpToken $x): string => $x->text, array_slice($code, $i, 5))),
                ];
            }
        }

        self::assertSame([[0, "set_exception_handler('p202ClickPathUncaught');"]], $registrations);
        self::assertNotNull($firstDatabase, 'connect2.php reaches DB::getInstance()');
        self::assertLessThan($firstDatabase, $registeredAt, 'registered before the first database call');
    }

    /** @return array{string, string, string} stdout, stderr, the error log */
    private static function runHandler(string $scenario): array
    {
        $log = tempnam(sys_get_temp_dir(), 'p202-uncaught-');
        self::assertIsString($log);
        try {
            $proc = proc_open(
                [
                    PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=1', '-d', 'error_log=' . $log,
                    // As a development php.ini has them: a frame's arguments kept, whole.
                    '-d', 'zend.exception_ignore_args=0', '-d', 'zend.exception_string_param_max_len=1000000',
                    __DIR__ . '/fixtures/click-path-uncaught-runner.php', $scenario,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($proc);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            $logged = (string) file_get_contents($log);
        } finally {
            @unlink($log);
        }
        self::assertSame(0, $exit, "exit $exit\n$stdout$stderr$logged");

        return [$stdout, $stderr, $logged];
    }
}
