<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;

class ReportsController
{
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
    ];

    private const array ALLOWED_SORTS = ['total_clicks', 'total_leads', 'total_income', 'total_cost', 'total_net', 'roi', 'epc', 'conv_rate'];
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

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    public function summary(array $params): array
    {
        $where = ['de.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        $this->applyTimeFilters($params, $where, $binds, $types);
        $this->applyEntityFilters($params, $where, $binds, $types);

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
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Summary query failed');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ['data' => $row];
    }

    public function breakdown(array $params): array
    {
        $breakdownType = $params['breakdown'] ?? 'campaign';
        if (!isset(self::BREAKDOWNS[$breakdownType])) {
            throw new ValidationException(
                'Invalid breakdown type',
                ['breakdown' => 'Valid values: ' . implode(', ', array_keys(self::BREAKDOWNS))]
            );
        }

        $bd = self::BREAKDOWNS[$breakdownType];
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));
        $sortBy = $params['sort'] ?? 'total_clicks';
        $sortDir = strtoupper($params['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        if (!in_array($sortBy, self::ALLOWED_SORTS, true)) {
            $sortBy = 'total_clicks';
        }

        $where = ['de.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        $this->applyTimeFilters($params, $where, $binds, $types);
        $this->applyEntityFilters($params, $where, $binds, $types);

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                ref.{$bd['id']} as id,
                ref.{$bd['name']} as name,
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
            INNER JOIN {$bd['table']} ref ON de.{$bd['de_id']} = ref.{$bd['id']}
            $whereClause
            GROUP BY ref.{$bd['id']}, ref.{$bd['name']}
            ORDER BY $sortBy $sortDir
            LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Breakdown query failed');
        }
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
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
        $validIntervals = ['hour', 'day', 'week', 'month'];
        $interval = $params['interval'] ?? 'day';
        if (!in_array($interval, $validIntervals, true)) {
            throw new ValidationException(
                'Invalid interval',
                ['interval' => 'Valid values: ' . implode(', ', $validIntervals)]
            );
        }

        $where = ['de.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        $this->applyTimeFilters($params, $where, $binds, $types);
        $this->applyEntityFilters($params, $where, $binds, $types);

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
            LIMIT 2000";

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Timeseries query failed');
        }
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return ['data' => $rows, 'interval' => $interval];
    }

    public function daypart(array $params): array
    {
        $sortBy = (string)($params['sort'] ?? 'hour_of_day');
        if (!in_array($sortBy, self::DAYPART_ALLOWED_SORTS, true)) {
            throw new ValidationException(
                'Invalid sort field',
                ['sort' => 'Valid values: ' . implode(', ', self::DAYPART_ALLOWED_SORTS)]
            );
        }

        $sortDir = strtoupper((string)($params['sort_dir'] ?? 'ASC'));
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'ASC';
        }

        $timezone = $this->resolveUserTimezone();

        $where = ['de.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        $this->applyTimeFilters($params, $where, $binds, $types);
        $this->applyEntityFilters($params, $where, $binds, $types);

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
        $stmt->bind_param($daypartTypes, ...$daypartBinds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Daypart query failed');
        }
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
        $sortBy = (string)($params['sort'] ?? 'day_of_week');
        if (!in_array($sortBy, self::WEEKPART_ALLOWED_SORTS, true)) {
            throw new ValidationException(
                'Invalid sort field',
                ['sort' => 'Valid values: ' . implode(', ', self::WEEKPART_ALLOWED_SORTS)]
            );
        }

        $sortDir = strtoupper((string)($params['sort_dir'] ?? 'ASC'));
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'ASC';
        }

        $timezone = $this->resolveUserTimezone();

        $where = ['de.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        $this->applyTimeFilters($params, $where, $binds, $types);
        $this->applyEntityFilters($params, $where, $binds, $types);

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
        $stmt->bind_param($weekpartTypes, ...$weekpartBinds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Weekpart query failed');
        }
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

    private function applyTimeFilters(array $params, array &$where, array &$binds, string &$types): void
    {
        if (!empty($params['time_from'])) {
            $where[] = 'de.click_time >= ?';
            $binds[] = (int)$params['time_from'];
            $types .= 'i';
        }
        if (!empty($params['time_to'])) {
            $where[] = 'de.click_time <= ?';
            $binds[] = (int)$params['time_to'];
            $types .= 'i';
        }
        if (!empty($params['period'])) {
            $now = time();
            $todayStart = strtotime('today midnight');
            [$from, $to] = match ($params['period']) {
                'today'     => [$todayStart, $now],
                'yesterday' => [$todayStart - 86400, $todayStart - 1],
                'last7'     => [$now - (7 * 86400), $now],
                'last30'    => [$now - (30 * 86400), $now],
                'last90'    => [$now - (90 * 86400), $now],
                default     => [0, $now],
            };
            $where[] = 'de.click_time >= ?';
            $binds[] = $from;
            $types .= 'i';
            $where[] = 'de.click_time <= ?';
            $binds[] = $to;
            $types .= 'i';
        }
    }

    private function applyEntityFilters(array $params, array &$where, array &$binds, string &$types): void
    {
        foreach (['aff_campaign_id', 'aff_network_id', 'ppc_account_id', 'ppc_network_id', 'landing_page_id', 'country_id'] as $f) {
            if (!empty($params[$f])) {
                $where[] = "de.$f = ?";
                $binds[] = (int)$params[$f];
                $types .= 'i';
            }
        }
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }

    private function resolveUserTimezone(): string
    {
        $stmt = $this->prepare('SELECT user_timezone FROM 202_users WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Failed to resolve timezone');
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Failed to resolve timezone');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        $timezone = trim((string)($row['user_timezone'] ?? ''));
        if ($timezone === '') {
            return 'UTC';
        }

        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            return 'UTC';
        }
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
