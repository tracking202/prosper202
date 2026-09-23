<?php

declare(strict_types=1);

namespace Tests\Release;

use P202Build\ReleaseTree;
use Tests\TestCase;

/**
 * The release zip is extracted straight into the web root of hosts with no
 * terminal, so what it carries is decided by build/release-manifest.php and
 * enforced by build/scripts/release-tree.php. These tests hold the checker
 * itself to account: a classifier that passes everything, or a resolver that
 * exits before reporting, would let a broken or bloated zip ship green.
 */
final class ReleaseTreeTest extends TestCase
{
    private const REPO = __DIR__ . '/../..';

    /** @var list<string> */
    private array $tempDirs = [];

    public static function setUpBeforeClass(): void
    {
        require_once self::REPO . '/build/scripts/ReleaseTree.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        parent::tearDown();
    }

    public function testEveryTrackedTopLevelPathIsClassified(): void
    {
        if (!is_dir(self::REPO . '/.git') && !is_file(self::REPO . '/.git')) {
            self::markTestSkipped('not a git checkout');
        }
        [$status, $output] = $this->runCommand(
            [PHP_BINARY, self::REPO . '/build/scripts/release-tree.php', 'check-manifest']
        );

        self::assertSame(0, $status, "release-tree check-manifest reported problems:\n{$output}");
        self::assertStringContainsString('release-tree check-manifest: ok', $output);
    }

    public function testManifestShapeLoads(): void
    {
        $tree = ReleaseTree::load(self::REPO . '/build/release-manifest.php');

        // The app itself and its bundled dependencies must ship; tests must not.
        self::assertSame([], $tree->classify(['index.php', 'tracking202', 'vendor', 'tests']));
    }

    public function testUnclassifiedAndDoublyClassifiedEntriesAreReported(): void
    {
        $tree = new ReleaseTree($this->manifest(
            ship: ['index.php', 'docs*'],
            exclude: ['docs-internal', 'notes-*.md'],
        ));

        $problems = $tree->classify(['index.php', 'notes-1.md', 'docs-internal', 'surprise.md']);

        self::assertCount(2, $problems, implode("\n", $problems));
        self::assertStringContainsString("'docs-internal' matches both", $problems[0]);
        self::assertStringContainsString("'surprise.md' is not classified", $problems[1]);
    }

    public function testDotfilesNeedAnExplicitPattern(): void
    {
        // FNM_PERIOD: '*' must not quietly classify .env or .git-anything.
        $tree = new ReleaseTree($this->manifest(ship: ['*']));

        self::assertCount(1, $tree->classify(['.env']));
    }

    public function testCheckTrackedReportsPathsTheManifestMisspells(): void
    {
        $tree = new ReleaseTree($this->manifest(
            ship: ['202-config', 'go-cli'],
            keepOnly: ['go-cli' => ['dist'], 'go-ci' => ['dist']],
            excludeNested: ['202-config/PHPStan', '202-config/PHPstan'],
        ));

        $problems = $tree->checkTracked(['202-config/PHPStan/Rules/A.php', '202-config/x.php', 'go-cli/main.go']);

        self::assertCount(2, $problems, implode("\n", $problems));
        self::assertStringContainsString("'202-config/PHPstan'", $problems[0]);
        self::assertStringContainsString("'go-ci'", $problems[1]);
    }

    public function testPruneRefusesAnUnclassifiedTreeBeforeDeletingAnything(): void
    {
        $stage = $this->tree(['index.php' => '', 'tests/a.php' => '', 'new-thing.md' => '']);
        $tree = new ReleaseTree($this->manifest(ship: ['index.php'], exclude: ['tests']));

        $problems = $tree->prune($stage);

        self::assertCount(1, $problems);
        self::assertStringContainsString("'new-thing.md' is not classified", $problems[0]);
        self::assertFileExists("{$stage}/tests/a.php", 'prune deleted files although it refused the tree');
    }

    public function testPruneRemovesExactlyWhatTheManifestExcludes(): void
    {
        $stage = $this->tree([
            'index.php' => '',
            'task-plan-x.md' => '',
            'tests/a.php' => '',
            '202-config/connect.php' => '',
            '202-config/PHPStan/Rules/R.php' => '',
            '202-config/Messaging/mock-server.php' => '',
            '202-config/Messaging/MessagingClient.class.php' => '',
            'go-cli/main.go' => '',
            'go-cli/.ax-session/state' => '',
            'go-cli/dist/linux-amd64/p202' => 'bin',
            '.claude/settings.json' => '',
            '.claude/skills/onboard-prosper202/SKILL.md' => '',
            '.claude/skills/p202-verify/SKILL.md' => '',
        ]);
        $tree = new ReleaseTree($this->manifest(
            ship: ['index.php', '202-config', 'go-cli', '.claude'],
            exclude: ['tests', 'task-plan-*.md'],
            keepOnly: ['.claude' => ['skills'], '.claude/skills' => ['onboard-prosper202'], 'go-cli' => ['dist']],
            excludeNested: ['202-config/PHPStan', '202-config/Messaging/mock-server.php'],
        ));

        self::assertSame([], $tree->prune($stage));

        self::assertSame([
            '.claude/skills/onboard-prosper202/SKILL.md',
            '202-config/Messaging/MessagingClient.class.php',
            '202-config/connect.php',
            'go-cli/dist/linux-amd64/p202',
            'index.php',
        ], $this->files($stage));
    }

    /**
     * Git tracks tracking202/Redirect/ beside tracking202/redirect/. Staged on
     * a case-insensitive filesystem the two merge and the click endpoints land
     * in Redirect/, which a Linux host serves as 404s. A file that is in the
     * tree only under a different case must count as missing, on every OS.
     */
    public function testVerifyReportsTrackedFilesNotAtTheirExactPath(): void
    {
        $stage = $this->tree([
            'app/Redirect/Helper.php' => '',
            'app/Redirect/dl.php' => '',   // tracked as app/redirect/dl.php
            'app/index.php' => '',
        ]);
        $tree = new ReleaseTree($this->manifest(ship: ['app'], exclude: ['tests']));

        $problems = $tree->verify($stage, [
            'app/Redirect/Helper.php',
            'app/redirect/dl.php',
            'app/index.php',
            'tests/SomeTest.php',          // excluded: never expected in the tree
        ]);

        $paths = preg_grep('/^tracked file/', $problems);
        self::assertSame(
            ["tracked file 'app/redirect/dl.php' is not in the release tree at that exact path"
                . ' (built on a case-insensitive filesystem?)'],
            array_values($paths),
            implode("\n", $problems)
        );
    }

    public function testShipsFollowsTheSameRulesAsPrune(): void
    {
        $tree = new ReleaseTree($this->manifest(
            ship: ['202-config', 'go-cli', '.claude'],
            exclude: ['tests'],
            keepOnly: ['.claude' => ['skills'], '.claude/skills' => ['onboard-prosper202'], 'go-cli' => ['dist']],
            excludeNested: ['202-config/PHPStan'],
        ));

        self::assertTrue($tree->ships('202-config/connect.php'));
        self::assertTrue($tree->ships('.claude/skills/onboard-prosper202/SKILL.md'));
        self::assertFalse($tree->ships('tests/A.php'));
        self::assertFalse($tree->ships('202-config/PHPStan/Rules/R.php'));
        self::assertFalse($tree->ships('go-cli/main.go'));
        self::assertFalse($tree->ships('.claude/skills/p202-verify/SKILL.md'));
        self::assertFalse($tree->ships('unlisted.md'));
    }

    public function testReferencesInFindsImportsAndFullyQualifiedNames(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;

            use Vendor\Pkg\Thing;
            use Vendor\Pkg\Other as Alias;
            use Group\Pre\{A, B as BAlias, function helper, const LIMIT,};
            use function Fn\Space\call;
            use const Const\Space\MAX;

            #[\Attr\Marker(1)]
            final class C
            {
                use SomeTrait;

                public function f(\Hint\Type $t): void
                {
                    $x = new \Made\Here(1);
                    \Static\Call::go();
                    \Ns\fn_call();
                    $cb = function () use ($x) { return $x; };
                }
            }
            PHP;

        $refs = array_map(static fn (array $r): string => "{$r[1]} {$r[0]}", ReleaseTree::referencesIn($code));
        sort($refs);

        self::assertSame([
            'class Attr\Marker',
            'class Group\Pre\A',
            'class Group\Pre\B',
            'class Hint\Type',
            'class Made\Here',
            'class Static\Call',
            'class Vendor\Pkg\Other',
            'class Vendor\Pkg\Thing',
            'const Const\Space\MAX',
            'const Group\Pre\LIMIT',
            'function Fn\Space\call',
            'function Group\Pre\helper',
            'function Ns\fn_call',
        ], $refs);
    }

    public function testDeclarationsInFindsEveryKindAndSkipsClassConstants(): void
    {
        $code = <<<'PHP'
            <?php
            namespace A\B;
            final class C {}
            interface I {}
            trait T {}
            enum E: string {}
            readonly class R {}
            $x = C::class;
            $y = new class {};
            PHP;

        self::assertSame(
            ['A\B\C', 'A\B\I', 'A\B\T', 'A\B\E', 'A\B\R'],
            ReleaseTree::declarationsIn($code)
        );
        self::assertSame(
            ['G', 'N\In'],
            ReleaseTree::declarationsIn("<?php\nclass G {}\nnamespace N { class In {} }\n")
        );
    }

    public function testTraitUseInsideABracketedNamespaceIsNotAnImport(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App {
                use Real\Import;
                class C { use Trait\NotImport; }
            }
            PHP;

        $names = array_column(ReleaseTree::referencesIn($code), 0);

        self::assertSame(['Real\Import'], $names);
    }

    /**
     * Runs the real child process. A shipped class file that includes the
     * bootstrap exits the process when loaded; the resolver once did exactly
     * that and reported nothing, so it must locate files without loading them.
     */
    public function testVerifyReportsNamesTheShippedAutoloaderCannotResolve(): void
    {
        $classLoader = realpath(self::REPO . '/vendor/composer/ClassLoader.php');
        if ($classLoader === false) {
            self::markTestSkipped('vendor/composer/ClassLoader.php is not installed');
        }
        $stage = $this->tree([
            'vendor/autoload.php' => "<?php\n"
                . "if (!class_exists(\\Composer\\Autoload\\ClassLoader::class, false)) {\n"
                . '    require ' . var_export($classLoader, true) . ";\n"
                . "}\n"
                . "\$loader = new \\Composer\\Autoload\\ClassLoader();\n"
                . "\$loader->addPsr4('App\\\\', dirname(__DIR__) . '/src/');\n"
                . "\$loader->addPsr4('Acme\\\\', __DIR__ . '/acme/src/');\n"
                . "\$loader->register();\n"
                . "return \$loader;\n",
            'src/Present.php' => "<?php\nnamespace App;\nfinal class Present {}\n",
            'src/Bootstraps.php' => "<?php\nexit(0);\n",
            'index.php' => "<?php\n"
                . "use App\\Present;\n"
                . "use App\\Bootstraps;\n"
                . "use App\\Missing;\n"
                . "use Gone\\Package\\Thing;\n"
                . "new \\Tolerated\\Legacy();\n",
            'bin/tool' => "#!/usr/bin/env php\n<?php\n\\Cli\\Absent::run();\n",
            // A vendor class only the autoloader can answer for. PSR-4 maps
            // Acme\\ to vendor/acme/src/ but the file is in Src/: on a
            // case-insensitive disk (macOS) is_file() finds it anyway; Linux
            // does not, so the verifier must not either.
            'vendor/acme/Src/Widget.php' => "<?php\nnamespace Acme;\nfinal class Widget {}\n",
            'case.php' => "<?php\nuse Acme\\Widget;\n",
            // Declared in the shipped tree but in no PSR-4 directory, like the
            // page controllers beside their pages: present, so resolved.
            'pages/controller.php' => "<?php\nnamespace App\\Pages;\nfinal class Controller {}\n",
            'page.php' => "<?php\nrequire_once __DIR__ . '/pages/controller.php';\nnew \\App\\Pages\\Controller();\n",
        ]);
        $tree = new ReleaseTree($this->manifest(
            ship: ['index.php', 'src', 'vendor', 'bin', 'case.php', 'pages', 'page.php'],
            knownUnresolved: ['Tolerated\Legacy' => 'test', 'Long\Fixed' => 'test'],
        ));

        $problems = $tree->verify($stage);

        self::assertSame([], preg_grep('/did not finish/', $problems), implode("\n", $problems));
        $unresolved = [];
        foreach ($problems as $problem) {
            $pattern = "/^'([^']+)' \\(referenced at ([^)]+)\\) is not declared in the shipped code/";
            if (preg_match($pattern, $problem, $m) === 1) {
                $unresolved[$m[1]] = $m[2];
            }
        }
        ksort($unresolved);
        self::assertSame([
            'Acme\Widget' => 'case.php:2',
            'App\Missing' => 'index.php:4',
            'Cli\Absent' => 'bin/tool:3',
            'Gone\Package\Thing' => 'index.php:5',
        ], $unresolved);
        self::assertNotEmpty(
            preg_grep("/'known_unresolved' lists 'Long\\\\Fixed', which no longer fails to resolve/", $problems),
            'a stale known_unresolved entry must be reported so the list shrinks'
        );
    }

    /**
     * @param list<string> $ship
     * @param list<string> $exclude
     * @param array<string, list<string>> $keepOnly
     * @param list<string> $excludeNested
     * @param array<string, string> $knownUnresolved
     * @return array{
     *     ship: list<string>,
     *     exclude: list<string>,
     *     keep_only: array<string, list<string>>,
     *     exclude_nested: list<string>,
     *     go_binaries: list<string>,
     *     known_unresolved: array<string, string>
     * }
     */
    private function manifest(
        array $ship = [],
        array $exclude = [],
        array $keepOnly = [],
        array $excludeNested = [],
        array $knownUnresolved = [],
    ): array {
        return [
            'ship' => $ship,
            'exclude' => $exclude,
            'keep_only' => $keepOnly,
            'exclude_nested' => $excludeNested,
            'go_binaries' => [],
            'known_unresolved' => $knownUnresolved,
        ];
    }

    /** @param array<string, string> $files relative path => contents */
    private function tree(array $files): string
    {
        $root = sys_get_temp_dir() . '/p202-release-tree-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $root;
        foreach ($files as $path => $contents) {
            $full = "{$root}/{$path}";
            if (!is_dir(dirname($full)) && !mkdir(dirname($full), 0755, true)) {
                self::fail("cannot create " . dirname($full));
            }
            if (file_put_contents($full, $contents) === false) {
                self::fail("cannot write {$full}");
            }
        }
        if (!is_dir($root) && !mkdir($root, 0755, true)) {
            self::fail("cannot create {$root}");
        }

        return $root;
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
        sort($files);

        return $files;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $child) {
            $this->removeTree("{$path}/{$child}");
        }
        rmdir($path);
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $command): array
    {
        $log = tempnam(sys_get_temp_dir(), 'p202-release-tree-test-');
        self::assertIsString($log);
        $process = proc_open($command, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        self::assertIsResource($process);
        $status = proc_close($process);
        $output = (string) file_get_contents($log);
        unlink($log);

        return [$status, $output];
    }
}
