<?php

declare(strict_types=1);

namespace Tests\Release;

use P202Build\ReleaseTree;
use PHPUnit\Framework\TestCase;

/**
 * PHP's class names ignore case and Composer's file lookup follows the
 * filesystem's: a class written in another case than any spelling the
 * autoloader can load is found on a Mac and not on Linux, unless something
 * loaded it first. The page controllers declared Tracking202\Analyze and
 * Tracking202\Setup sat in tracking202/analyze/ and tracking202/setup/ and
 * loaded only because every page required them by hand; the directories are
 * Analyze/ and Setup/ since 1.9.79. release-tree.php verify asks the same of
 * the zip.
 */
final class ClassNamesKeepTheirCaseTest extends TestCase
{
    private const REPO = __DIR__ . '/../..';

    private static ?string $fixture = null;

    public static function setUpBeforeClass(): void
    {
        require_once self::REPO . '/build/scripts/ReleaseTree.php';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixture !== null) {
            self::remove(self::$fixture);
        }
    }

    public function testShippedCodeWritesEveryClassAsTheAutoloaderLoadsIt(): void
    {
        if (!is_dir(self::REPO . '/.git') && !is_file(self::REPO . '/.git')) {
            self::markTestSkipped('not a git checkout');
        }
        if (!is_file(self::REPO . '/vendor/composer/autoload_psr4.php')) {
            self::markTestSkipped('composer install has not run');
        }
        $tree = ReleaseTree::load(self::REPO . '/build/release-manifest.php');
        $sources = [];
        foreach (ReleaseTree::trackedFiles(self::REPO) as $path) {
            if (!$tree->ships($path)) {
                continue;
            }
            $text = ReleaseTree::codeText(self::REPO . '/' . $path);
            if ($text !== null && (str_ends_with($path, '.php') || preg_match('/^#!.*php/', $text) === 1)) {
                $sources[$path] = $text;
            }
        }
        $loadable = ReleaseTree::autoloadableNames(self::REPO);

        self::assertGreaterThan(500, count($sources), 'the shipped code was not read');
        self::assertArrayHasKey('prosper202\http\redirecthelper', $loadable, 'the app\'s own classes were not listed');
        self::assertSame([], ReleaseTree::classNameCaseProblems($sources, $loadable));
    }

    /**
     * Composer's maps, as a fixture: a PSR-4 directory, a narrower prefix
     * over a lowercase directory, PSR-0 and a classmap entry.
     */
    public function testTheAutoloaderLoadsTheSpellingsItsDirectoriesSpell(): void
    {
        $loadable = ReleaseTree::autoloadableNames(self::fixture());

        self::assertSame(['App\Http\ClickCookie'], $loadable['app\http\clickcookie']);
        $both = $loadable['app\setup\pagecontroller'];
        sort($both);
        self::assertSame(['App\Setup\PageController', 'App\setup\PageController'], $both);
        self::assertSame(['Old\Thing'], $loadable['old\thing']);
        self::assertSame(['Mapped_Legacy'], $loadable['mapped_legacy']);
        self::assertArrayNotHasKey('app\setup\class-page', $loadable, 'a file name that is no class name');
    }

    /** @return iterable<string, array{string, list<string>}> code, the names reported */
    public static function writtenNames(): iterable
    {
        $ns = "<?php\nnamespace App\\Http;\n";
        yield 'an import' => ["<?php\nuse App\\http\\ClickCookie;\n", ['App\http\ClickCookie']];
        yield 'fully qualified' => ["<?php\nnew \\App\\Http\\clickCookie();\n", ['App\Http\clickCookie']];
        yield 'qualified, under the namespace' => [
            "<?php\nnamespace App;\nnew Http\\Clickcookie();\n",
            ['App\Http\Clickcookie'],
        ];
        yield 'new' => [$ns . "new clickcookie();\n", ['App\Http\clickcookie']];
        yield 'a switch arm' => [
            $ns . "switch (\\$x) { case clickCookie::LEGACY: break; }\n",
            ['App\Http\clickCookie'],
        ];
        yield 'a static call' => [$ns . "CLICKCOOKIE::value([], 'x');\n", ['App\Http\CLICKCOOKIE']];
        yield '::class' => [$ns . "\$c = Clickcookie::class;\n", ['App\Http\Clickcookie']];
        yield 'instanceof' => [$ns . "\$x instanceof clickCookie;\n", ['App\Http\clickCookie']];
        yield 'extends' => [$ns . "class A extends clickCookie {}\n", ['App\Http\clickCookie']];
        yield 'implements, second' => [
            $ns . "class A implements \\Countable, clickCookie {}\n",
            ['App\Http\clickCookie'],
        ];
        yield 'a trait, second' => [$ns . "class A { use Other, clickCookie; }\n", ['App\Http\clickCookie']];
        yield 'catch, second' => [$ns . "try {} catch (\\Exception | clickCookie \$e) {}\n", ['App\Http\clickCookie']];
        yield 'catch, no variable' => [$ns . "try {} catch (clickCookie) {}\n", ['App\Http\clickCookie']];
        yield 'a parameter' => [$ns . "function f(clickCookie \$c) {}\n", ['App\Http\clickCookie']];
        yield 'a nullable parameter' => [$ns . "function f(?clickCookie \$c) {}\n", ['App\Http\clickCookie']];
        yield 'a union' => [$ns . "function f(int|clickCookie \$c) {}\n", ['App\Http\clickCookie']];
        yield 'an intersection, first' => [
            $ns . "function f(clickCookie&\\Countable \$c) {}\n",
            ['App\Http\clickCookie'],
        ];
        yield 'by reference' => [$ns . "function f(clickCookie &\$c) {}\n", ['App\Http\clickCookie']];
        yield 'variadic' => [$ns . "function f(clickCookie ...\$c) {}\n", ['App\Http\clickCookie']];
        yield 'a return type' => [$ns . "function f(): clickCookie {}\n", ['App\Http\clickCookie']];
        yield 'a nullable return type' => [$ns . "\$f = fn (): ?clickCookie => null;\n", ['App\Http\clickCookie']];
        yield 'a property' => [$ns . "class A { private readonly clickCookie \$c; }\n", ['App\Http\clickCookie']];
        yield 'a constant\'s type' => [$ns . "class A { const clickCookie C = null; }\n", ['App\Http\clickCookie']];
        yield 'an attribute' => [$ns . "#[clickCookie]\nfunction f() {}\n", ['App\Http\clickCookie']];
        yield 'a string' => ["<?php\nclass_exists('App\\\\Http\\\\clickcookie');\n", ['App\Http\clickcookie']];
        yield 'a double-quoted string' => [
            "<?php\nclass_exists(\"App\\\\Http\\\\clickcookie\");\n",
            ['App\Http\clickcookie'],
        ];

        yield 'an alias in another case: the import is what loads' => [
            "<?php\nnamespace Site;\nuse App\\Http\\ClickCookie;\nCLICKCOOKIE::value([], 'x');\n",
            [],
        ];
        yield 'either spelling of a narrower prefix' => [
            "<?php\nnew \\App\\Setup\\PageController();\nnew \\App\\setup\\PageController();\n",
            [],
        ];
        yield 'a call, a member, a constant and a builtin type' => [
            $ns . "clickcookie(); \$x->clickcookie; const CLICKCOOKIE = 1; function f(int \$a): string {}\n",
            [],
        ];
    }

    /**
     * @dataProvider writtenNames
     * @param list<string> $expected
     */
    public function testAClassWrittenInAnotherCaseIsReported(string $code, array $expected): void
    {
        $loadable = ReleaseTree::autoloadableNames(self::fixture());

        $problems = ReleaseTree::classNameCaseProblems(['page.php' => $code], $loadable);

        self::assertCount(count($expected), $problems, implode("\n", $problems));
        foreach ($expected as $i => $name) {
            self::assertMatchesRegularExpression(
                '/^page\.php:\d+ writes ' . preg_quote($name, '/') . ', which the autoloader loads only as /',
                $problems[$i]
            );
        }
    }

    private static function fixture(): string
    {
        if (self::$fixture !== null) {
            return self::$fixture;
        }
        $root = sys_get_temp_dir() . '/p202-class-case-' . bin2hex(random_bytes(6));
        $files = [
            'vendor/composer/autoload_psr4.php' => "<?php\n\$baseDir = dirname(__DIR__, 2);\nreturn [\n"
                . "    'App\\\\' => [\$baseDir . '/src'],\n"
                . "    'App\\\\Setup\\\\' => [\$baseDir . '/src/setup'],\n"
                . "];\n",
            'vendor/composer/autoload_namespaces.php' =>
                "<?php\nreturn ['Old' => [dirname(__DIR__, 2) . '/old/lib']];\n",
            'vendor/composer/autoload_classmap.php' =>
                "<?php\nreturn ['Mapped_Legacy' => dirname(__DIR__) . '/legacy.php'];\n",
            'src/Http/ClickCookie.php' => "<?php\nnamespace App\\Http;\nfinal class ClickCookie {}\n",
            'src/setup/PageController.php' => "<?php\nnamespace App\\Setup;\nfinal class PageController {}\n",
            'src/setup/class-page.php' => "<?php\n",
            'old/lib/Old/Thing.php' => "<?php\nnamespace Old;\nfinal class Thing {}\n",
        ];
        foreach ($files as $path => $contents) {
            $full = "{$root}/{$path}";
            if (!is_dir(dirname($full)) && !mkdir(dirname($full), 0755, true)) {
                self::fail('cannot create ' . dirname($full));
            }
            if (file_put_contents($full, $contents) === false) {
                self::fail("cannot write {$full}");
            }
        }

        return self::$fixture = $root;
    }

    private static function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $child) {
            self::remove("{$path}/{$child}");
        }
        rmdir($path);
    }
}
