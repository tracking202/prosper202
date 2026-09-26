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

    /**
     * Regions, ISPs, browsers and platforms are install-wide lookups that grow
     * with every account's clicks; the classic calendar's GROUP BY over them
     * put all of them in a <select>. The lists come from this account's
     * clicks in the window instead, bounded, and the filter in force keeps
     * its name. What runs against a real server is driven in
     * overview-visitors-spy.spec.js; this pins the shape of every query.
     */
    public function testTheUnboundedListsAreThisAccountsClicksInTheWindowAndBounded(): void
    {
        $fake = new \Tests\Support\FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('FROM 202_dataengine', [['id' => 9, 'label' => 'Zeta Telecom'], ['id' => 4, 'label' => 'alpha net']]);
        $fake->whenQueryContainsReturnRows('FROM 202_locations_isp AS l WHERE', [['label' => 'Chosen ISP']]);
        $conn = new \Prosper202\Database\Connection($fake);

        $lists = p202_overview_filter_lists($conn, 7, ['isp_id', 'region_id', 'browser_id', 'platform_id'], [
            'data_user_id' => 7, 'from' => 1000, 'to' => 2000, 'values' => ['isp_id' => '55'],
        ]);

        foreach ($fake->statements as $stmt) {
            self::assertDoesNotMatchRegularExpression('/GROUP BY\s+\w*_name/i', $stmt->sql, 'no list is every name in an install-wide lookup: ' . $stmt->sql);
            if (str_contains($stmt->sql, 'FROM 202_dataengine')) {
                self::assertMatchesRegularExpression('/\bLIMIT ' . P202_OVERVIEW_SEEN_LIMIT . '$/', $stmt->sql, 'bounded');
                self::assertStringContainsString('d.user_id = ? AND d.click_time >= ? AND d.click_time <= ?', $stmt->sql, 'this account\'s clicks, in the window');
                self::assertSame([7, 1000, 2000], $stmt->boundValues);
            }
        }
        self::assertCount(4, $fake->statementsContaining('FROM 202_dataengine'), 'one bounded query per list');
        self::assertSame(['4' => 'alpha net', '55' => 'Chosen ISP', '9' => 'Zeta Telecom'], $lists['isp_id'],
            'the clicks\' values in name order, and the filter in force with its own name though no click in the window carried it');
        $lookup = $fake->statementsContaining('FROM 202_locations_isp AS l WHERE');
        self::assertCount(1, $lookup, 'the one value not seen is looked up by id, once');
        self::assertSame([55], $lookup[0]->boundValues);

        $all = new \Tests\Support\FakeMysqliConnection();
        p202_overview_seen_list(new \Prosper202\Database\Connection($all), 'browser_id', null, 1, 2);
        self::assertStringContainsString('d.user_id != 0', $all->statements[0]->sql, 'a user who sees every campaign sees every account\'s values, as DataEngine reports them');
        self::assertSame([1, 2], $all->statements[0]->boundValues);

        $this->expectException(\InvalidArgumentException::class);
        p202_overview_seen_list($conn, 'country_id', 7, 1, 2);
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

    /**
     * A page link followed as a navigation (middle-click, a new tab, no
     * script) opens the page with the filters the fragment was drawn under,
     * not with whatever another tab has stored since (#162). The href is
     * the view as the page's own query, so the page applies it as it would
     * any link naming its filters.
     */
    public function testAPageLinkFollowedWithoutScriptCarriesTheViewItWasDrawnUnder(): void
    {
        $view = p202_report_view_query(['country_id', 'keyword'], ['country_id' => '12', 'keyword' => 'a&b c'], ['range' => P202_RANGE_CUSTOM, 'from' => '2026-01-02', 'to' => '2026-01-05']);
        self::assertNotSame('', $view);
        $html = p202_overview_pagination(3, 1, 'Pages of clicks', $view);
        self::assertSame(1, preg_match('/<a class="page-link" href="([^"]*)" data-p202-offset="2" aria-label="Next page"/', $html, $m), $html);
        $href = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        self::assertStringStartsWith('?', $href);
        parse_str(substr($href, 1), $query);
        self::assertSame('2', $query['offset'] ?? null, 'the link still says which page');
        unset($query['offset']);
        parse_str($view, $expected);
        self::assertSame($expected, $query, 'every filter and the window the fragment was drawn under, and nothing else');
        self::assertSame('2026-01-02', $query['from'] ?? null);
        self::assertSame('a&b c', $query['keyword'] ?? null, 'a value with a separator in it survives the markup');

        $read = p202_report_prefs_from_query($query, ['range', 'country_id', 'keyword'], [], P202_REPORT_GROUP_NONE);
        self::assertSame([], $read['errors'], 'the page accepts the link as its own filters');

        self::assertStringContainsString('href="?offset=2"', p202_overview_pagination(3, 1), 'a fragment drawn under no view links the offset alone, as before');

        // And every fragment that draws page links hands them the view it
        // installed: the variable p202_report_view_begin() returned, not a
        // view read some other way or none at all.
        $callers = 0;
        foreach (glob($this->root . '/tracking202/ajax/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            if (!str_contains($src, 'p202_overview_pagination(')) {
                continue;
            }
            $callers++;
            $rel = substr($file, strlen($this->root) + 1);
            self::assertSame(1, preg_match('/^(\$\w+) = p202_report_view_begin\(\);$/m', $src, $begin), "$rel installs its view in one statement");
            self::assertSame(1, preg_match_all('/p202_overview_pagination\(/', $src), "$rel draws one set of page links");
            self::assertSame(1, preg_match('/p202_overview_pagination\([^;]*,\s*' . preg_quote($begin[1], '/') . '\);/', $src), "$rel hands its page links the view it installed ({$begin[1]})");
        }
        self::assertGreaterThan(0, $callers, 'the sweep found the fragments that page');
    }

    /**
     * `defaults` names stored filter columns. The window is not one, and a
     * name the page does not offer has nothing to fill: either used to reach
     * p202_report_prefs_save() as a bogus column and throw on a first visit.
     * Both are refused by name, before anything is written (#162).
     */
    public function testADefaultForTheWindowOrAnUnofferedNameIsRefusedByName(): void
    {
        foreach ([
            ['names' => ['range', 'country_id'], 'defaults' => ['range' => 'today']],
            ['names' => ['country_id'], 'defaults' => ['not_a_filter' => '1']],
            ['names' => ['country_id'], 'defaults' => ['group_1' => '1']],
        ] as $spec) {
            $fake = new \Tests\Support\FakeMysqliConnection();
            $conn = new \Prosper202\Database\Connection($fake);
            $name = (string) array_key_first($spec['defaults']);
            try {
                p202_overview_page_state($conn, 7, $spec, []);
                self::fail("a default for '$name' was accepted");
            } catch (\InvalidArgumentException $refused) {
                self::assertStringContainsString("'$name'", $refused->getMessage());
            }
            self::assertSame([], $fake->statementsContaining('UPDATE'), 'nothing was written');
        }
    }
}
