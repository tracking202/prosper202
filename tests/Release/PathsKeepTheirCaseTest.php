<?php

declare(strict_types=1);

namespace Tests\Release;

use P202Build\ReleaseTree;
use PHPUnit\Framework\TestCase;
use Prosper202\Http\NotFoundPage;
use Tests\Support\SourceScan;

/**
 * A path written in another case than the file it names is found on a Mac and
 * missing on Linux, where installs run (#250). Every path the shipped code
 * writes (an include, a link, an asset) must spell the file or directory as
 * the tree does. release-tree.php verify asks the same of the zip.
 */
final class PathsKeepTheirCaseTest extends TestCase
{
    private const REPO = __DIR__ . '/../..';

    /** The files the planted sources below name. */
    private const PATHS = [
        '202-config/Http/ClickCookie.php',
        '202-config/connect.php',
        '202-img/flags/pm.png',
        '202-js/p202-ui.js',
        'tracking202/Analyze/keywords.php',
        'tracking202/Setup/aff_campaigns.php',
    ];

    public static function setUpBeforeClass(): void
    {
        require_once self::REPO . '/build/scripts/ReleaseTree.php';
    }

    public function testShippedCodeWritesEveryPathInTheCaseOnDisk(): void
    {
        if (!is_dir(self::REPO . '/.git') && !is_file(self::REPO . '/.git')) {
            self::markTestSkipped('not a git checkout');
        }
        $tree = ReleaseTree::load(self::REPO . '/build/release-manifest.php');
        $tracked = ReleaseTree::trackedFiles(self::REPO);
        $paths = $tracked;
        if (is_dir(self::REPO . '/vendor')) {
            foreach (new \RecursiveIteratorIterator(SourceScan::tree(self::REPO . '/vendor')) as $file) {
                /** @var \SplFileInfo $file */
                $paths[] = 'vendor/' . substr($file->getPathname(), strlen(self::REPO . '/vendor/'));
            }
        }
        $sources = [];
        foreach ($tracked as $path) {
            $text = $tree->ships($path) ? ReleaseTree::codeText(self::REPO . '/' . $path) : null;
            if ($text !== null) {
                $sources[$path] = $text;
            }
        }

        self::assertGreaterThan(500, count($sources), 'the shipped code was not read');
        self::assertSame([], ReleaseTree::pathCaseProblems($sources, $paths));
    }

    /**
     * The 404 names the folders 1.9.79 renamed by their old spellings, which
     * no other file may write.
     */
    public function testTheOldSpellingsAreWrittenOnlyWhereTheyAreAllowed(): void
    {
        $source = "<?php // tracking202/analyze/keywords.php\n"
            . "const MOVED = ['tracking202/setup' => 'tracking202/Setup'];";
        $notFound = '202-config/Http/NotFoundPage.php';

        self::assertSame([], ReleaseTree::pathCaseProblems([$notFound => $source], self::PATHS));
        self::assertCount(2, ReleaseTree::pathCaseProblems(['page.php' => $source], self::PATHS));
        self::assertCount(
            1,
            ReleaseTree::pathCaseProblems([$notFound => "<?php require '202-config/Connect.php';"], self::PATHS),
            'another path the 404 writes is held as any other file\'s'
        );
    }

    public function testTheAllowanceNamesWhatTheNotFoundPageMaps(): void
    {
        self::assertSame(
            array_keys(NotFoundPage::MOVED),
            ReleaseTree::PATHS_IN_ANOTHER_CASE['202-config/Http/NotFoundPage.php']['prefixes']
        );
    }

    /** @return iterable<string, array{string, array<string, string>}> source, written => on disk */
    public static function writtenPaths(): iterable
    {
        yield 'an include through __DIR__' => [
            "<?php require_once __DIR__ . '/../../202-config/http/ClickCookie.php';",
            ['202-config/http/ClickCookie.php' => '202-config/Http/ClickCookie.php'],
        ];
        yield 'an include from the install root' => [
            "<?php include_once substr(__DIR__, 0, -21) . '/tracking202/setup/aff_campaigns.php';",
            ['tracking202/setup/aff_campaigns.php' => 'tracking202/Setup/aff_campaigns.php'],
        ];
        yield 'an interpolated include' => [
            '<?php require "$root/202-config/Connect.php";',
            ['202-config/Connect.php' => '202-config/connect.php'],
        ];
        yield 'a link' => [
            '<a href="/tracking202/analyze/keywords.php">Keywords</a>',
            ['tracking202/analyze/keywords.php' => 'tracking202/Analyze/keywords.php'],
        ];
        yield 'a directory' => [
            "<?php header('Location: /tracking202/setup/');",
            ['tracking202/setup' => 'tracking202/Setup'],
        ];
        yield 'a script' => [
            '<script src="/202-js/P202-ui.js"></script>',
            ['202-js/P202-ui.js' => '202-js/p202-ui.js'],
        ];
        yield 'an image in a stylesheet' => [
            '.flag { background: url(../202-img/Flags/pm.png); }',
            ['202-img/Flags/pm.png' => '202-img/flags/pm.png'],
        ];
        yield 'a file name alone' => [
            "<?php \$file = 'ClickCookie.PHP';",
            ['ClickCookie.PHP' => 'ClickCookie.php'],
        ];
        yield 'written as on disk' => [
            "<?php require_once __DIR__ . '/../../202-config/Http/ClickCookie.php'; // tracking202/Setup/",
            [],
        ];
        yield 'a word, not a path' => ['<?php // Setup the Http client', []];
        yield 'a word at the end of another host\'s path' => ['<a href="https://example.com/docs/Setup">', []];
    }

    /**
     * @dataProvider writtenPaths
     * @param array<string, string> $expected
     */
    public function testAPathInAnotherCaseIsReported(string $source, array $expected): void
    {
        $problems = ReleaseTree::pathCaseProblems(['page.php' => $source], self::PATHS);

        self::assertCount(count($expected), $problems, implode("\n", $problems));
        foreach ($expected as $written => $onDisk) {
            self::assertStringStartsWith("page.php:1 writes '{$written}', which is '{$onDisk}' on disk", $problems[0]);
        }
    }
}
