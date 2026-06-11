<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Maps the report sort keys posted by the UI to ORDER BY clauses.
 *
 * The mapping is intentionally inverted ("... asc" produces DESC and vice
 * versa): the posted value is the *next* state the column header toggles to,
 * not the current one. This table preserves the exact legacy pairs.
 */
final class SortOrder
{
    public const DEFAULT_ORDER = ' ORDER BY leads DESC';

    private const ORDER_MAP = [
        'sort_breakdown_time_order desc' => 'ORDER BY click_time DESC',
        'sort_breakdown_time_order asc' => 'ORDER BY click_time ASC',
        'breakdown desc' => 'ORDER BY cast(DATE_FORMAT(FROM_UNIXTIME(click_time),"%k") as UNSIGNED) DESC',
        'breakdown asc' => 'ORDER BY cast(DATE_FORMAT(FROM_UNIXTIME(click_time),"%k") as UNSIGNED) ASC',
        'sort_breakdown_clicks asc' => 'ORDER BY `clicks` DESC',
        'sort_breakdown_clicks desc' => 'ORDER BY `clicks` ASC',
        'sort_breakdown_click_throughs asc' => 'ORDER BY `click_out` DESC',
        'sort_breakdown_click_throughs desc' => 'ORDER BY `click_out` ASC',
        'sort_breakdown_ctr asc' => 'ORDER BY `ctr` DESC',
        'sort_breakdown_ctr desc' => 'ORDER BY `ctr` ASC',
        'sort_breakdown_leads asc' => 'ORDER BY `leads` DESC',
        'sort_breakdown_leads desc' => 'ORDER BY `leads` ASC',
        'sort_breakdown_su_ratio asc' => 'ORDER BY `su` DESC',
        'sort_breakdown_su_ratio desc' => 'ORDER BY `su` ASC',
        'sort_breakdown_payout asc' => 'ORDER BY `payout` DESC',
        'sort_breakdown_payout desc' => 'ORDER BY `payout` ASC',
        'sort_breakdown_epc asc' => 'ORDER BY `epc` DESC',
        'sort_breakdown_epc desc' => 'ORDER BY `epc` ASC',
        'sort_breakdown_cpc asc' => 'ORDER BY `cpc` DESC',
        'sort_breakdown_cpc desc' => 'ORDER BY `cpc` ASC',
        'sort_breakdown_income asc' => 'ORDER BY `income` DESC',
        'sort_breakdown_income desc' => 'ORDER BY `income` ASC',
        'sort_breakdown_cost asc' => 'ORDER BY `cost` DESC',
        'sort_breakdown_cost desc' => 'ORDER BY `cost` ASC',
        'sort_breakdown_net asc' => 'ORDER BY `net` DESC',
        'sort_breakdown_net desc' => 'ORDER BY `net` ASC',
        'sort_breakdown_roi asc' => 'ORDER BY `roi` DESC',
        'sort_breakdown_roi desc' => 'ORDER BY `roi` ASC',
    ];

    /**
     * Resolve a posted sort key to an ORDER BY clause.
     *
     * Note the asymmetric whitespace: mapped clauses have no leading space
     * (their call sites end with whitespace), while the default clause keeps
     * its leading space because grouped-report queries concatenate it
     * directly after "group by <column>".
     */
    public static function orderByClause(string $sortKey): string
    {
        return self::ORDER_MAP[$sortKey] ?? self::DEFAULT_ORDER;
    }

    private function __construct()
    {
    }
}
