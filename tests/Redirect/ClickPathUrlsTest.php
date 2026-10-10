<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * No click endpoint (tracking202/redirect/, tracking202/static/) writes a
 * URL of this install as a root-absolute path: every one goes through
 * p202InstallPath(), which puts the install's directory under the document
 * root in front.
 *
 * The cloaked redirects' forms (off.php twice, offrtr.php twice, lpc.php,
 * cl.php) posted to `/tracking202/redirect/cl2.php`, and cl.php, cl2.php and
 * lpc.php sent a bad request to `/202-404.php`. On an install that is not at
 * the document root both are 404s: served from a subdirectory, off.php's
 * cloaked page named /tracking202/redirect/cl2.php, which answered 404 while
 * /<dir>/tracking202/redirect/cl2.php answered 200.
 *
 * What is read: every PHP string literal and every piece of HTML or
 * interpolated string text in those files. A string naming a top-level entry
 * of the install (`/tracking202/…`, `/202-404.php`, `/api/…` — the entries
 * are read from the repository root) at its start, after `location:`,
 * `url=`, `=`, a quote or a space is reported — unless it is the right-hand
 * side of a `.`, which is a filesystem path being completed
 * (`substr(__DIR__, 0, -21) . '/202-config/connect2.php'`). Comments are not
 * read. A path assembled at run time (`'/' . 'tracking202'`) is not seen.
 */
final class ClickPathUrlsTest extends TestCase
{
    private const DIRECTORIES = ['tracking202/redirect/', 'tracking202/static/'];

    /** The install's top-level entries, as a regex alternation. */
    private static function entries(): string
    {
        $names = [];
        foreach ((array) scandir(SourceScan::repoRoot()) as $name) {
            if (!is_string($name) || $name === '' || $name[0] === '.') {
                continue;
            }
            $names[] = preg_quote($name, '#');
        }
        self::assertContains('tracking202', $names);

        return implode('|', $names);
    }

    /**
     * Root-absolute paths of this install in one source.
     *
     * @return list<array{int, string}> line, the text that names it
     */
    private static function rootPaths(string $source, string $entries): array
    {
        $pattern = '#(?:^|location:\s*|url=|[=\s"\'(])(/(?:' . $entries . ')(?=[/?\#"\'\s>]|$)[^"\'\s>]*)#i';
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                // The right-hand side of a concatenation completes a path
                // started at run time: a filesystem path from __DIR__.
                if (($tokens[$i - 1] ?? null) === '.') {
                    continue;
                }
                $text = substr($token[1], 1, -1);
            } elseif (in_array($token[0], [T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE], true)) {
                $text = $token[1];
            } else {
                continue;
            }
            if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[1] as [$path, $offset]) {
                    $found[] = [$token[2] + substr_count(substr($text, 0, $offset), "\n"), $path];
                }
            }
        }

        return $found;
    }

    public function testNoClickEndpointWritesARootAbsolutePathOfThisInstall(): void
    {
        $entries = self::entries();
        $found = [];
        $read = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            $inScope = false;
            foreach (self::DIRECTORIES as $directory) {
                $inScope = $inScope || str_starts_with($path, $directory);
            }
            if (!$inScope) {
                continue;
            }
            $read++;
            foreach (self::rootPaths($source, $entries) as [$line, $text]) {
                $found[] = $path . ':' . $line . '  ' . $text;
            }
        }

        // dl, rtr, lp, lpc, off, offrtr, cl, cl2, go, pci; the recorders, the
        // pixels and postbacks, landing.php.
        self::assertGreaterThanOrEqual(20, $read, 'the scan reads the click endpoints');
        self::assertSame([], $found, "A click endpoint writes a URL of this install from the document root:\n  "
            . implode("\n  ", $found)
            . "\nAn install in a subdirectory answers 404 there. Use p202InstallPath('tracking202/…').");
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function shapes(): iterable
    {
        yield 'a form action in HTML' => [
            '<form action="/tracking202/redirect/cl2.php">',
            ['/tracking202/redirect/cl2.php'],
        ];
        yield 'a redirect to the 404 page' => ["<?php RedirectHelper::redirect('/202-404.php');", ['/202-404.php']];
        yield 'a Location header' => ["<?php header('Location: /tracking202/redirect/dl.php?x=1');", [
            '/tracking202/redirect/dl.php?x=1',
        ]];
        yield 'a meta refresh' => ['<meta content="0; url=/202-404.php">', ['/202-404.php']];
        yield 'a double-quoted assignment' => ['<?php $u = "/tracking202/redirect/cl2.php";', [
            '/tracking202/redirect/cl2.php',
        ]];
        yield 'interpolated' => ['<?php $u = "/api/v3/x?id=$id";', ['/api/v3/x?id=']];
        yield 'an include completing __DIR__' => [
            "<?php include substr(__DIR__, 0, -21) . '/202-config/connect2.php';",
            [],
        ];
        yield 'the install path in front' => [
            "<?php \$a = p202InstallPath('tracking202/redirect/cl2.php');",
            [],
        ];
        yield 'another site\'s path' => ['<a href="https://example.com/tracking202/x">', []];
        yield 'a path that is no entry of the install' => ['<a href="/nowhere/x">', []];
        yield 'a comment' => ["<?php // header('Location: /202-404.php');\n", []];
    }

    /**
     * @dataProvider shapes
     * @param list<string> $expected
     */
    public function testTheShapesARootPathTakes(string $source, array $expected): void
    {
        self::assertSame($expected, array_column(self::rootPaths($source, self::entries()), 1));
    }

    /**
     * p202InstallPath() from its own source in a child PHP: the install's
     * directory under the document root, '/' at the root.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function documentRoots(): iterable
    {
        $root = SourceScan::repoRoot();
        yield 'at the document root' => [$root, '/tracking202/redirect/cl2.php'];
        yield 'in a subdirectory' => [dirname($root), '/' . basename($root) . '/tracking202/redirect/cl2.php'];
    }

    /** @dataProvider documentRoots */
    public function testTheInstallPathIsUnderTheDocumentRoot(string $documentRoot, string $expected): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-config/connect2.php');
        self::assertSame(1, preg_match('/^function p202InstallPath\(.*?^\}\n/ms', $source, $m));
        $code = 'require ' . var_export(SourceScan::repoRoot() . '/vendor/autoload.php', true) . ';'
            . ' define("ROOT_PATH", ' . var_export(SourceScan::repoRoot() . '/', true) . ');'
            . ' $_SERVER["DOCUMENT_ROOT"] = ' . var_export($documentRoot, true) . ';'
            . $m[0]
            . ' echo p202InstallPath("tracking202/redirect/cl2.php");';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        self::assertSame($expected, $out, $err);
    }
}
