<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * documentation/ has no dangling links, and its index lists every page.
 *
 * At the release gate four pages were reachable from nowhere — the Coolify
 * deployment guide, the visitor-identity feature page, the measurement
 * rewrite plan and an earlier edition of Step 7 — because nothing checked
 * the index against the tree. Links are read in the three forms the pages
 * use: inline (`[text](path)` and `![alt](path)`), reference definitions
 * (`[id]: path`) and HTML (`href="…"`, `src="…"`). Fenced code blocks are
 * skipped, since a path in an example is not a link. External URLs and
 * in-page anchors are not files and are not checked here; a link's own
 * `#fragment` is dropped before the file is looked for.
 */
final class DocumentationLinksTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> absolute paths of every page under documentation/ */
    private static function pages(): array
    {
        $pages = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/documentation', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (strtolower($file->getExtension()) === 'md') {
                $pages[] = $file->getPathname();
            }
        }
        sort($pages);

        return $pages;
    }

    /** @return list<string> the link targets a page names, as written */
    private static function targets(string $markdown): array
    {
        $text = (string) preg_replace('/^(```|~~~).*?^\1[^\n]*$/ms', '', $markdown);
        $targets = [];
        // Inline links and images: [text](target "title") — the target may be
        // wrapped in <…> when it holds spaces.
        preg_match_all('/\]\(\s*(<[^>]+>|[^)\s]+)(?:\s+"[^"]*")?\s*\)/', $text, $m);
        array_push($targets, ...$m[1]);
        // Reference definitions: [id]: target
        preg_match_all('/^\s{0,3}\[[^\]]+\]:\s*(<[^>]+>|\S+)/m', $text, $m);
        array_push($targets, ...$m[1]);
        // HTML attributes.
        preg_match_all('/\b(?:href|src)\s*=\s*"([^"]+)"/i', $text, $m);
        array_push($targets, ...$m[1]);

        return array_map(static fn(string $t): string => trim($t, '<>'), $targets);
    }

    /** The file a relative target names, or null for an external URL or an in-page anchor. */
    private static function resolve(string $page, string $target): ?string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1 || str_starts_with($target, '#') || str_starts_with($target, '//')) {
            return null;
        }
        $path = rawurldecode(explode('#', explode('?', $target)[0])[0]);
        if ($path === '') {
            return null;
        }
        $base = str_starts_with($path, '/') ? self::root() : dirname($page);

        return $base . '/' . ltrim($path, '/');
    }

    public function testNoPageLinksToAFileThatIsNotThere(): void
    {
        $pages = self::pages();
        self::assertGreaterThan(50, count($pages));
        $dangling = [];
        $checked = 0;
        foreach ($pages as $page) {
            foreach (self::targets((string) file_get_contents($page)) as $target) {
                $file = self::resolve($page, $target);
                if ($file === null) {
                    continue;
                }
                $checked++;
                if (!file_exists($file)) {
                    $dangling[] = substr($page, strlen(self::root()) + 1) . ' -> ' . $target;
                }
            }
        }
        // A reader that stopped finding links would pass on nothing.
        self::assertGreaterThan(250, $checked, 'links checked');
        self::assertSame([], $dangling, "links to files that do not exist:\n  " . implode("\n  ", $dangling));
    }

    public function testTheIndexListsEveryPage(): void
    {
        $index = self::root() . '/documentation/README.md';
        $listed = [];
        foreach (self::targets((string) file_get_contents($index)) as $target) {
            $file = self::resolve($index, $target);
            if ($file !== null && is_file($file)) {
                $listed[(string) realpath($file)] = true;
            }
        }

        $unlisted = [];
        foreach (self::pages() as $page) {
            if ($page === $index) {
                continue;
            }
            if (!isset($listed[(string) realpath($page)])) {
                $unlisted[] = substr($page, strlen(self::root()) + 1);
            }
        }

        self::assertSame([], $unlisted, "pages documentation/README.md does not link to:\n  " . implode("\n  ", $unlisted));
    }

    /**
     * The reader itself, on the shapes it claims to read and the ones it
     * must not count.
     */
    public function testTheLinkReaderReadsEachForm(): void
    {
        $markdown = "[a](one.md) ![b](img/two.png \"t\") [c](<with space.md>)\n"
            . "[ref]: three.md\n<a href=\"four.md\">x</a> <img src=\"five.png\">\n"
            . "```\n[not](six.md)\n```\n[ext](https://example.com/x.md) [anchor](#here)";
        self::assertEqualsCanonicalizing(['one.md', 'img/two.png', 'with space.md', 'three.md', 'four.md', 'five.png', 'https://example.com/x.md', '#here'],
            self::targets($markdown));
        self::assertNull(self::resolve('/r/documentation/p.md', 'https://example.com/x.md'));
        self::assertNull(self::resolve('/r/documentation/p.md', '#here'));
        self::assertSame('/r/documentation/api/x.md', self::resolve('/r/documentation/p.md', 'api/x.md#section'));
    }
}
