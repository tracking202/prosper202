<?php

declare(strict_types=1);

namespace Prosper202\Report;

use RuntimeException;

/**
 * In-memory implementation that stores raw dataengine rows and computes aggregations.
 *
 * Each row should have the same structure as 202_dataengine: user_id, click_time,
 * clicks, click_out, leads, income, cost, plus dimension IDs (aff_campaign_id, etc.).
 */
final class InMemoryReportRepository implements ReportRepositoryInterface
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, array<int, string>> Breakdown dimension: id => name mappings */
    public array $dimensions = [];

    private const array BREAKDOWNS = [
        'campaign'     => 'aff_campaign_id',
        'aff_network'  => 'aff_network_id',
        'ppc_account'  => 'ppc_account_id',
        'ppc_network'  => 'ppc_network_id',
        'landing_page' => 'landing_page_id',
        'keyword'      => 'keyword_id',
        'country'      => 'country_id',
        'city'         => 'city_id',
        'region'       => 'region_id',
        'browser'      => 'browser_id',
        'platform'     => 'platform_id',
        'device'       => 'device_id',
        'isp'          => 'isp_id',
        'text_ad'      => 'text_ad_id',
    ];

    public function summary(ReportQuery $query): array
    {
        $filtered = $this->filter($query);

        return $this->aggregate($filtered);
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

        $deId = self::BREAKDOWNS[$breakdownType];
        $filtered = $this->filter($query);

        // Group by dimension ID
        $groups = [];
        foreach ($filtered as $row) {
            $id = (int) ($row[$deId] ?? 0);
            $groups[$id][] = $row;
        }

        $result = [];
        foreach ($groups as $id => $groupRows) {
            $agg = $this->aggregate($groupRows);
            $agg['id'] = $id;
            $agg['name'] = $this->dimensions[$breakdownType][$id] ?? '';
            $result[] = $agg;
        }

        // Sort
        usort($result, function (array $a, array $b) use ($sortBy, $sortDir): int {
            $cmp = ($a[$sortBy] ?? 0) <=> ($b[$sortBy] ?? 0);
            return $sortDir === 'ASC' ? $cmp : -$cmp;
        });

        return array_slice($result, $offset, $limit);
    }

    public function timeseries(
        ReportQuery $query,
        string $interval = 'day',
    ): array {
        $filtered = $this->filter($query);

        $groups = [];
        foreach ($filtered as $row) {
            $ts = (int) ($row['click_time'] ?? 0);
            $period = match ($interval) {
                'hour'  => date('Y-m-d H:00', $ts),
                'day'   => date('Y-m-d', $ts),
                'week'  => date('o-\\WW', $ts),
                'month' => date('Y-m', $ts),
                default => throw new RuntimeException("Invalid interval: $interval"),
            };
            $groups[$period][] = $row;
        }

        ksort($groups);

        $result = [];
        foreach ($groups as $period => $groupRows) {
            $agg = $this->aggregate($groupRows);
            $agg['period'] = $period;
            $result[] = $agg;
        }

        return array_slice($result, 0, 2000);
    }

    public function daypart(ReportQuery $query, string $timezone = 'UTC'): array
    {
        $filtered = $this->filter($query);
        $tz = new \DateTimeZone($timezone);

        $groups = [];
        foreach ($filtered as $row) {
            $ts = (int) ($row['click_time'] ?? 0);
            $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
            $hour = (int) $dt->format('G');
            $groups[$hour][] = $row;
        }

        $result = [];
        foreach ($groups as $hour => $groupRows) {
            $agg = $this->aggregate($groupRows);
            $agg['hour_of_day'] = $hour;
            $result[] = $agg;
        }

        return $result;
    }

    public function weekpart(ReportQuery $query, string $timezone = 'UTC'): array
    {
        $filtered = $this->filter($query);
        $tz = new \DateTimeZone($timezone);

        $groups = [];
        foreach ($filtered as $row) {
            $ts = (int) ($row['click_time'] ?? 0);
            $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
            // 0=Mon..6=Sun (same as MySQL WEEKDAY())
            $day = ((int) $dt->format('N')) - 1;
            $groups[$day][] = $row;
        }

        $result = [];
        foreach ($groups as $day => $groupRows) {
            $agg = $this->aggregate($groupRows);
            $agg['day_of_week'] = $day;
            $result[] = $agg;
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filter(ReportQuery $query): array
    {
        return array_values(array_filter($this->rows, function (array $row) use ($query): bool {
            if ((int) ($row['user_id'] ?? 0) !== $query->userId) {
                return false;
            }
            $clickTime = (int) ($row['click_time'] ?? 0);
            if ($query->timeFrom !== null && $clickTime < $query->timeFrom) {
                return false;
            }
            if ($query->timeTo !== null && $clickTime > $query->timeTo) {
                return false;
            }
            foreach ($query->entityFilters as $field => $value) {
                if ((int) ($row[$field] ?? 0) !== $value) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, float|int>
     */
    private function aggregate(array $rows): array
    {
        $clicks = 0;
        $clickOut = 0.0;
        $leads = 0.0;
        $income = 0.0;
        $cost = 0.0;

        foreach ($rows as $row) {
            $clicks += (int) ($row['clicks'] ?? 0);
            $clickOut += (float) ($row['click_out'] ?? 0);
            $leads += (float) ($row['leads'] ?? 0);
            $income += (float) ($row['income'] ?? 0);
            $cost += (float) ($row['cost'] ?? 0);
        }

        $net = $income - $cost;

        return [
            'total_clicks' => $clicks,
            'total_click_throughs' => (int) $clickOut,
            'total_leads' => (int) $leads,
            'total_income' => $income,
            'total_cost' => $cost,
            'total_net' => $net,
            'epc' => $clicks > 0 ? $income / $clicks : 0.0,
            'avg_cpc' => $clicks > 0 ? $cost / $clicks : 0.0,
            'conv_rate' => $clickOut > 0 ? ($leads / $clickOut) * 100 : 0.0,
            'roi' => $cost > 0 ? ($net / $cost) * 100 : 0.0,
            'cpa' => $leads > 0 ? $cost / $leads : 0.0,
        ];
    }
}
