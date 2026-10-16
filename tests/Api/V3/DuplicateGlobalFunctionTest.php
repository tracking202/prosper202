<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\Support\GlobalFunctionScan;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * A function is declared in one file. Two declarations under one name are a
 * fatal "Cannot redeclare" the first time one request loads both, and until
 * then they drift: api/v1 and api/v2 each kept a copy of the legacy report
 * helpers, and v1's reportQuery() answered a refused statement as the API's
 * JSON error while v2's printed the HTML error page; the legacy
 * dollar_format() took ($amount, $cpv) where functions-tracking202.php's
 * takes ($amount, $currency, $cpv). A guard (`if (!function_exists(...))`)
 * does not make a second copy safe -- it makes whichever loads first win in
 * silence -- so a guarded declaration counts like any other.
 *
 * Tree-wide, as SourceScan reads it (tests/ is not scanned: each runner there
 * is its own process). phpstan-legacy-stubs.php is left out: it is PHPStan's
 * bootstrap, standing in for functions the analysis cannot load, and is never
 * loaded by the app.
 */
final class DuplicateGlobalFunctionTest extends TestCase
{
    private const NOT_LOADED_BY_THE_APP = ['phpstan-legacy-stubs.php'];

    /** @return array<string, list<string>> lowercased name => "file:line" of each declaration */
    private function declarations(): array
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (in_array($path, self::NOT_LOADED_BY_THE_APP, true)) {
                continue;
            }
            foreach (GlobalFunctionScan::declarations($source) as $declaration) {
                $found[$declaration['name']][] = $path . ':' . $declaration['line'];
            }
        }

        return $found;
    }

    public function testTheScannerFindsGlobalFunctionsAtAll(): void
    {
        $found = $this->declarations();
        // Without these the test below passes on a scan that reads nothing.
        $this->assertGreaterThan(300, count($found));
        foreach (['pretty_json', 'reportquery', 'getstats', 'getauth', 'record_mysql_error'] as $name) {
            $this->assertArrayHasKey($name, $found, "$name() is declared and was not found");
        }
    }

    public function testNoGlobalFunctionIsDeclaredTwice(): void
    {
        $duplicated = [];
        foreach ($this->declarations() as $name => $sites) {
            if (count($sites) < 2) {
                continue;
            }
            $duplicated[$name] = $sites;
        }

        $this->assertSame([], $duplicated, sprintf(
            "These functions are declared more than once:\n%s\n"
            . 'Keep one declaration in a file every caller loads (require_once), and give a '
            . 'per-caller difference an explicit parameter rather than a second copy.',
            implode("\n", array_map(
                static fn (string $name, array $sites): string => "  $name(): " . implode(', ', $sites),
                array_keys($duplicated),
                $duplicated
            ))
        ));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function shapes(): array
    {
        return [
            'plain' => ['<?php function foo() {}', ['foo']],
            'guarded by function_exists' => ["<?php if (!function_exists('foo')) {\n function foo() {}\n}", ['foo']],
            'returning by reference' => ['<?php function &foo() { static $a = []; return $a; }', ['foo']],
            'any case' => ['<?php function Pretty_JSON() {}', ['pretty_json']],
            'with an attribute' => ['<?php #[Deprecated] function foo() {}', ['foo']],
            'alternative syntax' => ['<?php if (true): function foo() {} endif;', ['foo']],
            'inside another function' => ['<?php function outer() { function inner() {} }', ['outer', 'inner']],
            'inside a method' => ['<?php class A { public function m() { function inner() {} } }', ['inner']],
            'namespaced' => ['<?php namespace App\Legacy; function foo() {}', ['app\legacy\foo']],
            'two namespaces' => ['<?php namespace A; function f() {} namespace B; function f() {}', ['a\f', 'b\f']],
            'namespace blocks' => [
                '<?php namespace App { function foo() {} } namespace { function bar() {} }',
                ['app\foo', 'bar'],
            ],
            'methods' => [
                '<?php abstract class A { function a() {} abstract function b(); }'
                . ' interface I { function c(); } trait T { function d() {} }'
                . " enum E: string { case X = 'x'; function e() {} } function after() {}",
                ['after'],
            ],
            'an anonymous class with a closure argument' => [
                '<?php $o = new class(function () { return 1; }) { function m() {} }; function after() {}',
                ['after'],
            ],
            'a trait use with a block' => [
                '<?php class A { use T { foo as bar; } function m() {} } function after() {}',
                ['after'],
            ],
            'closures and arrow functions' => ['<?php $f = function () use ($x) { return fn () => 1; };', []],
            'imports' => ['<?php use function Foo\bar; use Foo\{Baz, function qux}; function real() {}', ['real']],
            '::class is no class body' => ['<?php $c = Foo::class; if ($c) { function after() {} }', ['after']],
            'braces in strings' => [
                '<?php $s = "{$a[\'x\']} ${b}"; class A { function m() {} } function after() {}',
                ['after'],
            ],
        ];
    }

    /**
     * @dataProvider shapes
     * @param list<string> $expected
     */
    public function testEveryShapeOfADeclarationIsRead(string $source, array $expected): void
    {
        $this->assertSame($expected, array_column(GlobalFunctionScan::declarations($source), 'name'));
    }
}
