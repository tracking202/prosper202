<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * go.php sets the click cookies a 202v link carries before any bootstrap
 * loads the Composer autoloader, so every class it reaches there is loaded
 * by hand. CookieDomain then gained a dependency (RequestHost, which reads
 * the Host header for it and for this install's own URLs), go.php still
 * loaded CookieDomain alone, and every go.php?202v= request answered 500 —
 * nothing that ran with the autoloader in place could see it. So this runs
 * go.php the way a request does: in a PHP process of its own, no autoloader.
 */
final class GoPhpBeforeBootstrapTest extends TestCase
{
    /**
     * @param array<string, string> $get
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function runGo(array $get): array
    {
        $go = dirname(__DIR__, 2) . '/tracking202/redirect/go.php';
        $script = '$_GET = ' . var_export($get, true) . ';'
            . ' $_SERVER["HTTP_HOST"] = "track.example.com:8443";'
            . ' require ' . var_export($go, true) . ';';
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

        return [proc_close($process), $stdout, $stderr];
    }

    public function testThe202vCookiesAreSetWithoutTheAutoloader(): void
    {
        [$exit, $stdout, $stderr] = self::runGo(['202v' => base64_encode('123 456 2')]);
        self::assertSame('', $stderr, 'go.php reached a class it did not load');
        self::assertSame(0, $exit);
        // No lpip, acip or rpi: the script's own answer, after the cookies.
        self::assertSame('Missing LPIP, ACIP or RPI variable!', trim($stdout));
    }
}
