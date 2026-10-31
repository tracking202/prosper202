<?php

declare(strict_types=1);

namespace Tests\Report;

use Prosper202\DataEngine\ReportView;
use Tests\TestCase;

/**
 * A report page's view, carried to the requests it makes later.
 *
 * The stored filters are one row per user; a second tab writes it. So a v2
 * report page hands its fragment, poll and download the view it rendered
 * (p202_report_view_query()), and that request installs it
 * (p202_report_view_from_request()) over the row every reader reads
 * (ReportView::apply()) — in memory, for that request only. What is pinned
 * here: the round trip is exact, a view that does not read is refused
 * rather than dropped, and the overlay touches only the viewing user's row
 * and only the columns the reader selected. tests/browser's
 * overview-visitors-spy spec drives the two-tab case end to end;
 * ReportViewReadersTest holds every reader of the row to apply().
 */
final class ReportViewTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        require_once $root . '/202-config/functions-ui.php';
        require_once $root . '/202-config/functions-report-prefs.php';
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        ReportView::reset();
    }

    protected function tearDown(): void
    {
        ReportView::reset();
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    public function testNothingInstalledLeavesTheStoredRowAsItIs(): void
    {
        $row = ['user_pref_show' => 'leads', 'user_pref_limit' => '50'];
        self::assertSame($row, ReportView::apply($row, 7));
        self::assertFalse(ReportView::active());
    }

    public function testAnInstalledViewOverlaysOnlyTheViewersRowAndOnlyTheColumnsRead(): void
    {
        ReportView::install(7, ['user_pref_show' => 'all', 'user_pref_limit' => '10', 'user_pref_ppc_network_id' => null]);
        self::assertTrue(ReportView::active());

        $stored = ['user_pref_show' => 'leads', 'user_pref_limit' => '50', 'user_pref_ppc_network_id' => '3', 'user_account_currency' => 'EUR'];
        self::assertSame(
            ['user_pref_show' => 'all', 'user_pref_limit' => '10', 'user_pref_ppc_network_id' => null, 'user_account_currency' => 'EUR'],
            ReportView::apply($stored, 7),
            'the view wins for its columns; everything else is the stored row'
        );
        self::assertSame(['user_pref_show' => 'all'], ReportView::apply(['user_pref_show' => 'leads'], '7'),
            'a reader that selected one column gets one column back, and the id may arrive as a string');
        self::assertSame($stored, ReportView::apply($stored, 8), 'another user\'s row is never overlaid');
        self::assertSame(
            ['user_pref_show' => 'all', 'user_pref_limit' => '10', 'user_pref_ppc_network_id' => null],
            ReportView::apply([], 7),
            'a missing row (a new account) reads as the view'
        );
    }

    public function testInstallRefusesWhatIsNotAReportColumn(): void
    {
        foreach ([
            'a settings column' => [7, ['user_slack_incoming_webhook' => 'x']],
            'no user' => [0, ['user_pref_show' => 'all']],
            'a non-string value' => [7, ['user_pref_limit' => 10]],
        ] as $case => [$user, $columns]) {
            try {
                ReportView::install($user, $columns);
                self::fail("$case was installed");
            } catch (\InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
        self::assertFalse(ReportView::active(), 'nothing half-installed');
    }

    public function testAPagesViewRoundTripsToTheColumnsThePageWrote(): void
    {
        $names = ['ppc_network_id', 'user_pref_show', 'country_id', 'keyword', 'user_pref_limit'];
        $values = ['ppc_network_id' => '', 'user_pref_show' => 'real', 'country_id' => '12', 'keyword' => 'shoes & socks', 'user_pref_limit' => '25'];
        $view = p202_report_view_query($names, $values, ['range' => P202_RANGE_CUSTOM, 'from' => '2026-08-01', 'to' => '2026-08-31']);

        $wrote = p202_report_prefs_from_query(['range' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31'] + $values, array_merge(['range'], $names));
        self::assertSame([], $wrote['errors']);

        self::assertSame($view, p202_report_view_from_request([ReportView::PARAM => $view], 7));
        self::assertSame(
            $wrote['columns'],
            array_intersect_key(ReportView::apply(array_fill_keys(array_keys($wrote['columns']), 'stored'), 7), $wrote['columns']),
            'the request draws exactly what the page wrote, "not filtering" included'
        );
        $row = ReportView::apply(['user_pref_ppc_network_id' => '9', 'user_pref_time_predefined' => 'today'], 7);
        self::assertNull($row['user_pref_ppc_network_id'], 'an empty filter in the view clears a stored one: another tab\'s filter does not leak in');
        self::assertSame('', $row['user_pref_time_predefined'], 'and a custom window replaces a stored preset');
    }

    public function testAPresetWindowTravelsAsItsName(): void
    {
        $view = p202_report_view_query(['user_pref_show'], ['user_pref_show' => 'all'], ['range' => 'last7', 'from' => '2026-09-18', 'to' => '2026-09-25']);
        parse_str($view, $query);
        self::assertSame(['range' => 'last7', 'user_pref_show' => 'all'], $query, 'a preset is resolved when the request runs, not frozen to the page\'s dates');
    }

    public function testAStoredValueThePageWouldRefuseIsLeftOutRatherThanBreakingTheView(): void
    {
        // The classic calendar stored "landingpages", which no v2 control
        // sends: the view leaves that one column to the stored row.
        $view = p202_report_view_query(['method_of_promotion', 'user_pref_show'], ['method_of_promotion' => 'landingpages', 'user_pref_show' => 'leads'], null);
        parse_str($view, $query);
        self::assertSame(['user_pref_show' => 'leads'], $query);
        p202_report_view_from_request([ReportView::PARAM => $view], 7);
        self::assertSame(['user_pref_method_of_promotion' => 'landingpages', 'user_pref_show' => 'leads'],
            ReportView::apply(['user_pref_method_of_promotion' => 'landingpages', 'user_pref_show' => 'all'], 7));
    }

    public function testNoViewMeansTheStoredFiltersStand(): void
    {
        self::assertSame('', p202_report_view_from_request(['offset' => '2'], 7));
        self::assertFalse(ReportView::active());
    }

    public function testAViewThatDoesNotReadIsRefusedNotDropped(): void
    {
        foreach ([
            'not a report filter' => 'user_pref_show=all&user_slack_incoming_webhook=x',
            'dates with no range' => 'from=2026-01-01&to=2026-01-02',
            'a value the page would refuse' => 'user_pref_limit=500',
            'an impossible date' => 'range=custom&from=2026-02-30&to=2026-03-01',
            'a grouping on a report with none' => 'group_1=1',
            'an id that is not one' => 'country_id=12abc',
        ] as $case => $view) {
            try {
                p202_report_view_from_request([ReportView::PARAM => $view], 7);
                self::fail("$case was accepted");
            } catch (\InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage(), "$case says why");
            }
            self::assertFalse(ReportView::active(), "$case installed nothing");
        }
        $this->expectException(\InvalidArgumentException::class);
        p202_report_view_from_request([ReportView::PARAM => ['user_pref_show' => 'all']], 7);
    }

    public function testAGroupedViewReadsAgainstTheGroupingsOffered(): void
    {
        $view = p202_report_view_query(['group_1', 'group_2'], ['group_1' => '4', 'group_2' => '0'], null, ['1', '4']);
        p202_report_view_from_request([ReportView::PARAM => $view], 7, ['1', '4']);
        self::assertSame(['user_pref_group_1' => '4', 'user_pref_group_2' => '0'], ReportView::apply(['user_pref_group_1' => '1', 'user_pref_group_2' => '1'], 7));
    }

    public function testAViewIsAddedToAUrlWithOrWithoutAQuery(): void
    {
        self::assertSame('/f.php?view=a%3D1%26b%3D2', p202_report_view_url('/f.php', 'a=1&b=2'));
        self::assertSame('/f.php?spy=1&view=a%3D1', p202_report_view_url('/f.php?spy=1', 'a=1'));
        self::assertSame('/f.php?view=a%3D1#top', p202_report_view_url('/f.php#top', 'a=1'));
        self::assertSame('/f.php', p202_report_view_url('/f.php', ''), 'no view, no parameter');
    }
}
