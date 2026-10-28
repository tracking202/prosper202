<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Every `@group instance` suite runs on the autoloader the Agent Evals job
 * has: `composer install --no-dev`, which registers no `Tests\` map
 * (autoload-dev), and the PHPUnit phar loading each suite by path. A suite
 * that reaches a `Tests\` class it does not declare, or require by path,
 * dies there with "Class not found" — SetupEndpointsInstanceTest and
 * RtrClickInstanceTest did, on `Tests\Support\InstancePrivacy`, while every
 * local run, on the dev autoloader, was green.
 *
 * Read from the tokens: each class name in a position that loads it (`new`,
 * `::` other than `::class`, `extends`, `implements`, a trait's `use`) is
 * resolved through the file's namespace and imports, and one in `Tests\` has
 * to be declared in the suite or in a file it requires as
 * `__DIR__ . '<path>'` above the use, which is read the same way. A require
 * it cannot read is refused by name. Not seen: the order methods run in (a
 * require in a method above the one that uses the class is credited, as
 * setUpBeforeClass() runs first), a name built at runtime (`new $class`), and
 * `instanceof`, `catch` and type declarations, which load nothing.
 */
final class InstanceSuitesLoadWithoutTheDevAutoloaderTest extends TestCase
{
    private const NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    private const REQUIRES = [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE];

    public function testEveryInstanceSuiteLoadsWhatItUsesWithoutTheDevAutoloader(): void
    {
        $root = dirname(__DIR__, 2);
        $selector = escapeshellarg($root . '/tests/integration-suites.php');
        exec(escapeshellarg(PHP_BINARY) . ' ' . $selector . ' --instance', $suites, $status);
        self::assertSame(0, $status, 'tests/integration-suites.php --instance failed');
        self::assertGreaterThanOrEqual(9, count($suites), 'the selector found the instance suites');

        $problems = [];
        foreach ($suites as $suite) {
            $path = $root . '/' . $suite;
            foreach (self::unloadable($path, (string) file_get_contents($path)) as $problem) {
                $problems[] = $problem;
            }
        }

        self::assertSame([], $problems, "An instance suite uses a Tests\\ class the --no-dev autoloader cannot load;"
            . " require its file by path (`require_once __DIR__ . '/../Support/X.php';`):\n  "
            . implode("\n  ", $problems));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function plants(): array
    {
        $ns = "<?php\nnamespace Tests\\Api\\V3;\n";
        $import = "use Tests\\Support\\InstancePrivacy;\n";
        $required = "require_once __DIR__ . '/../../Support/InstancePrivacy.php';\n";
        $call = "InstancePrivacy::restore('', '', '');\n";
        $at = 'tests/Api/V3/XTest.php';

        return [
            'an import, then a static call' => [$ns . $import . $call, $at, true],
            'an import under another name' => [$ns . "use Tests\\Support\\InstancePrivacy as P;\nnew P();", $at, true],
            'a fully qualified new' => [$ns . "new \\Tests\\Support\\InstancePrivacy();", $at, true],
            'a name relative to the namespace' => [
                "<?php\nnamespace Tests;\nSupport\\InstancePrivacy::trackInFull('', '');",
                'tests/XTest.php',
                true,
            ],
            'an unqualified helper of the namespace' => [$ns . "Helper::make();", $at, true],
            'a Tests\\ parent class' => [$ns . "final class XTest extends \\Tests\\TestCase {}", $at, true],
            'a Tests\\ interface' => [
                $ns . "final class XTest implements \\Countable, \\Tests\\Support\\Probe {}",
                $at,
                true,
            ],
            'a Tests\\ trait' => [$ns . "final class XTest { use \\Tests\\Report\\ScratchReportDatabase; }", $at, true],
            'a require it cannot read' => [$ns . $import . "require_once \$helper;\n" . $call, $at, true],
            'a require of a file that is not there' => [
                $ns . "use Tests\\Support\\Nope;\nrequire_once __DIR__ . '/../../Support/Nope.php';\nNope::x();",
                $at,
                true,
            ],
            'a use above the require' => [$ns . $import . $call . $required, $at, true],
            'required by path' => [$ns . $import . $required . $call, $at, false],
            'required by path inside a method' => [
                $ns . $import . "final class XTest {\n"
                    . "public static function setUpBeforeClass(): void { " . $required . $call . "}\n"
                    . "public static function tearDownAfterClass(): void { " . $call . "}\n}",
                $at,
                false,
            ],
            'what PHPUnit and PHP give' => [
                $ns . "use PHPUnit\\Framework\\TestCase;\nfinal class XTest extends TestCase {\n"
                    . "public function t(): void { new \\RuntimeException(); self::x(); static::y(); parent::z();\n"
                    . "\$c = InstancePrivacy::class; }\n}",
                $at,
                false,
            ],
            'a class the file declares' => [
                $ns . "final class Local {}\n"
                    . "final class XTest { public function t(): void { new Local(); Local::class; } }",
                $at,
                false,
            ],
            'a closure use' => [$ns . "\$x = 1;\n\$f = function () use (\$x) { return \$x; };", $at, false],
        ];
    }

    /** @dataProvider plants */
    public function testEachShapeIsReadAsItIs(string $source, string $relative, bool $refused): void
    {
        $problems = self::unloadable(dirname(__DIR__, 2) . '/' . $relative, $source);
        self::assertSame($refused, $problems !== [], $source . "\n" . implode("\n", $problems));
    }

    /**
     * The Tests\ classes $source uses where they would be loaded and neither
     * it nor a file it requires by path declares, as "file:line: what".
     *
     * @param array<string, true> $seen files already read, against a require cycle
     * @return list<string>
     */
    private static function unloadable(string $path, string $source, array &$seen = []): array
    {
        $seen[$path] = true;
        $where = basename(dirname($path)) . '/' . basename($path);
        $tokens = self::significant($source);
        [$namespace, $imports, $declared] = self::scope($tokens);
        $problems = [];
        $used = [];
        // A required file's classes, from the token the require is at: a use
        // above the require in the source is refused, whatever runs first.
        $requiredFrom = [];

        $depth = 0;
        $classDepth = null;
        $opens = [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];
        foreach ($tokens as $i => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if ($text === '{' || (is_array($token) && in_array($token[0], $opens, true))) {
                $depth++;
                continue;
            }
            if ($text === '}') {
                $depth--;
                if ($classDepth !== null && $depth < $classDepth) {
                    $classDepth = null;
                }
                continue;
            }
            if (!is_array($token)) {
                continue;
            }
            $line = $token[2];
            if (in_array($token[0], [T_CLASS, T_TRAIT, T_ENUM], true) && self::declares($tokens, $i)) {
                $classDepth = $depth + 1;
            }
            if (in_array($token[0], self::REQUIRES, true)) {
                $file = self::requiredPath($tokens, $i, $path);
                if ($file === null) {
                    $problems[] = "$where:$line: a require this check cannot read"
                        . " (only `__DIR__ . '<path>'` of a file that is there)";
                    continue;
                }
                $contents = (string) file_get_contents($file);
                $requiredFrom[] = [$i, self::scope(self::significant($contents))[2]];
                if (!isset($seen[$file])) {
                    $problems = array_merge($problems, self::unloadable($file, $contents, $seen));
                }
                continue;
            }
            if ($token[0] === T_USE && $classDepth !== null && $depth === $classDepth) {
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], self::NAMES, true)) {
                        $used[] = [self::resolve($tokens[$j][1], $namespace, $imports), $i, $line, 'a trait'];
                    }
                }
                continue;
            }
            $relative = in_array(strtolower($text), ['self', 'static', 'parent'], true);
            if (!in_array($token[0], self::NAMES, true) || $relative) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $after = $tokens[$i + 2] ?? null;
            $previousId = is_array($previous) ? $previous[0] : null;
            $static = is_array($next) && $next[0] === T_DOUBLE_COLON
                && !(is_array($after) && strtolower($after[1]) === 'class');
            if ($previousId === T_NEW || $previousId === T_EXTENDS || $static || self::inImplements($tokens, $i)) {
                $used[] = [self::resolve($text, $namespace, $imports), $i, $line, 'a class'];
            }
        }

        foreach ($used as [$class, $at, $line, $what]) {
            $key = strtolower($class);
            if (!str_starts_with($class, 'Tests\\') || isset($declared[$key])) {
                continue;
            }
            foreach ($requiredFrom as [$from, $classes]) {
                if ($from < $at && isset($classes[$key])) {
                    continue 2;
                }
            }
            $problems[] = "$where:$line: $what $class, which neither the file"
                . " nor a file it requires by path above it declares";
        }

        return $problems;
    }

    /** @return list<mixed> */
    private static function significant(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /**
     * The file's namespace, its class imports (alias => class) and the
     * classes it declares (lower-cased fully qualified name => true).
     *
     * @param list<mixed> $tokens
     * @return array{0: string, 1: array<string, string>, 2: array<string, true>}
     */
    private static function scope(array $tokens): array
    {
        $namespace = '';
        $imports = [];
        $declared = [];
        $depth = 0;
        foreach ($tokens as $i => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if ($text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE && is_array($tokens[$i + 1] ?? null)) {
                $namespace = ltrim($tokens[$i + 1][1], '\\');
            }
            $imported = is_array($tokens[$i + 1] ?? null) && in_array($tokens[$i + 1][0], self::NAMES, true);
            if ($token[0] === T_USE && $depth === 0 && $imported) {
                $class = ltrim($tokens[$i + 1][1], '\\');
                $alias = substr($class, (int) strrpos('\\' . $class, '\\'));
                if (is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_AS) {
                    $alias = $tokens[$i + 3][1];
                }
                $imports[strtolower($alias)] = $class;
            }
            if (in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && self::declares($tokens, $i)) {
                $declared[strtolower(($namespace === '' ? '' : $namespace . '\\') . $tokens[$i + 1][1])] = true;
            }
        }

        return [$namespace, $imports, $declared];
    }

    /** Whether the class-like keyword at $i declares a named class: not `::class`, not `new class`. */
    private static function declares(array $tokens, int $i): bool
    {
        $previous = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + 1] ?? null;

        return is_array($next) && $next[0] === T_STRING
            && !(is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true));
    }

    /** @param array<string, string> $imports */
    private static function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($imports[$first])) {
            $rest = substr($name, strlen($first));

            return $imports[$first] . $rest;
        }

        return ($namespace === '' ? '' : $namespace . '\\') . $name;
    }

    /** Whether the name at $i is in a class's `implements` list. */
    private static function inImplements(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (is_array($token) && $token[0] === T_IMPLEMENTS) {
                return true;
            }
            if ($token === ',' || (is_array($token) && in_array($token[0], self::NAMES, true))) {
                continue;
            }

            return false;
        }

        return false;
    }

    /** The file `require_once __DIR__ . '<path>'` at $i names, when it is there; null otherwise. */
    private static function requiredPath(array $tokens, int $i, string $path): ?string
    {
        $j = $i + 1;
        $open = ($tokens[$j] ?? null) === '(';
        if ($open) {
            $j++;
        }
        $dir = $tokens[$j] ?? null;
        $dot = $tokens[$j + 1] ?? null;
        $literal = $tokens[$j + 2] ?? null;
        $end = $tokens[$j + 3] ?? null;
        $readable = is_array($dir) && $dir[0] === T_DIR && $dot === '.'
            && is_array($literal) && $literal[0] === T_CONSTANT_ENCAPSED_STRING
            && $end === ($open ? ')' : ';');
        if (!$readable) {
            return null;
        }
        $file = realpath(dirname($path) . substr($literal[1], 1, -1));

        return $file === false ? null : $file;
    }
}
