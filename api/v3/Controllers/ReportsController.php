<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\AccountTimezone;
use Api\V3\Support\ReportFilter;
use Api\V3\Support\ResponseSanitizer;
use Api\V3\Support\StatementHelpers;

class ReportsController
{
    use StatementHelpers;
    use AccountTimezone;

    /**
     * Breakdown dimension => how its rows are read. A plain entry joins
     * `table` as `ref` on de.<de_id> = ref.<id> and names the row
     * ref.<name>. An entry that needs more says so: `join` replaces the join
     * (it must still alias the table whose id the row carries as `ref`),
     * `name_sql` the name expression, `group` the GROUP BY list, and `extra`
     * adds columns (alias => expression, each also grouped on).
     *
     * Every dimension groups by its id, so a row is one stored value; the
     * clicks that have no value for it (no referer, no c1, not through a
     * rotator rule) are in /reports/summary and in no row here.
     *
     * The dimensions after text_ad are the ones the Analyze pages and the
     * group overview had and the API did not:
     *  - ip: Analyze › IPs. An IPv6 address is a 202_ips row whose
     *    ip_address holds its 202_ips_v6 row's id; the pages join that as
     *    `2i6.ip_id = 2i.ip_address COLLATE …`, a number compared with
     *    text, which leaves it to the server whether the IPv4 address
     *    2.0.1.1 is IPv6 row 2 (`SELECT 2 = '2.0.1.1'` is 1 on MariaDB
     *    10.11; the pages' join, measured there, did not match). Only an
     *    all-digit ip_address is a reference here, compared as a number, and
     *    a reference whose v6 row is gone is named null, not by its id.
     *  - referer: Analyze › Referers, the referring domain; referer_url: the
     *    group overview's Referer, the whole URL.
     *  - device_type: the group overview's Device Type (Desktop, Mobile,
     *    Tablet, Bot), through the click's device model.
     *  - c1..c4, utm_*: the group overview's levels.
     *  - rotator, rotator_rule: the group overview's levels, from the
     *    click's 202_clicks_rotator row (the rule that matched). The
     *    Overview's Rotator Breakdown counted a rule from 202_clicks.rule_id,
     *    which rtr.php fills with the redirect's id, not the rule's; see
     *    rotatorStats(). A rotator's default (no rule matched) is no rule
     *    and in no rotator_rule row; rotatorStats() has it.
     */
    private const array BREAKDOWNS = [
        'campaign'     => ['table' => '202_aff_campaigns',      'id' => 'aff_campaign_id',  'name' => 'aff_campaign_name',  'de_id' => 'aff_campaign_id'],
        'aff_network'  => ['table' => '202_aff_networks',       'id' => 'aff_network_id',   'name' => 'aff_network_name',   'de_id' => 'aff_network_id'],
        'ppc_account'  => ['table' => '202_ppc_accounts',       'id' => 'ppc_account_id',   'name' => 'ppc_account_name',   'de_id' => 'ppc_account_id'],
        'ppc_network'  => ['table' => '202_ppc_networks',       'id' => 'ppc_network_id',   'name' => 'ppc_network_name',   'de_id' => 'ppc_network_id'],
        'landing_page' => ['table' => '202_landing_pages',      'id' => 'landing_page_id',  'name' => 'landing_page_url',   'de_id' => 'landing_page_id'],
        'keyword'      => ['table' => '202_keywords',           'id' => 'keyword_id',       'name' => 'keyword',            'de_id' => 'keyword_id'],
        'country'      => ['table' => '202_locations_country',  'id' => 'country_id',       'name' => 'country_name',       'de_id' => 'country_id'],
        'city'         => ['table' => '202_locations_city',      'id' => 'city_id',          'name' => 'city_name',          'de_id' => 'city_id'],
        'region'       => ['table' => '202_locations_region',    'id' => 'region_id',        'name' => 'region_name',        'de_id' => 'region_id'],
        'browser'      => ['table' => '202_browsers',           'id' => 'browser_id',       'name' => 'browser_name',       'de_id' => 'browser_id'],
        'platform'     => ['table' => '202_platforms',           'id' => 'platform_id',      'name' => 'platform_name',      'de_id' => 'platform_id'],
        'device'       => ['table' => '202_device_models',       'id' => 'device_id',        'name' => 'device_name',        'de_id' => 'device_id'],
        'isp'          => ['table' => '202_locations_isp',       'id' => 'isp_id',           'name' => 'isp_name',           'de_id' => 'isp_id'],
        'text_ad'      => ['table' => '202_text_ads',            'id' => 'text_ad_id',       'name' => 'text_ad_name',       'de_id' => 'text_ad_id'],
        'ip' => [
            'table' => '202_ips', 'id' => 'ip_id', 'name' => 'ip_address', 'de_id' => 'ip_id',
            'join' => "INNER JOIN 202_ips ref ON de.ip_id = ref.ip_id\n"
                . "            LEFT JOIN 202_ips_v6 i6 ON (ref.ip_address REGEXP '^[0-9]+\$'"
                . ' AND i6.ip_id = CAST(ref.ip_address AS UNSIGNED))',
            'name_sql' => "CASE WHEN ref.ip_address REGEXP '^[0-9]+\$'"
                . ' THEN INET6_NTOA(i6.ip_address) ELSE ref.ip_address END',
            'group' => 'ref.ip_id, ref.ip_address, i6.ip_address',
        ],
        'referer' => [
            'table' => '202_site_domains', 'id' => 'site_domain_id',
            'name' => 'site_domain_host', 'de_id' => 'click_referer_site_url_id',
            'join' => "INNER JOIN 202_site_urls su ON de.click_referer_site_url_id = su.site_url_id\n"
                . '            INNER JOIN 202_site_domains ref ON ref.site_domain_id = su.site_domain_id',
        ],
        'referer_url' => [
            'table' => '202_site_urls', 'id' => 'site_url_id',
            'name' => 'site_url_address', 'de_id' => 'click_referer_site_url_id',
        ],
        'device_type' => [
            'table' => '202_device_types', 'id' => 'type_id', 'name' => 'type_name', 'de_id' => 'device_id',
            'join' => "INNER JOIN 202_device_models dm ON de.device_id = dm.device_id\n"
                . '            INNER JOIN 202_device_types ref ON ref.type_id = dm.device_type',
        ],
        'c1' => [
            'table' => '202_tracking_c1', 'id' => 'c1_id',
            'name' => 'c1', 'de_id' => 'c1_id',
        ],
        'c2' => [
            'table' => '202_tracking_c2', 'id' => 'c2_id',
            'name' => 'c2', 'de_id' => 'c2_id',
        ],
        'c3' => [
            'table' => '202_tracking_c3', 'id' => 'c3_id',
            'name' => 'c3', 'de_id' => 'c3_id',
        ],
        'c4' => [
            'table' => '202_tracking_c4', 'id' => 'c4_id',
            'name' => 'c4', 'de_id' => 'c4_id',
        ],
        'utm_source' => [
            'table' => '202_utm_source', 'id' => 'utm_source_id',
            'name' => 'utm_source', 'de_id' => 'utm_source_id',
        ],
        'utm_medium' => [
            'table' => '202_utm_medium', 'id' => 'utm_medium_id',
            'name' => 'utm_medium', 'de_id' => 'utm_medium_id',
        ],
        'utm_campaign' => [
            'table' => '202_utm_campaign', 'id' => 'utm_campaign_id',
            'name' => 'utm_campaign', 'de_id' => 'utm_campaign_id',
        ],
        'utm_term' => [
            'table' => '202_utm_term', 'id' => 'utm_term_id',
            'name' => 'utm_term', 'de_id' => 'utm_term_id',
        ],
        'utm_content' => [
            'table' => '202_utm_content', 'id' => 'utm_content_id',
            'name' => 'utm_content', 'de_id' => 'utm_content_id',
        ],
        'rotator' => [
            'table' => '202_rotators', 'id' => 'id',
            'name' => 'name', 'de_id' => 'rotator_id',
        ],
        'rotator_rule' => [
            'table' => '202_rotator_rules', 'id' => 'id', 'name' => 'rule_name', 'de_id' => 'rule_id',
            'extra' => ['rotator_id' => 'ref.rotator_id'],
        ],
    ];

    /**
     * Breakdown dimensions whose `name` values are visitor-authored or
     * visitor-derived (keywords, c1-c4 and utm values from tracking-link
     * parameters, the referring URL from the visitor's browser, geo/ISP
     * names and the address from the visitor's IP, browser/platform/device
     * names from the user agent). These are sanitized on the way out — see
     * ResponseSanitizer. device_type is not: its names are the installer's
     * four (Desktop, Mobile, Tablet, Bot).
     */
    private const array VISITOR_AUTHORED_BREAKDOWNS = [
        'keyword', 'city', 'region', 'isp', 'browser', 'platform', 'device',
        'ip', 'referer', 'referer_url', 'c1', 'c2', 'c3', 'c4',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    ];

    /** A breakdown sorts by any metric its rows carry. */
    private const array ALLOWED_SORTS = self::METRIC_FIELDS;

    /** What a breakdown takes besides the window and the filters. */
    private const array BREAKDOWN_PARAMS = ['breakdown', 'sort', 'sort_dir', 'limit', 'offset'];

    public const int BREAKDOWN_MAX_LIMIT = 500;
    private const array DAYPART_ALLOWED_SORTS = [
        'hour_of_day',
        'total_clicks',
        'total_click_throughs',
        'total_leads',
        'total_income',
        'total_cost',
        'total_net',
        'epc',
        'avg_cpc',
        'conv_rate',
        'roi',
        'cpa',
    ];
    private const array WEEKPART_ALLOWED_SORTS = [
        'day_of_week',
        'total_clicks',
        'total_click_throughs',
        'total_leads',
        'total_income',
        'total_cost',
        'total_net',
        'epc',
        'avg_cpc',
        'conv_rate',
        'roi',
        'cpa',
    ];
    /** Most buckets one timeseries response carries; more sets `truncated`. */
    public const int TIMESERIES_MAX_ROWS = 2000;

    private const array DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    private const array METRIC_FIELDS = [
        'total_clicks',
        'total_click_throughs',
        'total_leads',
        'total_income',
        'total_cost',
        'total_net',
        'epc',
        'avg_cpc',
        'conv_rate',
        'roi',
        'cpa',
    ];
    private const array INTEGER_METRIC_FIELDS = [
        'total_clicks',
        'total_click_throughs',
        'total_leads',
    ];

    /** METRIC_FIELDS over 202_dataengine `de`, as every report computes them. */
    private const string METRICS_SELECT = 'SUM(de.clicks) as total_clicks,
                SUM(de.click_out) as total_click_throughs,
                SUM(de.leads) as total_leads,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as total_net,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.cost) / SUM(de.clicks) ELSE 0 END as avg_cpc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi,
                CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cpa';

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * The dimensions breakdown() accepts, in BREAKDOWNS order. /capabilities
     * advertises this list (features.report_breakdowns) so clients validate
     * against the server rather than a copy.
     *
     * @return list<string>
     */
    public static function breakdownDimensions(): array
    {
        return array_keys(self::BREAKDOWNS);
    }

    /**
     * The parameters each report endpoint takes: the window and the filters
     * (ReportFilter), and its own. /capabilities could advertise them; the
     * 422 for an unknown one lists them.
     *
     * @return list<string>
     */
    public static function reportParams(string $report): array
    {
        $own = match ($report) {
            'summary'    => [],
            'breakdown'  => self::BREAKDOWN_PARAMS,
            'timeseries' => ['interval'],
            'daypart', 'weekpart' => ['sort', 'sort_dir'],
            default      => throw new \InvalidArgumentException("No report named $report"),
        };

        return [...ReportFilter::params(), ...$own];
    }

    public function summary(array $params): array
    {
        ReportFilter::rejectUnknown($params, self::reportParams('summary'));
        [$where, $binds, $types] = $this->filtered($params);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                SUM(de.clicks) as total_clicks,
                SUM(de.click_out) as total_click_throughs,
                SUM(de.leads) as total_leads,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as total_net,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.cost) / SUM(de.clicks) ELSE 0 END as avg_cpc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi,
                CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cpa
            FROM 202_dataengine de
            $whereClause";

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Summary query failed');
        $result = $stmt->get_result();
        if ($result === false) {
            // false is not "no rows": an aggregate always returns one, so
            // reading on would serve a null summary as the account's totals.
            $stmt->close();
            throw new DatabaseException('Summary query failed');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return ['data' => $row];
    }

    public function breakdown(array $params): array
    {
        ReportFilter::rejectUnknown($params, self::reportParams('breakdown'));

        $breakdownType = $params['breakdown'] ?? '';
        if ($breakdownType === '') {
            $breakdownType = 'campaign';
        }
        if (!is_string($breakdownType) || !isset(self::BREAKDOWNS[$breakdownType])) {
            throw new ValidationException(
                'Invalid breakdown type',
                ['breakdown' => 'Valid values: ' . implode(', ', array_keys(self::BREAKDOWNS))]
            );
        }

        $bd = self::BREAKDOWNS[$breakdownType];
        $limit = self::wholeNumber($params, 'limit', 50, 1, self::BREAKDOWN_MAX_LIMIT, 'page with offset for more');
        $offset = self::wholeNumber($params, 'offset', 0, 0, PHP_INT_MAX, 'the number of rows to skip');
        $sortBy = self::sortField($params, 'total_clicks', self::ALLOWED_SORTS);
        $sortDir = self::sortDirection($params, 'DESC');

        [$where, $binds, $types] = $this->filtered($params);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $idSql = "ref.{$bd['id']}";
        $nameSql = $bd['name_sql'] ?? "ref.{$bd['name']}";
        $join = $bd['join'] ?? "INNER JOIN {$bd['table']} ref ON de.{$bd['de_id']} = ref.{$bd['id']}";
        $group = $bd['group'] ?? "ref.{$bd['id']}, ref.{$bd['name']}";
        $extraSelect = '';
        foreach ($bd['extra'] ?? [] as $alias => $expr) {
            $extraSelect .= "\n                $expr as $alias,";
            $group .= ", $expr";
        }

        // The id breaks sort ties (many rows share 0 clicks), so offset
        // paging neither skips nor repeats a row between pages.
        $sql = "SELECT
                $idSql as id,
                $nameSql as name,$extraSelect
                SUM(de.clicks) as total_clicks,
                SUM(de.click_out) as total_click_throughs,
                SUM(de.leads) as total_leads,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as total_net,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.cost) / SUM(de.clicks) ELSE 0 END as avg_cpc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi,
                CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cpa
            FROM 202_dataengine de
            $join
            $whereClause
            GROUP BY $group
            ORDER BY $sortBy $sortDir, $idSql ASC
            LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Breakdown query failed');
        $result = $stmt->get_result();
        if ($result === false) {
            // Read as an empty result set this was "no traffic", and a pager
            // stopped as though it had every row (CLAUDE.md #1).
            $stmt->close();
            throw new DatabaseException('Breakdown query failed');
        }

        $sanitizeNames = in_array($breakdownType, self::VISITOR_AUTHORED_BREAKDOWNS, true);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if ($sanitizeNames) {
                $row = ResponseSanitizer::cleanRowFields($row, ['name']);
            }
            $rows[] = $row;
        }
        $stmt->close();

        return [
            'data' => $rows,
            'breakdown' => $breakdownType,
            'available_breakdowns' => array_keys(self::BREAKDOWNS),
        ];
    }

    public function timeseries(array $params): array
    {
        ReportFilter::rejectUnknown($params, self::reportParams('timeseries'));
        $validIntervals = ['hour', 'day', 'week', 'month'];
        $interval = $params['interval'] ?? '';
        if ($interval === '') {
            $interval = 'day';
        }
        if (!in_array($interval, $validIntervals, true)) {
            throw new ValidationException(
                'Invalid interval',
                ['interval' => 'Valid values: ' . implode(', ', $validIntervals)]
            );
        }

        [$where, $binds, $types] = $this->filtered($params);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $groupExpr = match ($interval) {
            'hour'  => "FROM_UNIXTIME(de.click_time, '%Y-%m-%d %H:00')",
            'day'   => "FROM_UNIXTIME(de.click_time, '%Y-%m-%d')",
            'week'  => "FROM_UNIXTIME(de.click_time, '%x-W%v')",
            'month' => "FROM_UNIXTIME(de.click_time, '%Y-%m')",
        };

        $sql = "SELECT
                $groupExpr as period,
                SUM(de.clicks) as total_clicks,
                SUM(de.click_out) as total_click_throughs,
                SUM(de.leads) as total_leads,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as total_net,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.cost) / SUM(de.clicks) ELSE 0 END as avg_cpc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi,
                CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cpa
            FROM 202_dataengine de
            $whereClause
            GROUP BY period
            ORDER BY period ASC
            LIMIT " . (self::TIMESERIES_MAX_ROWS + 1);

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Timeseries query failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Timeseries query failed');
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        // The extra row only says more buckets exist; buckets run oldest
        // first, so a truncated series is missing its most recent periods.
        $truncated = count($rows) > self::TIMESERIES_MAX_ROWS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::TIMESERIES_MAX_ROWS);
        }

        return [
            'data' => $rows,
            'interval' => $interval,
            'truncated' => $truncated,
            'limit' => self::TIMESERIES_MAX_ROWS,
        ];
    }

    public function daypart(array $params): array
    {
        ReportFilter::rejectUnknown($params, self::reportParams('daypart'));
        $sortBy = self::sortField($params, 'hour_of_day', self::DAYPART_ALLOWED_SORTS);
        $sortDir = self::sortDirection($params, 'ASC');

        $timezone = $this->accountTimezone();

        [$where, $binds, $types] = $this->filtered($params, $timezone);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                COALESCE(
                    HOUR(CONVERT_TZ(FROM_UNIXTIME(de.click_time), '+00:00', ?)),
                    MOD(FLOOR(de.click_time / 3600), 24)
                ) as hour_of_day,
                SUM(de.clicks) as total_clicks,
                SUM(de.click_out) as total_click_throughs,
                SUM(de.leads) as total_leads,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as total_net,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.cost) / SUM(de.clicks) ELSE 0 END as avg_cpc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi,
                CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cpa
            FROM 202_dataengine de
            $whereClause
            GROUP BY hour_of_day";

        $stmt = $this->prepare($sql);
        $daypartBinds = array_merge([$timezone], $binds);
        $daypartTypes = 's' . $types;
        $this->bind($stmt, $daypartTypes, ...$daypartBinds);
        $this->execute($stmt, 'Daypart query failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Daypart query failed');
        }

        $rowsByHour = [];
        for ($hour = 0; $hour <= 23; $hour++) {
            $rowsByHour[$hour] = $this->zeroPartRow('hour_of_day', $hour);
        }

        while ($row = $result->fetch_assoc()) {
            $hour = (int)($row['hour_of_day'] ?? -1);
            if ($hour < 0 || $hour > 23) {
                continue;
            }
            $rowsByHour[$hour] = $this->hydratePartRow('hour_of_day', $hour, $row);
        }
        $stmt->close();

        $rows = array_values($rowsByHour);
        $this->sortPartRows($rows, 'hour_of_day', $sortBy, $sortDir);

        return [
            'data' => $rows,
            'timezone' => $timezone,
        ];
    }

    public function weekpart(array $params): array
    {
        ReportFilter::rejectUnknown($params, self::reportParams('weekpart'));
        $sortBy = self::sortField($params, 'day_of_week', self::WEEKPART_ALLOWED_SORTS);
        $sortDir = self::sortDirection($params, 'ASC');

        $timezone = $this->accountTimezone();

        [$where, $binds, $types] = $this->filtered($params, $timezone);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // WEEKDAY() returns 0=Monday .. 6=Sunday
        $sql = "SELECT
                COALESCE(
                    WEEKDAY(CONVERT_TZ(FROM_UNIXTIME(de.click_time), '+00:00', ?)),
                    MOD(FLOOR(de.click_time / 86400) + 3, 7)
                ) as day_of_week,
                SUM(de.clicks) as total_clicks,
                SUM(de.click_out) as total_click_throughs,
                SUM(de.leads) as total_leads,
                SUM(de.income) as total_income,
                SUM(de.cost) as total_cost,
                SUM(de.income) - SUM(de.cost) as total_net,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.income) / SUM(de.clicks) ELSE 0 END as epc,
                CASE WHEN SUM(de.clicks) > 0 THEN SUM(de.cost) / SUM(de.clicks) ELSE 0 END as avg_cpc,
                CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate,
                CASE WHEN SUM(de.cost) > 0 THEN (SUM(de.income) - SUM(de.cost)) / SUM(de.cost) * 100 ELSE 0 END as roi,
                CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cpa
            FROM 202_dataengine de
            $whereClause
            GROUP BY day_of_week";

        $stmt = $this->prepare($sql);
        $weekpartBinds = array_merge([$timezone], $binds);
        $weekpartTypes = 's' . $types;
        $this->bind($stmt, $weekpartTypes, ...$weekpartBinds);
        $this->execute($stmt, 'Weekpart query failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Weekpart query failed');
        }

        $rowsByDay = [];
        for ($day = 0; $day <= 6; $day++) {
            $rowsByDay[$day] = $this->zeroPartRow('day_of_week', $day);
            $rowsByDay[$day]['day_name'] = self::DAY_NAMES[$day];
        }

        while ($row = $result->fetch_assoc()) {
            $day = (int)($row['day_of_week'] ?? -1);
            if ($day < 0 || $day > 6) {
                continue;
            }
            $rowsByDay[$day] = $this->hydratePartRow('day_of_week', $day, $row);
            $rowsByDay[$day]['day_name'] = self::DAY_NAMES[$day];
        }
        $stmt->close();

        $rows = array_values($rowsByDay);
        $this->sortPartRows($rows, 'day_of_week', $sortBy, $sortDir);

        return [
            'data' => $rows,
            'timezone' => $timezone,
        ];
    }

    /**
     * One rotator's figures, in the shape of the Overview's Rotator
     * Breakdown (tracking202/ajax/sort_rotator.php): the rotator's totals,
     * each of its rules, and its default — the clicks no rule matched — over
     * the window and the filters every report takes.
     *
     * Read from 202_dataengine, whose rotator_id and rule_id are the click's
     * 202_clicks_rotator row: the rotator that routed it and the rule that
     * matched. The Overview page counted from 202_clicks instead, which
     * differs in two ways this does not copy:
     *  - it found a rule's clicks by 202_clicks.rule_id, which rtr.php fills
     *    with the chosen REDIRECT's id (rule_redirect_id). A rule's row
     *    counted the clicks whose redirect id happened to equal the rule id:
     *    another rule's clicks, or none;
     *  - it found a rotator's clicks by 202_clicks.rotator_id, which only
     *    rtr.php sets; a landing page's offer rotator (offrtr.php) records
     *    its clicks in 202_clicks_rotator alone, so they were missing.
     * Its money is this one's: income is the payout of the clicks that
     * converted, cost their CPC. Every rule is listed, a rule without clicks
     * at zero; a rule since deleted that still has clicks in the window is
     * listed after them with `deleted: true`, so the rules and the default
     * add up to the totals.
     */
    public function rotatorStats(int $rotatorId, array $params): array
    {
        ReportFilter::rejectUnknown($params, ReportFilter::params());

        $stmt = $this->prepare('SELECT id, name FROM 202_rotators WHERE id = ? AND user_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $rotatorId, $this->userId);
        $this->execute($stmt, 'Rotator lookup failed');
        $rotator = $this->fetchAll($stmt, 'Rotator lookup failed')[0] ?? null;
        if ($rotator === null) {
            throw new NotFoundException('Rotator not found');
        }

        $stmt = $this->prepare(
            'SELECT id, rule_name, status FROM 202_rotator_rules WHERE rotator_id = ? ORDER BY id ASC'
        );
        $this->bind($stmt, 'i', $rotatorId);
        $this->execute($stmt, 'Rotator rules lookup failed');
        $rules = $this->fetchAll($stmt, 'Rotator rules lookup failed');

        [$where, $binds, $types] = $this->filtered($params);
        $where[] = 'de.rotator_id = ?';
        $binds[] = $rotatorId;
        $types .= 'i';
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // 202_dataengine.rule_id is nullable; a NULL and a 0 group would be
        // two rows keyed 0 below, the second overwriting the first.
        $stmt = $this->prepare(
            'SELECT COALESCE(de.rule_id, 0) AS rule_id, ' . self::METRICS_SELECT
            . " FROM 202_dataengine de $whereClause GROUP BY COALESCE(de.rule_id, 0)"
        );
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Rotator stats query failed');
        $byRule = [];
        foreach ($this->fetchAll($stmt, 'Rotator stats query failed') as $row) {
            $byRule[(int) ($row['rule_id'] ?? 0)] = self::typedMetrics($row);
        }

        $stmt = $this->prepare('SELECT ' . self::METRICS_SELECT . " FROM 202_dataengine de $whereClause");
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Rotator stats query failed');
        $totals = self::typedMetrics($this->fetchAll($stmt, 'Rotator stats query failed')[0] ?? []);

        $ruleRows = [];
        foreach ($rules as $rule) {
            $id = (int) $rule['id'];
            $ruleRows[] = [
                'rule_id' => $id,
                'rule_name' => (string) $rule['rule_name'],
                'status' => $rule['status'] === null ? null : (int) $rule['status'],
                'deleted' => false,
            ] + ($byRule[$id] ?? self::typedMetrics([]));
            unset($byRule[$id]);
        }
        $default = $byRule[0] ?? self::typedMetrics([]);
        unset($byRule[0]);
        ksort($byRule);
        foreach ($byRule as $id => $metrics) {
            $ruleRows[] = ['rule_id' => $id, 'rule_name' => null, 'status' => null, 'deleted' => true] + $metrics;
        }

        return ['data' => [
            'rotator' => ['id' => (int) $rotator['id'], 'name' => (string) $rotator['name']],
            'totals' => $totals,
            'rules' => $ruleRows,
            'default' => $default,
        ]];
    }

    /**
     * Every row of an executed statement; a false get_result() is an error,
     * never an empty answer (CLAUDE.md #1). Closes the statement.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAll(\mysqli_stmt $stmt, string $message): array
    {
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException($message);
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * The metric columns, typed: counts as int, money and ratios as float,
     * 0 when the row has none.
     *
     * @return array<string, int|float>
     */
    private static function typedMetrics(array $row): array
    {
        $out = [];
        foreach (self::METRIC_FIELDS as $field) {
            $value = $row[$field] ?? 0;
            $out[$field] = in_array($field, self::INTEGER_METRIC_FIELDS, true) ? (int) $value : (float) $value;
        }

        return $out;
    }

    /**
     * The account's rows, narrowed by the window and the filters.
     *
     * @param ?string $timezone the account's timezone when the caller has
     *   already read it; otherwise it is read only if a bound needs it
     * @return array{0: list<string>, 1: list<int|string>, 2: string} WHERE terms, binds, bind types
     */
    private function filtered(array $params, ?string $timezone = null): array
    {
        $where = ['de.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';
        ReportFilter::apply(
            $params,
            fn (): string => $timezone ?? $this->accountTimezone(),
            $where,
            $binds,
            $types
        );

        return [$where, $binds, $types];
    }

    /**
     * A sort column from the list; '' or absent is the default. Anything
     * else is a 422 naming the valid ones: an unknown breakdown sort used to
     * become total_clicks without a word, so `sort=cpa` came back ranked by
     * clicks and read as ranked by CPA.
     *
     * @param list<string> $allowed
     */
    private static function sortField(array $params, string $default, array $allowed): string
    {
        $sort = $params['sort'] ?? '';
        if ($sort === '') {
            return $default;
        }
        if (!is_string($sort) || !in_array($sort, $allowed, true)) {
            throw new ValidationException('Invalid sort field', ['sort' => 'Valid values: ' . implode(', ', $allowed)]);
        }

        return $sort;
    }

    /** ASC or DESC, either case; '' or absent is the default. */
    private static function sortDirection(array $params, string $default): string
    {
        $dir = $params['sort_dir'] ?? '';
        if ($dir === '') {
            return $default;
        }
        $dir = is_string($dir) ? strtoupper($dir) : '';
        if ($dir !== 'ASC' && $dir !== 'DESC') {
            throw new ValidationException('Invalid sort_dir', ['sort_dir' => 'Valid values: ASC, DESC']);
        }

        return $dir;
    }

    /**
     * A paging number in [$min, $max]; '' or absent is the default. A value
     * out of range is refused rather than clamped: limit=1000 came back as
     * 500 rows that read as all of them.
     */
    private static function wholeNumber(
        array $params,
        string $name,
        int $default,
        int $min,
        int $max,
        string $what
    ): int {
        $value = $params[$name] ?? '';
        if ($value === '') {
            return $default;
        }
        $text = is_int($value) ? (string) $value : (is_string($value) ? $value : '');
        if (preg_match('/^[0-9]{1,18}$/D', $text) !== 1 || (int) $text < $min || (int) $text > $max) {
            $range = $max === PHP_INT_MAX ? "$min or more" : "$min to $max";
            throw new ValidationException('Invalid ' . $name, [$name => "A whole number, $range: $what"]);
        }

        return (int) $text;
    }

    private function zeroPartRow(string $keyName, int $keyValue): array
    {
        $row = [$keyName => $keyValue];
        foreach (self::METRIC_FIELDS as $field) {
            $row[$field] = 0;
        }
        return $row;
    }

    private function hydratePartRow(string $keyName, int $keyValue, array $row): array
    {
        $out = $this->zeroPartRow($keyName, $keyValue);
        foreach (self::METRIC_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $out[$field] = in_array($field, self::INTEGER_METRIC_FIELDS, true)
                    ? (int)$row[$field]
                    : (float)$row[$field];
            }
        }
        return $out;
    }

    private function sortPartRows(array &$rows, string $keyName, string $sortBy, string $sortDir): void
    {
        usort($rows, function (array $a, array $b) use ($keyName, $sortBy, $sortDir): int {
            if ($sortBy === $keyName) {
                $cmp = ((int)$a[$keyName]) <=> ((int)$b[$keyName]);
                return $sortDir === 'DESC' ? -$cmp : $cmp;
            }

            $cmp = ((float)$a[$sortBy]) <=> ((float)$b[$sortBy]);
            if ($cmp !== 0) {
                return $sortDir === 'DESC' ? -$cmp : $cmp;
            }

            return ((int)$a[$keyName]) <=> ((int)$b[$keyName]);
        });
    }
}
