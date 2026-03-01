<?php

declare(strict_types=1);

namespace Prosper202\Report;

interface ReportRepositoryInterface
{
    /**
     * Aggregate summary (no grouping) — total clicks, leads, income, cost, net, etc.
     *
     * @return array<string, mixed> Single row of aggregated metrics
     */
    public function summary(ReportQuery $query): array;

    /**
     * Breakdown by a dimension (campaign, country, keyword, etc.) with pagination.
     *
     * @return list<array<string, mixed>> Rows with id, name, and metric columns
     */
    public function breakdown(
        ReportQuery $query,
        string $breakdownType,
        string $sortBy = 'total_clicks',
        string $sortDir = 'DESC',
        int $limit = 50,
        int $offset = 0,
    ): array;

    /**
     * Time series aggregation grouped by hour/day/week/month.
     *
     * @return list<array<string, mixed>> Rows with period and metric columns
     */
    public function timeseries(
        ReportQuery $query,
        string $interval = 'day',
    ): array;

    /**
     * Group by hour of day (0-23) with timezone support.
     *
     * @return list<array<string, mixed>> 24 rows (one per hour), each with metrics
     */
    public function daypart(ReportQuery $query, string $timezone = 'UTC'): array;

    /**
     * Group by day of week (0=Mon..6=Sun) with timezone support.
     *
     * @return list<array<string, mixed>> 7 rows (one per day), each with metrics + day_name
     */
    public function weekpart(ReportQuery $query, string $timezone = 'UTC'): array;
}
