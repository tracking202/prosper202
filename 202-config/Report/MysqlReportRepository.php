<?php

declare(strict_types=1);

namespace Prosper202\Report;

use Prosper202\Database\Connection;
use RuntimeException;

final class MysqlReportRepository implements ReportRepositoryInterface
{
    private const array BREAKDOWNS = [
        'campaign'     => ['table' => '202_aff_campaigns',     'id' => 'aff_campaign_id',  'name' => 'aff_campaign_name',  'de_id' => 'aff_campaign_id'],
        'aff_network'  => ['table' => '202_aff_networks',      'id' => 'aff_network_id',   'name' => 'aff_network_name',   'de_id' => 'aff_network_id'],
        'ppc_account'  => ['table' => '202_ppc_accounts',      'id' => 'ppc_account_id',   'name' => 'ppc_account_name',   'de_id' => 'ppc_account_id'],
        'ppc_network'  => ['table' => '202_ppc_networks',      'id' => 'ppc_network_id',   'name' => 'ppc_network_name',   'de_id' => 'ppc_network_id'],
        'landing_page' => ['table' => '202_landing_pages',     'id' => 'landing_page_id',  'name' => 'landing_page_url',   'de_id' => 'landing_page_id'],
        'keyword'      => ['table' => '202_keywords',          'id' => 'keyword_id',       'name' => 'keyword',            'de_id' => 'keyword_id'],
        'country'      => ['table' => '202_locations_country', 'id' => 'country_id',       'name' => 'country_name',       'de_id' => 'country_id'],
        'city'         => ['table' => '202_locations_city',    'id' => 'city_id',          'name' => 'city_name',          'de_id' => 'city_id'],
        'region'       => ['table' => '202_locations_region',  'id' => 'region_id',        'name' => 'region_name',        'de_id' => 'region_id'],
        'browser'      => ['table' => '202_browsers',          'id' => 'browser_id',       'name' => 'browser_name',       'de_id' => 'browser_id'],
        'platform'     => ['table' => '202_platforms',          'id' => 'platform_id',      'name' => 'platform_name',      'de_id' => 'platform_id'],
        'device'       => ['table' => '202_device_models',      'id' => 'device_id',        'name' => 'device_name',        'de_id' => 'device_id'],
        'isp'          => ['table' => '202_locations_isp',      'id' => 'isp_id',           'name' => 'isp_name',           'de_id' => 'isp_id'],
        'text_ad'      => ['table' => '202_text_ads',           'id' => 'text_ad_id',       'name' => 'text_ad_name',       'de_id' => 'text_ad_id'],
    ];

    private const string METRIC_SELECT = "
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
        CASE WHEN SUM(de.leads) > 0 THEN SUM(de.cost) / SUM(de.leads) ELSE 0 END as cpa";

    private const array ALLOWED_SORTS = ['total_clicks', 'total_leads', 'total_income', 'total_cost', 'total_net', 'roi', 'epc', 'conv_rate'];

    public function __construct(private Connection $conn)
    {
    }

    public function summary(ReportQuery $query): array
    {
        [$whereClause, $types, $binds] = $this->buildWhere($query);

        $sql = "SELECT " . self::METRIC_SELECT . "
            FROM 202_dataengine de
            $whereClause";

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);
        $row = $this->conn->fetchOne($stmt);

        return $row ?? [];
    }

    public function breakdown(
        ReportQuery $query,
        string $breakdownType,
        string $sortBy = 'total_clicks',
        string $sortDir = 'DESC',
        int $limit = 50,
        int $offset = 0,
    ): array {
        if (!isset(self::BREAKDOWNS[$breakdownType])) {
            throw new RuntimeException("Invalid breakdown type: $breakdownType");
        }

        $bd = self::BREAKDOWNS[$breakdownType];
        if (!in_array($sortBy, self::ALLOWED_SORTS, true)) {
            $sortBy = 'total_clicks';
        }
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        [$whereClause, $types, $binds] = $this->buildWhere($query);

        $sql = "SELECT
                ref.{$bd['id']} as id,
                ref.{$bd['name']} as name,
                " . self::METRIC_SELECT . "
            FROM 202_dataengine de
            INNER JOIN {$bd['table']} ref ON de.{$bd['de_id']} = ref.{$bd['id']}
            $whereClause
            GROUP BY ref.{$bd['id']}, ref.{$bd['name']}
            ORDER BY $sortBy $sortDir
            LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return $this->conn->fetchAll($stmt);
    }

    public function timeseries(
        ReportQuery $query,
        string $interval = 'day',
    ): array {
        $groupExpr = match ($interval) {
            'hour'  => "FROM_UNIXTIME(de.click_time, '%Y-%m-%d %H:00')",
            'day'   => "FROM_UNIXTIME(de.click_time, '%Y-%m-%d')",
            'week'  => "FROM_UNIXTIME(de.click_time, '%x-W%v')",
            'month' => "FROM_UNIXTIME(de.click_time, '%Y-%m')",
            default => throw new RuntimeException("Invalid interval: $interval"),
        };

        [$whereClause, $types, $binds] = $this->buildWhere($query);

        $sql = "SELECT
                $groupExpr as period,
                " . self::METRIC_SELECT . "
            FROM 202_dataengine de
            $whereClause
            GROUP BY period
            ORDER BY period ASC
            LIMIT 2000";

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return $this->conn->fetchAll($stmt);
    }

    public function daypart(ReportQuery $query, string $timezone = 'UTC'): array
    {
        [$whereClause, $types, $binds] = $this->buildWhere($query);

        $sql = "SELECT
                COALESCE(
                    HOUR(CONVERT_TZ(FROM_UNIXTIME(de.click_time), '+00:00', ?)),
                    MOD(FLOOR(de.click_time / 3600), 24)
                ) as hour_of_day,
                " . self::METRIC_SELECT . "
            FROM 202_dataengine de
            $whereClause
            GROUP BY hour_of_day";

        // Timezone param prepended
        $allBinds = array_merge([$timezone], $binds);
        $allTypes = 's' . $types;

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $allTypes, $allBinds);

        return $this->conn->fetchAll($stmt);
    }

    public function weekpart(ReportQuery $query, string $timezone = 'UTC'): array
    {
        [$whereClause, $types, $binds] = $this->buildWhere($query);

        $sql = "SELECT
                COALESCE(
                    WEEKDAY(CONVERT_TZ(FROM_UNIXTIME(de.click_time), '+00:00', ?)),
                    MOD(FLOOR(de.click_time / 86400) + 3, 7)
                ) as day_of_week,
                " . self::METRIC_SELECT . "
            FROM 202_dataengine de
            $whereClause
            GROUP BY day_of_week";

        $allBinds = array_merge([$timezone], $binds);
        $allTypes = 's' . $types;

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $allTypes, $allBinds);

        return $this->conn->fetchAll($stmt);
    }

    /**
     * @return array{string, string, list<int>}
     */
    private function buildWhere(ReportQuery $query): array
    {
        $where = ['de.user_id = ?'];
        $binds = [$query->userId];
        $types = 'i';

        if ($query->timeFrom !== null) {
            $where[] = 'de.click_time >= ?';
            $binds[] = $query->timeFrom;
            $types .= 'i';
        }
        if ($query->timeTo !== null) {
            $where[] = 'de.click_time <= ?';
            $binds[] = $query->timeTo;
            $types .= 'i';
        }

        foreach ($query->entityFilters as $field => $value) {
            $where[] = "de.$field = ?";
            $binds[] = $value;
            $types .= 'i';
        }

        return ['WHERE ' . implode(' AND ', $where), $types, $binds];
    }
}
