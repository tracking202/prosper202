<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * go.php sets the click cookies a 202v link carries, and reads the outbound
 * cookie, before any bootstrap loads the Composer autoloader, so every class
 * it reaches there is loaded by hand. CookieDomain then gained a dependency
 * (RequestHost, which reads the Host header for it and for this install's
 * own URLs), go.php still loaded CookieDomain alone, and every
 * go.php?202v= request answered 500 — nothing that ran with the autoloader
 * in place could see it. So this runs go.php the way a request does: in a
 * PHP process of its own, no autoloader.
 */
final class GoPhpBeforeBootstrapTest extends TestCase
{
    /**
     * @param array<string, string> $get
     * @param array<string, string> $cookies
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function runGo(array $get, array $cookies = []): array
    {
        $go = dirname(__DIR__, 2) . '/tracking202/redirect/go.php';
        // At exit: where the visitor was sent from the outbound cookie, and
        // whether lp.php (which records a click) was reached instead.
        $script = '$_GET = ' . var_export($get, true) . ';'
            . ' $_COOKIE = ' . var_export($cookies, true) . ';'
            . ' $_SERVER["HTTP_HOST"] = "track.example.com:8443";'
            . ' register_shutdown_function(static function (): void {'
            . '   echo "\n", json_encode(["outbound" => $GLOBALS["tracking202outbound"] ?? null,'
            . '     "lp" => in_array(' . var_export(dirname($go) . '/lp.php', true) . ', get_included_files(), true)]);'
            . ' });'
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
        self::assertStringStartsWith('Missing LPIP, ACIP or RPI variable!', trim($stdout));
    }

    /**
     * On plain HTTP only the outbound cookie's -legacy twin exists
     * (ClickCookie); go.php sends the visitor where it says, not on to lp.php.
     */
    public function testTheOutboundCookiesTwinIsFollowedWithoutTheAutoloader(): void
    {
        $outbound = 'http://track.example.com:8443/tracking202/redirect/pci.php?pci=51234';
        [$exit, $stdout, $stderr] = self::runGo(['lpip' => '999999999'], ['tracking202outbound-legacy' => $outbound]);
        self::assertSame('', $stderr, 'go.php reached a class it did not load');
        self::assertSame(0, $exit);
        self::assertSame(['outbound' => $outbound, 'lp' => false], json_decode(trim($stdout), true));
    }
}
