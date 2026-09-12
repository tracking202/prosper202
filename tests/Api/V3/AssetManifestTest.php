<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * Every third-party front-end file the page shell loads is pinned in
 * 202-config/assets.php with the version it came from and, for files served
 * from this repository, the SHA-384 of the exact bytes.
 *
 * Before the manifest, four scripts loaded unversioned from a CloudFront
 * bucket and Highcharts followed the CDN's rolling "latest" build, so a page
 * could change under the site without a commit. This test is what makes the
 * manifest true: a file whose bytes drift from its entry, a filename whose
 * version does not match the entry, or an external URL that stops pinning a
 * version fails the build instead of shipping.
 */
final class AssetManifestTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
        require_once $this->root . '/202-config/functions-ui.php';
    }

    public function testEveryEntryMatchesTheFileItDescribes(): void
    {
        $manifest = p202_asset_manifest();
        self::assertNotEmpty($manifest, 'the manifest lists at least one asset');

        foreach ($manifest as $id => $asset) {
            self::assertIsString($id);
            self::assertArrayHasKey('version', $asset, "$id: every entry carries the upstream version");
            self::assertNotSame('', $asset['version'], "$id: the version is not empty");
            self::assertTrue(isset($asset['path']) xor isset($asset['url']), "$id: exactly one of path or url");

            if (isset($asset['url'])) {
                self::assertStringStartsWith('https://', $asset['url'], "$id: external assets load over https");
                self::assertStringContainsString('/' . $asset['version'] . '/', $asset['url'], "$id: the external URL pins the version in its path");
                self::assertArrayNotHasKey('sha384', $asset, "$id: no integrity hash for a file we do not serve");
                continue;
            }

            $file = $this->root . '/' . $asset['path'];
            self::assertFileExists($file, "$id: the file is in the repository");
            self::assertArrayHasKey('sha384', $asset, "$id: a served file carries its SHA-384");
            self::assertSame(
                $asset['sha384'],
                base64_encode(hash_file('sha384', $file, true)),
                "$id: the bytes of {$asset['path']} no longer match the manifest; re-hash after an intentional upgrade, otherwise restore the file"
            );
            self::assertStringContainsString($asset['version'], $asset['path'], "$id: the path carries the version so an upgrade cannot reuse a stale filename");
            if (isset($asset['banner'])) {
                self::assertStringContainsString($asset['banner'], (string) file_get_contents($file), "$id: the file's own version banner");
            }
        }
    }

    public function testEveryFileTheShellsReferenceExists(): void
    {
        foreach ($this->contexts() as $label => $context) {
            foreach ([P202_UI_CLASSIC, P202_UI_V2] as $ui) {
                $assets = p202_shell_assets($ui, $context);
                foreach (['css', 'js_head', 'js_page'] as $list) {
                    foreach ($assets[$list] as $item) {
                        if (isset($item['asset'])) {
                            $entry = p202_asset($item['asset']);
                            self::assertNotEmpty($entry, "$ui/$label: asset {$item['asset']} is in the manifest");
                            continue;
                        }
                        self::assertArrayHasKey('path', $item, "$ui/$label: an item names an asset id or a path");
                        self::assertFileExists($this->root . '/' . $item['path'], "$ui/$label: {$item['path']} exists");
                    }
                }
            }
        }
    }

    public function testTagsRenderTheElementTheExtensionCallsFor(): void
    {
        $base = 'https://example.test/p202/';

        $css = p202_asset_tag('bootstrap.css', $base);
        self::assertStringStartsWith('<link rel="stylesheet" href="https://example.test/p202/202-css/vendor/bootstrap-5.3.8.min.css?v=5.3.8"', $css);

        $js = p202_asset_tag('jquery.js', $base);
        self::assertSame('<script src="https://example.test/p202/202-js/vendor/jquery-3.7.1.min.js?v=3.7.1"></script>', $js);

        $external = p202_asset_tag('highcharts.js', $base);
        self::assertSame('<script src="https://code.highcharts.com/11.4.8/highcharts.js"></script>', $external);

        $path = p202_shell_asset_tag(['path' => '202-js/dni.search.offers.tablesorter.php', 'query' => 'ddlci=a%26b'], $base);
        self::assertSame('<script src="https://example.test/p202/202-js/dni.search.offers.tablesorter.php?ddlci=a%26b"></script>', $path);
    }

    public function testUnknownAndUntaggableAssetsAreErrors(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        p202_asset('no-such-asset');
    }

    public function testFontsCannotBeRenderedAsTags(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        p202_asset_tag('bootstrap-icons.woff2', 'https://example.test/');
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
}
