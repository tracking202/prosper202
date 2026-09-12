<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * The two page shells never share a framework file, load nothing third-party
 * that the manifest does not pin, and nothing in the tree loads a library
 * from a host this install does not control.
 *
 * A page on the v2 shell runs Bootstrap 5.3; a classic page runs Bootstrap 3
 * with Flat UI Pro. The two cannot coexist on one page — each carries a
 * global reset and its own grid — so p202_shell_assets() must hand every
 * page exactly one of the two stacks, and the chrome, which both shells
 * render, must come from neither. A typo in the 'ui' option must be an
 * error, not a fallback to the wrong stack around the wrong markup.
 *
 * Manifest ids are compared by the file they resolve to, so a classic asset
 * cannot slip into the v2 list under its id; every `legacy.*` id is classic
 * by definition, so the forbidden set grows with the manifest.
 */
final class ShellIsolationTest extends TestCase
{
    /** Only the v2 shell may load these (manifest ids or first-party paths). */
    private const V2_ONLY = [
        'bootstrap.css',
        'bootstrap.js',
        'bootstrap-icons.css',
        'jquery.js',
        '202-css/p202-theme.css',
        '202-css/p202-components.css',
        '202-js/p202-ui.js',
    ];

    /** Only the classic shell may load these, in addition to every `legacy.*` manifest id. */
    private const CLASSIC_ONLY = [
        '202-css/custom.css',
        '202-css/p202-ui.css',
        '202-css/design-system.css',
        '202-js/custom.php',
        '202-js/account.php',
        '202-js/attribution.js',
    ];

    private const CHROME = [
        '202-css/p202-chrome.css',
        '202-js/p202-chrome.js',
    ];

    /**
     * The only files a shell may load by bare path. Everything else is
     * third-party and goes through the manifest, which records its version
     * and hash. Add a first-party file here when the shell gains one.
     */
    private const FIRST_PARTY = [
        '202-css/p202-chrome.css',
        '202-css/p202-theme.css',
        '202-css/p202-components.css',
        '202-css/messenger.css',
        '202-css/custom.css',
        '202-css/p202-ui.css',
        '202-css/design-system.css',
        '202-js/p202-chrome.js',
        '202-js/p202-ui.js',
        '202-js/chart.theme.js',
        '202-js/custom.php',
        '202-js/account.php',
        '202-js/attribution.js',
        '202-js/dni.search.offers.tablesorter.php',
    ];

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
        '//www.googletagservices.com/tag/js/gpt.js' => 'Google Publisher Tag on the standalone pages (info_top())',
        'http://partner.googleadservices.com/gampad/google_service.js' => 'Google ad service on the mobile mini-stats page',
    ];

    private const SKIP_DIRS = ['vendor', 'node_modules', 'tests', '.git', 'go-cli', 'docs', 'documentation', '202-js/vendor', '202-css/vendor', '202-config/temp', '202-config/data', '202-config/geo'];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/202-config/functions-ui.php';
    }

    public function testTheClassicShellLoadsNoBootstrapFiveFile(): void
    {
        $forbidden = array_map($this->toFile(...), self::V2_ONLY);
        foreach ($this->contexts() as $label => $context) {
            $loaded = $this->filesLoaded(P202_UI_CLASSIC, $context);
            foreach ($forbidden as $file) {
                self::assertNotContains($file, $loaded, "classic/$label loads $file, which belongs to the v2 shell");
            }
        }
    }

    public function testTheV2ShellLoadsNoClassicFile(): void
    {
        $forbidden = array_map($this->toFile(...), self::CLASSIC_ONLY);
        $legacyIds = array_values(array_filter(array_keys(p202_asset_manifest()), static fn (string $id): bool => str_starts_with($id, 'legacy.')));
        self::assertGreaterThan(10, count($legacyIds), 'the manifest lists the classic-only files under the legacy. prefix');
        foreach ($legacyIds as $id) {
            $forbidden[] = $this->toFile($id);
        }
        foreach ($this->contexts() as $label => $context) {
            $loaded = $this->filesLoaded(P202_UI_V2, $context);
            foreach ($forbidden as $file) {
                self::assertNotContains($file, $loaded, "v2/$label loads $file, which belongs to the classic shell");
            }
        }
    }

    public function testBothShellsLoadTheChromeAndNothingElseSharesAFramework(): void
    {
        foreach ($this->contexts() as $label => $context) {
            foreach ([P202_UI_CLASSIC, P202_UI_V2] as $ui) {
                $loaded = $this->filesLoaded($ui, $context);
                foreach (self::CHROME as $chrome) {
                    self::assertContains($chrome, $loaded, "$ui/$label loads the chrome file $chrome");
                }
                $assets = p202_shell_assets($ui, $context);
                self::assertSame('202-css/p202-chrome.css', $assets['css'][$ui === P202_UI_V2 ? 0 : count($assets['css']) - ($context['logged_in'] ?? false ? 2 : 1)]['path'] ?? '', "$ui/$label: the chrome sheet loads first on v2 and last (before the messenger) on classic");
            }
        }
    }

    public function testEveryBarePathTheShellsLoadIsFirstParty(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (self::FIRST_PARTY as $file) {
            self::assertFileExists($root . '/' . $file, "$file is in the repository");
            self::assertStringNotContainsString('/vendor/', $file, "$file is not a vendored library");
        }
        foreach ($this->contexts() as $label => $context) {
            foreach ([P202_UI_CLASSIC, P202_UI_V2] as $ui) {
                $assets = p202_shell_assets($ui, $context);
                foreach (['css', 'js_head', 'js_page'] as $list) {
                    foreach ($assets[$list] as $item) {
                        if (isset($item['asset'])) {
                            continue;
                        }
                        self::assertContains($item['path'] ?? '', self::FIRST_PARTY, "$ui/$label loads {$item['path']} by bare path; a third-party file goes through 202-config/assets.php, a first-party one is listed in this test");
                    }
                }
            }
        }
    }

    public function testTheV2ShellCarriesChartsWhereTheClassicOneDoes(): void
    {
        $charted = ['section' => 'tracking202', 'sub' => 'analyze', 'page' => 'keywords.php', 'logged_in' => true];
        $plain = ['section' => '202-account', 'sub' => 'help.php', 'logged_in' => true];
        $highcharts = $this->toFile('highcharts.js');
        self::assertContains($highcharts, $this->filesLoaded(P202_UI_V2, $charted));
        self::assertContains($highcharts, $this->filesLoaded(P202_UI_CLASSIC, $charted));
        self::assertNotContains($highcharts, $this->filesLoaded(P202_UI_V2, $plain));
        self::assertNotContains($highcharts, $this->filesLoaded(P202_UI_CLASSIC, $plain));
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

    public function testUnknownShellIsAnError(): void
    {
        self::assertSame(P202_UI_CLASSIC, p202_ui_shell(null));
        self::assertSame(P202_UI_CLASSIC, p202_ui_shell(''));
        self::assertSame(P202_UI_CLASSIC, p202_ui_shell('classic'));
        self::assertSame(P202_UI_V2, p202_ui_shell('v2'));

        $this->expectException(\InvalidArgumentException::class);
        p202_ui_shell('bootstrap5');
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
    private function filesLoaded(string $ui, array $context): array
    {
        $assets = p202_shell_assets($ui, $context);
        $loaded = [];
        foreach (['css', 'js_head', 'js_page'] as $list) {
            foreach ($assets[$list] as $item) {
                $loaded[] = isset($item['asset']) ? $this->toFile($item['asset']) : $item['path'];
            }
        }
        return $loaded;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function contexts(): array
    {
        return [
            'anonymous' => [],
            'account home' => ['section' => '202-account', 'sub' => '', 'logged_in' => true],
            'attribution dashboard' => ['section' => '202-account', 'sub' => 'attribution.php', 'logged_in' => true],
            'campaigns setup' => ['section' => 'tracking202', 'sub' => 'setup', 'page' => 'aff_campaigns.php', 'logged_in' => true, 'ddlci' => 'x'],
            'analyze' => ['section' => 'tracking202', 'sub' => 'analyze', 'page' => 'keywords.php', 'logged_in' => true],
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
            $files[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
        }
        sort($files);
        return $files;
    }
}
