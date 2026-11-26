<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * jQuery and inline `style=` attributes only leave the served tree.
 *
 * The pages render on Bootstrap 5 and the kit's components; jQuery and
 * inline styles are what the migration left. Each file that still has them
 * is listed with how many it has, and the count must be exactly what the
 * file holds: a new file, or a listed one with more, fails; a file with
 * fewer fails until its entry is lowered (or removed at zero), so the lists
 * only shrink.
 *
 * The scan reads every token with no structure to miss (CLAUDE.md #20): a
 * jQuery use is the word jquery in any case (the asset, a comment, a call)
 * or a `$` that is not part of a name followed by `(` or `.`, which PHP
 * cannot write and JavaScript writes only for jQuery here; an inline style is
 * a `style=` attribute in any quoting, escaped quotes included, or a
 * script's `setAttribute('style', …)`. A comment that names jQuery counts:
 * word it another way.
 */
final class JqueryAndInlineStylesOnlyShrinkTest extends TestCase
{
    private const SKIP_DIRS = [
        'vendor', 'node_modules', 'tests', '.git', 'documentation', 'docs', 'build/logs', 'sdk',
        '202-js/vendor', '202-css/vendor', '202-config/temp', '202-config/data', '202-config/geo',
    ];

    private const JQUERY = '/\bjquery\b|(?<![\w$])\$[(.]/i';
    private const INLINE_STYLE = '/(?<![\w.$-])style\s*=\s*\\\\?["\']|setAttribute\(\s*["\']style["\']/i';

    /** Files that must be in the sweep, so a filter that skips a family fails here. */
    private const MUST_SCAN = [
        '202-config/template.php',
        '202-js/p202-setup.js',
        'tracking202/redirect/off.php',
        'tracking202/ajax/ltv_customer.php',
    ];

    /** @var array<string, int> jQuery uses each file still has. */
    private const JQUERY_ALLOWED = [
        '202-account/ajax/delay-alert.php' => 1,
        '202-account/ajax/dni.php' => 2,
        '202-config/ReportBasicForm.class.php' => 5,
        '202-config/assets.php' => 3,
        '202-config/connect.php' => 1,
        '202-config/functions-account-ui.php' => 1,
        '202-config/functions-auth.php' => 1,
        '202-config/functions-ui.php' => 3,
        '202-config/template.php' => 2,
        '202-js/ltv.js' => 7,
        '202-js/p202-chrome.js' => 1,
        '202-js/p202-overview.js' => 20,
        '202-js/p202-setup.js' => 13,
        'tracking202/ajax/custom_variables.php' => 1,
        'tracking202/ajax/delete_tracker.php' => 1,
        'tracking202/ajax/dni_get_offers.php' => 1,
        'tracking202/ajax/get_adv_landing_code.php' => 1,
        'tracking202/ajax/get_landing_code.php' => 1,
        'tracking202/ajax/ltv_companies.php' => 2,
        'tracking202/ajax/ltv_customer.php' => 3,
        'tracking202/ajax/ltv_merge_modal.php' => 1,
        'tracking202/ajax/ltv_products.php' => 1,
        'tracking202/ajax/ltv_settings.php' => 1,
        'tracking202/ajax/ltv_subscriptions.php' => 1,
        'tracking202/ajax/ltv_ui.php' => 1,
        'tracking202/ajax/sort_ltv.php' => 5,
        'tracking202/update/clear-subids.php' => 1,
    ];

    /** @var array<string, int> Inline style attributes each file still has. */
    private const INLINE_STYLE_ALLOWED = [
        '202-account/api-integrations.php' => 2,
        '202-account/disclosure.php' => 5,
        '202-account/ui-kit.php' => 7,
        '202-config/Conversion/TrafficSourcePixels.php' => 2,
        '202-config/ReportBasicForm.class.php' => 8,
        '202-config/ReportSummaryForm.class.php' => 2,
        '202-config/Setup/PostbackCode.php' => 4,
        '202-config/connect.php' => 1,
        '202-config/functions-standalone-ui.php' => 1,
        '202-config/functions-tracking202.php' => 1,
        '202-config/functions-ui-overview.php' => 3,
        '202-config/functions-ui-partials.php' => 3,
        '202-config/functions.php' => 1,
        '202-config/template.php' => 1,
        '202-js/ltv.js' => 2,
        '202-js/p202-setup.js' => 8,
        'tracking202/ajax/account_overview.php' => 1,
        'tracking202/ajax/click_history_row.php' => 2,
        'tracking202/ajax/group_overview.php' => 1,
        'tracking202/ajax/ltv_companies.php' => 3,
        'tracking202/ajax/ltv_customer.php' => 9,
        'tracking202/ajax/ltv_merge_modal.php' => 2,
        'tracking202/ajax/ltv_products.php' => 3,
        'tracking202/ajax/ltv_settings.php' => 8,
        'tracking202/ajax/ltv_subscriptions.php' => 2,
        'tracking202/ajax/sort_ltv.php' => 3,
        'tracking202/analyze/ltv.php' => 2,
        'tracking202/analyze/templates/mobile_apps/_funnel.php' => 1,
        'tracking202/analyze/templates/report.php' => 1,
        'tracking202/redirect/cl.php' => 1,
        'tracking202/redirect/cl2.php' => 1,
        'tracking202/redirect/lpc.php' => 1,
        'tracking202/redirect/off.php' => 2,
        'tracking202/redirect/offrtr.php' => 2,
        'tracking202/setup/aff_campaigns.php' => 1,
        'tracking202/setup/get_trackers.php' => 1,
        'tracking202/setup/rotator.php' => 1,
        'tracking202/setup/templates/mobile_apps/_goals.php' => 1,
    ];

    public function testNoServedFileAddsJqueryOrAnInlineStyle(): void
    {
        $root = dirname(__DIR__, 3);
        $files = $this->servedFiles($root);
        self::assertGreaterThan(400, count($files), 'the sweep walked the tree');
        foreach (self::MUST_SCAN as $file) {
            self::assertContains($file, $files, "$file is swept");
        }

        $found = ['jQuery' => [], 'inline style' => []];
        foreach ($files as $file) {
            $source = file_get_contents($root . '/' . $file);
            self::assertIsString($source, "$file is readable");
            foreach (['jQuery' => self::JQUERY, 'inline style' => self::INLINE_STYLE] as $what => $pattern) {
                $count = preg_match_all($pattern, $source);
                self::assertIsInt($count, "$file is scanned for $what");
                if ($count > 0) {
                    $found[$what][$file] = $count;
                }
            }
        }

        $problems = [];
        $lists = ['jQuery' => self::JQUERY_ALLOWED, 'inline style' => self::INLINE_STYLE_ALLOWED];
        foreach ($lists as $what => $allowed) {
            foreach ($found[$what] as $file => $count) {
                $listed = $allowed[$file] ?? 0;
                if ($count > $listed) {
                    $problems[] = "$file: $count $what uses, $listed allowed: use the kit's components and"
                        . ' p202-ui.js or a stylesheet class (202-account/ui-kit.php), not jQuery or style=';
                } elseif ($count < $listed) {
                    $problems[] = "$file: $count $what uses, $listed listed: lower its entry, the list only shrinks";
                }
            }
            foreach (array_diff_key($allowed, $found[$what]) as $file => $listed) {
                $problems[] = "$file: no $what uses left (or gone), $listed listed: remove its entry";
            }
        }
        self::assertSame([], $problems, implode("\n", $problems));
    }

    /** @return array<string, array{0: string, 1: int, 2: int}> */
    public static function shapes(): array
    {
        return [
            'a jQuery call' => ['$(document).on("click", f);', 1, 0],
            'a jQuery utility' => ['$.ajax({url: u});', 1, 0],
            'jQuery by name' => ['jQuery(function () {});', 1, 0],
            'the jQuery asset' => ["<?php echo p202_asset('jquery.js'); ?>", 1, 0],
            'jQuery written from PHP' => ['<?php echo "<script>\$(\'#x\').hide();</script>"; ?>', 1, 0],
            'PHP variables and template literals' => ['<?php $this->x = $style; ?> `${a}`', 0, 0],
            'a double-quoted style' => ['<div style="color: red">', 0, 1],
            'a single-quoted style' => ["<div style='color: red'>", 0, 1],
            'a style in an escaped string' => ['<?php echo "<td style=\"width:1px\">"; ?>', 0, 1],
            'a style with spaces' => ['<p STYLE = "x">', 0, 1],
            'a script setting the attribute' => ["el.setAttribute('style', 'x');", 0, 1],
            'not a style attribute' => ['el.style.color = "red"; $style = "x"; <a data-style="x">', 0, 0],
        ];
    }

    /** @dataProvider shapes */
    public function testTheScannerSeesEachSpelling(string $source, int $jquery, int $styles): void
    {
        self::assertSame($jquery, preg_match_all(self::JQUERY, $source), 'jQuery uses');
        self::assertSame($styles, preg_match_all(self::INLINE_STYLE, $source), 'inline styles');
    }

    public function testTheFlashChartsAreGone(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertDirectoryDoesNotExist($root . '/202-charts', 'nothing renders the Flash charts');
        $manifest = require $root . '/build/release-manifest.php';
        self::assertNotContains('202-charts', $manifest['ship'], 'the release zip does not ship them');
        self::assertNotContains('202-charts', $manifest['exclude']);
    }

    /** @return list<string> */
    private function servedFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                \Tests\Support\SourceScan::tree($root),
                static function (\SplFileInfo $file) use ($root): bool {
                    $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
                    foreach (self::SKIP_DIRS as $skip) {
                        if ($relative === $skip || str_starts_with($relative, $skip . '/')) {
                            return false;
                        }
                    }
                    return $file->isDir() || preg_match('/\.(php|js|html?)$/', $file->getFilename()) === 1;
                }
            )
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }
        sort($files);

        return $files;
    }
}
