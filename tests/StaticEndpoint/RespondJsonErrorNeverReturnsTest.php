<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * p202RespondJsonError() ends the request, and the language says so.
 *
 * The pixel and postback endpoints call it from a catch block and go on to
 * the legacy conversion write below it (gpb.php, pb.php, upx.php): what
 * keeps a failed web event from recording a legacy conversion with an
 * undefined $webEvent is that the helper never returns. It used to say so
 * only by calling die() in its body, declared `: void`, so a refactor that
 * returned instead would have changed 28 call sites without touching one.
 * Declared `: never`, a helper that returns is a fatal error (executed: a
 * `return` in it does not compile, falling off its end is a TypeError), and
 * PHPStan reads every call site as terminal.
 */
final class RespondJsonErrorNeverReturnsTest extends TestCase
{
    public function testTheHelperIsDeclaredNeverAndDefinedOnce(): void
    {
        $root = dirname(__DIR__, 2);
        $definitions = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = substr((string) $file->getPathname(), strlen($root) + 1);
            if (!str_ends_with($path, '.php') || preg_match('#^(vendor|\.git|\.claude|node_modules)/#', $path) === 1) {
                continue;
            }
            $src = (string) file_get_contents((string) $file->getPathname());
            if (preg_match_all('/function\s+p202RespondJsonError\s*\(([^)]*)\)\s*(:\s*\??\w+)?/i', $src, $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $def) {
                    $definitions[] = [$path, trim(ltrim($def[2] ?? '', ':'))];
                }
            }
        }
        self::assertSame(
            [['202-config/static-endpoint-helpers.php', 'never']],
            $definitions,
            'one definition, declared never: it sits behind function_exists(), so a second one (or a stub) would win silently'
        );

        require_once $root . '/202-config/static-endpoint-helpers.php';
        $type = (new \ReflectionFunction('p202RespondJsonError'))->getReturnType();
        self::assertNotNull($type);
        self::assertSame('never', (string) $type);
    }
}
