<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;

/**
 * A cron job that cannot run says why and exits non-zero (measurement plan
 * §8.1, PR 13).
 *
 * 202-config/connect.php answered every early stop — no 202-config.php, no
 * vendor/, no database, a database that needs an upgrade — the web's way: a
 * page or a redirect, then die(). On the command line that is silence (or an
 * HTML page) and exit status 0, so a cron against a database behind the code
 * looked like a job with nothing to do. Every stop now calls
 * p202_cli_fail() first, which on the CLI writes the reason to stderr and
 * exits 1, and leaves the web path exactly as it was.
 *
 * What this holds, over every entry point in 202-cronjobs/ (CLAUDE.md #5):
 *
 * - each bootstraps with `require_once __DIR__ . '/../202-config/connect.php'`
 *   — by its own path (several used a path relative to the working
 *   directory, which only resolves when cron runs from 202-cronjobs/) and
 *   with require, never include (an include that fails warns and runs on);
 * - the one job that bootstraps the API instead (sync-worker.php) checks the
 *   schema version itself and exits 1;
 * - every die/exit and _die() in connect.php is preceded, in its own block,
 *   by an unconditional p202_cli_fail() call;
 * - the minutely cron takes its lock only after it has bootstrapped, so a
 *   run that could not start leaves no lock behind to make the next ones
 *   report "already running";
 * - and, executed: where this checkout has no 202-config.php, every entry
 *   point run from another directory exits non-zero naming the missing file.
 *
 * The upgrade case itself runs against a live instance whose version is
 * wound back: tests/live/cron-needs-upgrade.sh.
 */
final class CronEntryPointsFailLoudlyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';
    private const BOOTSTRAP = "__DIR__.'/../202-config/connect.php'";

    /** @return list<string> */
    private static function entryPoints(): array
    {
        $files = glob(self::ROOT . '202-cronjobs/*.php') ?: [];
        sort($files);
        self::assertGreaterThan(10, count($files), 'the cron directory was found');

        return $files;
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}> significant tokens: id, text, line
     */
    private static function tokens(string $file): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out[] = [$t[0], $t[1], $t[2]];
            } else {
                $out[] = [0, $t, 0];
            }
        }

        return $out;
    }

    public function testEveryCronJobRequiresConnectPhpByItsOwnPath(): void
    {
        foreach (self::entryPoints() as $file) {
            $name = basename($file);
            $tokens = self::tokens($file);
            $bootstraps = 0;
            foreach ($tokens as $i => [$id, $text, $line]) {
                if ($id === T_INCLUDE || $id === T_INCLUDE_ONCE) {
                    self::fail("$name:$line uses $text; a bootstrap that fails must stop the job (require_once)");
                }
                if ($id !== T_REQUIRE && $id !== T_REQUIRE_ONCE) {
                    continue;
                }
                $arg = '';
                for ($j = $i + 1; $j < count($tokens) && $tokens[$j][1] !== ';'; $j++) {
                    $arg .= $tokens[$j][1];
                }
                $arg = trim($arg, '()');
                if (str_contains($arg, 'connect')) {
                    self::assertSame(self::BOOTSTRAP, $arg, "$name:$line bootstraps connect.php by another path");
                    $bootstraps++;
                }
                self::assertTrue(
                    str_starts_with($arg, '__DIR__.') || str_starts_with($arg, 'dirname(__DIR__).'),
                    "$name:$line requires $arg, a path relative to wherever cron starts it"
                );
            }
            if ($name === 'sync-worker.php') {
                self::assertSame(0, $bootstraps, 'sync-worker.php bootstraps the API; its version check is below');
                continue;
            }
            self::assertSame(1, $bootstraps, "$name bootstraps through connect.php exactly once");
        }
    }

    public function testTheOneJobThatBootstrapsTheApiChecksTheSchemaVersionItself(): void
    {
        $src = (string) file_get_contents(self::ROOT . '202-cronjobs/sync-worker.php');
        $check = strpos($src, '$stale = \Prosper202\Database\SchemaVersion::mismatch($db, PROSPER202_VERSION);');
        $work = strpos($src, '$controller = new SyncController(');
        self::assertIsInt($check, 'sync-worker.php checks the schema version');
        self::assertIsInt($work);
        self::assertLessThan($work, $check, 'before it does any work');
        self::assertMatchesRegularExpression(
            '/if \(\$stale !== null\) \{\s*fwrite\(STDERR, [^;]+\$stale[^;]+;\s*exit\(1\);\s*\}/',
            substr($src, $check, $work - $check),
            'and a stale schema is written to stderr with exit status 1'
        );
    }

    public function testEveryStopInConnectPhpFailsLoudlyOnTheCommandLineFirst(): void
    {
        $tokens = self::tokens(self::ROOT . '202-config/connect.php');
        $depth = 0;
        $stack = []; // per open brace: [index of '{', is function body]
        $functionPending = false;
        $stops = 0;
        foreach ($tokens as $i => [$id, $text, $line]) {
            if ($id === T_FUNCTION || $id === T_FN) {
                $functionPending = true;
            }
            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $stack[] = [$i, $functionPending];
                $functionPending = false;
                continue;
            }
            if ($text === '}') {
                array_pop($stack);
                continue;
            }
            $inFunction = array_filter($stack, static fn (array $f): bool => $f[1]) !== [];
            $isStop = $id === T_EXIT
                || ($id === T_STRING && $text === '_die' && ($tokens[$i + 1][1] ?? '') === '(' && !in_array($tokens[$i - 1][0] ?? 0, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true));
            if (!$isStop || $inFunction) {
                continue;
            }
            $stops++;
            $blockStart = $stack === [] ? -1 : $stack[count($stack) - 1][0];
            $found = false;
            $nested = 0;
            for ($j = $i - 1; $j > $blockStart; $j--) {
                $t = $tokens[$j][1];
                if ($t === '}') {
                    $nested++;
                } elseif ($t === '{') {
                    $nested--;
                }
                if ($nested !== 0) {
                    continue;
                }
                if (in_array($tokens[$j][0], [T_RETURN, T_EXIT, T_THROW, T_BREAK, T_CONTINUE, T_GOTO], true)) {
                    break; // a stop reached only past a jump is not what runs here
                }
                if ($tokens[$j][0] === T_STRING && $t === 'p202_cli_fail' && ($tokens[$j + 1][1] ?? '') === '('
                    && in_array($tokens[$j - 1][1] ?? '', [';', '{', '}'], true)) {
                    $found = true;
                    break;
                }
            }
            self::assertTrue($found, "connect.php:$line stops ($text) without calling p202_cli_fail() first in the same block");
        }
        self::assertGreaterThanOrEqual(6, $stops, 'the stops were found (version file, config, vendor, database, upgrade, safe mode)');
    }

    public function testTheMinutelyCronTakesItsLockOnlyAfterItHasBootstrapped(): void
    {
        $src = (string) file_get_contents(self::ROOT . '202-cronjobs/index.php');
        $bootstrap = strpos($src, "require_once __DIR__ . '/../202-config/connect.php';");
        $lock = strpos($src, 'touch($lockFile)');
        self::assertIsInt($bootstrap);
        self::assertIsInt($lock);
        self::assertLessThan($lock, $bootstrap);
    }

    public function testWithoutAnInstallEveryCronJobSaysSoAndExitsNonZero(): void
    {
        if (file_exists(self::ROOT . '202-config.php')) {
            self::markTestSkipped('this checkout has a 202-config.php; tests/live/cron-needs-upgrade.sh covers an installed one');
        }
        if (!file_exists(self::ROOT . 'vendor/autoload.php')) {
            self::markTestSkipped('no vendor/ to bootstrap with');
        }
        foreach (self::entryPoints() as $file) {
            $name = basename($file);
            $proc = proc_open(
                [PHP_BINARY, '-d', 'display_errors=stderr', $file],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                sys_get_temp_dir()
            );
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($proc);

            self::assertNotSame(0, $status, "$name exited 0 without an install; stdout: " . substr($stdout, 0, 300));
            self::assertStringContainsString('202-config.php', $stderr, "$name names what is missing on stderr");
            if ($name !== 'sync-worker.php') {
                self::assertStringContainsString('Prosper202 (' . $name . '): ', $stderr, "$name stopped in connect.php's command-line branch");
                self::assertSame(1, $status, "$name exits 1");
            }
        }
    }
}
