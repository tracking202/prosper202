<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * The two page shells never share a framework file.
 *
 * A page on the v2 shell runs Bootstrap 5.3; a classic page runs Bootstrap 3
 * with Flat UI Pro. The two cannot coexist on one page — each carries a
 * global reset and its own grid — so p202_shell_assets() must hand every
 * page exactly one of the two stacks, and the chrome, which both shells
 * render, must come from neither. A typo in the 'ui' option must be an
 * error, not a fallback to the wrong stack around the wrong markup.
 */
final class ShellIsolationTest extends TestCase
{
    /** Files only the v2 shell may load. */
    private const V2_ONLY = [
        'bootstrap.css',
        'bootstrap.js',
        'bootstrap-icons.css',
        'jquery.js',
        '202-css/p202-theme.css',
        '202-css/p202-components.css',
        '202-js/p202-ui.js',
    ];

    /** Files only the classic shell may load. */
    private const CLASSIC_ONLY = [
        '202-css/css/bootstrap.min.css',
        '202-css/css/flat-ui-pro.min.css',
        '202-css/css/font-awesome.min.css',
        '202-css/css/bootstrap-tokenfield.min.css',
        '202-css/css/tokenfield-typeahead.min.css',
        '202-css/css/select2.css',
        '202-css/custom.css',
        '202-css/p202-ui.css',
        '202-css/design-system.css',
        '202-js/fileinput.js',
        '202-js/radiocheck.js',
        '202-js/jquery.validate.min.js',
        '202-js/bootstrap-tokenfield.min.js',
        '202-js/typeahead.bundle.js',
        '202-js/custom.php',
    ];

    private const CHROME = [
        '202-css/p202-chrome.css',
        '202-js/p202-chrome.js',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/202-config/functions-ui.php';
    }

    public function testTheClassicShellLoadsNoBootstrapFiveFile(): void
    {
        foreach ($this->contexts() as $label => $context) {
            $loaded = $this->everythingLoaded(P202_UI_CLASSIC, $context);
            foreach (self::V2_ONLY as $forbidden) {
                self::assertNotContains($forbidden, $loaded, "classic/$label loads $forbidden, which belongs to the v2 shell");
            }
        }
    }

    public function testTheV2ShellLoadsNoClassicFile(): void
    {
        foreach ($this->contexts() as $label => $context) {
            $loaded = $this->everythingLoaded(P202_UI_V2, $context);
            foreach (self::CLASSIC_ONLY as $forbidden) {
                self::assertNotContains($forbidden, $loaded, "v2/$label loads $forbidden, which belongs to the classic shell");
            }
            foreach ($loaded as $item) {
                self::assertStringStartsNotWith('legacy.', $item, "v2/$label loads the vendored legacy file $item");
            }
        }
    }

    public function testBothShellsLoadTheChromeAndNothingElseSharesAFramework(): void
    {
        foreach ($this->contexts() as $label => $context) {
            foreach ([P202_UI_CLASSIC, P202_UI_V2] as $ui) {
                $loaded = $this->everythingLoaded($ui, $context);
                foreach (self::CHROME as $chrome) {
                    self::assertContains($chrome, $loaded, "$ui/$label loads the chrome file $chrome");
                }
                $assets = p202_shell_assets($ui, $context);
                self::assertSame('202-css/p202-chrome.css', $assets['css'][$ui === P202_UI_V2 ? 0 : count($assets['css']) - ($context['logged_in'] ?? false ? 2 : 1)]['path'] ?? '', "$ui/$label: the chrome sheet loads first on v2 and last (before the messenger) on classic");
            }
        }
    }

    public function testTheV2ShellCarriesChartsWhereTheClassicOneDoes(): void
    {
        $charted = ['section' => 'tracking202', 'sub' => 'analyze', 'page' => 'keywords.php', 'logged_in' => true];
        $plain = ['section' => '202-account', 'sub' => 'help.php', 'logged_in' => true];
        self::assertContains('highcharts.js', $this->everythingLoaded(P202_UI_V2, $charted));
        self::assertContains('highcharts.js', $this->everythingLoaded(P202_UI_CLASSIC, $charted));
        self::assertNotContains('highcharts.js', $this->everythingLoaded(P202_UI_V2, $plain));
        self::assertNotContains('highcharts.js', $this->everythingLoaded(P202_UI_CLASSIC, $plain));
    }

    public function testHighchartsIsPinnedAndTheAssetBucketIsGone(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertStringContainsString('/11.4.8/', p202_asset('highcharts.js')['url']);
        foreach (['202-config/template.php', '202-config/functions-ui.php', 'tracking202/_config/top.php'] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            self::assertStringNotContainsString('dp5k1x6z3k332.cloudfront.net', $source, "$file still loads from the retired asset bucket");
            self::assertStringNotContainsString('code.highcharts.com/highcharts.js', $source, "$file loads the unpinned Highcharts build");
        }
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

    /**
     * @param array<string, mixed> $context
     * @return list<string> asset ids and paths, in load order
     */
    private function everythingLoaded(string $ui, array $context): array
    {
        $assets = p202_shell_assets($ui, $context);
        $loaded = [];
        foreach (['css', 'js_head', 'js_page'] as $list) {
            foreach ($assets[$list] as $item) {
                $loaded[] = $item['asset'] ?? $item['path'];
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
}
