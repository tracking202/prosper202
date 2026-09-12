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
}
