<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * getDashEmail() is read by the footer of every signed-in page. A failed
 * read (the admin _mysqli_query() answers false, and logs it) called
 * fetch_assoc() on false: a fatal in place of the rest of the page.
 *
 * The shipped body runs in a process of its own, beside a _mysqli_query()
 * that answers false.
 */
final class DashEmailReadTest extends TestCase
{
    public function testAFailedReadAnswersNoAddress(): void
    {
        $code = 'require ' . var_export(__DIR__ . '/fixtures/lift-function.php', true) . ';'
            . ' function _mysqli_query($sql) { return false; }'
            . ' eval(lift_function(' . var_export(dirname(__DIR__, 2) . '/202-config/functions-tracking202.php', true)
            . ", 'getDashEmail'));"
            . " \$_SESSION = ['user_id' => 1];"
            . ' var_export(getDashEmail());';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, "the footer's read ended the page: $out $err");
        self::assertSame('false', $out, $err);
    }
}
