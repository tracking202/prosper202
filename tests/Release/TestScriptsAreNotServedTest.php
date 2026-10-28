<?php

declare(strict_types=1);

namespace Tests\Release;

use P202Build\ReleaseTree;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * tests/ holds scripts that act with no sign-in: the child processes of the
 * integration tests (one deletes a user, one installs a schema, one runs the
 * upgrade ladder) and the live passes' drivers, each on the database its
 * environment or its defaults name. Most stop over HTTP before they act only
 * because $argv is a global on the command line alone; the schema installer
 * and the ladder read no argument, and run.
 *
 *  - The container image leaves tests/ out of its build context
 *    (.dockerignore), and the release zip keeps none of these scripts.
 *  - A checkout served as the web root (the dev compose stack, any host
 *    serving a clone) still serves it, so every runnable script under tests/
 *    answers any SAPI but the CLI with a 404 before it does anything else.
 *
 * Runnable is read from a file's top-level statements in order. declare,
 * namespace and use statements, class-like and named-function declarations,
 * and a load (require/include) whose path is written from __DIR__ and stays in
 * the repository are not acts: a file outside tests/ is served on its own
 * wherever this one is, and a .php file inside is held to this rule itself.
 * The first other statement is an act, and it must be the guard; a file with
 * none is a class file, a test case or a function library. A construct the
 * reading does not know (a load whose path it cannot work out, a top-level
 * `const`, a `return`, inline HTML, a block `declare`) reads as an act, the
 * loud direction.
 */
final class TestScriptsAreNotServedTest extends TestCase
{
    private const REPO = __DIR__ . '/../..';

    private const GUARD = "if (PHP_SAPI !== 'cli') {\n    http_response_code(404);\n    exit;\n}";

    /** Scripts that run under a web SAPI on purpose, with what a request for one can do. */
    private const RUN_BY_A_WEB_SERVER = [
        'tests/fixtures/agent-eval/ci/router.php' => "php -S's router for the eval instance and the upgrade check"
            . ' (install-instance.sh, upgrade-equals-install.sh), so it runs under cli-server. Requested as a page,'
            . ' the most it does is run the index.php of a directory the web root serves anyway.',
    ];

    /** @var array<string, array{kind: string, line: int, what: string}>|null */
    private static ?array $verdicts = null;

    public static function setUpBeforeClass(): void
    {
        require_once self::REPO . '/build/scripts/ReleaseTree.php';
    }

    public function testEveryRunnableScriptRefusesAWebRequestFirst(): void
    {
        $kinds = array_count_values(array_column(self::verdicts(), 'kind'));
        // Floors: the walk read the suite, and the reading found the runners.
        self::assertGreaterThan(500, $kinds['inert'] ?? 0, 'the walk read the test suite');
        self::assertGreaterThanOrEqual(25, $kinds['guarded'] ?? 0, 'the reading found the runners');

        $unguarded = [];
        foreach (self::verdicts() as $path => $verdict) {
            if ($verdict['kind'] === 'unguarded' && !isset(self::RUN_BY_A_WEB_SERVER[$path])) {
                $unguarded[] = "{$path}:{$verdict['line']}: {$verdict['what']}";
            }
        }

        self::assertSame(
            [],
            $unguarded,
            "A script under tests/ acts before it refuses a web request. Wherever a checkout is the web root, "
            . "tests/ is served and a request runs it with no sign-in. Make this its first statement (after "
            . "declare, namespace and use):\n\n" . self::GUARD . "\n\nwith \\PHP_SAPI and \\http_response_code in a "
            . 'file that declares a namespace or imports a function or constant.'
        );
    }

    public function testTheScriptsRunByAWebServerAreStillThere(): void
    {
        foreach (array_keys(self::RUN_BY_A_WEB_SERVER) as $path) {
            self::assertSame(
                'unguarded',
                self::verdicts()[$path]['kind'] ?? 'missing',
                "{$path} is listed as run by a web server; a script no longer there, or one that now refuses "
                . 'a web request, comes off the list'
            );
        }
    }

    public function testTheReleaseKeepsNoneOfThem(): void
    {
        $tree = ReleaseTree::load(self::REPO . '/build/release-manifest.php');
        $shipped = [];
        foreach (self::verdicts() as $path => $verdict) {
            if ($verdict['kind'] !== 'inert' && $tree->ships($path)) {
                $shipped[] = $path;
            }
        }

        self::assertSame([], $shipped, 'build/release-manifest.php ships a runnable script from tests/');
    }

    public function testTheImageLeavesTestsOut(): void
    {
        self::assertFileDoesNotExist(
            self::REPO . '/Dockerfile.dockerignore',
            'BuildKit reads Dockerfile.dockerignore in place of .dockerignore; this test reads only the latter'
        );
        $patterns = self::dockerignore((string) file_get_contents(self::REPO . '/.dockerignore'));

        $files = 0;
        $inContext = [];
        foreach (self::filesUnderTests() as $path) {
            $files++;
            if (!self::dockerIgnores($patterns, $path)) {
                $inContext[] = $path;
            }
        }
        self::assertGreaterThan(500, $files, 'the walk read tests/');
        self::assertSame([], $inContext, '.dockerignore sends these to the image build, and the app stage serves them');

        // A reading that left everything out would pass the above: what the
        // Dockerfile copies by name, and the app, must still be sent.
        $needed = [...self::dockerfileCopySources(), 'index.php', '202-config/connect.php', 'api/v3/index.php',
            'tracking202/redirect/dl.php'];
        self::assertContains('build/scripts/docker-entrypoint.sh', $needed, 'the Dockerfile COPY lines were read');
        foreach ($needed as $path) {
            self::assertFalse(self::dockerIgnores($patterns, $path), ".dockerignore leaves out {$path}");
        }
    }

    public function testNoFileUnderTestsHasAnotherExtensionAHostMayRunAsPhp(): void
    {
        $other = array_values(array_filter(
            self::filesUnderTests(),
            static fn (string $path): bool => preg_match('/\.(?:phtml|pht|phar|php\d)$/i', $path) === 1
        ));

        self::assertSame([], $other, 'a host may run these as PHP, and this test reads only .php');
    }

    public function testARequestForEachGuardedScriptIsANotFoundThatRunsNothing(): void
    {
        $guarded = array_keys(array_filter(
            self::verdicts(),
            static fn (array $verdict): bool => $verdict['kind'] === 'guarded'
        ));
        self::assertGreaterThanOrEqual(25, count($guarded));

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        // Databases nothing reaches, had a script got that far: port 1 refuses.
        $env = [
            'PATH' => (string) getenv('PATH'),
            'P202_TEST_DB_HOST' => '127.0.0.1', 'P202_TEST_DB_PORT' => '1',
            'P202_DB_HOST' => '127.0.0.1', 'P202_DB_PORT' => '1',
        ];
        $server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', '-S', "127.0.0.1:{$port}", '-t', (string) realpath(self::REPO)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($server);
        $answers = [];
        try {
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port);
                if ($socket !== false) {
                    fclose($socket);
                    break;
                }
                usleep(100000);
            }
            // The server runs PHP under tests/: a function library answers 200.
            $control = self::get($port, '/tests/Config/fixtures/lift-function.php');
            foreach ($guarded as $path) {
                $answers[$path] = self::get($port, "/{$path}");
            }
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        self::assertSame([200, ''], $control, 'the control request did not run');
        $wrong = [];
        foreach ($answers as $path => [$status, $body]) {
            if ($status !== 404 || $body !== '') {
                $wrong[] = "{$path}: {$status} " . substr($body, 0, 200);
            }
        }
        self::assertSame([], $wrong, 'a guarded script answered a request with something other than an empty 404');
    }

    /**
     * Each shape the reading claims to tell apart, planted.
     *
     * @dataProvider plantedShapes
     */
    public function testTheReadingOfOneFile(string $source, string $kind, int $line = 0): void
    {
        $verdict = self::verdict(self::REPO . '/tests/Release/planted.php', $source);

        self::assertSame([$kind, $line], [$verdict['kind'], $verdict['line']], $verdict['what']);
    }

    /** @return iterable<string, array{0: string, 1: string, 2?: int}> */
    public static function plantedShapes(): iterable
    {
        $guard = self::GUARD;
        $qualified = str_replace(['PHP_SAPI', 'http_response_code'], ['\\PHP_SAPI', '\\http_response_code'], $guard);

        yield 'a class file' => [self::php(
            'declare(strict_types=1);',
            'namespace Tests\\X;',
            'use A\\B;',
            '#[Attr]',
            'final readonly class C extends B',
            '{',
            '    public function f(): void { $x = "{$y[\'}\']}"; }',
            '}'
        ), 'inert'];
        yield 'an interface, a trait, an enum, a function' => [self::php(
            'interface I {}',
            'trait T { }',
            "enum E: string { case A = 'a'; }",
            'abstract class A {}',
            'function &f(array $a = [1, 2]): array { return $a; }'
        ), 'inert'];
        yield 'nothing but declare' => [self::php('declare(strict_types=1);'), 'inert'];
        yield 'a load from __DIR__ out of tests/' => [self::php(
            'namespace X;',
            "require_once dirname(__DIR__, 2) . '/202-config/functions-tracking202.php';",
            'final class T {}'
        ), 'inert'];
        yield 'a load of a .php file in tests/' => [self::php(
            "require __DIR__ . '/../Config/fixtures/lift-function.php';",
            "include_once(dirname(__DIR__) . '/Support/SourceScan.php');"
        ), 'inert'];
        yield 'loads in braced namespaces' => [self::php(
            'declare(strict_types=1);',
            'namespace {',
            "    require_once dirname(__DIR__, 2) . '/202-config/functions-tracking202.php';",
            '}',
            'namespace Tests\\X {',
            '    use A\\B;',
            '    final class T extends B {}',
            '}'
        ), 'inert'];

        yield 'the guard after declare' => [
            self::php('/** doc */', 'declare(strict_types=1);', '// why', $guard, '[, $a] = $argv;'), 'guarded', 5,
        ];
        yield 'the guard with no declare' => [
            self::php('', '/** doc */', '', $guard, "require __DIR__ . '/x.php';"), 'guarded', 5,
        ];
        yield 'the guard after use' => [self::php('use A\\B;', 'use C\\D;', $guard, 'B::run();'), 'guarded', 4];
        yield 'the guard, fully qualified, in a namespace' => [
            self::php('namespace X;', $qualified, 'run();'), 'guarded', 3,
        ];
        yield 'the guard first in a braced namespace' => [
            self::php('namespace {', $guard, 'run();', '}'), 'guarded', 3,
        ];
        yield 'the guard then function declarations' => [self::php($guard, 'function f() {}', 'f();'), 'guarded', 2];
        yield 'the guard unqualified beside a closure and a trait' => [self::php(
            $guard,
            '$f = function () use ($x) { return function () {}; };',
            'final class C { use T { f as protected; } }'
        ), 'guarded', 2];

        yield 'no guard' => [self::php(
            'declare(strict_types=1);',
            '[, $userId] = $argv;',
            "require __DIR__ . '/../../vendor/autoload.php';"
        ), 'unguarded', 3];
        yield 'an assignment' => [self::php('$x = 1;'), 'unguarded', 2];
        yield 'a call' => [self::php('mysqli_report(MYSQLI_REPORT_STRICT);'), 'unguarded', 2];
        yield 'echo' => [self::php("echo 'x';"), 'unguarded', 2];
        yield 'a static call' => [self::php('use A\\B;', 'B::run();'), 'unguarded', 3];
        yield 'a relative-namespace call' => [self::php('namespace\\run();'), 'unguarded', 2];
        yield 'an if that declares' => [self::php("if (!function_exists('f')) { function f() {} }"), 'unguarded', 2];
        yield 'a closure called' => [self::php('(function () {})();'), 'unguarded', 2];
        yield 'a closure statement' => [self::php('function () {};'), 'unguarded', 2];
        yield 'an anonymous class' => [self::php('new class {};'), 'unguarded', 2];
        yield 'a top-level const' => [self::php('const X = 1;'), 'unguarded', 2];
        yield 'a return' => [self::php("return ['a' => 1];"), 'unguarded', 2];
        yield 'a block declare' => [self::php('declare(ticks=1) { run(); }'), 'unguarded', 2];
        yield 'inline HTML first' => ["#!/usr/bin/env php\n" . self::php($guard), 'unguarded', 1];
        yield 'inline HTML after a close tag' => [self::php('final class C {}', '?>', 'hello'), 'unguarded', 4];
        yield 'an echo tag' => ["<?= 'x' ?>\n", 'unguarded', 1];

        yield 'a load from a variable' => [self::php('require $root . \'/x.php\';'), 'unguarded', 2];
        yield 'a load with interpolation' => [self::php('require __DIR__ . "/x{$y}.php";'), 'unguarded', 2];
        yield 'a load of a non-PHP file in tests/' => [
            self::php("require __DIR__ . '/../fixtures/agent-eval/README.md';"), 'unguarded', 2,
        ];
        yield 'a load out of the repository' => [
            self::php("require dirname(__DIR__, 9) . '/etc/x.php';"), 'unguarded', 2,
        ];
        yield 'a load followed by more' => [self::php('require __DIR__ . \'/a.php\' . $x;'), 'unguarded', 2];

        yield 'the guard below a declaration' => [self::php('function f() {}', $guard, 'f();'), 'unguarded', 2];
        yield 'the guard below a load' => [
            self::php("require dirname(__DIR__, 2) . '/vendor/autoload.php';", $guard), 'unguarded', 2,
        ];
        yield 'the guard unqualified in a namespace' => [self::php('namespace X;', $guard, 'run();'), 'unguarded', 3];
        yield 'the guard unqualified beside a function import' => [
            self::php('use function A\\http_response_code;', $guard, 'run();'), 'unguarded', 3,
        ];
        yield 'the guard unqualified beside a constant import' => [
            self::php('use const A\\PHP_SAPI;', $guard, 'run();'), 'unguarded', 3,
        ];
        yield 'the guard unqualified beside a group import' => [
            self::php('use A\\{B, function http_response_code};', $guard, 'run();'), 'unguarded', 3,
        ];
        yield 'php_sapi_name()' => [self::php(str_replace('PHP_SAPI', 'php_sapi_name()', $guard)), 'unguarded', 2];
        yield 'cli-server' => [self::php(str_replace("'cli'", "'cli-server'", $guard)), 'unguarded', 2];
        yield 'no exit' => [self::php(str_replace("    exit;\n", '', $guard), 'run();'), 'unguarded', 2];
        yield 'another status' => [self::php(str_replace('404', '200', $guard)), 'unguarded', 2];
        yield 'a loose comparison' => [self::php(str_replace('!==', '!=', $guard)), 'unguarded', 2];
        yield 'a guard that only returns' => [self::php(str_replace('exit;', 'return;', $guard)), 'unguarded', 2];
    }

    /** A PHP file: the opening tag on line 1, then these lines. */
    private static function php(string ...$lines): string
    {
        return "<?php\n" . implode("\n", $lines) . "\n";
    }

    /** @return array<string, array{kind: string, line: int, what: string}> repo-relative path => verdict */
    private static function verdicts(): array
    {
        if (self::$verdicts !== null) {
            return self::$verdicts;
        }
        $verdicts = [];
        foreach (self::filesUnderTests() as $path) {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $source = file_get_contents(self::REPO . '/' . $path);
            if ($source === false) {
                throw new \RuntimeException("Could not read {$path}");
            }
            $verdicts[$path] = self::verdict(self::REPO . '/' . $path, $source);
        }

        return self::$verdicts = $verdicts;
    }

    /** @return list<string> repo-relative paths of every file under tests/ */
    private static function filesUnderTests(): array
    {
        $root = (string) realpath(self::REPO);
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            SourceScan::tree($root . '/tests'),
            static fn (\SplFileInfo $entry): bool => $entry->getFilename() !== 'node_modules'
        ));
        foreach ($walk as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * 'inert' when the file holds no act; 'guarded' when its first act is the
     * guard and nothing but declare, namespace and use precede it; otherwise
     * 'unguarded', at the line of the first statement that should have
     * followed the guard.
     *
     * @return array{kind: string, line: int, what: string}
     */
    private static function verdict(string $file, string $source): array
    {
        $tokens = self::tokens($source);
        $n = count($tokens);
        $unqualifiedIsGlobal = self::unqualifiedNamesAreGlobal($tokens);
        $first = null;
        $braced = false;
        $i = 0;
        while ($i < $n) {
            [$id, $text, $line] = $tokens[$i];
            if ($text === ';' || $id === T_CLOSE_TAG) {
                $i++;
                continue;
            }
            if ($braced && $text === '}') {
                $braced = false;
                $i++;
                continue;
            }
            if ($id === T_NAMESPACE) {
                $j = $i + 1;
                $named = in_array($tokens[$j][0] ?? null, [T_STRING, T_NAME_QUALIFIED], true);
                $j += $named ? 1 : 0;
                $after = $tokens[$j][1] ?? '';
                if ($after === '{' && !$braced) {
                    $braced = true;
                    $i = $j + 1;
                    continue;
                }
                if ($named && $after === ';') {
                    $i = $j + 1;
                    continue;
                }
            } elseif ($id === T_DECLARE && ($tokens[$i + 1][1] ?? '') === '(') {
                $close = self::closer($tokens, $i + 1);
                $after = $tokens[($close ?? $n) + 1] ?? [0, '', 0];
                if ($close !== null && ($after[1] === ';' || $after[0] === T_CLOSE_TAG)) {
                    $i = $close + 2;
                    continue;
                }
            } elseif ($id === T_USE) {
                $end = self::statementEnd($tokens, $i);
                if ($end !== null) {
                    $i = $end + 1;
                    continue;
                }
            } elseif (self::isGuardAt($tokens, $i, $unqualifiedIsGlobal)) {
                return $first === null
                    ? ['kind' => 'guarded', 'line' => $line, 'what' => 'refuses a web request first']
                    : ['kind' => 'unguarded', 'line' => $first[0],
                        'what' => "the guard on line {$line} comes after `{$first[1]}`"];
            }
            $end = self::declarationEnd($tokens, $i) ?? self::loadEnd($tokens, $i, $file);
            if ($end === null) {
                $above = $first === null ? '' : " (the guard goes above line {$first[0]})";

                $what = '`' . self::excerpt($tokens, $i) . "` runs with no guard before it{$above}";

                return ['kind' => 'unguarded', 'line' => $line, 'what' => $what];
            }
            $first ??= [$line, self::excerpt($tokens, $i)];
            $i = $end + 1;
        }

        return ['kind' => 'inert', 'line' => 0, 'what' => 'declares and loads only'];
    }

    /**
     * Every token but whitespace, comments and the opening tag, as
     * [id (0 for a single character), text, line].
     *
     * @return list<array{0: int, 1: string, 2: int}>
     */
    private static function tokens(string $source): array
    {
        $out = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [0, $token];
            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                $out[] = [$id, $text, $line];
            }
            $line += substr_count($text, "\n");
        }

        return $out;
    }

    /**
     * An unqualified name means the global function or constant only in a
     * file that declares no namespace and imports no function or constant.
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     */
    private static function unqualifiedNamesAreGlobal(array $tokens): bool
    {
        foreach ($tokens as $i => [$id]) {
            $next = $tokens[$i + 1][0] ?? null;
            if ($id === T_NAMESPACE && in_array($next, [T_STRING, T_NAME_QUALIFIED], true)) {
                return false;
            }
            if ($id !== T_USE) {
                continue;
            }
            if (in_array($next, [T_FUNCTION, T_CONST], true)) {
                return false;
            }
            // A group import, `use A\{function f, const C}`; a trait's or a
            // closure's `use` has no `\{`.
            $j = $i + 1;
            while (in_array($tokens[$j][0] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $j++;
            }
            if (($tokens[$j][0] ?? null) === T_NS_SEPARATOR && ($tokens[$j + 1][1] ?? '') === '{') {
                $close = self::closer($tokens, $j + 1) ?? count($tokens) - 1;
                for ($k = $j + 1; $k <= $close; $k++) {
                    if (in_array($tokens[$k][0], [T_FUNCTION, T_CONST], true)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /** @param list<array{0: int, 1: string, 2: int}> $tokens */
    private static function isGuardAt(array $tokens, int $i, bool $unqualifiedIsGlobal): bool
    {
        $sapi = $unqualifiedIsGlobal ? ['PHP_SAPI', '\\PHP_SAPI'] : ['\\PHP_SAPI'];
        $answer = $unqualifiedIsGlobal ? ['http_response_code', '\\http_response_code'] : ['\\http_response_code'];
        $want = [
            ['if'], ['('], $sapi, ['!=='], ["'cli'"], [')'], ['{'],
            $answer, ['('], ['404'], [')'], [';'], ['exit'], [';'], ['}'],
        ];
        foreach ($want as $k => $texts) {
            if (!in_array($tokens[$i + $k][1] ?? null, $texts, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The index of the `}` that ends a class-like or named-function
     * declaration starting at $i (attributes and modifiers included), or null
     * when none starts there.
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     */
    private static function declarationEnd(array $tokens, int $i): ?int
    {
        $j = $i;
        while (($tokens[$j][0] ?? null) === T_ATTRIBUTE) {
            $close = self::closer($tokens, $j);
            if ($close === null) {
                return null;
            }
            $j = $close + 1;
        }
        if (($tokens[$j][0] ?? null) === T_FUNCTION) {
            $name = ($tokens[$j + 1][1] ?? '') === '&' ? $j + 2 : $j + 1;
            if (($tokens[$name][0] ?? null) !== T_STRING) {
                return null;
            }
        } else {
            while (in_array($tokens[$j][0] ?? null, [T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                $j++;
            }
            $named = ($tokens[$j + 1][0] ?? null) === T_STRING;
            if (!$named || !in_array($tokens[$j][0] ?? null, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                return null;
            }
        }
        for ($k = $j, $n = count($tokens); $k < $n; $k++) {
            if ($tokens[$k][0] === 0 && $tokens[$k][1] === '{') {
                return self::closer($tokens, $k);
            }
            if (self::opens($tokens[$k])) {
                $k = self::closer($tokens, $k) ?? $n;
            } elseif ($tokens[$k][0] === 0 && $tokens[$k][1] === ';') {
                return null;
            }
        }

        return null;
    }

    /**
     * The index of the `;` that ends a require or include at $i, when its
     * path is __DIR__, dirname(__DIR__) or dirname(__DIR__, n) followed by a
     * plain string, and names a file in the repository: outside tests/, or a
     * .php file inside it. Worked out from the path's text alone, so the
     * answer does not hang on which files a checkout happens to hold.
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     */
    private static function loadEnd(array $tokens, int $i, string $file): ?int
    {
        if (!in_array($tokens[$i][0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            return null;
        }
        $end = self::statementEnd($tokens, $i);
        if ($end === null) {
            return null;
        }
        $expr = array_map(static fn (array $token): string => $token[1], array_slice($tokens, $i + 1, $end - $i - 1));
        if (($expr[0] ?? '') === '(' && end($expr) === ')') {
            $expr = array_slice($expr, 1, -1);
        }
        $literal = array_pop($expr);
        $plain = $literal !== null && preg_match('/^\'([^\'\\\\]*)\'$|^"([^"\\\\$]*)"$/', $literal, $m) === 1;
        if (!$plain || array_pop($expr) !== '.') {
            return null;
        }
        $levels = match (true) {
            $expr === ['__DIR__'] => 0,
            in_array($expr, [['dirname', '(', '__DIR__', ')'], ['\\dirname', '(', '__DIR__', ')']], true) => 1,
            count($expr) === 6 && in_array($expr[0], ['dirname', '\\dirname'], true)
                && array_slice($expr, 1, 3) === ['(', '__DIR__', ','] && ctype_digit($expr[4]) && $expr[5] === ')'
                => (int) $expr[4],
            default => null,
        };
        if ($levels === null) {
            return null;
        }
        $dir = dirname($file);
        $dir = $levels > 0 ? dirname($dir, $levels) : $dir;
        $target = self::normalize($dir . '/' . ($m[1] !== '' ? $m[1] : ($m[2] ?? '')));
        $root = self::normalize(self::REPO);
        if ($target === null || !str_starts_with($target, $root . '/')) {
            return null;
        }
        $inTests = str_starts_with($target, $root . '/tests/');

        return !$inTests || str_ends_with(strtolower($target), '.php') ? $end : null;
    }

    /** $path with `.` and `..` segments worked out, without asking the filesystem. */
    private static function normalize(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    /**
     * The index of the `;` or `?>` that ends the statement starting at $i.
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     */
    private static function statementEnd(array $tokens, int $i): ?int
    {
        for ($k = $i, $n = count($tokens); $k < $n; $k++) {
            if (($tokens[$k][0] === 0 && $tokens[$k][1] === ';') || $tokens[$k][0] === T_CLOSE_TAG) {
                return $k;
            }
            if (self::opens($tokens[$k])) {
                $close = self::closer($tokens, $k);
                if ($close === null) {
                    return null;
                }
                $k = $close;
            } elseif (self::closes($tokens[$k])) {
                return null;
            }
        }

        return null;
    }

    /**
     * The index of the bracket that closes the one opened at $i. Strings are
     * single tokens, so a bracket inside one is never counted; `{$` and `${`
     * in an interpolation open, and `#[` opens an attribute.
     *
     * @param list<array{0: int, 1: string, 2: int}> $tokens
     */
    private static function closer(array $tokens, int $i): ?int
    {
        $depth = 0;
        for ($k = $i, $n = count($tokens); $k < $n; $k++) {
            if (self::opens($tokens[$k])) {
                $depth++;
            } elseif (self::closes($tokens[$k]) && --$depth === 0) {
                return $k;
            }
        }

        return null;
    }

    /** @param array{0: int, 1: string, 2: int} $token */
    private static function opens(array $token): bool
    {
        return ($token[0] === 0 && in_array($token[1], ['(', '[', '{'], true))
            || in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true);
    }

    /** @param array{0: int, 1: string, 2: int} $token */
    private static function closes(array $token): bool
    {
        return $token[0] === 0 && in_array($token[1], [')', ']', '}'], true);
    }

    /** @param list<array{0: int, 1: string, 2: int}> $tokens */
    private static function excerpt(array $tokens, int $i): string
    {
        $text = (string) preg_replace('/\s+/', ' ', implode(' ', array_column(array_slice($tokens, $i, 8), 1)));

        return strlen($text) > 60 ? substr($text, 0, 60) . '…' : $text;
    }

    /**
     * .dockerignore as BuildKit reads it (moby/buildkit's dockerignore
     * ReadAll and moby/patternmatcher): a line whose first character is `#` is
     * a comment; a line is trimmed, a leading `!` re-includes, the pattern is
     * cleaned and loses a leading `/`. `*` and `?` match within one path
     * segment and `**` across any number. Character classes and escapes are
     * not read here, so a pattern using them is refused rather than misread.
     *
     * @return list<array{exclude: bool, regex: string}>
     */
    private static function dockerignore(string $contents): array
    {
        $patterns = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $raw) {
            if (str_starts_with($raw, '#')) {
                continue;
            }
            $pattern = trim($raw);
            if ($pattern === '') {
                continue;
            }
            $exclude = !str_starts_with($pattern, '!');
            $pattern = $exclude ? $pattern : trim(substr($pattern, 1));
            if (preg_match('#[\[\]\\\\]|(^|/)\.\.(/|$)#', $pattern) === 1) {
                throw new \RuntimeException(
                    "This test does not read the .dockerignore pattern '{$raw}'; teach dockerignore() its syntax"
                );
            }
            $pattern = (string) preg_replace('#/+#', '/', $pattern);
            $pattern = (string) preg_replace('#(^|/)\.(?=/|$)#', '', $pattern);
            $pattern = $pattern === '/' ? '/' : rtrim($pattern, '/');
            $pattern = strlen($pattern) > 1 ? ltrim($pattern, '/') : $pattern;
            $regex = '';
            for ($k = 0, $len = strlen($pattern); $k < $len; $k++) {
                $ch = $pattern[$k];
                if ($ch === '*' && ($pattern[$k + 1] ?? '') === '*') {
                    $k++;
                    if (($pattern[$k + 1] ?? '') === '/') {
                        $k++;
                    }
                    $regex .= $k + 1 >= $len ? '.*' : '(.*/)?';
                } elseif ($ch === '*') {
                    $regex .= '[^/]*';
                } elseif ($ch === '?') {
                    $regex .= '[^/]';
                } else {
                    $regex .= preg_quote($ch, '#');
                }
            }
            $patterns[] = ['exclude' => $exclude, 'regex' => '#^' . $regex . '$#'];
        }

        return $patterns;
    }

    /**
     * Whether the build context leaves $path out: the last pattern that
     * matches the path or one of its parent directories decides.
     *
     * @param list<array{exclude: bool, regex: string}> $patterns
     */
    private static function dockerIgnores(array $patterns, string $path): bool
    {
        $candidates = [$path];
        $parts = explode('/', $path);
        for ($k = 1; $k < count($parts); $k++) {
            $candidates[] = implode('/', array_slice($parts, 0, $k));
        }
        $ignored = false;
        foreach ($patterns as $pattern) {
            foreach ($candidates as $candidate) {
                if (preg_match($pattern['regex'], $candidate) === 1) {
                    $ignored = $pattern['exclude'];
                    break;
                }
            }
        }

        return $ignored;
    }

    /** @return list<string> the context paths the Dockerfile's COPY lines name, `.` aside */
    private static function dockerfileCopySources(): array
    {
        $sources = [];
        foreach (file(self::REPO . '/Dockerfile', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^COPY\s+(.*)$/', $line, $m) !== 1 || str_contains($m[1], '--from=')) {
                continue;
            }
            $args = array_values(array_filter(
                preg_split('/\s+/', trim($m[1])) ?: [],
                static fn (string $arg): bool => !str_starts_with($arg, '--')
            ));
            foreach (array_slice($args, 0, -1) as $source) {
                if ($source !== '.') {
                    $sources[] = $source;
                }
            }
        }

        return $sources;
    }

    /** @return array{0: int, 1: string} status and body */
    private static function get(int $port, string $target): array
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 10);
        if ($socket === false) {
            return [0, "no connection: {$error}"];
        }
        stream_set_timeout($socket, 10);
        fwrite($socket, "GET {$target} HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $response = (string) stream_get_contents($socket);
        fclose($socket);
        [$head, $body] = explode("\r\n\r\n", $response, 2) + [1 => ''];
        $status = preg_match('#^HTTP/1\.[01] (\d{3})#', $head, $m) === 1 ? (int) $m[1] : 0;

        return [$status, $body];
    }
}
