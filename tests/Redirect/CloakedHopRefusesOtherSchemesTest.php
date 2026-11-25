<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * cl2.php needs no sign-in and sends q on to the visitor as a script's
 * location, a meta refresh and a link, so cl2.php?q=javascript:... opened by
 * a signed-in owner ran script in the admin's origin. This runs the shipped
 * cl2.php in a PHP process of its own, with a stand-in for its bootstrap
 * (the page reads no database), and reads what it answers.
 */
final class CloakedHopRefusesOtherSchemesTest extends TestCase
{
    /** @return array{string, string} stdout, stderr */
    private static function runCl2(string $q): array
    {
        $page = dirname(__DIR__, 2) . '/tracking202/redirect/cl2.php';
        $source = (string) file_get_contents($page);
        $require = "require_once substr(__DIR__, 0, -21) . '/202-config/connect2.php';";
        self::assertStringContainsString(
            $require,
            $source,
            'cl2.php no longer loads its bootstrap the way this test replaces'
        );

        $dir = sys_get_temp_dir() . '/p202-cl2-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $root = dirname(__DIR__, 2);
        $standIn = 'require ' . var_export($root . '/vendor/autoload.php', true) . ";\n"
            . 'require_once ' . var_export($root . '/tracking202/Redirect/RedirectHelper.php', true) . ";\n"
            . "function p202InstallPath(string \$p): string { return '/' . \$p; }\n";
        file_put_contents($dir . '/cl2.php', str_replace($require, $standIn, $source));

        $script = '$_GET = ' . var_export(['q' => $q], true) . ';'
            . ' register_shutdown_function(static function (): void { echo "\n[exit]"; });'
            . ' require ' . var_export($dir . '/cl2.php', true) . ';';
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($dir . '/cl2.php');
        rmdir($dir);

        return [$stdout, $stderr];
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'javascript' => ['javascript:alert(document.domain)'];
        yield 'javascript, upper case' => ['JAVASCRIPT:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'tab inside the scheme' => ["java\tscript:alert(1)"];
        yield 'scheme-relative' => ['//evil.example/x'];
    }

    /** @dataProvider refused */
    public function testAnythingButAnHttpUrlGoesToThe404Page(string $q): void
    {
        [$out, $err] = self::runCl2($q);
        self::assertSame('', $err);
        self::assertSame("\n[exit]", $out, 'cl2.php rendered a page for ' . json_encode($q));
    }

    public function testAnHttpsOfferUrlIsSentOn(): void
    {
        [$out, $err] = self::runCl2('https://offer.example/x?a=1&b=2');
        self::assertSame('', $err);
        // json_encode writes & as \u0026 (JSON_HEX_AMP).
        $amp = chr(92) . 'u0026';
        self::assertStringContainsString('window.location="https:\/\/offer.example\/x?a=1' . $amp . 'b=2"', $out);
        self::assertStringContainsString('content="0; url=https://offer.example/x?a=1&amp;b=2"', $out);
    }
}
