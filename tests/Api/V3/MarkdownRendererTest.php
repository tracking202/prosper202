<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * The in-app documentation viewer renders what the documents it serves use.
 *
 * `202-account/docs.php` serves four documents from `documentation/` through
 * `markdownToHtml()`. The renderer it had handled headings, code, links,
 * emphasis and `-` lists, so the pipe tables and numbered lists in those
 * documents reached the reader as literal `|` characters and list numbers
 * run together in one blob, and a `<body>` inside a code span was emitted as
 * a tag. This test pins the constructs the served documents actually
 * contain, and fails when a document starts using one the renderer drops.
 */
final class MarkdownRendererTest extends TestCase
{
    /** The documents docs.php exposes, from its own allowlist. */
    private const SERVED = [
        'documentation/tutorials-and-guides/14-advanced-attribution-engine.md',
        'documentation/tutorials-and-guides/15-advanced-attribution-troubleshooting.md',
        'documentation/api/00-api-integrations.md',
        'documentation/features/ui-standard.md',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/202-config/markdown.php';
    }

    public function testTheAllowlistInDocsPhpMatchesTheDocumentsCheckedHere(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root . '/202-account/docs.php');
        self::assertSame(1, preg_match('/\$allowed_docs = \[(.*?)\];/s', $source, $match), 'docs.php declares its allowlist');
        self::assertGreaterThan(0, preg_match_all("/=>\s*'([^']+\.md)'/", $match[1], $paths), 'the allowlist names .md files');
        $served = $paths[1];
        sort($served);
        $checked = self::SERVED;
        sort($checked);
        self::assertSame($checked, $served, 'every document docs.php serves is checked here');
        foreach ($served as $path) {
            self::assertFileExists($root . '/' . $path);
        }
    }

    public function testNoServedDocumentLeavesMarkupForTheReader(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (self::SERVED as $path) {
            $html = markdownToHtml((string) file_get_contents($root . '/' . $path));
            self::assertSame(0, preg_match_all('~<p>[^<]{0,40}\|~', $html), "$path: a table row reached the reader as text");
            self::assertSame(0, preg_match_all('~<p>\s*\d+\.\s~', $html), "$path: a numbered list item reached the reader as text");
            self::assertSame(0, preg_match_all('~<p>\s*[-*+]\s~', $html), "$path: a bullet reached the reader as text");
            self::assertStringNotContainsString('```', $html, "$path: a code fence reached the reader as text");
            self::assertSame(0, preg_match_all("~\x02P\\d+\x03~", $html), "$path: an internal placeholder was not restored");
            self::assertGreaterThan(0, substr_count($html, '<p>'), "$path: prose is wrapped in paragraphs");
        }
    }

    public function testTheConstructsTheDocumentsUse(): void
    {
        $html = markdownToHtml(<<<'MD'
            # Title

            Some prose that runs
            across two lines.

            | Class | Purpose |
            |---|---|
            | `.a` | First |
            | `.b` | Second |

            1. First item, whose text
               continues on the next line.
            2. Second item.

            - A bullet
            - Another

            ```php
            $x = ['ui' => 'v2'];
            ```

            Prose with `<body>` in a code span, a [link](https://example.test) and **bold**.
            MD);

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<p>Some prose that runs across two lines.</p>', $html, 'wrapped lines join into one paragraph');
        self::assertStringContainsString('<table class="doc-table"><thead><tr><th>Class</th><th>Purpose</th></tr></thead>', $html);
        self::assertSame(2, substr_count($html, '<tr><td>'), 'both body rows');
        self::assertStringContainsString('<ol><li>First item, whose text continues on the next line.</li><li>Second item.</li></ol>', $html);
        self::assertStringContainsString('<ul><li>A bullet</li><li>Another</li></ul>', $html);
        self::assertStringContainsString("<pre><code class=\"language-php\">\$x = ['ui' =&gt; 'v2'];</code></pre>", $html, 'code is escaped and keeps its language');
        self::assertStringContainsString('<code>&lt;body&gt;</code>', $html, 'a tag inside a code span is escaped, not emitted');
        self::assertStringContainsString('<a href="https://example.test">link</a>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testAsterisksInsideTwoCodeSpansOnOneLineDoNotBecomeEmphasis(): void
    {
        $html = markdownToHtml('Scope with `p202-section-*` or `p202-sub-*` instead.');
        self::assertStringContainsString('<code>p202-section-*</code>', $html);
        self::assertStringContainsString('<code>p202-sub-*</code>', $html);
        self::assertStringNotContainsString('<em>', $html, 'the asterisks belong to the code spans');
    }

    public function testEmphasisStillWorksOutsideCode(): void
    {
        self::assertStringContainsString('<em>emphasised</em>', markdownToHtml('A word that is *emphasised* here.'));
        self::assertStringContainsString('<strong>strong</strong>', markdownToHtml('A word that is **strong** here.'));
    }

    public function testAListSurvivesABlankLineBetweenItemsAndEndsAtProse(): void
    {
        $html = markdownToHtml("1. One\n\n2. Two\n\nAfterwards prose.\n");
        self::assertStringContainsString('<ol><li>One</li><li>Two</li></ol>', $html, 'a blank line between items keeps one list');
        self::assertStringContainsString('<p>Afterwards prose.</p>', $html, 'prose after the list is its own paragraph');

        $mixed = markdownToHtml("- Bullet\n\n1. Number\n");
        self::assertStringContainsString('<ul><li>Bullet</li></ul>', $mixed, 'a numbered item starts a new list');
        self::assertStringContainsString('<ol><li>Number</li></ol>', $mixed);
    }

    public function testThePaid1956NotesRenderWholeAndTheirLinksLead(): void
    {
        $root = dirname(__DIR__, 3);
        $path = \Prosper202\Update\PaidOrigin::NOTES_FILE;
        self::assertFileExists($root . '/' . $path);
        self::assertFileExists($root . '/' . \Prosper202\Update\PaidOrigin::NOTES_PAGE);
        $pages = ['202-config/upgrade.php', '202-account/help.php', '202-account/auto-upgrade.php',
            '202-account/auto-upgrade-premium.php'];
        foreach ($pages as $page) {
            self::assertStringContainsString(
                'p202_came_from_paid_1956($db)',
                (string) file_get_contents($root . '/' . $page),
                "$page links the notes for an install that came from paid 1.9.56"
            );
        }
        $html = markdownDocLinks(markdownToHtml((string) file_get_contents($root . '/' . $path)), $path);

        self::assertSame(0, preg_match_all('~<p>\s*[-*+]\s~', $html), 'a bullet reached the reader as text');
        self::assertSame(0, preg_match_all('~<p>[^<]{0,40}\|~', $html), 'a table row reached the reader as text');
        self::assertStringNotContainsString('```', $html);
        preg_match_all('~<a href="([^"]*)"~', $html, $links);
        self::assertNotSame([], $links[1]);
        foreach ($links[1] as $href) {
            if ($href[0] === '#') {
                $id = ' id="' . substr($href, 1) . '"';
                self::assertStringContainsString($id, $html, "$href names a heading on the page");
                continue;
            }
            self::assertMatchesRegularExpression('~^https://~', $href, 'any other link leaves the install');
            $prefix = 'https://github.com/tracking202/prosper202/blob/master/';
            if (str_starts_with($href, $prefix)) {
                $file = explode('#', substr($href, strlen($prefix)))[0];
                self::assertFileExists($root . '/' . $file, "$href names a file of the repository");
            }
        }
    }

    public function testDocumentLinksAreResolvedFromTheDocumentsOwnDirectory(): void
    {
        $html = markdownDocLinks(
            markdownToHtml("## Not working yet, after `1.9.80`\n\nSee [PurLinks](../features/purlinks.md#snippet),"
                . " [this page](#not-working-yet-after-1980) and [the site](https://example.com/a.md)."),
            'documentation/upgrading/from-paid-1.9.56.md'
        );

        self::assertStringContainsString('<h2 id="not-working-yet-after-1980">', $html);
        self::assertStringContainsString(
            '<a href="https://github.com/tracking202/prosper202/blob/master/documentation/features/purlinks.md#snippet"'
            . ' target="_blank" rel="noopener">',
            $html
        );
        self::assertStringContainsString('<a href="#not-working-yet-after-1980">', $html);
        self::assertStringContainsString('<a href="https://example.com/a.md">', $html);
    }
}
