<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;

/**
 * Customer LTV draws the window its page drew, not the stored one (#163).
 *
 * ltv.php stores the window and draws it; the partials 202-js/ltv.js loads
 * and ltv_download.php read it back later, each in its own request. Reading
 * the stored row there let a second tab that stored another window change
 * what the first tab's next view showed. So the page installs its view and
 * hands it to ltv.js, ltv.js sends it with every request under
 * tracking202/ajax/, and every request that can draw the window installs it
 * before it reads anything. This pins each link of that chain in the
 * source; the browser pass (analyze-ltv.spec.js, "A second tab's window")
 * drives the two-tab case end to end.
 */
final class LtvReportViewTest extends TestCase
{
    private const BEGIN = "require_once(substr(__DIR__, 0, -17) . '/202-config/functions-report-prefs.php');\n\$reportView = p202_report_view_begin();";

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string> the partial behind each view ltv.js routes */
    private static function routedPartials(): array
    {
        $js = (string) file_get_contents(self::root() . '/202-js/ltv.js');
        self::assertSame(1, preg_match('/var views = \{(.*?)\};/s', $js, $m), 'ltv.js names its views');
        preg_match_all("/(\w+): '(\w+)'/", $m[1], $pairs, PREG_SET_ORDER);
        $views = [];
        foreach ($pairs as [, $view, $partial]) {
            $views[$view] = 'tracking202/ajax/' . $partial . '.php';
        }
        self::assertCount(7, $views, 'every view ltv.js routes was read');
        return $views;
    }

    public function testEveryRequestThatCanDrawTheWindowInstallsTheViewFirst(): void
    {
        $files = array_values(self::routedPartials());
        $files[] = 'tracking202/analyze/ltv_download.php';
        foreach ($files as $file) {
            $source = (string) file_get_contents(self::root() . '/' . $file);
            $login = strpos($source, "AUTH::require_user();\n");
            self::assertNotFalse($login, "$file checks the login");
            $expected = $file === 'tracking202/analyze/ltv_download.php'
                ? "require_once \$rootPath . '/202-config/functions-report-prefs.php';\np202_report_view_begin();"
                : self::BEGIN;
            $at = strpos($source, $expected);
            self::assertNotFalse($at, "$file installs the view the page drew");
            self::assertSame(1, substr_count($source, 'p202_report_view_begin('), "$file installs it once");
            // Nothing between the login check and the install reads the
            // window, directly or through the tabs.
            $between = substr($source, $login, $at - $login);
            foreach (['grab_timeframe(', 'p202_ltv_tabs(', 'p202_ltv_window_query(', '202_users_pref', 'include', 'require '] as $read) {
                self::assertStringNotContainsString($read, $between, "$file reads the window ($read) before it installs the view");
            }
            // And it sits at the top level, not under a condition.
            $depth = 0;
            foreach (\PhpToken::tokenize(substr($source, 0, (int) $at)) as $token) {
                $depth += match ($token->text) { '{' => 1, '}' => -1, default => 0 };
            }
            self::assertSame(0, $depth, "$file installs the view unconditionally");
        }
    }

    public function testThePageHandsItsViewToTheRouterAndTheRouterSendsIt(): void
    {
        $page = (string) file_get_contents(self::root() . '/tracking202/analyze/ltv.php');
        self::assertStringContainsString("\$view = p202_report_view_query([], [], \$window);\np202_report_view_from_request([\\Prosper202\\DataEngine\\ReportView::PARAM => \$view], \$userId);", $page,
            'the page installs the view it draws');
        self::assertStringContainsString('data-ltv-view="<?php echo $e($view); ?>"', $page, 'and hands it to ltv.js');

        $js = (string) file_get_contents(self::root() . '/202-js/ltv.js');
        self::assertStringContainsString("reportView = content.getAttribute('data-ltv-view') || '';", $js, 'ltv.js reads the view');
        self::assertStringContainsString("window.jQuery.ajaxPrefilter(function (options) {\n                options.url = withView(options.url);", $js,
            'and puts it on every request it and the partials make');

        $sort = (string) file_get_contents(self::root() . '/tracking202/ajax/sort_ltv.php');
        self::assertStringContainsString("p202_report_view_url(get_absolute_url() . 'tracking202/analyze/ltv_download.php', \$reportView)", $sort,
            'the download link exports the view on screen');
        self::assertStringNotContainsString("'tracking202/analyze/ltv_download.php\" ", $sort);
    }

    /**
     * The page reads the window and nothing else: a `page` or `order` that
     * does not parse neither blocks the save nor is reported as the range's
     * fault (#163), as AnalyzeReportController does for its reports.
     */
    public function testAStrayPageOrOrderDoesNotHoldTheWindowBack(): void
    {
        $page = (string) file_get_contents(self::root() . '/tracking202/analyze/ltv.php');
        self::assertSame(1, preg_match('/^\$errors = array_diff_key\(\$input->errors, \[\'page\' => true, \'order\' => true\]\);$/m', $page),
            'the parse errors of fields the page does not read are set aside');
        self::assertSame(1, substr_count($page, '$input->errors'), 'and nothing else reads the unfiltered errors');

        $input = \Tracking202\Report\ReportFilterInput::fromQuery(['range' => 'last7', 'order' => 'not-a-column', 'page' => 'x'], []);
        self::assertTrue($input->speaks);
        self::assertArrayHasKey('order', $input->errors, 'the reader still refuses the sort it cannot read');
        self::assertSame([], array_diff_key($input->errors, ['page' => true, 'order' => true]), 'and that is all it refuses');
        self::assertSame('last7', $input->window['range'] ?? null, 'the window is read regardless');
    }
}
