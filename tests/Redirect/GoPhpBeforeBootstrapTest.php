<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * go.php reads the outbound cookie before any bootstrap loads the Composer
 * autoloader, so every class it reaches there is loaded by hand. It used to
 * set a 202v link's click cookies there too: CookieDomain then gained a
 * dependency (RequestHost), go.php still loaded CookieDomain alone, and every
 * go.php?202v= request answered 500 — nothing that ran with the autoloader
 * in place could see it. So this runs go.php the way a request does: in a
 * PHP process of its own, no autoloader. The 202v cookies now load the
 * bootstrap first, to put the click's owner's privacy setting in force; that
 * run uses a stand-in for it.
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

    /**
     * The stand-in connect2.php the 202v test runs go.php against: it records
     * what go.php asks of it, in order. Click 123 is account 2's, on campaign
     * 9; no other click exists.
     */
    private const STAND_IN_BOOTSTRAP = <<<'PHP'
        <?php
        $GLOBALS['p202GoLog'][] = ['bootstrap'];
        function p202ClickOwner(int $clickId): ?array
        {
            $GLOBALS['p202GoLog'][] = ['p202ClickOwner', $clickId];

            return $clickId === 123 ? ['user_id' => 2, 'aff_campaign_id' => 9] : null;
        }
        function p202ApplyOwnerPrivacy($ownerId): void
        {
            $GLOBALS['p202GoLog'][] = ['p202ApplyOwnerPrivacy', $ownerId];
        }
        function setClickIdCookie($clickId, $campaignId = 0): void
        {
            $GLOBALS['p202GoLog'][] = ['setClickIdCookie', $clickId, $campaignId];
        }
        function setPCIdCookie($clickIdPublic): void
        {
            $GLOBALS['p202GoLog'][] = ['setPCIdCookie', $clickIdPublic];
        }
        PHP;

    /** @return iterable<string, array{string, list<list<mixed>>}> 202v, what go.php asks */
    public static function links(): iterable
    {
        yield 'a stored click' => ['123 456 2', [
            ['bootstrap'],
            ['p202ClickOwner', 123],
            ['p202ApplyOwnerPrivacy', 2],
            ['setClickIdCookie', '123', '2'],
            ['setPCIdCookie', '456'],
        ]];
        yield 'no campaign named: the click\'s own' => ['123 456', [
            ['bootstrap'],
            ['p202ClickOwner', 123],
            ['p202ApplyOwnerPrivacy', 2],
            ['setClickIdCookie', '123', '9'],
            ['setPCIdCookie', '456'],
        ]];
        yield 'a click that does not exist: no cookie' => ['999 456 2', [['bootstrap'], ['p202ClickOwner', 999]]];
        yield 'not a click id: no cookie' => ['12x 456 2', [['bootstrap'], ['p202ClickOwner', 0]]];
        yield 'a public id that is not one: the click cookie alone' => ['123 4;5 2', [
            ['bootstrap'],
            ['p202ClickOwner', 123],
            ['p202ApplyOwnerPrivacy', 2],
            ['setClickIdCookie', '123', '2'],
        ]];
    }

    /**
     * A 202v link's click cookies are the click path's tracking cookies, so
     * go.php sets them as every endpoint does: it loads the bootstrap for
     * them, asks whose click the link names, puts that account's privacy
     * setting in force and sets them through connect2.php's setters (which set
     * nothing for a visitor the setting holds back) — and none for a click
     * that does not exist. It set them itself, before any bootstrap, under
     * every setting and for any id a link named. Run against a stand-in
     * connect2.php beside a copy of go.php, which records what go.php asks.
     *
     * @dataProvider links
     * @param list<list<mixed>> $expected
     */
    public function testA202vLinksCookiesAreSetUnderItsOwnersPrivacySetting(string $link, array $expected): void
    {
        $repo = dirname(__DIR__, 2);
        $tree = sys_get_temp_dir() . '/p202-go-' . bin2hex(random_bytes(4));
        $files = [
            'tracking202/redirect/go.php' => (string) file_get_contents($repo . '/tracking202/redirect/go.php'),
            'tracking202/Redirect/RedirectHelper.php' => (string) file_get_contents(
                $repo . '/tracking202/Redirect/RedirectHelper.php'
            ),
            '202-config/Http/ClickCookie.php' => (string) file_get_contents($repo . '/202-config/Http/ClickCookie.php'),
            '202-config/connect2.php' => self::STAND_IN_BOOTSTRAP,
        ];
        foreach ($files as $path => $contents) {
            @mkdir(dirname($tree . '/' . $path), 0777, true);
            file_put_contents($tree . '/' . $path, $contents);
        }
        try {
            $script = '$_GET = ' . var_export(['202v' => base64_encode($link)], true) . ';'
                . ' register_shutdown_function(static function (): void {'
                . '   echo "\n", json_encode($GLOBALS["p202GoLog"] ?? []);'
                . ' });'
                . ' require ' . var_export($tree . '/tracking202/redirect/go.php', true) . ';';
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
        } finally {
            foreach (array_keys($files) as $path) {
                @unlink($tree . '/' . $path);
            }
            $dirs = ['tracking202/redirect', 'tracking202/Redirect', 'tracking202', '202-config/Http', '202-config'];
            foreach ($dirs as $dir) {
                @rmdir($tree . '/' . $dir);
            }
            @rmdir($tree);
        }
        self::assertSame('', $stderr);
        $lines = explode("\n", trim($stdout));
        // No lpip, acip or rpi: the script's own answer, after the cookies.
        self::assertStringStartsWith('Missing LPIP, ACIP or RPI variable!', $lines[0]);
        self::assertSame($expected, json_decode((string) end($lines), true));
    }

    /** go.php sets no cookie of its own: every one goes through connect2.php's setters. */
    public function testGoSetsNoCookieItself(): void
    {
        $calls = [];
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/redirect/go.php');
        foreach (token_get_all($source) as $t) {
            $named = is_array($t) && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true);
            if ($named && in_array(strtolower(ltrim($t[1], '\\')), ['setcookie', 'setrawcookie', 'header'], true)) {
                $calls[] = $t[1] . ' on line ' . $t[2];
            }
        }
        self::assertSame([], $calls);
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
