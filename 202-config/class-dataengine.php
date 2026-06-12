<?php

declare(strict_types=1);

use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\DataEngine\GroupedReportDefinition;
use Prosper202\DataEngine\GroupedReportRegistry;
use Prosper202\DataEngine\HtmlReportFormatter;
use Prosper202\DataEngine\MetricsSql;
use Prosper202\DataEngine\ReportTotals;
use Prosper202\DataEngine\SortOrder;
use Prosper202\DataEngine\UserPrefFilters;

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

    private function getDbConnection(): ?mysqli
    {
        if ($this->isDatabaseConnected()) {
            return self::$db;
        }

        // Fallback to the legacy global database connection.
        global $db;
        if ($db instanceof mysqli) {
            return $db;
        }

        return null;
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

        if (isset($_SESSION['publisher']) && $_SESSION['publisher'] == false) {
            // User is able to see all campaigns.
            $this->mysql['user_id_query'] = " WHERE 2st.user_id != '0' ";
        } else {
            // User can only see their own campaigns.
            $this->mysql['user_id_query'] = " WHERE 2st.user_id ='" . ($_SESSION['user_own_id'] ?? '') . "' ";
        }

        // Make MySQL use the timezone chosen by the user.
        $timezone = new DateTimeZone(date_default_timezone_get());
        $offsetHours = round($timezone->getOffset(new DateTime()) / 3600);
        if ($offsetHours >= 0) {
            $offsetHours = '+' . $offsetHours;
        }
        $this->getDbConnection()?->query("SET time_zone = '" . $offsetHours . ":00'");
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
        if (!$result) {
            $error = self::$db instanceof mysqli
                ? self::$db->error
                : (($GLOBALS['db'] ?? null) instanceof mysqli ? $GLOBALS['db']->error : 'unknown');
            error_log('DataEngine count query failed: ' . $error);
            return 0;
        }

        $row = $result->fetch_assoc();
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Count distinct values for pagination without SQL_CALC_FOUND_ROWS.
     * Runs a simple COUNT(DISTINCT ...) on 202_dataengine — no lookup JOINs,
     * no aggregates — so it can use covering indexes.
     *
     * @param array{join?: string, filter?: string} $filters
     */
    private function countGroups(string $fkColumn, string $from, string $to, array $filters): int
    {
        if (!isset($filters['join'], $filters['filter'])) {
            return 0;
        }

        $countSql = "SELECT COUNT(DISTINCT 2st." . $fkColumn . ") AS cnt FROM 202_dataengine AS 2st "
            . $filters['join']
            . $this->mysql['user_id_query']
            . " AND click_time >= " . $from
            . " AND click_time <= " . $to
            . $filters['filter'];

        return $this->runCountQuery($countSql);
    }

    /**
     * @param array{join?: string, filter?: string} $filters
     */
    private function countRefererGroups(string $from, string $to, array $filters): int
    {
        if (!isset($filters['join'], $filters['filter'])) {
            return 0;
        }

        $countSql = "SELECT COUNT(*) AS cnt FROM (
                SELECT 2sd.site_domain_host
                FROM 202_dataengine AS 2st "
            . $filters['join']
            . " LEFT JOIN 202_site_urls AS 2su ON (2st.click_referer_site_url_id = 2su.site_url_id)
                LEFT JOIN 202_site_domains AS 2sd ON (2sd.site_domain_id = 2su.site_domain_id)"
            . $this->mysql['user_id_query']
            . " AND click_time >= " . $from
            . " AND click_time <= " . $to
            . $filters['filter']
            . " GROUP BY 2sd.site_domain_host
            ) AS referer_groups";

        return $this->runCountQuery($countSql);
    }

    public function getReportData($reportType, $clickFrom, $clickTo, $cpv): mixed
    {
        return match ($reportType) {
            'LpOverview' => $this->doLpOverviewReport($clickFrom, $clickTo, $cpv),
            'campaignOverview' => $this->doCampaignOverviewReport($clickFrom, $clickTo, $cpv),
            'slp_direct_link_per_ppc' => $this->doPerPpcReport('slp_direct_link', $clickFrom, $clickTo, $cpv),
            'alp_per_ppc' => $this->doPerPpcReport('alp', $clickFrom, $clickTo, $cpv),
            'breakdown' => $this->doBreakdownReport($clickFrom, $clickTo, $cpv),
            'hourly' => $this->doHourlyReport($clickFrom, $clickTo, $cpv),
            'weekly' => $this->doWeeklyReport($clickFrom, $clickTo, $cpv),
            'keyword' => $this->doKeywordReport($clickFrom, $clickTo, $cpv),
            'textad' => $this->doTextadReport($clickFrom, $clickTo, $cpv),
            'referer' => $this->doRefererReport($clickFrom, $clickTo, $cpv),
            'ip' => $this->doIPReport($clickFrom, $clickTo, $cpv),
            'country' => $this->doCountryReport($clickFrom, $clickTo, $cpv),
            'region' => $this->doRegionReport($clickFrom, $clickTo, $cpv),
            'city' => $this->doCityReport($clickFrom, $clickTo, $cpv),
            'isp' => $this->doISPReport($clickFrom, $clickTo, $cpv),
            'landingpage' => $this->doLandingPageReport($clickFrom, $clickTo, $cpv),
            'device' => $this->doDeviceReport($clickFrom, $clickTo, $cpv),
            'browser' => $this->doBrowserReport($clickFrom, $clickTo, $cpv),
            'platform' => $this->doPlatformReport($clickFrom, $clickTo, $cpv),
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

        $userId = self::$db->real_escape_string((string) $_SESSION['user_id']);
        $offset = (isset($_POST['offset']) && $_POST['offset'] != '')
            ? (int) self::$db->real_escape_string((string) $_POST['offset'])
            : 0;

        $user_result = _mysqli_query("SELECT * FROM 202_users_pref WHERE user_id=" . $userId);
        if (!$user_result || !($user_row = $user_result->fetch_assoc())) {
            throw new Exception('Unable to load user report preferences');
        }

        $ipIdList = null;
        if (!empty($user_row['user_pref_ip'])) {
            $ipIdList = (string) $this->get_ip_id($this->ipAddress($user_row['user_pref_ip']));
        }

        $refererIdList = null;
        if (!empty($user_row['user_pref_referer'])) {
            $refererIdList = (string) $this->get_site_url_id($user_row['user_pref_referer']);
        }

        return UserPrefFilters::build($user_row, $offset, $this->forDownload === 1, $ipIdList, $refererIdList);
    }

    public function getAccountOverviewFilters(): string
    {
        if (!self::$db instanceof mysqli) {
            throw new Exception('Database connection not available');
        }

        $userId = self::$db->real_escape_string((string) $_SESSION['user_id']);
        $user_result = _mysqli_query("SELECT user_pref_show FROM 202_users_pref WHERE user_id=" . $userId);
        if (!$user_result || !($user_row = $user_result->fetch_assoc())) {
            throw new Exception('Unable to load user report preferences');
        }

        return UserPrefFilters::showFilter((string) ($user_row['user_pref_show'] ?? 'all'));
    }

    /**
     * Resolve a keyword search to a comma separated keyword_id list.
     */
    public function get_keyword_id($keyword)
    {
        if (!self::$db instanceof mysqli) {
            return null;
        }

        $escaped = self::$db->real_escape_string((string) $keyword);
        $keyword_sql = "SELECT group_concat(keyword_id) as keyword_id FROM 202_keywords WHERE keyword like '%" . $escaped . "%'";
        $keyword_row = memcache_mysql_fetch_assoc($keyword_sql);

        return $keyword_row['keyword_id'] ?? null;
    }

    public function get_ip_id($ip)
    {
        if (!self::$db instanceof mysqli) {
            return null;
        }

        global $memcacheWorking, $memcache, $inet6_ntoa, $inet6_aton;

        if (!isset($inet6_ntoa)) {
            $inet6_ntoa = '';
            $inet6_aton = 'INET6_ATON';
        }

        $escaped = self::$db->real_escape_string((string) $ip->address);

        if ($inet6_ntoa == '' && $ip->type == 'ipv6') {
            $escaped = inet6_aton($escaped); // encode for db check
        }

        if ($ip->type === 'ipv6') {
            $ip_sql = 'SELECT 202_ips.ip_id FROM 202_ips_v6  INNER JOIN 202_ips on (202_ips_v6.ip_id = 202_ips.ip_address COLLATE utf8mb4_general_ci) WHERE 202_ips_v6.ip_address= ' . $inet6_aton . '("' . $escaped . '")';
        } else {
            $ip_sql = "SELECT ip_id FROM 202_ips WHERE ip_address='" . $escaped . "'";
        }

        $cacheKey = md5("ip-id" . $escaped . systemHash());
        if ($memcacheWorking) {
            $cached = $memcache->get($cacheKey);
            if ($cached) {
                return $cached;
            }
        }

        $ip_result = _mysqli_query($ip_sql);
        $ip_row = $ip_result ? $ip_result->fetch_assoc() : null;

        if ($ip_row) {
            $ip_id = $ip_row['ip_id'];
            if ($memcacheWorking) {
                setCache($cacheKey, $ip_id, 2592000); // 30 days
            }
            return $ip_id;
        }

        INDEXES::insert_ip(self::$db);
        return null;
    }

    public function ipAddress($ip_address): stdClass
    {
        global $inet6_ntoa, $inet6_aton;

        if (!isset($inet6_ntoa)) {
            $inet6_ntoa = '';
            $inet6_aton = 'INET6_ATON';
        }

        $ip = new stdClass();

        if (filter_var($ip_address, FILTER_VALIDATE_IP)) {
            $ip->address = $ip_address;
            $ip->type = filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'ipv4' : 'ipv6';
        } else {
            $ip->type = 'invalid';
        }

        return $ip;
    }

    /**
     * Resolve a referer search to a comma separated site_url_id list.
     */
    public function get_site_url_id($site_url_address)
    {
        if (!self::$db instanceof mysqli) {
            return null;
        }

        global $memcacheWorking, $memcache;

        $escaped = self::$db->real_escape_string((string) $site_url_address);

        $cacheKey = md5("url-id" . $site_url_address . systemHash());
        if ($memcacheWorking) {
            $cached = $memcache->get($cacheKey);
            if ($cached) {
                return $cached;
            }
        }

        $site_url_sql = "SELECT GROUP_CONCAT(distinct 2de.click_referer_site_url_id) AS site_url_id FROM 202_dataengine as 2de LEFT JOIN 202_site_urls ON (2de.click_referer_site_url_id = site_url_id)  WHERE site_url_address LIKE '%" . $escaped . "%'";
        $site_url_result = _mysqli_query($site_url_sql);
        $site_url_row = $site_url_result ? $site_url_result->fetch_assoc() : null;

        if ($site_url_row) {
            $site_url_id = $site_url_row['site_url_id'];
            if ($memcacheWorking) {
                setCache($cacheKey, $site_url_id, 604800); // 7 days
            }
            return $site_url_id;
        }

        return null;
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

        $sql = 'SELECT ' . $definition->labelSelect . ',' . MetricsSql::GROUPED_SELECT
            . ' FROM 202_dataengine as 2st '
            . ($definition->includeFilterJoin ? $filters['join'] : '')
            . $definition->joins
            . $this->mysql['user_id_query']
            . ' AND click_time >= ' . $clickFrom
            . ' AND click_time <= ' . $clickTo
            . $filters['filter']
            . ' group by ' . $definition->groupBy
            . $this->sortOrder()
            . $filters['limit'];

        $data = $this->collectRows($sql, $cpv);

        if ($definition->usesRefererCount) {
            self::$found_rows = $this->countRefererGroups((string) $clickFrom, (string) $clickTo, $filters);
        } elseif ($definition->countColumn !== null) {
            self::$found_rows = $this->countGroups($definition->countColumn, (string) $clickFrom, (string) $clickTo, $filters);
        }

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

        $sql = "select landing_page_nickname,
2st.landing_page_id, sum(clicks) as clicks, sum(click_out) as click_out,
(clicks/click_out)*100 as ctr,SUM(leads) AS leads,(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout,SUM(income)/SUM(clicks) as epc,SUM(cost)/sum(clicks) AS cpc,SUM(income) AS income, SUM(cost) AS cost,(SUM(income)-SUM(cost)) AS net,((SUM(income)-SUM(cost))/SUM(cost)*100 ) as roi
from 202_dataengine as 2st
LEFT OUTER JOIN 202_landing_pages USING (landing_page_id)"
            . $this->mysql['user_id_query'] . "
AND 2st.click_time >= " . $clickFrom . "
AND 2st.click_time <= " . $clickTo . $click_filtered . "
group BY landing_page_id
ORDER BY landing_page_id ASC";

        return $this->collectRows($sql, $cpv);
    }

    public function doCampaignOverviewReport($clickFrom, $clickTo, $cpv)
    {
        $click_filtered = $this->getAccountOverviewFilters();

        $sql = "SELECT  2c.aff_campaign_id,
             2ac.aff_campaign_name,
             SUM(2c.clicks) AS clicks,
            SUM(2c.click_out) AS click_out,
            SUM(2c.leads) AS leads,
            2ac.aff_campaign_payout AS payout,
            (clicks/click_out)*100 as ctr,
            SUM(2c.income) AS income,
            SUM(2c.cost) AS cost,
            SUM(income)/SUM(clicks) as epc,
            (SUM(click_lead)/sum(clicks))*100 as su_ratio,
            SUM(cost)/sum(clicks) AS cpc,
            (SUM(income)-SUM(cost)) AS net,
            ((SUM(income)-SUM(cost))/SUM(cost)*100 ) as roi
             FROM 202_dataengine AS 2c
             LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
             WHERE 2c.user_id = " . $this->mysql['user_id'] . "
AND 2c.click_time >= " . $clickFrom . "
AND 2c.click_time <= " . $clickTo . $click_filtered . "
             GROUP BY IF(2c.aff_campaign_id is null or 2c.aff_campaign_id = '', '0', 2c.aff_campaign_id)";

        return $this->collectRows($sql, $cpv, 'overview');
    }

    public function doPerPpcReport($type, $clickFrom, $clickTo, $cpv)
    {
        $data = [];

        if ($type == 'alp') {
            $select_by_id = 'landing_page_id';
            $labelSelect = "
            landing_page_nickname,
            2st.landing_page_id,";
            $labelJoins = "
            LEFT JOIN 202_landing_pages USING (landing_page_id)";
            $typeCondition = "
            AND 2st.aff_campaign_id IS FALSE
            AND 2st.landing_page_id IS TRUE";
        } else {
            $select_by_id = 'aff_campaign_id';
            $labelSelect = "
            aff_network_name,
            aff_campaign_name,
            2st.aff_campaign_id,";
            $labelJoins = "
            LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
            LEFT JOIN 202_aff_networks on (2st.aff_network_id= 202_aff_networks.`aff_network_id`)";
            $typeCondition = "
            AND 2st.aff_campaign_id IS TRUE";
        }

        $click_sql = "select" . $labelSelect . "
        sum(clicks) as clicks,
        sum(click_out) as click_out,
        (clicks/click_out)*100 as ctr,
        SUM(leads) AS leads,
        (SUM(click_lead)/sum(clicks))*100 as su_ratio,
        (SUM(income) / sum(leads)) AS payout,
        SUM(income)/SUM(clicks) as epc,
        SUM(cost)/sum(clicks) AS cpc,
        SUM(income) AS income,
        SUM(cost) AS cost,
        (SUM(income)-SUM(cost)) AS net,
        ((SUM(income)-SUM(cost))/SUM(cost)*100 ) as roi
        from 202_dataengine as 2st"
            . $labelJoins
            . $this->mysql['user_id_query']
            . $typeCondition . "
        AND 2st.click_time >= '" . $clickFrom . "'
        AND 2st.click_time <= '" . $clickTo . "'
        group BY " . $select_by_id . "
        ORDER BY " . $select_by_id . " ASC";

        $click_result = _mysqli_query($click_sql);
        if (!$click_result) {
            return $data;
        }

        $ids = [];
        while ($click_row = $click_result->fetch_assoc()) {
            $data[$click_row[$select_by_id]] = $this->htmlFormat($click_row, $cpv, 'total');
            $ids[] = $click_row[$select_by_id];
        }

        if (empty($ids)) {
            return $data;
        }

        $ppc_sql = "select
            ppc_account_name,
            ppc_network_name,
            2st.ppc_account_id,
            2st.{$select_by_id},
            sum(clicks) as clicks,
            sum(click_out) as click_out,
            (clicks/click_out)*100 as ctr,
            SUM(leads) AS leads,
            (SUM(click_lead)/sum(clicks))*100 as su_ratio,
            (SUM(income) / sum(leads)) AS payout,
            SUM(income)/SUM(clicks) as epc,
            SUM(cost)/sum(clicks) AS cpc,
            SUM(income) AS income,
            SUM(cost) AS cost,
            (SUM(income)-SUM(cost)) AS net,
            ((SUM(income)-SUM(cost))/SUM(cost)*100 ) as roi
            from 202_dataengine as 2st
            LEFT JOIN 202_ppc_accounts ON (2st.ppc_account_id = 202_ppc_accounts.ppc_account_id)
            LEFT JOIN 202_ppc_networks ON (202_ppc_accounts.ppc_network_id = 202_ppc_networks.ppc_network_id)"
            . $this->mysql['user_id_query']
            . " AND 2st.{$select_by_id} IN (" . implode(",", $ids) . ")";

        if ($type == 'alp') {
            $ppc_sql .= " AND 2st.aff_campaign_id IS FALSE";
        }

        $ppc_sql .= "
            AND 2st.click_time >= '" . $clickFrom . "'
            AND 2st.click_time <= '" . $clickTo . "'
            group BY 2st.{$select_by_id},2st.ppc_account_id
            ORDER BY 2st.ppc_account_id ASC;";

        $ppc_result = _mysqli_query($ppc_sql);

        if ($ppc_result && $ppc_result->num_rows > 0) {
            while ($ppc_row = $ppc_result->fetch_assoc()) {
                $data[$ppc_row[$select_by_id]]['ppc_accounts'][$ppc_row['ppc_account_id']] = $this->htmlFormat($ppc_row, $cpv);
            }
        }

        return $data;
    }

    public function doBreakdownReport($clickFrom, $clickTo, $cpv)
    {
        new UserPrefs();

        [$groupby, $dateFormat] = match (UserPrefs::getPref('user_pref_breakdown')) {
            'hour' => [" HOUR(FROM_UNIXTIME(click_time)) ", "DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %d, %Y at %l%p')"],
            'month' => [" MONTH(FROM_UNIXTIME(click_time)) ", "DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %Y')"],
            'year' => [" YEAR(FROM_UNIXTIME(click_time)) ", "DATE_FORMAT(FROM_UNIXTIME(click_time),'%Y')"],
            default => [" DAY(FROM_UNIXTIME(click_time)) ", "DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %d, %Y')"],
        };

        $filters = $this->getFilters();
        $sql = "SELECT " . $dateFormat . " as click_time_from_disp," . MetricsSql::GROUPED_SELECT
            . " FROM 202_dataengine as 2st " . $filters['join'] . $this->mysql['user_id_query']
            . " AND click_time >= " . $clickFrom . " AND click_time <= " . $clickTo . $filters['filter']
            . " group by" . $groupby . $this->sortOrder('sort_breakdown_time_order asc');

        return $this->collectRows($sql, $cpv);
    }

    public function doHourlyReport($clickFrom, $clickTo, $cpv)
    {
        $filters = $this->getFilters();
        $sql = "SELECT  DATE_FORMAT(FROM_UNIXTIME(click_time),'%l %p')  as click_time_from_disp, DATE_FORMAT(FROM_UNIXTIME(click_time),'%p') as ampm,"
            . MetricsSql::GROUPED_SELECT
            . " FROM 202_dataengine as 2st " . $filters['join'] . $this->mysql['user_id_query']
            . " AND click_time >= " . $clickFrom . " AND click_time <= " . $clickTo . $filters['filter']
            . " group by HOUR(FROM_UNIXTIME(click_time)) " . $this->sortOrder('breakdown asc');

        return $this->collectRows($sql, $cpv);
    }

    public function doWeeklyReport($clickFrom, $clickTo, $cpv)
    {
        $filters = $this->getFilters();
        $sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(click_time),'%a') as click_time_from_disp, DATE_FORMAT(FROM_UNIXTIME(click_time),'%w') as click_time_from_sort,"
            . MetricsSql::GROUPED_SELECT
            . " FROM 202_dataengine as 2st " . $filters['join'] . $this->mysql['user_id_query']
            . " AND click_time >= " . $clickFrom . " AND click_time <= " . $clickTo . $filters['filter']
            . " group by click_time_from_disp  ORDER BY click_time_from_sort ASC";

        return $this->collectRows($sql, $cpv);
    }

    public function doKeywordReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('keyword'), $clickFrom, $clickTo, $cpv);
    }

    public function doTextadReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('textad'), $clickFrom, $clickTo, $cpv);
    }

    public function doRefererReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('referer'), $clickFrom, $clickTo, $cpv);
    }

    public function doIPReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('ip'), $clickFrom, $clickTo, $cpv);
    }

    public function doCountryReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('country'), $clickFrom, $clickTo, $cpv);
    }

    public function doRegionReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('region'), $clickFrom, $clickTo, $cpv);
    }

    public function doCityReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('city'), $clickFrom, $clickTo, $cpv);
    }

    public function doISPReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('isp'), $clickFrom, $clickTo, $cpv);
    }

    public function doLandingPageReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('landingpage'), $clickFrom, $clickTo, $cpv);
    }

    public function doDeviceReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('device'), $clickFrom, $clickTo, $cpv);
    }

    public function doBrowserReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('browser'), $clickFrom, $clickTo, $cpv);
    }

    public function doPlatformReport($clickFrom, $clickTo, $cpv)
    {
        return $this->runGroupedReport($this->groupedDefinition('platform'), $clickFrom, $clickTo, $cpv);
    }

    public function doVariableReport($clickFrom, $clickTo, $cpv)
    {
        $filters = $this->getFilters();
        $data = [];

        $click_sql = " SELECT 2st.user_id,
        2st.ppc_network_id,
        ppc_network_name,
        sum(clicks) as clicks,
        sum(click_out) as click_out,
        (sum(click_out)/sum(clicks))*100 as ctr,
        SUM(leads) AS leads,
        (SUM(click_lead)/sum(clicks))*100 as su_ratio,
        (SUM(income) / sum(leads)) AS payout,
        SUM(2st.income) AS income,
        SUM(2st.income)/sum(clicks) as epc,
        SUM(2st.cost) AS cost,
        SUM(2st.cost)/sum(clicks) AS cpc,
        (SUM(2st.income)-SUM(2st.cost)) AS net,
        ((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi,
        GROUP_CONCAT(DISTINCT(2st.variable_set_id)) as variable_set_ids
        FROM 202_dataengine as 2st
        JOIN 202_ppc_networks ON (202_ppc_networks.ppc_network_id = 2st.ppc_network_id)"
            . $this->mysql['user_id_query']
            . " AND 2st.variable_set_id != 0 AND click_time >= " . $clickFrom . " AND click_time <= " . $clickTo . $filters['filter'] . "
        group by 2st.user_id, 2st.ppc_network_id" . $filters['limit'];

        $totals = new ReportTotals();
        $click_result = _mysqli_query($click_sql);
        if ($click_result) {
            while ($click_row = $click_result->fetch_assoc()) {
                if (!empty($_SESSION['publisher']) && $click_row['user_id'] != $this->mysql['user_id']) {
                    continue;
                }
                $totals->add($click_row);
            }
            $data[] = $this->htmlFormat($totals->toArray(), $cpv, 'total');
        }

        $click_sql = " SELECT
            2st.user_id,
    ppc_network_name,
    name as variable_name,
    variable as variable_value,
    sum(clicks) as clicks,
    sum(click_out) as click_out,
    (sum(click_out) / sum(clicks)) * 100 as ctr,
    SUM(leads) AS leads,
    (SUM(click_lead) / sum(clicks)) * 100 as su_ratio,
    (SUM(income) / sum(leads)) AS payout,
    SUM(2st.income) AS income,
    SUM(2st.income) / sum(clicks) as epc,
    SUM(2st.cost) AS cost,
    SUM(2st.cost) / sum(clicks) AS cpc,
    (SUM(2st.income) - SUM(2st.cost)) AS net,
    ((SUM(2st.income) - SUM(2st.cost)) / SUM(2st.cost) * 100) as roi,
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
    202_ppc_networks ON (202_ppc_networks.ppc_network_id = 2st.ppc_network_id)
" . $this->mysql['user_id_query'] . "
        AND 2st.variable_set_id != 0
        AND click_time >= " . $clickFrom . " AND click_time <= " . $clickTo . $filters['filter'] . "
group by ppc_network_id , name , variable
ORDER BY ppc_network_id , name , variable";

        $click_result = _mysqli_query($click_sql);
        if ($click_result) {
            while ($click_row = $click_result->fetch_assoc()) {
                $formatted = $this->htmlFormat($click_row, $cpv);
                $data[$click_row['ppc_network_id']][] = $formatted;
                $data[$click_row['ppc_network_id']]['variables'][$click_row['ppc_variable_id']][] = $formatted;
                $data[$click_row['ppc_network_id']]['variables'][$click_row['ppc_variable_id']]['values'][] = $formatted;
            }
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
            $currency = '$';
            $result = self::$db->query("SELECT user_account_currency FROM 202_users_pref WHERE user_id = '" . ($this->mysql['user_id'] ?? '') . "'");
            if ($result && ($row = $result->fetch_assoc())) {
                $currency = (string) ($row['user_account_currency'] ?? '$');
            }
            $this->formatter = new HtmlReportFormatter($currency);
        }

        return $this->formatter;
    }

    /**
     * Resolve the ORDER BY clause for a report.
     *
     * Preserved legacy contract: the posted sort key is always overwritten
     * with the explicit $order argument (default ''), so grouped reports
     * always sort by leads DESC and pagination links (which read
     * $_POST['order'] afterwards) carry the same value. Do not "fix" this
     * without also auditing the grouped-report SQL: mapped clauses have no
     * leading space and only parse after the call sites that pass an
     * explicit $order.
     */
    public function sortOrder($order = '')
    {
        $_POST['order'] = htmlentities((string) $order, ENT_QUOTES, 'UTF-8');

        return SortOrder::orderByClause($_POST['order']);
    }

    /**
     * Roll a single click up into 202_dataengine so reports reflect it.
     * When no click id is given, the most recent click from the current
     * visitor IP (last 24h) is used.
     */
    public function setDirtyHour($click_id)
    {
        global $ip_address, $db;

        $inet6_ntoa = $this->resolveIpv6Functions();

        if (!isset($click_id) || $click_id == '') {
            $escapedIp = $db->real_escape_string((string) $ip_address->address);

            if ($inet6_ntoa == '' && $ip_address->type == 'ipv6') {
                $escapedIp = inet6_aton($escapedIp); // encode for db check
            }

            $daysago = time() - 86400; // 24 hours
            $click_sql1 = 'SELECT  202_clicks.click_id
                           FROM            202_clicks
                           LEFT JOIN       202_clicks_advance USING (click_id)
                           LEFT JOIN       202_ips USING (ip_id)
                           LEFT JOIN       202_ips_v6 ON (202_ips_v6.ip_id = 202_ips.ip_address COLLATE utf8mb4_general_ci)
                           WHERE           IFNULL(' . $inet6_ntoa . '(202_ips_v6.ip_address),202_ips.ip_address)="' . $escapedIp . '"
                           AND             202_clicks.user_id="1"
                           AND             202_clicks.click_time >= "' . $daysago . '"
                           ORDER BY        202_clicks.click_id DESC
                           LIMIT           1';

            $click_result1 = $db->query($click_sql1) or record_mysql_error($click_sql1);
            $click_row1 = $click_result1->fetch_assoc();
            $click_id = $click_row1 ? $db->real_escape_string((string) ($click_row1['click_id'] ?? '')) : '';
        }

        if (!isset($click_id) || $click_id == '') {
            return false;
        }

        $dsql = ClickRollupSql::insertSelect('202_dataengine', '2c.click_id=' . $click_id);

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
            exit();
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

        $this->doQuery($query, $from, $to, $upgrade, $new);
        return $query . "<br><br>";
    }

    public function doQuery($query, $from, $to, $upgrade = false, $new = false)
    {
        global $db;

        $user_id = $_SESSION['user_own_id'] ?? 1;

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

        $this->doSummary($info_result, $from, $to, $user_id, $upgrade, $new);
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
                if (!$value) {
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
            $this->getSummary($time_from, $time_to, "AND 2c.user_id = 1", 1, true);
        }
    }

    /** Chart metric => [SELECT expression, display name]. */
    private const CHART_METRICS = [
        'clicks' => [' SUM(clicks) AS clicks', 'Clicks'],
        'click_out' => [' SUM(click_out) AS click_out', 'Click Throughs'],
        'ctr' => [' (clicks/click_out)*100 AS ctr', 'CTR'],
        'leads' => [' SUM(leads) AS leads', 'Leads'],
        'su_ratio' => [' (SUM(click_lead)/SUM(clicks))*100 AS su_ratio', 'Avg S/U'],
        'payout' => [' (SUM(income) / sum(leads)) AS payout', 'Avg Payout'],
        'epc' => [' SUM(income)/clicks AS epc', 'Avg EPC'],
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

            if ($time_range == 'hours') {
                $rangeGroupby = "DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %d %Y %l:00%p')";
            } else {
                $rangeGroupby = "DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %d %Y')";
            }
            $rangeFormat = ", " . $rangeGroupby . " AS date_range";

            if ($campaign != '0') {
                $rangeFormat .= ", aff_campaign_name";
            }

            $sqlObj = "SELECT" . $sqlSelectObj . $rangeFormat . " FROM 202_dataengine ";

            if ($campaign != '0') {
                $sqlObj .= "LEFT JOIN 202_aff_campaigns USING (aff_campaign_id) ";
            }

            $sqlObj .= "WHERE click_time >= '" . $from . "' AND click_time <= '" . $to . "' ";

            if ($campaign != '0') {
                $sqlObj .= "AND aff_campaign_id = '" . $campaign . "' ";
            }
            $sqlObj .= $click_filtered . " ";
            $sqlObj .= "GROUP BY " . $rangeGroupby . ";";

            // No recognized metrics selected: skip the query and let every
            // series fall through to its zero-filled default.
            $result = $selectParts === [] ? false : self::$db->query($sqlObj);

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
 * Renders report data (as produced by DataEngine) as HTML tables, Excel
 * downloads and pagination controls.
 */
class DisplayData
{
    private static ?mysqli $db = null;

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

    /** Report type => excel download endpoint (under tracking202/analyze/). */
    private const DOWNLOAD_URLS = [
        'keyword' => 'keywords_download.php',
        'textad' => 'text_ads_download.php',
        'referer' => 'referers_download.php',
        'ip' => 'ips_download.php',
        'country' => 'countries_download.php',
        'region' => 'regions_download.php',
        'city' => 'cities_download.php',
        'isp' => 'isps_download.php',
        'landingpage' => 'landing_pages_download.php',
        'device' => 'device_download.php',
        'browser' => 'browser_download.php',
        'platform' => 'platform_download.php',
    ];

    /** Report types whose table is not paginated. */
    private const UNPAGINATED = ['breakdown', 'hourly', 'weekly'];

    public function __construct()
    {
        try {
            self::$db = DB::getInstance()->getConnection();
        } catch (Exception) {
            self::$db = null;
        }
    }

    /**
     * Bootstrap label style (primary/important/default) for a net or ROI value.
     */
    private static function labelStyle($value): string
    {
        $number = self::convertToNumber($value);
        if ($number > 0) {
            return 'primary';
        }
        if ($number < 0) {
            return 'important';
        }

        return 'default';
    }

    private static function featureKey(string $reportType, array $html): string
    {
        switch ($reportType) {
            case 'LpOverview':
                return $html['landing_page_nickname'] ?? '';
            case 'campaignOverview':
                return ($html['aff_network_name'] ?? '') . ' - ' . ($html['aff_campaign_name'] ?? '');
            case 'breakdown':
            case 'hourly':
            case 'weekly':
                return $html['click_time_from_disp'] ?? '';
            case 'keyword':
                $keyword = $html['keyword'] ?? 'Unknown';
                return '<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap;
 width: 250px;" title="' . $keyword . '">' . $keyword . '</div>';
            case 'textad':
                return $html['text_ad_name'] ?? 'Unknown';
            case 'referer':
                $referer_name = $html['referer_name'] ?? 'Unknown';
                return '<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap;
 width: 250px;" title="' . $referer_name . '">' . $referer_name . '</div>';
            case 'ip':
                $ip_address = $html['ip_address'] ?? 'Unknown';
                return '<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap;
 width: 100%;" title="' . $ip_address . '">' . $ip_address . '</div>';
            case 'country':
            case 'region':
            case 'city':
                $nameKey = ['country' => 'country_name', 'region' => 'region_name', 'city' => 'city_name'][$reportType];
                $country_code = !empty($html['country_code']) ? $html['country_code'] : 'unknown';
                $name = $html[$nameKey] ?? 'Unknown';
                return '<img src="' . get_absolute_url() . '202-img/flags/' . strtolower((string) $country_code) . '.png"> ' . $name . ' (' . $country_code . ')';
            case 'isp':
                return $html['isp_name'] ?? 'Unknown';
            case 'landingpage':
                $landing_page_nickname = $html['landing_page_nickname'] ?? 'Unknown';
                return '<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap;
 width: 240px;" title="' . $landing_page_nickname . '">' . $landing_page_nickname . '</div>';
            case 'device':
                return $html['device_name'] ?? 'Unknown';
            case 'browser':
                return $html['browser_name'] ?? 'Unknown';
            case 'platform':
                return $html['platform_name'] ?? 'Unknown';
        }

        return '';
    }

    public function displayReport($reportType, $theData, $foundRows = '')
    {
        global $userObj;

        $paginateReport = !in_array($reportType, self::UNPAGINATED, true);
        $downloadUrl = self::DOWNLOAD_URLS[$reportType] ?? '';
        $featureLabel = self::FEATURE_LABELS[$reportType] ?? 'Item';

        if ($downloadUrl != '') {
            echo '<div class="row">
                    <div class="col-xs-12 text-right" style="padding-bottom: 10px;">
                        <img style="margin-bottom:2px;" src="' . get_absolute_url() . '202-img/icons/16x16/page_white_excel.png"/>
                        <a style="font-size:12px;" target="_new" href="' . get_absolute_url() . 'tracking202/analyze/' . $downloadUrl . '">
                            <strong>Download to excel</strong>
                        </a>
                    </div>
                </div>';
        }

        echo '<table class="table table-bordered table-hover" id="stats-table">
        <thead>
        <tr style="background-color: #f2fbfa;">
        <th colspan="4" style="text-align:left">' . $featureLabel . '</th>
        <th>Clicks</th>
        <th>Click Throughs</th>
        <th>CTR</th>
        <th>Leads</th>
        <th>Avg S/U</th>
        <th>Avg Payout</th>
        <th>Avg EPC</th>
        <th>Avg CPC</th>
        <th>Income</th>
        <th>Cost</th>
        <th>Net</th>
        <th>ROI</th>
        </tr>
        </thead>
        <tbody>';

        $rows = array_values((array) $theData);
        $rowCount = count($rows);

        for ($i = 0; $i < $rowCount; $i++) {
            $html = $rows[$i];
            $featureKey = self::featureKey((string) $reportType, $html);

            $netStyle = self::labelStyle($html['net'] ?? 0);
            $roiStyle = self::labelStyle($html['roi'] ?? 0);
            $totalNetStyle = self::labelStyle($html['total_net'] ?? 0);
            $totalRoiStyle = self::labelStyle($html['total_roi'] ?? 0);

            $masked = $userObj && !$userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher'];

            if ($i != $rowCount - 1) {
                if ($masked) {
                    $html['clicks'] = '?';
                    $html['click_out'] = '?';
                    $html['leads'] = '?';
                    $html['income'] = '?';
                    $html['cost_wrapper'] = '?';
                    $html['net'] = '?';
                } else {
                    $html['cost_wrapper'] = '(' . $html['cost'] . ')';
                }

                echo ' <tr>
               <td colspan="4" style="text-align:left; padding-left:10px">' . $featureKey . '</td>
                   <td>' . $html['clicks'] . '</td>

                        <td>' . $html['click_out'] . '</td>
            			<td>' . $html['ctr'] . '</td>
            			<td>' . $html['leads'] . '</td>
            			<td>' . $html['su_ratio'] . '</td>
            			<td>' . $html['payout'] . '</td>
            			<td>' . $html['epc'] . '</td>
            			<td>' . $html['cpc'] . '</td>
            			<td><span class="label label-info">' . $html['income'] . '</span></td>
            			<td><span class="label label-info">' . $html['cost_wrapper'] . '</span></td>
            			<td> <span class="label label-' . $netStyle . '">' . $html['net'] . '</span></td>
            			<td> <span class="label label-' . $roiStyle . '">' . $html['roi'] . '</span></td>

            		</tr> ';
            } else {
                if ($masked) {
                    $html['total_clicks'] = '?';
                    $html['total_click_out'] = '?';
                    $html['total_leads'] = '?';
                    $html['total_income'] = '?';
                    $html['total_cost_wrapper'] = '?';
                    $html['total_net'] = '?';
                } else {
                    $html['total_cost_wrapper'] = '(' . $html['total_cost'] . ')';
                }

                echo '<tr style="background-color: #F8F8F8;" id="totals" class="no-sort">
        <td colspan="4" style="text-align:left; padding-left:10px;"><strong>Totals for report</strong></td>
        <td><strong>' . $html['total_clicks'] . '</strong></td>
            <td><strong>' . $html['total_click_out'] . '</strong></td>
                <td><strong>' . $html['total_ctr'] . '</strong></td>
        			<td><strong>' . $html['total_leads'] . '</strong></td>
        			<td><strong>' . $html['total_su_ratio'] . '</strong></td>
        			<td><strong>' . $html['total_payout'] . '</strong></td>
        			<td><strong>' . $html['total_epc'] . '</strong></td>
        			<td><strong>' . $html['total_cpc'] . '</strong></td>
        			<td><strong>' . $html['total_income'] . '</strong></td>
        			<td><strong>' . $html['total_cost_wrapper'] . '</strong></td>
        			<td><strong><span class="label label-' . $totalNetStyle . '">' . $html['total_net'] . '</span></strong></td>
        			<td><strong><span class="label label-' . $totalRoiStyle . '">' . $html['total_roi'] . '</span></strong></td>
        		</tr>
        		</tbody>
        	</table> ';
            }
        }

        if ($paginateReport) {
            echo $this->paginate($reportType, $foundRows);
        }
    }

    public function displayPerPPCReport($type, $theData)
    {
        global $userObj;

        $featureLabel = match ($type) {
            'slp_direct_link' => '[direct link & simple lp]',
            'alp' => '[adv lp]',
            default => '',
        };

        if (empty($theData)) {
            return;
        }

        foreach ($theData as $campaign) {
            $name = match ($type) {
                'slp_direct_link' => $campaign['total_aff_network_name'] . ' - ' . $campaign['total_aff_campaign_name'],
                'alp' => $campaign['total_landing_page_nickname'],
                default => '',
            };

            $totalNetStyle = self::labelStyle($campaign['total_net']);
            $totalRoiStyle = self::labelStyle($campaign['total_roi']);

            echo '
            <strong><small>' . $name . ' <span style="font-size: 65%; color: grey; font-weight: normal;">' . $featureLabel . '</span></small></strong>
            <table class="table table-bordered table-hover" id="stats-table">
            <thead>
            <tr style="background-color: #f2fbfa;">
            <th colspan="4" class="no-sort" style="text-align:left">Traffic Source - Traffic Source Account</th>
            <th>Clicks</th>
            <th>Click Throughs</th>
            <th>CTR</th>
            <th>Leads</th>
            <th>Avg S/U</th>
            <th>Avg Payout</th>
            <th>Avg EPC</th>
            <th>Avg CPC</th>
            <th>Income</th>
            <th>Cost</th>
            <th>Net</th>
            <th>ROI</th>
            </tr>
            </thead>
            <tbody>';

            foreach ($campaign['ppc_accounts'] as $ppc_account) {
                $netStyle = self::labelStyle($ppc_account['net']);
                $roiStyle = self::labelStyle($ppc_account['roi']);

                if ($userObj && !$userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher']) {
                    $ppc_account['clicks'] = '?';
                    $ppc_account['click_out'] = '?';
                    $ppc_account['leads'] = '?';
                    $ppc_account['income'] = '?';
                    $ppc_account['cost_wrapper'] = '?';
                    $ppc_account['net'] = '?';
                } else {
                    $ppc_account['cost_wrapper'] = '(' . $ppc_account['cost'] . ')';
                }

                if (($ppc_account['ppc_network_name'] != '') && ($ppc_account['ppc_account_name'] != '')) {
                    $source_name = $ppc_account['ppc_network_name'] . ' - ' . $ppc_account['ppc_account_name'];
                } else {
                    $source_name = '[No Traffic Source Account]';
                }

                echo ' <tr>
                   <td colspan="4" style="text-align:left; padding-left:10px">' . $source_name . '</td>
                   <td>' . $ppc_account['clicks'] . '</td>

                        <td>' . $ppc_account['click_out'] . '</td>
                        <td>' . $ppc_account['ctr'] . '</td>
                        <td>' . $ppc_account['leads'] . '</td>
                        <td>' . $ppc_account['su_ratio'] . '</td>
                        <td>' . $ppc_account['payout'] . '</td>
                        <td>' . $ppc_account['epc'] . '</td>
                        <td>' . $ppc_account['cpc'] . '</td>
                        <td><span class="label label-info">' . $ppc_account['income'] . '</span></td>
                        <td><span class="label label-info">' . $ppc_account['cost_wrapper'] . '</span></td>
                        <td> <span class="label label-' . $netStyle . '">' . $ppc_account['net'] . '</span></td>
                        <td> <span class="label label-' . $roiStyle . '">' . $ppc_account['roi'] . '</span></td>

                </tr> ';
            }

            if ($userObj && !$userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher']) {
                $campaign['total_clicks'] = '?';
                $campaign['total_click_out'] = '?';
                $campaign['total_leads'] = '?';
                $campaign['total_income'] = '?';
                $campaign['cost_wrapper'] = '?';
                $campaign['net'] = '?';
            } else {
                $campaign['cost_wrapper'] = '(' . $campaign['total_cost'] . ')';
            }

            echo '<tr style="background-color: #F8F8F8;" id="totals" class="no-sort">
                    <td colspan="4" style="text-align:left; padding-left:10px;"><strong>Totals for report</strong></td>
                    <td><strong>' . $campaign['total_clicks'] . '</strong></td>
                    <td><strong>' . $campaign['total_click_out'] . '</strong></td>
                    <td><strong>' . $campaign['total_ctr'] . '</strong></td>
                    <td><strong>' . $campaign['total_leads'] . '</strong></td>
                    <td><strong>' . $campaign['total_su_ratio'] . '</strong></td>
                    <td><strong>' . $campaign['total_payout'] . '</strong></td>
                    <td><strong>' . $campaign['total_epc'] . '</strong></td>
                    <td><strong>' . $campaign['total_cpc'] . '</strong></td>
                    <td><strong>' . $campaign['total_income'] . '</strong></td>
                    <td><strong>' . $campaign['cost_wrapper'] . '</strong></td>
                    <td><strong><span class="label label-' . $totalNetStyle . '">' . $campaign['total_net'] . '</span></strong></td>
                    <td><strong><span class="label label-' . $totalRoiStyle . '">' . $campaign['total_roi'] . '</span></strong></td>
                </tr>
                </tbody>
            </table> ';
        }
    }

    public function displayVariableReport($theData)
    {
        echo '<div class="row">
                    <div class="col-xs-12 text-right" style="padding-bottom: 10px;">
                        <img style="margin-bottom:2px;" src="' . get_absolute_url() . '202-img/icons/16x16/page_white_excel.png"/>
                        <a style="font-size:12px;" target="_new" href="' . get_absolute_url() . 'tracking202/analyze/variables_download.php">
                            <strong>Download to excel</strong>
                        </a>
                    </div>
                </div>';

        echo '<table class="table table-bordered" id="stats-table">
            <thead>
            <tr style="background-color: #f2fbfa;">
            <th style="text-align:left">Variables</th>
            <th>Clicks</th>
            <th>Click Throughs</th>
             <th>CTR</th>
            <th>Leads</th>
            <th>Avg S/U</th>
            <th>Avg Payout</th>
            <th>Avg EPC</th>
            <th>Avg CPC</th>
            <th>Income</th>
            <th>Cost</th>
            <th>Net</th>
            <th>ROI</th>
            </tr>
            </thead>
            <tbody>';

        $rows = array_values((array) $theData);
        $rowCount = count($rows);

        for ($i = 0; $i < $rowCount; $i++) {
            $html = $rows[$i];

            if ($i != $rowCount - 1) {
                if (isset($html['variables']) && $html['variables']) {
                    foreach ($html['variables'] as $variables) {
                        echo '
                    <tr class="sub">
                       <td class="result_main_column_level_1" colspan="13"><strong>' . ($html[$i]['ppc_network_name'] ?? '') . ' - ' . ($variables[0]['variable_name'] ?? '') . '</strong></td>
                    </tr> ';

                        foreach ($variables['values'] ?? [] as $value) {
                            $value_netStyle = self::labelStyle($value['net']);
                            $value_roiStyle = self::labelStyle($value['roi']);

                            echo '
                        <tr class="lite">
                           <td class="result_main_column_level_3">' . $value['variable_value'] . '</td>
                           <td>' . $value['clicks'] . '</td>
                           <td>' . $value['click_out'] . '</td>
                           <td>' . $value['ctr'] . '</td>
                           <td>' . $value['leads'] . '</td>
                           <td>' . $value['su_ratio'] . '</td>
                           <td>' . $value['payout'] . '</td>
                           <td>' . $value['epc'] . '</td>
                           <td>' . $value['cpc'] . '</td>
                           <td><span class="label label-info">' . $value['income'] . '</span></td>
                           <td><span class="label label-info">' . $value['cost'] . '</span></td>
                           <td> <span class="label label-' . $value_netStyle . '">' . $value['net'] . '</span></td>
                           <td> <span class="label label-' . $value_roiStyle . '">' . $value['roi'] . '</span></td>
                        </tr> ';
                        }
                    }
                }
            } else {
                $totalNetStyle = self::labelStyle($html['total_net'] ?? 0);
                $totalRoiStyle = self::labelStyle($html['total_roi'] ?? 0);

                echo '
                <tr style="background-color: #F8F8F8;" id="totals" class="no-sort">
                    <td class="result_main_column_level_1"><strong>Totals for report</strong></td>
                    <td><strong>' . $html['total_clicks'] . '</strong></td>
                    <td><strong>' . $html['total_click_out'] . '</strong></td>
                    <td><strong>' . $html['total_ctr'] . '</strong></td>
                    <td><strong>' . $html['total_leads'] . '</strong></td>
                    <td><strong>' . $html['total_su_ratio'] . '</strong></td>
                    <td><strong>' . $html['total_payout'] . '</strong></td>
                    <td><strong>' . $html['total_epc'] . '</strong></td>
                    <td><strong>' . $html['total_cpc'] . '</strong></td>
                    <td><strong>' . $html['total_income'] . '</strong></td>
                    <td><strong>' . $html['total_cost'] . '</strong></td>
                    <td><strong><span class="label label-' . $totalNetStyle . '">' . $html['total_net'] . '</span></strong></td>
                    <td><strong><span class="label label-' . $totalRoiStyle . '">' . $html['total_roi'] . '</span></strong></td>
                </tr>
            </tbody>
            </table>';
            }
        }
    }

    public function downloadReport($reportType, $theData, $foundRows = '')
    {
        global $userObj;

        $featureLabel = self::FEATURE_LABELS[$reportType] ?? 'Item';

        echo $featureLabel . "\t" . "Clicks" . "\t" . "Click Throughs" . "\t" . "LP CTR" . "\t" . "Leads" . "\t" . "S/U" . "\t" . "Payout" . "\t" . "EPC" . "\t" . "Avg CPC" . "\t" . "Income" . "\t" . "Cost" . "\t" . "Net" . "\t" . "ROI" . "\n";

        foreach (array_values((array) $theData) as $html) {
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

            if ($userObj && !$userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher']) {
                $html['clicks'] = '?';
                $html['click_out'] = '?';
                $html['leads'] = '?';
                $html['income'] = '?';
                $html['cost'] = '?';
                $html['net'] = '?';
            }

            echo $featureKey . "\t" . $html['clicks'] . "\t" . $html['click_out'] . "\t" . $html['ctr'] . "\t" . $html['leads'] . "\t" . $html['su_ratio'] . "\t" . $html['payout'] . "\t" . $html['epc'] . "\t" . $html['cpc'] . "\t" . $html['income'] . "\t" . $html['cost'] . "\t" . $html['net'] . "\t" . $html['roi'] . "\n";
        }
    }

    public function downloadVariables($theData)
    {
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

    public function paginate($reportType, $foundRows)
    {
        $order = $_POST['order'] ?? '';

        $fileSlug = match ($reportType) {
            'textad' => 'text_ads',
            'landingpage' => 'landing_pages',
            'keyword', 'referer', 'ip', 'region', 'isp', 'device', 'browser', 'platform' => $reportType . 's',
            'country', 'city' => substr((string) $reportType, 0, -1) . 'ies',
            default => $reportType,
        };
        $fileName = "sort_" . $fileSlug . ".php";

        new UserPrefs();
        $userPrefLimit = (int) UserPrefs::getPref('user_pref_limit');
        if ($userPrefLimit < 1) {
            $userPrefLimit = 50; // matches the schema default; guards against division by zero
        }
        $pages = (int) ceil((int) $foundRows / $userPrefLimit);

        if (isset($_POST['offset']) && $_POST['offset'] != '') {
            $offset = (int) $_POST['offset'];
        } else {
            $offset = 0;
        }

        if ($pages > 1) {
?>
            <div class="row">
                <div class="col-xs-12 text-center">
                    <div class="pagination" id="table-pages">
                        <ul>
                            <?php
                            $page = ($offset == 0) ? 0 : $offset - 1;
                            printf(' <li class="previous"><a class="fui-arrow-left" onclick="loadContent(\'%stracking202/ajax/%s\',\'%s\',\'%s\');"></a></li>', get_absolute_url(), $fileName, $page, $order);

                            for ($i = 0; $i < $pages; $i++) {
                                if (($i >= $offset - 10) and ($i < $offset + 11)) {
                                    $class = ($offset == $i) ? 'class="active"' : '';
                                    printf(' <li %s><a onclick="loadContent(\'%stracking202/ajax/%s\',\'%s\',\'%s\');">%s</a></li>', $class, get_absolute_url(), $fileName, $i, $order, $i + 1);
                                }
                            }

                            $page = ($offset + 1 == $pages) ? $offset : $offset + 1;
                            printf(' <li class="next"><a class="fui-arrow-right" onclick="loadContent(\'%stracking202/ajax/%s\',\'%s\',\'%s\');"></a></li>', get_absolute_url(), $fileName, $page, $order);
                            ?>
                        </ul>
                    </div>
                </div>
            </div>
<?php
        }
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

        $userId = self::$db->real_escape_string((string) $_SESSION['user_id']);

        $user_result = _mysqli_query("SELECT * FROM 202_users_pref WHERE user_id=" . $userId);
        if (!$user_result) {
            throw new Exception('Unable to load user preferences');
        }

        $user_row = $user_result->fetch_assoc();
        if ($user_row) {
            self::$userPref = $user_row;
        }
    }

    public static function getPref($pref)
    {
        return self::$userPref[$pref] ?? null;
    }
}
