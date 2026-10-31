<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

use InvalidArgumentException;
use Prosper202\Report\LocalTime;

/** Exact metric sums before calendar formatting or dimension-label joins. */
final class ReportAggregation
{
    public const METRICS = ['clicks', 'click_out', 'click_lead', 'leads', 'income', 'cost'];

    /**
     * Build a FROM clause over matching clicks grouped by raw dimension IDs.
     *
     * The caller supplies an internally built WHERE clause using $alias, with
     * account, date and click filters already applied. Labels and final grouping
     * belong outside this source; duplicate or missing labels still merge there.
     *
     * @param list<string> $dimensions Raw dataengine column names.
     * @param list<string> $metrics Columns to sum, excluding dimension columns.
     */
    public static function byDimensions(
        string $alias,
        array $dimensions,
        string $where,
        array $metrics = self::METRICS,
        ?string $resultAlias = null,
    ): string {
        return self::grouped($alias, $dimensions, $where, $metrics, [], $resultAlias);
    }

    /**
     * Group UTC hours only when every modeled local-hour boundary aligns.
     *
     * @param list<string> $dimensions Additional raw IDs needed after grouping.
     */
    public static function byHour(string $where, string $timezone, array $dimensions = []): string
    {
        foreach (LocalTime::offsets($timezone, time()) as [$transition, $offset]) {
            if ($transition % 3600 !== 0 || $offset % 3600 !== 0) {
                // Keep WHERE inside the source so callers can still join labels.
                return ' FROM (SELECT * FROM 202_dataengine AS 2st ' . $where . ') AS 2st ';
            }
        }

        return self::grouped('2st', $dimensions, $where, self::METRICS, ['2st.click_time DIV 3600']);
    }

    /**
     * @param list<string> $dimensions
     * @param list<string> $metrics
     * @param list<string> $extraGroups Internal grouping expressions.
     */
    private static function grouped(
        string $alias,
        array $dimensions,
        string $where,
        array $metrics,
        array $extraGroups = [],
        ?string $resultAlias = null,
    ): string {
        $resultAlias ??= $alias;
        if (!in_array($alias, ['2st', '2c'], true) || !in_array($resultAlias, ['2st', '2c'], true)) {
            throw new InvalidArgumentException('Unsupported report table alias.');
        }
        $dimensions = array_values(array_unique($dimensions));
        $select = [];
        $groups = $extraGroups;
        foreach ($dimensions as $column) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $column) !== 1 || in_array($column, $metrics, true)) {
                throw new InvalidArgumentException('Invalid report dimension.');
            }
            $select[] = "$alias.$column AS $column";
            $groups[] = "$alias.$column";
        }
        if (!in_array('click_time', $dimensions, true)) {
            $select[] = "MIN($alias.click_time) AS click_time";
        }
        foreach ($metrics as $column) {
            if (!in_array($column, self::METRICS, true)) {
                throw new InvalidArgumentException('Invalid report metric.');
            }
            $select[] = "SUM($alias.$column) AS $column";
        }

        return ' FROM (SELECT ' . implode(', ', $select)
            . " FROM 202_dataengine AS $alias " . $where
            . ($groups === [] ? '' : ' GROUP BY ' . implode(', ', $groups)) . ") AS $resultAlias ";
    }
}
