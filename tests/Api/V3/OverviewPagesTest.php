<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * The Overview, Visitors and Spy pages on the v2 shell
 * (202-config/functions-ui-overview.php).
 *
 * The pages are thin: each names its filters and its fragment, and
 * p202_overview_run() does the rest. What can go wrong without a browser
 * noticing is pinned here: a page that stops opting into the v2 shell, a
 * fragment a page loads that NoLegacyBootstrapClassesTest does not scan, the
 * grouping ids spelled out beside ReportBasicForm drifting from it, and the
 * pure helpers the fragments draw with. What only a browser can say is in
 * tests/browser/specs/overview-visitors-spy.spec.js.
 */
final class OverviewPagesTest extends TestCase
{
    private const PAGES = [
        'tracking202/overview/index.php',
        'tracking202/overview/breakdown.php',
        'tracking202/overview/day-parting.php',
        'tracking202/overview/week-parting.php',
        'tracking202/overview/group-overview.php',
        'tracking202/overview/rotator-breakdown.php',
        'tracking202/visitors/index.php',
        'tracking202/spy/index.php',
    ];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
        require_once $this->root . '/202-config/functions-ui.php';
        require_once $this->root . '/202-config/functions-ui-overview.php';
    }

    public function testEveryPageOfTheFamilyOptsIntoTheV2ShellThroughTheRecipe(): void
    {
        foreach (self::PAGES as $page) {
            $source = (string) file_get_contents($this->root . '/' . $page);
            self::assertStringContainsString("'shell' => ['ui' => 'v2']", $source, "$page names the v2 shell");
            self::assertSame(1, preg_match_all('/\bp202_overview_run\(/', $source), "$page is served by p202_overview_run()");
            self::assertStringNotContainsString('display_calendar(', $source, "$page no longer renders the classic calendar");
            self::assertStringNotContainsString('loadContent(', $source, "$page no longer calls the classic loader in custom.php, which v2 does not load");
        }
    }

    public function testEveryFragmentAPageLoadsIsScannedForLegacyClasses(): void
    {
        $scanned = (new \ReflectionClassConstant(NoLegacyBootstrapClassesTest::class, 'V2_SHARED'))->getValue();
        self::assertContains('202-js/p202-overview.js', $scanned, 'the script that draws the fragments is scanned');
        $fragments = [];
        foreach (self::PAGES as $page) {
            $source = (string) file_get_contents($this->root . '/' . $page);
            preg_match_all("#'tracking202/ajax/([a-z_]+\\.php)#", $source, $m);
            self::assertNotEmpty($m[1], "$page names the fragment it draws");
            foreach ($m[1] as $file) {
                $fragments[] = 'tracking202/ajax/' . $file;
            }
        }
        // click_history.php includes its row template, which draws every row.
        $fragments[] = 'tracking202/ajax/click_history_row.php';
        foreach (array_unique($fragments) as $fragment) {
            self::assertContains($fragment, $scanned, "$fragment is drawn into a v2 page, so NoLegacyBootstrapClassesTest must scan it");
        }
    }

    public function testTheSpelledOutGroupingIdsAreReportBasicFormsOwn(): void
    {
        require_once $this->root . '/202-config/ReportSummaryForm.class.php';
        self::assertSame((string) \ReportBasicForm::DETAIL_LEVEL_NONE, P202_OVERVIEW_GROUP_NONE);
        self::assertSame((string) \ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK, P202_OVERVIEW_GROUP_TRAFFIC_SOURCE);
        self::assertArrayHasKey(P202_OVERVIEW_GROUP_TRAFFIC_SOURCE, p202_overview_groupings(), 'the default grouping is one the menu offers');
        self::assertArrayNotHasKey(P202_OVERVIEW_GROUP_NONE, p202_overview_groupings(), '"none" is not a first grouping');
    }

    public function testResetNamesAValueEveryFilterAccepts(): void
    {
        // Reset is a URL like any other set of filters, so every default it
        // carries must survive the reader, or Reset would be refused.
        $defaults = p202_overview_defaults();
        $names = array_merge(['range'], P202_OVERVIEW_CLICK_FILTERS, ['user_pref_limit', 'user_pref_breakdown', 'user_cpc_or_cpv', 'group_1', 'group_2', 'group_3', 'group_4']);
        $query = [];
        foreach ($names as $name) {
            $query[$name] = $defaults[$name] ?? '';
        }
        $read = p202_report_prefs_from_query($query, $names, array_keys(p202_overview_groupings()), P202_OVERVIEW_GROUP_NONE);
        self::assertSame([], $read['errors']);
    }

    /**
     * @return array<string, array{string, ?float}>
     */
    public static function figures(): array
    {
        return [
            'money' => ['$1,304.00', 1304.0],
            'a negative in brackets' => ['($2.40)', -2.4],
            'a negative sign' => ['-$3.50', -3.5],
            'a percentage' => ['8,233%', 8233.0],
            'a count' => ['1,412', 1412.0],
            'escaped as the formatter escapes it' => ['&#36;12.50', 12.5],
            'a masked figure' => ['?', null],
            'nothing' => ['', null],
        ];
    }

    /**
     * @dataProvider figures
     */
    public function testAFormattedFigureSortsByItsNumber(string $formatted, ?float $number): void
    {
        self::assertSame($number, p202_overview_number($formatted));
    }

    public function testTheMetricsTableKeepsTotalsLastAndHidesFiguresFromWhoMayNotSeeThem(): void
    {
        $row = ['label' => 'Sep 25', 'clicks' => '30', 'click_out' => '6', 'ctr' => '20%', 'leads' => '24', 'su_ratio' => '80%',
            'payout' => '$8.33', 'epc' => '$6.67', 'cpc' => '$0.08', 'income' => '$200.00', 'cost' => '$2.40', 'net' => '$197.60', 'roi' => '8,233%'];
        $totals = [];
        foreach ($row as $key => $value) {
            $totals['total_' . $key] = $value;
        }
        $options = ['label' => 'Time', 'key' => static fn (array $r): string => $r['label']];

        $open = p202_overview_metrics_table([$row, $totals], $options);
        self::assertStringContainsString('<tr class="p202-table__totals no-sort">', $open, 'the totals row is the partial\'s, pinned last');
        self::assertStringContainsString('($2.40)', $open, 'cost reads as the classic table read it');
        self::assertStringContainsString('<span class="text-success">$197.60</span>', $open, 'a positive net is marked');
        self::assertStringContainsString('data-p202-sort', $open, 'an unpaginated table sorts in the browser');

        $masked = p202_overview_metrics_table([$row, $totals], $options + ['masked' => true]);
        self::assertStringNotContainsString('$200.00', $masked, 'income is hidden from a user without campaign data');
        self::assertStringNotContainsString('>30<', $masked, 'and so are clicks');
        self::assertStringContainsString('$8.33', $masked, 'while the averages DisplayData shows them stay');

        $empty = p202_overview_metrics_table([$totals], $options + ['empty' => p202_overview_empty('/')]);
        self::assertStringContainsString('p202-empty__title', $empty, 'no rows is an empty state, not a table of totals');
        self::assertStringContainsString('/tracking202/setup/get_trackers.php', $empty, 'which offers the first step');
    }

    public function testPageLinksCarryTheirOffsetAndMarkTheCurrentPage(): void
    {
        self::assertSame('', p202_overview_pagination(1, 0), 'one page needs no links');
        $html = p202_overview_pagination(3, 1);
        self::assertSame(3, substr_count($html, 'data-p202-offset="') - 2, 'three numbered links, plus previous and next');
        self::assertStringContainsString('<li class="page-item active" aria-current="page"><a class="page-link" href="?offset=1" data-p202-offset="1">2</a>', $html);
        self::assertStringContainsString('href="?offset=0" data-p202-offset="0" aria-label="Previous page"', $html);
        self::assertStringContainsString('href="?offset=2" data-p202-offset="2" aria-label="Next page"', $html);
        $last = p202_overview_pagination(3, 2);
        self::assertStringContainsString('<li class="page-item disabled"><span class="page-link" aria-hidden="true">&rsaquo;</span>', $last, 'there is no next page after the last');
    }
}
