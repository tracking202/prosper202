<?php

declare(strict_types=1);

use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\DataEngine\GroupedReportDefinition;
use Prosper202\DataEngine\GroupedReportRegistry;
use Prosper202\DataEngine\HtmlReportFormatter;
use Prosper202\DataEngine\ReportView;
use Prosper202\DataEngine\MetricsSql;
use Prosper202\DataEngine\ReportTotals;
use Prosper202\DataEngine\SortOrder;
use Prosper202\DataEngine\UserPrefFilters;
use Prosper202\Report\LocalTime;

ini_set('memory_limit', '-1');
if (!isset($_SESSION['user_timezone']) || empty($_SESSION['user_timezone'])) {
    date_default_timezone_set('GMT');
} else {
    date_default_timezone_set($_SESSION['user_timezone']);
}

/**
 * Reporting/aggregation facade for the 202_dataengine rollup table.
 *
 * This class keeps the historical global name and public API used by the
 * AJAX, download, redirect and cron entry points, and delegates the heavy
 * lifting to the Prosper202\DataEngine components (SQL builders, filter
 * builder, totals accumulator, formatter).
 */
class DataEngine
{
    /** @var array<string, string> */
    private array $mysql = [];

    private static ?mysqli $db = null;

    private static int $found_rows = 0;

    private int $forDownload = 0;

    private ?HtmlReportFormatter $formatter = null;

    public function isDatabaseConnected(): bool
    {
        return self::$db !== null;
    }

    /**
     * click_time as the wall clock of the account's zone, in SQL (LocalTime):
     * the zone the page put in force with AUTH::set_timezone(), which is the
     * zone grab_timeframe() computed the window in, so a report's hours,
     * weekdays and days are the ones its window is made of.
     *
     * The engine used to SET the connection's time_zone to that zone's
     * offset *today*, rounded to whole hours, and group by FROM_UNIXTIME():
     * India's +05:30 was +06:00 all year, every click on the far side of a
     * daylight-saving change from today was an hour out, and the setting
     * stayed on the connection for the rest of the request (the cron's
     * attribution exports ran in it). Nothing here touches the connection's
     * zone now.
     */
    private static function localClickTime(): string
    {
        return LocalTime::datetimeSql('click_time', date_default_timezone_get());
    }

    public function __construct()
    {
        try {
            self::$db = DB::getInstance()->getConnection();
        } catch (Exception) {
            self::$db = null;
        }

        if (self::$db !== null) {
            $this->mysql['user_id'] = self::$db->real_escape_string((string) ($_SESSION['user_own_id'] ?? ''));
        }

        // Whose clicks: the rule every report page reads (DataScope).
        $dataUserId = \Prosper202\DataEngine\DataScope::userId();
        $this->mysql['user_id_query'] = $dataUserId === null
            ? " WHERE 2st.user_id != '0' "
            : " WHERE 2st.user_id ='" . $dataUserId . "' ";

        // The account's clock is localClickTime(), in each query; the
        // connection's zone is not the engine's to set.
    }

    public function setDownload(): void
    {
        $this->forDownload = 1;
    }

    public function setDisplay(): void
    {
        $this->forDownload = 0;
    }

    public function foundRows(): int
    {
        return self::$found_rows;
    }

    private function runCountQuery(string $countSql): int
    {
        $result = _mysqli_query($countSql);
        if (!$result instanceof mysqli_result) {
            $error = self::$db instanceof mysqli
                ? self::$db->error
                : (($GLOBALS['db'] ?? null) instanceof mysqli ? $GLOBALS['db']->error : 'unknown');
            error_log('DataEngine count query failed: ' . $error);
            // Thrown as collectRows() throws: a count that failed is not 0
            // groups, which the pager reads as a report of one page, with no
            // way past the rows it shows.
            throw new RuntimeException('DataEngine count query failed');
        }

        $row = $result->fetch_assoc();
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Run one of a report's queries, failing as collectRows() fails: a query
     * that did not run is not a report with no rows.
     */
    private function reportQuery(string $sql): mysqli_result
    {
        $result = _mysqli_query($sql);
        if (!$result instanceof mysqli_result) {
            $error = self::$db instanceof mysqli ? self::$db->error : 'unknown';
            error_log('DataEngine report query failed: ' . $error);
            throw new RuntimeException('DataEngine report query failed');
        }

        return $result;
    }

    /**
     * The FROM/JOIN/WHERE shared by a grouped report and its pagination count.
     *
     * Both queries must see exactly the same rows, so they build this fragment from
     * the same place. Note $filters['join'] is conditional: the keyword report already
     * owns the 2k alias that the keyword preference filter joins under, so including
     * it there would be a duplicate-alias SQL error.
     *
     * @param array{join: string, filter: string} $filters
     */
    private function groupedReportFrom(GroupedReportDefinition $definition, string $from, string $to, array $filters): string
    {
        return ' FROM 202_dataengine as 2st '
            . ($definition->includeFilterJoin ? $filters['join'] : '')
            . $definition->joins
            . $this->mysql['user_id_query']
            . ' AND click_time >= ' . $from
            . ' AND click_time <= ' . $to
            . $filters['filter'];
    }

    /**
     * Count the groups a grouped report produces, for pagination.
     *
     * This counts the report's own GROUP BY, over the report's own joins. The previous
     * implementation counted DISTINCT foreign keys on 202_dataengine instead — a cheaper
     * query, but a different one: reports group by the *joined name* (region_name,
     * text_ad_name, …), not the id. Any two ids sharing a name, or several ids with no
     * lookup row (which all collapse into a single NULL-name group), made the count
     * exceed the number of rows actually returned. That inflated totalRows and could
     * advertise a trailing page that renders empty. Counting the real grouping cannot
     * disagree with the rows by construction.
     *
     * @param array{join?: string, filter?: string} $filters
     */
    private function countReportGroups(GroupedReportDefinition $definition, string $from, string $to, array $filters): int
    {
        if (!isset($filters['join'], $filters['filter'])) {
            return 0;
        }

        // The inner SELECT is the report's labelSelect so that a groupBy naming a SELECT
        // alias (the ip and referer reports) still resolves.
        $countSql = 'SELECT COUNT(*) AS cnt FROM ('
            . ' SELECT ' . $definition->labelSelect
            . $this->groupedReportFrom($definition, $from, $to, $filters)
            . ' GROUP BY ' . $definition->groupBy
            . ') AS report_groups';

        return $this->runCountQuery($countSql);
    }

    public function getReportData($reportType, $clickFrom, $clickTo, $cpv): mixed
    {
        // Fix #9: grouped dimension reports dispatch through GroupedReportRegistry
        // directly, eliminating the 12 one-line wrapper methods that were the
        // only callers of runGroupedReport($this->groupedDefinition(...)).
        // The ip report still needs resolveIpv6Functions() called first (via
        // groupedDefinition), which the path below handles correctly.
        $groupedTypes = [
            'keyword', 'textad', 'referer', 'ip', 'country', 'region', 'city',
            'isp', 'landingpage', 'device', 'browser', 'platform',
        ];

        if (in_array($reportType, $groupedTypes, true)) {
            return $this->runGroupedReport($this->groupedDefinition($reportType), $clickFrom, $clickTo, $cpv);
        }

        return match ($reportType) {
            'LpOverview' => $this->doLpOverviewReport($clickFrom, $clickTo, $cpv),
            'campaignOverview' => $this->doCampaignOverviewReport($clickFrom, $clickTo, $cpv),
            'slp_direct_link_per_ppc' => $this->doPerPpcReport('slp_direct_link', $clickFrom, $clickTo, $cpv),
            'alp_per_ppc' => $this->doPerPpcReport('alp', $clickFrom, $clickTo, $cpv),
            'breakdown' => $this->doBreakdownReport($clickFrom, $clickTo, $cpv),
            'hourly' => $this->doHourlyReport($clickFrom, $clickTo, $cpv),
            'weekly' => $this->doWeeklyReport($clickFrom, $clickTo, $cpv),
            'variable' => $this->doVariableReport($clickFrom, $clickTo, $cpv),
            default => null,
        };
    }

    /**
     * Build the user-preference filter fragments (JOIN/WHERE/LIMIT) for the
     * current request.
     *
     * @return array{join: string, filter: string, limit: string}
     */
    public function getFilters(): array
    {
        if (!self::$db instanceof mysqli) {
            throw new Exception('Database connection not available');
        }

        // Fix #4: resolve the IPv6 globals once per filter build, so every
        // report sees consistent SQL function names regardless of type.
        $this->resolveIpv6Functions();

        $userId = self::$db->real_escape_string((string) $_SESSION['user_id']);
        $offset = (isset($_POST['offset']) && $_POST['offset'] != '')
            ? (int) self::$db->real_escape_string((string) $_POST['offset'])
            : 0;

        $user_result = _mysqli_query("SELECT * FROM 202_users_pref WHERE user_id=" . $userId);
        if (!$user_result) {
            // Real DB failure — keep throwing (collectRows will log it if it
            // gets that far, but here we must abort before building filters).
            throw new Exception('Unable to load user report preferences');
        }
        // Fix #2: a missing pref row (brand-new account) should degrade
        // gracefully, not 500.  Build filters from an empty row → all defaults.
        $user_row = ReportView::apply($user_result->fetch_assoc() ?: [], $_SESSION['user_id']);

        // The keyword, referer and IP filters are subqueries over the stored
        // values (TextFilterSql); their text is quoted through this
        // connection. They used to be resolved here first: the referer to a
        // GROUP_CONCAT id list the server cut at group_concat_max_len, the
        // address to the first of its 202_ips rows.
        $db = self::$db;

        return UserPrefFilters::build(
            $user_row,
            $offset,
            $this->forDownload === 1,
            static fn (string $text): string => $db->real_escape_string($text)
        );
    }

    public function getAccountOverviewFilters(): string
    {
        if (!self::$db instanceof mysqli) {
            throw new Exception('Database connection not available');
        }

        $userId = self::$db->real_escape_string((string) $_SESSION['user_id']);
        $user_result = _mysqli_query("SELECT user_pref_show FROM 202_users_pref WHERE user_id=" . $userId);
        if (!$user_result) {
            // Real DB failure — throw.
            throw new Exception('Unable to load user report preferences');
        }
        // Fix #2: no pref row (brand-new account) → degrade to default ('all').
        $user_row = ReportView::apply($user_result->fetch_assoc() ?: [], $_SESSION['user_id']);

        return UserPrefFilters::showFilter((string) ($user_row['user_pref_show'] ?? 'all'));
    }

    /**
     * Run a report query and fold every row through the formatter while
     * accumulating the trailing "Totals for report" row.
     *
     * @return list<array<string, string>>
     */
    private function collectRows(string $sql, $cpv, string $rowMainKey = ''): array
    {
        $result = _mysqli_query($sql);
        if (!$result) {
            $error = self::$db instanceof mysqli ? self::$db->error : 'unknown';
            error_log('DataEngine report query failed: ' . $error);
            throw new RuntimeException('DataEngine report query failed');
        }

        $data = [];
        $totals = new ReportTotals();
        while ($row = $result->fetch_assoc()) {
            $data[] = $this->htmlFormat($row, $cpv, '', $rowMainKey);
            $totals->add($row);
        }
        $data[] = $this->htmlFormat($totals->toArray(), $cpv, 'total');

        return $data;
    }

    /**
     * Generic runner for every report grouped by a single dimension.
     *
     * @return list<array<string, string>>
     */
    private function runGroupedReport(GroupedReportDefinition $definition, $clickFrom, $clickTo, $cpv): array
    {
        $filters = $this->getFilters();

        // The group itself breaks ties in the sort. Every sort key is a
        // metric, and metrics tie all the time (every keyword with no leads
        // ties on the default sort), so without it MySQL returns tied rows
        // in whatever order the plan produces — a different order on two
        // runs of the same query, which moved rows between pages of a
        // paginated report (one row on two pages, another on none) and made
        // two downloads of the same report disagree.
        $sql = 'SELECT ' . $definition->labelSelect . ',' . MetricsSql::GROUPED_SELECT
            . $this->groupedReportFrom($definition, (string) $clickFrom, (string) $clickTo, $filters)
            . ' group by ' . $definition->groupBy
            . $this->sortOrder() . ', ' . $definition->groupBy
            . $filters['limit'];

        $data = $this->collectRows($sql, $cpv);

        self::$found_rows = $this->countReportGroups($definition, (string) $clickFrom, (string) $clickTo, $filters);

        return $data;
    }

    private function groupedDefinition(string $reportType): GroupedReportDefinition
    {
        $inet6Ntoa = '';
        if ($reportType === 'ip') {
            $inet6Ntoa = $this->resolveIpv6Functions();
        }

        $definition = GroupedReportRegistry::definition($reportType, $inet6Ntoa);
        if ($definition === null) {
            throw new InvalidArgumentException('Unknown grouped report type: ' . $reportType);
        }

        return $definition;
    }

    /**
     * Configure the global IPv6 SQL function names from the session and
     * return the display-decode function name used in SELECT clauses.
     */
    private function resolveIpv6Functions(): string
    {
        global $inet6_ntoa, $inet6_aton;

        if (isset($_SESSION['ipv6']) && $_SESSION['ipv6'] != '') {
            $inet6_ntoa = 'inet6_ntoa'; // decodes for display
            $inet6_aton = 'inet6_aton'; // encodes for db
        } else {
            $inet6_ntoa = '';
            $inet6_aton = '';
        }

        return $inet6_ntoa;
    }

    public function doLpOverviewReport($clickFrom, $clickTo, $cpv)
    {
        $click_filtered = $this->getAccountOverviewFilters();

        // Fix #3a: use the canonical metric SELECT list (MetricsSql::GROUPED_SELECT)
        // in place of the inline copy. The only join is 202_landing_pages which has
        // no income/cost/clicks columns, so the 2st. prefix in GROUPED_SELECT is safe.
        // Grouped by the page the join found, which is the account's or none
        // (CLAUDE.md #27): a click naming another account's page is counted
        // in the one row of clicks with no landing page, the direct links. It
        // was grouped by the click's own id, so it was a second row, also
        // named "[direct link]".
        // The WHERE is its own statement, so the ON clause ends in the string
        // AccountScopedJoinTest reads.
        $sql = "select lp.landing_page_nickname,
lp.landing_page_id," . MetricsSql::GROUPED_SELECT . "
from 202_dataengine as 2st
LEFT OUTER JOIN 202_landing_pages AS lp ON (lp.landing_page_id = 2st.landing_page_id AND lp.user_id = 2st.user_id)";
        $sql .= $this->mysql['user_id_query'] . "
AND 2st.click_time >= " . $clickFrom . "
AND 2st.click_time <= " . $clickTo . $click_filtered . "
group BY lp.landing_page_id
ORDER BY lp.landing_page_id ASC";

        return $this->collectRows($sql, $cpv);
    }

    public function doCampaignOverviewReport($clickFrom, $clickTo, $cpv)
    {
        $click_filtered = $this->getAccountOverviewFilters();

        // Average payout is a figure of the clicks, income over leads, as in
        // every other table and the totals row under this one. It was the
        // campaign's configured payout (aff_campaign_payout), under an "Avg
        // payout" heading, so the row of clicks with no campaign read "$":
        // dollar_format() of the NULL the join gave it.
        // Grouped by the campaign the join found, the account's or none
        // (CLAUDE.md #27).
        $sql = "SELECT 2ac.aff_campaign_id,
             2ac.aff_campaign_name," . MetricsSql::GROUPED_SELECT . "
             FROM 202_dataengine AS 2st
             LEFT OUTER JOIN 202_aff_campaigns AS 2ac
               ON (2st.aff_campaign_id = 2ac.aff_campaign_id AND 2ac.user_id = 2st.user_id)
             WHERE 2st.user_id = " . $this->mysql['user_id'] . "
AND 2st.click_time >= " . $clickFrom . "
AND 2st.click_time <= " . $clickTo . $click_filtered . "
             GROUP BY 2ac.aff_campaign_id
             ORDER BY 2ac.aff_campaign_id ASC";

        return $this->collectRows($sql, $cpv, 'overview');
    }

    public function doPerPpcReport($type, $clickFrom, $clickTo, $cpv)
    {
        $data = [];

        if ($type == 'alp') {
            $select_by_id = 'landing_page_id';
            $labelSelect = "
            202_landing_pages.landing_page_nickname,
            2st.landing_page_id,";
            // Each of the account's advanced landing pages (an inner join:
            // a click naming another account's page has no page to list it
            // under, and is counted in the tables above). "No campaign" is
            // the NULL the rollup writes as well as a 0: the test was IS
            // FALSE, which NULL is not, so no advanced landing page click
            // the rollup had written was ever listed here.
            $labelJoins = "
            INNER JOIN 202_landing_pages ON (202_landing_pages.landing_page_id = 2st.landing_page_id
              AND 202_landing_pages.user_id = 2st.user_id)";
            $typeCondition = "
            AND (2st.aff_campaign_id IS NULL OR 2st.aff_campaign_id = 0)
            AND 2st.landing_page_id > 0";
        } else {
            $select_by_id = 'aff_campaign_id';
            $labelSelect = "
            aff_network_name,
            202_aff_campaigns.aff_campaign_name,
            2st.aff_campaign_id,";
            // Each of the account's campaigns (an inner join, as above).
            $labelJoins = "
            INNER JOIN 202_aff_campaigns ON (202_aff_campaigns.aff_campaign_id = 2st.aff_campaign_id
              AND 202_aff_campaigns.user_id = 2st.user_id)
            LEFT JOIN 202_aff_networks on (2st.aff_network_id= 202_aff_networks.`aff_network_id` AND 202_aff_networks.user_id = 2st.user_id)";
            $typeCondition = "
            AND 2st.aff_campaign_id IS TRUE";
        }

        // Fix #3a: use canonical metric SELECT. Joins are 202_landing_pages /
        // 202_aff_campaigns / 202_aff_networks — none carry income/cost/clicks,
        // so the 2st. prefix in GROUPED_SELECT is unambiguous.
        $click_sql = "select" . $labelSelect . MetricsSql::GROUPED_SELECT . "
        from 202_dataengine as 2st"
            . $labelJoins
            . $this->mysql['user_id_query']
            . $typeCondition . "
        AND 2st.click_time >= '" . $clickFrom . "'
        AND 2st.click_time <= '" . $clickTo . "'
        group BY 2st." . $select_by_id . "
        ORDER BY 2st." . $select_by_id . " ASC";

        $click_result = $this->reportQuery($click_sql);

        $ids = [];
        while ($click_row = $click_result->fetch_assoc()) {
            $data[$click_row[$select_by_id]] = $this->htmlFormat($click_row, $cpv, 'total');
            $ids[] = $click_row[$select_by_id];
        }

        if (empty($ids)) {
            return $data;
        }

        // Fix #3a: use canonical metric SELECT. Joins are 202_ppc_accounts and
        // 202_ppc_networks — neither carries income/cost/clicks, so 2st. is safe.
        // Each names a row of the click's own account only (CLAUDE.md #27): a
        // click naming another account's source is still counted, unnamed.
        // The WHERE is its own statement, so the ON clause ends in the string
        // AccountScopedJoinTest reads.
        $ppc_sql = "select
            ppc_account_name,
            ppc_network_name,
            2st.ppc_account_id,
            2st.{$select_by_id}," . MetricsSql::GROUPED_SELECT . "
            from 202_dataengine as 2st
            LEFT JOIN 202_ppc_accounts ON (2st.ppc_account_id = 202_ppc_accounts.ppc_account_id AND 202_ppc_accounts.user_id = 2st.user_id)
            LEFT JOIN 202_ppc_networks ON (202_ppc_accounts.ppc_network_id = 202_ppc_networks.ppc_network_id AND 202_ppc_networks.user_id = 2st.user_id)";
        $ppc_sql .= $this->mysql['user_id_query']
            . " AND 2st.{$select_by_id} IN (" . implode(",", $ids) . ")";

        if ($type == 'alp') {
            $ppc_sql .= " AND (2st.aff_campaign_id IS NULL OR 2st.aff_campaign_id = 0)";
        }

        $ppc_sql .= "
            AND 2st.click_time >= '" . $clickFrom . "'
            AND 2st.click_time <= '" . $clickTo . "'
            group BY 2st.{$select_by_id},2st.ppc_account_id
            ORDER BY 2st.ppc_account_id ASC;";

        $ppc_result = $this->reportQuery($ppc_sql);
        while ($ppc_row = $ppc_result->fetch_assoc()) {
            $data[$ppc_row[$select_by_id]]['ppc_accounts'][$ppc_row['ppc_account_id']] = $this->htmlFormat($ppc_row, $cpv);
        }

        return $data;
    }

    public function doBreakdownReport($clickFrom, $clickTo, $cpv)
    {
        new UserPrefs();

        // Each label names one calendar hour, day, month or year of the
        // account's clock, so the rows are grouped by the label. They were
        // grouped by HOUR(), DAY() or MONTH() of the time alone: 7 September
        // and 7 October were one row, and so was every day's 3 pm.
        $label = match (UserPrefs::getPref('user_pref_breakdown')) {
            'hour' => '%b %d, %Y at %l%p',
            'month' => '%b %Y',
            'year' => '%Y',
            default => '%b %d, %Y',
        };

        $filters = $this->getFilters();
        $sql = "SELECT DATE_FORMAT(" . self::localClickTime() . ", '" . $label . "') as click_time_from_disp," . MetricsSql::GROUPED_SELECT
            . " FROM 202_dataengine as 2st " . $filters['join'] . $this->mysql['user_id_query']
            . " AND click_time >= " . $clickFrom . " AND click_time <= " . $clickTo . $filters['filter']
            . " group by click_time_from_disp" . $this->sortOrder('sort_breakdown_time_order asc');

        return $this->collectRows($sql, $cpv);
    }

    public function doHourlyReport($clickFrom, $clickTo, $cpv)
    {
        $filters = $this->getFilters();
        // The account's hour of the day. The labels are read from one row of
        // the hour, so the clock is worked out once a row, for the group.
        $local = self::localClickTime();
        $sql = "SELECT HOUR(" . $local . ") as hour_of_day, DATE_FORMAT(" . $local . ",'%l %p') as click_time_from_disp, DATE_FORMAT(" . $local . ",'%p') as ampm,"
            . MetricsSql::GROUPED_SELECT
            . " FROM 202_dataengine as 2st " . $filters['join'] . $this->mysql['user_id_query']
            . " AND click_time >= " . $clickFrom . " AND click_time <= " . $clickTo . $filters['filter']
            . " group by hour_of_day " . $this->sortOrder('breakdown asc');

        return $this->collectRows($sql, $cpv);
    }

    public function doWeeklyReport($clickFrom, $clickTo, $cpv)
    {
        $filters = $this->getFilters();
        // The account's weekday, grouped by its name.
        $local = self::localClickTime();
        $sql = "SELECT DATE_FORMAT(" . $local . ",'%a') as click_time_from_disp, DATE_FORMAT(" . $local . ",'%w') as click_time_from_sort,"
            . MetricsSql::GROUPED_SELECT
            . " FROM 202_dataengine as 2st " . $filters['join'] . $this->mysql['user_id_query']
            . " AND click_time >= " . $clickFrom . " AND click_time <= " . $clickTo . $filters['filter']
            . " group by click_time_from_disp  ORDER BY click_time_from_sort ASC";

        return $this->collectRows($sql, $cpv);
    }

    public function doVariableReport($clickFrom, $clickTo, $cpv)
    {
        $filters = $this->getFilters();
        $data = [];

        // Fix #1: include $filters['join'] so keyword filter's 2k alias resolves.
        // Fix #3a: replace inline metric columns with MetricsSql::GROUPED_SELECT.
        // 202_ppc_networks has no income/cost/clicks, so 2st. prefix is safe.
        // The traffic source is the click's own account's (CLAUDE.md #27): this
        // is an inner join, so a click naming another account's source drops
        // out of the report, as one naming a source that no longer exists did.
        $click_sql = " SELECT 2st.user_id,
        2st.ppc_network_id,
        ppc_network_name," . MetricsSql::GROUPED_SELECT . "
        FROM 202_dataengine as 2st
        JOIN 202_ppc_networks ON (202_ppc_networks.ppc_network_id = 2st.ppc_network_id AND 202_ppc_networks.user_id = 2st.user_id)";
        $click_sql .= $filters['join']
            . $this->mysql['user_id_query']
            . " AND 2st.variable_set_id != 0 AND click_time >= " . $clickFrom . " AND click_time <= " . $clickTo . $filters['filter'] . "
        group by 2st.user_id, 2st.ppc_network_id" . $filters['limit'];

        $totals = new ReportTotals();
        $click_result = $this->reportQuery($click_sql);
        while ($click_row = $click_result->fetch_assoc()) {
            if (!empty($_SESSION['publisher']) && $click_row['user_id'] != $this->mysql['user_id']) {
                continue;
            }
            $totals->add($click_row);
        }
        $data[] = $this->htmlFormat($totals->toArray(), $cpv, 'total');

        // Fix #1: include $filters['join'] so keyword filter's 2k alias resolves.
        // Fix #3a: replace inline metric columns with MetricsSql::GROUPED_SELECT.
        // Joined tables (202_variable_sets2, 202_custom_variables,
        // 202_ppc_network_variables, 202_ppc_networks) have no income/cost/clicks,
        // so the 2st. prefix in GROUPED_SELECT is unambiguous.
        //
        // One group per variable, not per variable name: a click records
        // each of its traffic source's variables (202_variable_sets2, one row
        // per variable), so a variable removed in Setup and added again
        // under the same name gave a click two rows in one name's group
        // (both recorded the value until the recorders read live variables
        // only), and the report counted that click twice. A removed
        // variable's values are still its clicks', so it stays, named as
        // removed.
        $click_sql = " SELECT
            2st.user_id,
    ppc_network_name,
    IF(202_ppc_network_variables.deleted = 0, name, CONCAT(name, ' (removed)')) as variable_name,
    variable as variable_value," . MetricsSql::GROUPED_SELECT . ",
    2st.ppc_network_id,
    2st.variable_set_id,
    variables,
    202_custom_variables.ppc_variable_id
FROM
    202_dataengine as 2st
        JOIN
    202_variable_sets2 USING (variable_set_id)
        JOIN
    202_custom_variables ON (202_custom_variables.custom_variable_id = 202_variable_sets2.variables)
        JOIN
    202_ppc_network_variables ON (202_custom_variables.ppc_variable_id = 202_ppc_network_variables.ppc_variable_id)
        JOIN
    202_ppc_networks ON (202_ppc_networks.ppc_network_id = 2st.ppc_network_id AND 202_ppc_networks.user_id = 2st.user_id)
";
        $click_sql .= $filters['join'] . $this->mysql['user_id_query'] . "
        AND 2st.variable_set_id != 0
        AND click_time >= " . $clickFrom . " AND click_time <= " . $clickTo . $filters['filter'] . "
group by 2st.ppc_network_id, 202_custom_variables.ppc_variable_id, variable
ORDER BY 2st.ppc_network_id, name, 202_custom_variables.ppc_variable_id, variable";

        $click_result = $this->reportQuery($click_sql);
        while ($click_row = $click_result->fetch_assoc()) {
            $formatted = $this->htmlFormat($click_row, $cpv);
            $data[$click_row['ppc_network_id']][] = $formatted;
            $data[$click_row['ppc_network_id']]['variables'][$click_row['ppc_variable_id']][] = $formatted;
            $data[$click_row['ppc_network_id']]['variables'][$click_row['ppc_variable_id']]['values'][] = $formatted;
        }

        $data[] = $this->htmlFormat($totals->toArray(), $cpv, 'total');

        return $data;
    }

    /**
     * Format a raw report row for display. Kept on the facade for backwards
     * compatibility; delegates to HtmlReportFormatter with the user's
     * currency resolved once per request instead of once per row.
     */
    public function htmlFormat($click_row, $cpv, $type = '', $mainKey = '')
    {
        if (!self::$db instanceof mysqli) {
            return [];
        }

        $row = is_array($click_row) ? $click_row : [];

        return $this->formatter()->format($row, (string) $type, (string) $mainKey);
    }

    private function formatter(): HtmlReportFormatter
    {
        if ($this->formatter === null) {
            // Same validator as every other reader of this column. This
            // passed whatever was stored straight to dollar_format(), which
            // expects a three-letter CODE and prepends anything it does not
            // recognise verbatim: a column holding 'XX' rendered
            // 'XX1,234.50', and a lowercase 'eur' rendered 'eur1,234.50'
            // instead of '€1,234.50'. Measured, not assumed — the old '$'
            // default was in fact fine.
            $stored = null;
            $result = self::$db->query("SELECT user_account_currency FROM 202_users_pref WHERE user_id = '" . ($this->mysql['user_id'] ?? '') . "'");
            if ($result && ($row = $result->fetch_assoc())) {
                $stored = $row['user_account_currency'] ?? null;
            }
            $this->formatter = new HtmlReportFormatter(
                \Api\V3\Controllers\UsersController::normalizeCurrency($stored)
            );
        }

        return $this->formatter;
    }

    /**
     * Resolve the ORDER BY clause for a report. An explicit $order (used by
     * the breakdown/hourly reports) wins; otherwise the posted sort key is
     * consulted. Unknown keys fall back to leads DESC via the SortOrder
     * whitelist, so request input can never reach the SQL verbatim.
     *
     * (The legacy version unconditionally overwrote $_POST['order'] with
     * the $order argument, which made posted sort keys unreachable for
     * grouped reports.)
     */
    public function sortOrder($order = '')
    {
        $sortKey = (string) $order;
        if ($sortKey === '') {
            // Fix #5: pass the raw posted value — htmlentities was wrong context
            // here (entities get decoded before JS executes) and could silently
            // produce unknown keys that forced the leads-DESC fallback.
            // The whitelist inside SortOrder::orderByClause makes this safe.
            $sortKey = (string) ($_POST['order'] ?? '');
        }

        return SortOrder::orderByClause($sortKey, date_default_timezone_get());
    }

    /**
     * Roll a single click up into 202_dataengine so reports reflect it.
     *
     * A request that names no click re-rolls none. It used to take "the
     * visitor's latest click": user 1's newest click in the last 24 hours from
     * the address in $ip_address. Measured, a cookie-less lpc.php request
     * from an address re-rolled user 1's click from it. Nothing leaked — the
     * answer is a bool, and a re-roll writes what the click's own rows say —
     * but it was a lookup and a rollup for a request that changed no click,
     * and it read the wrong things: only user 1's clicks, any visitor behind
     * the same address, and the $ip_address global where the click path's
     * own address lookups read the address as stored (StoredVisitorIp,
     * LastClickFromAddress).
     */
    public function setDirtyHour($click_id)
    {
        global $db;

        // Sets the IPv6 function globals for the rest of the request, as it
        // always has.
        $this->resolveIpv6Functions();

        if (!isset($click_id) || $click_id == '') {
            return false;
        }

        // click_id can originate from a caller-supplied cookie/request value;
        // cast to int so it cannot break out of the WHERE clause.
        $dsql = ClickRollupSql::insertSelect('202_dataengine', '2c.click_id=' . (int) $click_id);

        if (!$db->query($dsql)) {
            error_log('DataEngine setDirtyHour rollup failed: ' . $db->error);
            return false;
        }

        return true;
    }

    /**
     * Re-aggregate every unprocessed entry from 202_dirty_hours.
     */
    public function processDirtyHours()
    {
        set_time_limit(0);

        $delayed_result = self::$db->query("SELECT * FROM 202_dirty_hours where processed != 1");
        if (!$delayed_result) {
            // Legacy exit() here killed the whole cron run; log and let the
            // remaining cron tasks proceed.
            error_log('DataEngine processDirtyHours failed to read 202_dirty_hours: ' . self::$db->error);
            return;
        }

        while ($delayed_row = $delayed_result->fetch_assoc()) {
            $value = fn(string $key): string => self::$db->real_escape_string((string) ($delayed_row[$key] ?? ''));

            $snippet = "AND 2c.user_id = " . $value('user_id');
            if ($value('ppc_account_id')) {
                $snippet .= " AND 2c.ppc_account_id =" . $value('ppc_account_id');
            }
            if ($value('aff_campaign_id')) {
                $snippet .= " AND 2ac.aff_campaign_id =" . $value('aff_campaign_id');
            }

            $this->getSummary($value('click_time_from'), $value('click_time_to'), $snippet);

            if (!self::$db->query("UPDATE 202_dirty_hours set processed='1', deleted='1' where id=" . $delayed_row['id'])) {
                error_log('DataEngine processDirtyHours flag update failed: ' . self::$db->error);
            }
            flush();
        }

        if (!self::$db->query("DELETE FROM 202_dirty_hours where deleted=1")) {
            error_log('DataEngine processDirtyHours cleanup failed: ' . self::$db->error);
        }
    }

    /**
     * Roll up every click in a time window into the dataengine table.
     */
    public function getSummary($start, $end, $params, $user_id = 1, $upgrade = false, $new = false)
    {
        global $db;
        $from = $db->real_escape_string((string) $start);
        $to = $db->real_escape_string((string) $end);

        if ($upgrade) {
            $sql = "UPDATE 202_dataengine_job SET processing = '1' WHERE time_from ='" . $from . "' AND time_to = '" . $to . "'";
            if (!$db->query($sql)) {
                error_log('DataEngine getSummary job flag failed: ' . $db->error);
            }
        }

        $table = $new ? '202_dataengine_new' : '202_dataengine';

        $query = ClickRollupSql::insertSelect(
            $table,
            '2c.click_time >= ' . $from . "\nAND 2c.click_time <= " . $to . ' ' . $params
        );

        $job = "WHERE time_from = '" . $from . "' AND time_to = '" . $to . "'";
        try {
            $this->doQuery($query, $from, $to, $upgrade, $new);
        } catch (RuntimeException $e) {
            // Released, not finished: the next run takes the window again.
            if ($upgrade && !$db->query("UPDATE 202_dataengine_job SET processing = '0' " . $job)) {
                error_log('DataEngine getSummary job release failed: ' . $db->error);
            }
            throw $e;
        }
        // The window is done. doSummary() marked it, but an INSERT … SELECT
        // never reaches doSummary() (doQuery() returns at once), so a window
        // the cron job's processClickUpgrade() took stayed `processing` and
        // unprocessed for good, and no window after it was ever taken.
        if ($upgrade && !$db->query("UPDATE 202_dataengine_job SET processing = '0', processed = '1' " . $job)) {
            error_log('DataEngine getSummary job flag failed: ' . $db->error);
        }
        return $query . "<br><br>";
    }

    public function doQuery($query, $from, $to, $upgrade = false, $new = false)
    {
        global $db;

        $info_result = $db->query($query);
        if (!$info_result) {
            // Log details server-side; do not expose DB error or SQL to the response.
            error_log('dataengine doQuery failed: ' . $db->error);
            throw new RuntimeException('dataengine query failed');
        }

        // INSERT/UPDATE queries return true; only SELECT results feed doSummary.
        if ($info_result === true) {
            return true;
        }

        // Fix #9b: $user_id was recomputed here but doSummary never reads it;
        // pass the default value instead of reintroducing the dead variable.
        $this->doSummary($info_result, $from, $to, 1, $upgrade, $new);
        return $info_result;
    }

    public function doSummary($info_result, $from, $to, $user_id, $upgrade = false, $new = false)
    {
        global $db, $dbGlobalLink;
        $dbGlobalLink = $db;

        $upgrade_from = $db->real_escape_string((string) $from);
        $upgrade_to = $db->real_escape_string((string) $to);

        $table = $new ? '202_dataengine_new' : '202_dataengine';

        $columnList = '';
        $updateList = '';
        $valuesList = ' ';
        $i = 0;

        mysqli_data_seek($info_result, 0);

        while ($row = mysqli_fetch_array($info_result, MYSQLI_ASSOC)) {
            $valuesList .= "(";
            $rowFingerprint = '';

            foreach ($row as $key => $value) {
                $rowFingerprint .= "-" . $value;
                if ($i == 0) {
                    $columnList .= $key . ",";
                    $updateList .= "$key = VALUES($key),";
                }
                // Fix #6b: only treat genuinely empty values as ''; real zeros
                // (integer 0, string '0') must be written as-is so flag columns
                // and numeric zeroes survive strict-mode inserts.
                if ($value === null || $value === '') {
                    $valuesList .= "'',";
                } else {
                    $valuesList .= $db->real_escape_string((string) $value) . ",";
                }
            }

            $valuesList = substr($valuesList, 0, -1);
            $valuesList .= ",'" . sha1($rowFingerprint) . "'),";
            $i++;
        }

        if ($i > 0) {
            $outsql = "INSERT INTO " . $table . " (" . substr($columnList, 0, -1) . ",encode) VALUES "
                . substr($valuesList, 0, -1)
                . " ON DUPLICATE KEY UPDATE " . substr($updateList, 0, -1);
            if (!_mysqli_query($outsql)) {
                error_log('DataEngine doSummary insert failed: ' . $db->error);
            }
        }

        if ($upgrade) {
            $sql = "UPDATE 202_dataengine_job SET processing = '0', processed = '1' WHERE time_from = '" . $upgrade_from . "' AND time_to = '" . $upgrade_to . "'";
            if (!_mysqli_query($sql)) {
                error_log('DataEngine doSummary job flag failed: ' . $db->error);
            }
        }
    }

    public function setRowsForOldClickUpgrade($start)
    {
        global $db, $dbGlobalLink;
        $dbGlobalLink = $db;

        $end = time();
        $query = "SELECT (click_time - click_time % 3600) AS hourstart FROM 202_clicks WHERE click_time <= " . $end . " and click_time >= " . $start . " GROUP BY hourstart";
        $result = $db->query($query);
        if (!$result) {
            error_log('DataEngine setRowsForOldClickUpgrade failed: ' . $db->error);
            return;
        }

        $full_day = [];
        $hours = 1;
        $counter = 0;

        while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC)) {
            $counter++;

            if ($hours == 1) {
                $full_day[] = $row['hourstart'];
            }

            if ($hours == 24 || $counter == $result->num_rows) {
                $full_day[] = $row['hourstart'] + 3599;
                $hours = 0;

                $time_from = $db->real_escape_string((string) $full_day[0]);
                $time_to = $db->real_escape_string((string) $full_day[1]);

                $sql = "INSERT INTO 202_dataengine_job SET time_from = '" . $time_from . "', time_to = '" . $time_to . "'";
                if (!$db->query($sql)) {
                    error_log('DataEngine setRowsForOldClickUpgrade insert failed: ' . $db->error);
                }

                $full_day = [];
            }

            $hours++;
        }
    }

    public function processClickUpgrade()
    {
        global $db, $dbGlobalLink;
        $dbGlobalLink = $db;

        if (function_exists('curl_version')) { // if curl is installed use the multiget method
            include_once(substr(__DIR__, 0, -10) . '/202-cronjobs/process_dataengine_job.php');
            return;
        }

        // Loop daily.
        $result = $db->query("SELECT * FROM 202_dataengine_job WHERE processed = '0'");
        if (!$result) {
            error_log('DataEngine processClickUpgrade failed: ' . $db->error);
            return;
        }

        $row = $result->fetch_assoc();
        if ($result->num_rows && !$row['processing']) {
            $time_from = $db->real_escape_string((string) $row['time_from']);
            $time_to = $db->real_escape_string((string) $row['time_to']);
            // Every account's clicks in the window, as the curl path's
            // dej.php rolls them up: this fallback rolled up user 1's only,
            // so without curl a rebuild left every other account's clicks
            // out of the reports.
            $this->getSummary($time_from, $time_to, '', 1, true);
        }
    }

    /** Chart metric => [SELECT expression, display name]. */
    private const CHART_METRICS = [
        'clicks' => [' SUM(clicks) AS clicks', 'Clicks'],
        'click_out' => [' SUM(click_out) AS click_out', 'Click Throughs'],
        'ctr' => [' (SUM(click_out)/SUM(clicks))*100 AS ctr', 'CTR'],
        'leads' => [' SUM(leads) AS leads', 'Leads'],
        'su_ratio' => [' (SUM(click_lead)/SUM(clicks))*100 AS su_ratio', 'Avg S/U'],
        'payout' => [' (SUM(income) / sum(leads)) AS payout', 'Avg Payout'],
        'epc' => [' SUM(income)/SUM(clicks) AS epc', 'Avg EPC'],
        'cpc' => [' SUM(cost)/SUM(clicks) AS cpc', 'Avg CPC'],
        'income' => [' SUM(income) AS income', 'Income'],
        'cost' => [' SUM(cost) AS cost', 'Cost'],
        'net' => [' (SUM(income)-SUM(cost)) AS net', 'Net'],
        'roi' => [' ((SUM(income)-SUM(cost))/SUM(cost)*100 ) AS roi', 'ROI'],
    ];

    public function getChart($from, $to, $user_chart_data, $time_range, $rangeOutputFormat, $rangePeriod)
    {
        $chart = [];
        $series = [];

        $click_filtered = $this->getAccountOverviewFilters();

        if ($user_chart_data) {
            foreach ($user_chart_data as $chart_data) {
                $chart[$chart_data['campaign_id']][] = $chart_data['value_type'];
            }
        }

        foreach (array_keys($chart) as $campaign) {
            $types = [];
            $data = [];
            $selectParts = [];

            foreach ($chart[$campaign] as $type) {
                // Unknown/stale metric types in a saved config are excluded
                // from the SELECT (they would break the SQL) but still get a
                // series entry, which renders as zeros.
                [$metricSql, $typeName] = self::CHART_METRICS[$type] ?? [null, ucfirst((string) $type)];
                if ($metricSql !== null) {
                    $selectParts[] = $metricSql;
                }

                $types[] = [
                    'type_name' => $typeName,
                    'sql_name' => $type,
                ];
            }
            $sqlSelectObj = implode(',', $selectParts);

            // The account's hours or days, named as the series below name
            // returnRanges()' points, which step through the same zone.
            $rangeLabel = $time_range == 'hours' ? '%b %d %Y %l:00%p' : '%b %d %Y';
            $rangeFormat = ", DATE_FORMAT(" . self::localClickTime() . ", '" . $rangeLabel . "') AS date_range";

            if ($campaign != '0') {
                $rangeFormat .= ", aff_campaign_name";
            }

            $sqlObj = "SELECT" . $sqlSelectObj . $rangeFormat . " FROM 202_dataengine AS 2st ";

            if ($campaign != '0') {
                $sqlObj .= "LEFT JOIN 202_aff_campaigns AS 2ac ON 2ac.aff_campaign_id = 2st.aff_campaign_id AND 2ac.user_id = 2st.user_id ";
            }

            // The account's clicks, as every other reader here scopes them
            // (user_id_query): the chart had no account condition at all, so
            // "Clicks (all)" summed every account's clicks in the window, and
            // a campaign line drew whichever account owned that id.
            // click_time is an integer timestamp and aff_campaign_id an int id;
            // cast both so neither can break out of the clause regardless of
            // how the caller sourced them (from/to come from the request,
            // campaign from the stored chart config).
            $sqlObj .= $this->mysql['user_id_query']
                . "AND 2st.click_time >= '" . (int) $from . "' AND 2st.click_time <= '" . (int) $to . "' ";

            if ($campaign != '0') {
                $sqlObj .= "AND 2st.aff_campaign_id = '" . (int) $campaign . "' ";
            }
            $sqlObj .= $click_filtered . " ";
            $sqlObj .= "GROUP BY date_range;";

            // No recognized metrics selected: skip the query and let every
            // series fall through to its zero-filled default.
            $result = false;
            if ($selectParts !== []) {
                $result = self::$db->query($sqlObj);
                if (!$result instanceof mysqli_result) {
                    // Not a chart of zeroes, which is what the series below
                    // fill an absent day with: the chart said "no traffic".
                    error_log('DataEngine getChart query failed: ' . self::$db->error);
                    throw new RuntimeException('DataEngine chart query failed');
                }
            }

            $campaign_name = '';

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $campaign_name = $row['aff_campaign_name'] ?? '';
                    $data['categories'][$row['date_range']] = $row['date_range'];
                    foreach (array_keys(self::CHART_METRICS) as $sqlName) {
                        if (array_key_exists($sqlName, $row)) {
                            $data['data'][$row['date_range']][$sqlName] = $row[$sqlName];
                        }
                    }
                }
            }

            foreach ($types as $type) {
                $seriesData = [];
                $series_name = $type['type_name'] . ($campaign_name != '' ? " (" . $campaign_name . ")" : " (all)");

                foreach ($rangePeriod as $range) {
                    $key = '';
                    if ($time_range == 'days') {
                        $key = $range->format('M d Y');
                    } elseif ($time_range == 'hours') {
                        $key = $range->format('M d Y g:iA');
                    }
                    if (isset($data['categories'][$key]) && array_key_exists($type['sql_name'], $data['data'][$key])) {
                        // SQL NULL (e.g. payout with zero leads) stays null
                        // in the series; only truly absent metrics become '0'.
                        $seriesData[] = $data['data'][$key][$type['sql_name']];
                    } else {
                        $seriesData[] = '0';
                    }
                }

                $series[] = [
                    'name' => $series_name,
                    'data' => $seriesData,
                ];
            }
        }

        return ['series' => $series];
    }
}

/**
 * Renders report data (as produced by DataEngine) as Excel downloads. The
 * HTML tables and pagination it also rendered belonged to the classic report
 * pages and went with them (U8); the pages render their own.
 */
class DisplayData
{
    /** Report type => table header label. */
    private const FEATURE_LABELS = [
        'LpOverview' => 'Direct Link / Landing Pages',
        'campaignOverview' => 'Campaigns',
        'breakdown' => 'Time',
        'hourly' => 'Time',
        'weekly' => 'Time',
        'keyword' => 'Keyword',
        'textad' => 'Text ad',
        'referer' => 'Referer',
        'ip' => 'IP',
        'country' => 'Country',
        'region' => 'Region',
        'city' => 'City',
        'isp' => 'ISP/Carrier',
        'landingpage' => 'Landing Page',
        'device' => 'Device',
        'browser' => 'Browser',
        'platform' => 'Platform',
    ];

    /**
     * Masks the variables excel download for a viewer without
     * access_to_campaign_data. The 1.9.76 rewrite removed the HTML renderers
     * this used to sit among and took it with them, which left
     * downloadVariables() printing real clicks, leads, income, cost and net to
     * exactly the users the permission withholds them from.
     *
     * The variable report nests its rows (network -> variable -> value) and
     * carries totals under total_* keys, so the mask walks the whole structure
     * for both prefixes.
     */
    private function maskVariableData($theData)
    {
        if (!\Prosper202\Report\CampaignDataMask::hidden()) {
            return $theData;
        }

        return \Prosper202\Report\CampaignDataMask::applyDeep((array) $theData);
    }

    public function downloadReport($reportType, $theData, $foundRows = '')
    {
        $featureLabel = self::FEATURE_LABELS[$reportType] ?? 'Item';

        echo $featureLabel . "\t" . "Clicks" . "\t" . "Click Throughs" . "\t" . "LP CTR" . "\t" . "Leads" . "\t" . "S/U" . "\t" . "Payout" . "\t" . "EPC" . "\t" . "Avg CPC" . "\t" . "Income" . "\t" . "Cost" . "\t" . "Net" . "\t" . "ROI" . "\n";

        $masked = \Prosper202\Report\CampaignDataMask::hidden();

        foreach (array_values((array) $theData) as $html) {
            // The trailing totals row carries only total_* keys; letting it
            // fall through printed an "Unknown" row of empty cells (plus
            // undefined-key warnings) in device/browser/platform downloads.
            if (!isset($html['clicks'])) {
                continue;
            }

            $featureKey = match ($reportType) {
                'keyword' => $html['keyword'] ?? false,
                'textad' => $html['text_ad_name'] ?? false,
                'referer' => $html['referer_name'] ?? false,
                'ip' => $html['ip_address'] ?? false,
                'country' => isset($html['country_name'], $html['country_code'])
                    ? $html['country_name'] . ' (' . $html['country_code'] . ')'
                    : false,
                'region' => isset($html['region_name'], $html['country_code'])
                    ? $html['region_name'] . ' (' . $html['country_code'] . ')'
                    : false,
                'city' => isset($html['city_name'], $html['country_code'])
                    ? $html['city_name'] . ' (' . $html['country_code'] . ')'
                    : false,
                'isp' => $html['isp_name'] ?? false,
                'landingpage' => $html['landing_page_nickname'] ?? false,
                'device' => $html['device_name'] ?? 'Unknown',
                'browser' => $html['browser_name'] ?? 'Unknown',
                'platform' => $html['platform_name'] ?? 'Unknown',
                default => false,
            };

            if (!$featureKey) {
                continue;
            }

            if ($masked) {
                $html = \Prosper202\Report\CampaignDataMask::apply($html);
            }

            echo $featureKey . "\t" . $html['clicks'] . "\t" . $html['click_out'] . "\t" . $html['ctr'] . "\t" . $html['leads'] . "\t" . $html['su_ratio'] . "\t" . $html['payout'] . "\t" . $html['epc'] . "\t" . $html['cpc'] . "\t" . $html['income'] . "\t" . $html['cost'] . "\t" . $html['net'] . "\t" . $html['roi'] . "\n";
        }
    }

    public function downloadVariables($theData)
    {
        $theData = $this->maskVariableData($theData);

        echo "Custom Variables" . "\t" . "Clicks" . "\t" . "Click Throughs" . "\t" . "LP CTR" . "\t" . "Leads" . "\t" . "S/U" . "\t" . "Payout" . "\t" . "EPC" . "\t" . "Avg CPC" . "\t" . "Income" . "\t" . "Cost" . "\t" . "Net" . "\t" . "ROI" . "\n";

        $rows = array_values((array) $theData);
        $rowCount = count($rows);

        for ($i = 0; $i < $rowCount; $i++) {
            $html = $rows[$i];

            if ($i != $rowCount - 1 && isset($html['variables']) && $html['variables']) {
                $networkRow = $html[0] ?? [];
                echo "- " . ($networkRow['ppc_network_name'] ?? '') . "\t" . ($networkRow['clicks'] ?? '') . "\t" . ($networkRow['click_out'] ?? '') . "\t" . ($networkRow['ctr'] ?? '') . "\t" . ($networkRow['leads'] ?? '') . "\t" . ($networkRow['su_ratio'] ?? '') . "\t" . ($networkRow['payout'] ?? '') . "\t" . ($networkRow['epc'] ?? '') . "\t" . ($networkRow['cpc'] ?? '') . "\t" . ($networkRow['income'] ?? '') . "\t" . ($networkRow['cost'] ?? '') . "\t" . ($networkRow['net'] ?? '') . "\t" . ($networkRow['roi'] ?? '') . "\n";

                foreach ($html['variables'] as $variables) {
                    $varRow = $variables[0] ?? [];
                    echo " - " . ($varRow['variable_name'] ?? '') . "\t" . ($varRow['clicks'] ?? '') . "\t" . ($varRow['click_out'] ?? '') . "\t" . ($varRow['ctr'] ?? '') . "\t" . ($varRow['leads'] ?? '') . "\t" . ($varRow['su_ratio'] ?? '') . "\t" . ($varRow['payout'] ?? '') . "\t" . ($varRow['epc'] ?? '') . "\t" . ($varRow['cpc'] ?? '') . "\t" . ($varRow['income'] ?? '') . "\t" . ($varRow['cost'] ?? '') . "\t" . ($varRow['net'] ?? '') . "\t" . ($varRow['roi'] ?? '') . "\n";

                    foreach ($variables['values'] ?? [] as $value) {
                        echo " -- " . ($value['variable_value'] ?? '') . "\t" . ($value['clicks'] ?? '') . "\t" . ($value['click_out'] ?? '') . "\t" . ($value['ctr'] ?? '') . "\t" . ($value['leads'] ?? '') . "\t" . ($value['su_ratio'] ?? '') . "\t" . ($value['payout'] ?? '') . "\t" . ($value['epc'] ?? '') . "\t" . ($value['cpc'] ?? '') . "\t" . ($value['income'] ?? '') . "\t" . ($value['cost'] ?? '') . "\t" . ($value['net'] ?? '') . "\t" . ($value['roi'] ?? '') . "\n";
                    }
                }
            }
        }
    }

    public static function convertToNumber($val)
    {
        if ($val === null || $val === '') {
            return 0;
        }

        if (is_numeric($val)) {
            return $val;
        }

        return str_replace(['$', ','], '', $val);
    }

}

/**
 * Cached access to the current user's 202_users_pref row.
 */
class UserPrefs
{
    /** @var array<string, mixed> */
    private static array $userPref = [];

    private static ?mysqli $db = null;

    public function __construct()
    {
        try {
            self::$db = DB::getInstance()->getConnection();
        } catch (Exception) {
            self::$db = null;
        }

        // Fix #10: guard against null db — if connection failed, leave prefs
        // at their default empty state and return rather than hitting a TypeError.
        if (!self::$db instanceof mysqli) {
            self::$userPref = [];
            return;
        }

        $userId = self::$db->real_escape_string((string) $_SESSION['user_id']);

        $user_result = _mysqli_query("SELECT * FROM 202_users_pref WHERE user_id=" . $userId);
        if (!$user_result) {
            throw new Exception('Unable to load user preferences');
        }

        $user_row = $user_result->fetch_assoc();
        if ($user_row) {
            self::$userPref = ReportView::apply($user_row, $_SESSION['user_id']);
        }
    }

    public static function getPref($pref)
    {
        return self::$userPref[$pref] ?? null;
    }
}
