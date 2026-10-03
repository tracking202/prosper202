<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * No page can reach a legacy asset, no `ui` option exists, the shell loads
 * nothing third-party that the manifest does not pin, and nothing in the tree
 * loads a library from a host this install does not control.
 *
 * Until U8 there were two shells, and this test kept their stacks apart. U8
 * deleted the classic one (Bootstrap 3, Flat UI Pro, jQuery 1.11 and the
 * plugins written against them) with every file it loaded, so the invariant
 * is now about every page rather than the pages that opted out:
 *
 *   - the shell's asset list, in every context a page can put it in, names
 *     no legacy file, and every file it names exists;
 *   - the manifest carries no `legacy.*` entry, no file under a legacy/
 *     directory and no Bootstrap 3, Flat UI, Font Awesome 4, jQuery 1.x or
 *     jQuery UI build, and every entry in it is loaded (an asset nobody loads
 *     is deleted, not kept "just in case");
 *   - none of those files is left in the tree for a page to name by path;
 *   - no PHP file names a `ui` (or overview `shell`) option, and
 *     template_top() refuses one when it is passed anyway — executed, in a
 *     process of its own, not read.
 */
final class ShellIsolationTest extends TestCase
{
    /**
     * The only files the shell may load by bare path. Everything else is
     * third-party and goes through the manifest, which records its version
     * and hash. Add a first-party file here when the shell gains one.
     */
    private const FIRST_PARTY = [
        '202-css/p202-chrome.css',
        '202-css/p202-theme.css',
        '202-css/p202-components.css',
        '202-css/messenger.css',
        '202-js/p202-chrome.js',
        '202-js/p202-ui.js',
        '202-js/chart.theme.js',
    ];

    /**
     * What a legacy file looks like, by path or URL: the classic stack's
     * libraries and the first-party layers written for it. Matched against
     * every file the shell loads, every manifest entry and every file left
     * in 202-css/ and 202-js/.
     */
    private const LEGACY_FILE = '~(?:^|/)legacy/|bootstrap-3\.|flat-?ui|font-?awesome|fontawesome|glyphicons|jquery-1\.|jquery-ui|select2|tablesorter|tokenfield|typeahead|jquery\.validate|radiocheck|fileinput|(?:^|/)(?:custom|design-system|p202-ui)\.css$|(?:^|/)(?:custom|account|dni\.search\.offers\.tablesorter)\.(?:php|js)$~i';

    /** Directories the classic stack lived in; none of them may come back. */
    private const LEGACY_DIRS = ['202-css/css', '202-css/vendor/legacy', '202-js/vendor/legacy', '202-css/images', '202-config/template-parts'];

    /**
     * External script or stylesheet URLs that may appear anywhere in the
     * tree besides the manifest's pinned Highcharts build. Each is a hosted
     * service's loader, which is the service itself rather than a library
     * with a version to pin; nothing else qualifies.
     *
     * A font, an icon set or a charting library is never a service loader:
     * vendor it and pin it in the manifest. This sweep covers scripts and
     * stylesheets, including the ones a script injects at runtime; images the
     * page fetches from an ad or wallpaper service are content, not code, and
     * are out of its scope.
     */
    private const EXTERNAL_SERVICE_LOADERS = [
        'https://dna8twue3dlxq.cloudfront.net/js/profitwell.js' => 'ProfitWell, loaded by template_bottom()',
        '//www.googletagservices.com/tag/js/gpt.js' => 'Google Publisher Tag on the sign-in page (info_top([\'ads\' => true]))',
    ];

    private const SKIP_DIRS = ['vendor', 'node_modules', 'tests', '.git', 'go-cli', 'docs', 'documentation', '202-js/vendor', '202-css/vendor', '202-config/temp', '202-config/data', '202-config/geo'];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/202-config/functions-ui.php';
    }

    public function testNoContextLoadsALegacyFile(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ($this->contexts() as $label => $context) {
            $loaded = $this->filesLoaded($context);
            self::assertNotEmpty($loaded, "$label loads something");
            foreach ($loaded as $file) {
                self::assertSame(0, preg_match(self::LEGACY_FILE, $file), "$label loads $file, a file of the deleted classic stack");
                if (!str_starts_with($file, 'https://')) {
                    self::assertFileExists($root . '/' . $file, "$label loads $file, which is not in the repository");
                }
            }
        }
    }

    public function testTheManifestCarriesNoLegacyEntryAndNothingUnloaded(): void
    {
        $root = dirname(__DIR__, 3);
        $loaded = [];
        foreach ($this->contexts() as $context) {
            foreach (['css', 'js_head', 'js_page'] as $list) {
                foreach (p202_shell_assets($context)[$list] as $item) {
                    if (isset($item['asset'])) {
                        $loaded[$item['asset']] = true;
                    }
                }
            }
        }
        // A font is loaded by the stylesheet that names it, not by a tag.
        $stylesheets = '';
        foreach (array_keys($loaded) as $id) {
            $asset = p202_asset($id);
            if (isset($asset['path']) && str_ends_with($asset['path'], '.css')) {
                $stylesheets .= (string) file_get_contents($root . '/' . $asset['path']);
            }
        }

        foreach (p202_asset_manifest() as $id => $asset) {
            $location = $asset['path'] ?? $asset['url'];
            self::assertFalse(str_starts_with($id, 'legacy.'), "$id: the legacy entries were deleted with the classic shell");
            self::assertSame(0, preg_match(self::LEGACY_FILE, $location), "$id ($location) is a file of the deleted classic stack");
            if (isset($loaded[$id])) {
                continue;
            }
            $isFont = preg_match('~\.(?:woff2?|ttf|otf|eot)$~', $location) === 1;
            self::assertTrue($isFont && str_contains($stylesheets, basename($location)), "$id is in the manifest but nothing loads it; delete it (an asset nobody loads is not kept)");
        }
    }

    public function testNoLegacyFileIsLeftInTheTree(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (self::LEGACY_DIRS as $dir) {
            self::assertDirectoryDoesNotExist($root . '/' . $dir, "$dir held the classic stack");
        }
        $left = [];
        $seen = 0;
        foreach (['202-css', '202-js'] as $top) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $top, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                $seen++;
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if (preg_match(self::LEGACY_FILE, $relative) === 1) {
                    $left[] = $relative;
                }
            }
        }
        self::assertGreaterThan(10, $seen, 'the sweep walked 202-css/ and 202-js/');
        self::assertSame([], $left, "Files of the deleted classic stack are still in the tree, where a page could name them by path:\n" . implode("\n", $left));
    }

    public function testTheChromeLoadsFirstAndEverywhere(): void
    {
        foreach ($this->contexts() as $label => $context) {
            $assets = p202_shell_assets($context);
            self::assertSame('202-css/p202-chrome.css', $assets['css'][0]['path'] ?? '', "$label: the chrome sheet loads first");
            self::assertContains('202-js/p202-chrome.js', $this->filesLoaded($context), "$label loads the chrome script");
        }
    }

    public function testEveryBarePathTheShellLoadsIsFirstPartyAndEveryFirstPartyFileIsLoaded(): void
    {
        $root = dirname(__DIR__, 3);
        $used = [];
        foreach (self::FIRST_PARTY as $file) {
            self::assertFileExists($root . '/' . $file, "$file is in the repository");
            self::assertStringNotContainsString('/vendor/', $file, "$file is not a vendored library");
        }
        foreach ($this->contexts() as $label => $context) {
            $assets = p202_shell_assets($context);
            foreach (['css', 'js_head', 'js_page'] as $list) {
                foreach ($assets[$list] as $item) {
                    if (isset($item['asset'])) {
                        continue;
                    }
                    self::assertContains($item['path'] ?? '', self::FIRST_PARTY, "$label loads {$item['path']} by bare path; a third-party file goes through 202-config/assets.php, a first-party one is listed in this test");
                    $used[$item['path']] = true;
                }
            }
        }
        self::assertSame([], array_values(array_diff(self::FIRST_PARTY, array_keys($used))), 'every listed first-party file is loaded in some context; drop the ones that are not');
    }

    public function testChartsLoadOnlyInTheTrackingSection(): void
    {
        $highcharts = $this->toFile('highcharts.js');
        self::assertContains($highcharts, $this->filesLoaded(['section' => 'tracking202', 'logged_in' => true]));
        self::assertNotContains($highcharts, $this->filesLoaded(['section' => '202-account', 'logged_in' => true]));
        self::assertNotContains($highcharts, $this->filesLoaded(['logged_in' => false]));
    }

    public function testNothingLoadsAScriptOrStylesheetFromAnUnpinnedExternalHost(): void
    {
        $root = dirname(__DIR__, 3);
        $highcharts = p202_asset('highcharts.js')['url'];
        self::assertStringContainsString('/' . p202_asset('highcharts.js')['version'] . '/', $highcharts, 'the Highcharts URL pins its version');
        $allowed = self::EXTERNAL_SERVICE_LOADERS + [$highcharts => 'pinned in the manifest'];

        $offences = [];
        $scanned = 0;
        foreach ($this->sourceFiles($root) as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            $source = (string) preg_replace('~<!--.*?-->~s', '', $source);
            $scanned++;
            $urls = [];
            preg_match_all('~<script\b[^>]*\ssrc\s*=\s*["\']?([^"\'\s>]+)~i', $source, $matches);
            $urls = array_merge($urls, $matches[1]);
            preg_match_all('~<link\b[^>]*\bstylesheet\b[^>]*>~i', $source, $links);
            foreach ($links[0] as $tag) {
                if (preg_match('~\shref\s*=\s*["\']?([^"\'\s>]+)~i', $tag, $href) === 1) {
                    $urls[] = $href[1];
                }
            }
            preg_match_all('~["\']((?:https?:)?//[^"\'\s]+\.(?:js|css))["\']~i', $source, $matches);
            $urls = array_merge($urls, $matches[1]);
            // Injected at runtime: Highcharts' stock theme built a <link> whose
            // href had no .css extension (a Google Fonts query string), so the
            // extension rules above never saw it. These forms are JavaScript —
            // `href:` is object syntax, never an <a href="…"> in markup.
            // `location.href = …` is navigation, not an asset load.
            preg_match_all('~(?:\bhref\s*:|(?<!location)\.(?:href|src)\s*=|setAttribute\s*\(\s*["\'](?:href|src)["\']\s*,)\s*["\']((?:https?:)?//[^"\'\s]+)["\']~i', $source, $matches);
            $urls = array_merge($urls, $matches[1]);
            foreach (array_unique($urls) as $url) {
                if (preg_match('~^(?:https?:)?//~i', $url) !== 1) {
                    continue; // relative: served by this install
                }
                if (str_contains($url, '<?')) {
                    continue; // built from this install's own domain at runtime
                }
                if (isset($allowed[$url])) {
                    continue;
                }
                $offences[] = "$file: $url";
            }
        }
        self::assertGreaterThan(100, $scanned, 'the sweep walked the tree');
        self::assertSame([], $offences, "External script or stylesheet loads that are neither the pinned Highcharts build nor a listed service loader (pin the file in 202-config/assets.php, or add the loader to EXTERNAL_SERVICE_LOADERS with its reason):\n" . implode("\n", $offences));
    }

    /**
     * No PHP file passes a `ui` option as an array key. Read from the token
     * stream, so a key written with either quote, any spacing or a comment
     * before the arrow is seen; a string that merely mentions the word (an
     * error message, a docblock) is not a key and is not flagged. The
     * overview family's `shell` key is refused by p202_overview_run() and
     * pinned by OverviewPagesTest; 'shell' is also an API capability name,
     * so it is not banned tree-wide.
     */
    public function testNoPhpFileNamesAUiOption(): void
    {
        $root = dirname(__DIR__, 3);
        $offences = [];
        $scanned = 0;
        foreach ($this->sourceFiles($root) as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $scanned++;
            $tokens = array_values(array_filter(
                \PhpToken::tokenize((string) file_get_contents($root . '/' . $file)),
                static fn (\PhpToken $t): bool => !$t->isIgnorable()
            ));
            foreach ($tokens as $i => $token) {
                if ($token->id !== T_CONSTANT_ENCAPSED_STRING || strtolower(substr($token->text, 1, -1)) !== 'ui') {
                    continue;
                }
                if (($tokens[$i + 1] ?? null)?->id === T_DOUBLE_ARROW) {
                    $offences[] = "$file:{$token->line}: {$token->text} =>";
                }
            }
        }
        self::assertGreaterThan(300, $scanned, 'the sweep read the PHP files');
        self::assertSame([], $offences, "A 'ui' option survives; there is one page shell, and template_top() refuses the key:\n" . implode("\n", $offences));
    }

    /**
     * The refusal, executed: template_top() with a leftover 'ui' (either
     * value the old option took, and one it never took) and with an option
     * nobody defined throws before a byte of the page is written, and a
     * page's usual options still render. In a process of its own, because
     * template.php opens an output buffer when it is loaded.
     */
    public function testTemplateTopRefusesAUiOption(): void
    {
        $root = dirname(__DIR__, 3);
        $script = <<<'PHP'
            $_SERVER['HTTP_HOST'] = 'example.test';
            $_SERVER['REQUEST_URI'] = '/202-account/';
            $_SERVER['SCRIPT_NAME'] = '/202-account/index.php';
            if (!function_exists('get_absolute_url')) { function get_absolute_url() { return '/'; } }
            if (!function_exists('getDashEmail')) { function getDashEmail() { return ''; } }
            if (!function_exists('getTrackingDomain')) { function getTrackingDomain() { return 'example.test'; } }
            require $argv[1] . '/202-config/template.php';
            $navigation = ['', '202-account'];
            $said = [];
            foreach ([['ui' => 'v2'], ['ui' => 'classic'], ['ui' => 'bootstrap5'], ['meta_description' => 'x', 'ui' => 'v2'], ['shell' => 'v2']] as $options) {
                $before = ob_get_length();
                try {
                    template_top('T', $options);
                    $said[] = 'ACCEPTED ' . json_encode($options);
                } catch (InvalidArgumentException $e) {
                    $said[] = 'REFUSED ' . json_encode($options) . ' wrote=' . (ob_get_length() - $before) . ' ' . $e->getMessage();
                }
            }
            template_top('Plain', ['meta_description' => 'd', 'body_class' => 'x']);
            $page = ob_get_clean();
            $said[] = 'RENDERED ' . (str_contains((string) $page, '<body class="' . P202_SHELL_BODY_CLASS . ' ') ? 'shell' : 'no-shell');
            preg_match_all('~<script src="[^"]*/(p202-ui|p202-chrome|jquery-3[^"/]*|bootstrap-5[^"/]*)\.js[^"]*"( defer)?></script>~', (string) $page, $tags, PREG_SET_ORDER);
            foreach ($tags as $tag) {
                $said[] = 'SCRIPT ' . $tag[1] . (($tag[2] ?? '') !== '' ? ' deferred' : ' blocking');
            }
            echo implode("\n", $said), "\n";
            PHP;
        $file = tempnam(sys_get_temp_dir(), 'p202-tt');
        self::assertIsString($file);
        file_put_contents($file, "<?php\n" . $script);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1', $out, $code);
        unlink($file);
        $output = implode("\n", $out);

        self::assertSame(0, $code, "the probe ran:\n$output");
        self::assertStringNotContainsString('ACCEPTED', $output, "an option template_top() does not take was accepted:\n$output");
        self::assertSame(4, substr_count($output, "wrote=0 template_top() no longer takes a 'ui' option"), "each leftover 'ui' is refused by name, before any output:\n$output");
        self::assertStringContainsString("REFUSED {\"shell\":\"v2\"} wrote=0 template_top() has no option 'shell'", $output, "an unknown option is refused by name:\n$output");
        self::assertStringContainsString('RENDERED shell', $output, "a page's usual options still render the shell:\n$output");
        foreach (['p202-ui deferred', 'p202-chrome deferred', 'jquery-3.7.1.min blocking', 'bootstrap-5.3.8.bundle.min blocking'] as $script) {
            self::assertStringContainsString("SCRIPT $script", $output, "the rendered page loads $script:\n$output");
        }
    }

    /** A manifest id resolves to its file (path or URL); a path is itself. */
    private function toFile(string $idOrPath): string
    {
        $manifest = p202_asset_manifest();
        if (isset($manifest[$idOrPath])) {
            return $manifest[$idOrPath]['path'] ?? $manifest[$idOrPath]['url'];
        }
        return $idOrPath;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string> files (manifest ids resolved), in load order
     */
    private function filesLoaded(array $context): array
    {
        $assets = p202_shell_assets($context);
        $loaded = [];
        foreach (['css', 'js_head', 'js_page'] as $list) {
            foreach ($assets[$list] as $item) {
                $loaded[] = isset($item['asset']) ? $this->toFile($item['asset']) : $item['path'];
            }
        }
        return $loaded;
    }

    /**
     * Every context a page can put the shell in: signed out (the standalone
     * pages), and each section a signed-in page belongs to.
     *
     * @return array<string, array<string, mixed>>
     */
    private function contexts(): array
    {
        return [
            'signed out' => ['logged_in' => false],
            'account home' => ['section' => '202-account', 'logged_in' => true],
            'attribution dashboard' => ['section' => '202-account', 'logged_in' => true],
            'campaigns setup' => ['section' => 'tracking202', 'logged_in' => true],
            'analyze' => ['section' => 'tracking202', 'logged_in' => true],
            'tv202' => ['section' => '202-tv', 'logged_in' => true],
        ];
    }

    /**
     * Every PHP, JavaScript and HTML file that could emit or inject a tag.
     *
     * @return list<string> repo-relative paths
     */
    private function sourceFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file) use ($root): bool {
                    $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
                    foreach (self::SKIP_DIRS as $skip) {
                        if ($relative === $skip || str_starts_with($relative, $skip . '/')) {
                            return false;
                        }
                    }
                    return $file->isDir() || preg_match('/\.(php|js|html)$/', $file->getFilename()) === 1;
                }
            )
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            // A directory whose children the filter all rejected has no
            // children left, so the iterator yields the directory itself as a
            // leaf. Only files are readable.
            if (!$file->isFile()) {
                continue;
            }
            $files[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
        }
        sort($files);
        return $files;
    }
}
