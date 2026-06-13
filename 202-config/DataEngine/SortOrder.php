<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Maps report sort keys to ORDER BY clauses.
 *
 * The map is a strict whitelist: anything not listed falls back to the
 * default (leads DESC), so attacker-controlled input can never reach the
 * ORDER BY clause. Keys mean what they say — "<key> asc" sorts ascending.
 * (The legacy table inverted the metric keys because the posted value was
 * the *next* toggle state of a UI that no longer exists; no shipped code
 * emits these keys today, so the contract was straightened out for
 * whatever UI adopts it next.)
 *
 * Every clause carries a leading space so it can be concatenated directly
 * after "GROUP BY <column>".
 */
final class SortOrder
{
    public const DEFAULT_ORDER = ' ORDER BY leads DESC';

    private const ORDER_MAP = [
        'sort_breakdown_time_order asc' => ' ORDER BY click_time ASC',
        'sort_breakdown_time_order desc' => ' ORDER BY click_time DESC',
        'breakdown asc' => ' ORDER BY cast(DATE_FORMAT(FROM_UNIXTIME(click_time),"%k") as UNSIGNED) ASC',
        'breakdown desc' => ' ORDER BY cast(DATE_FORMAT(FROM_UNIXTIME(click_time),"%k") as UNSIGNED) DESC',
        'sort_breakdown_clicks asc' => ' ORDER BY `clicks` ASC',
        'sort_breakdown_clicks desc' => ' ORDER BY `clicks` DESC',
        'sort_breakdown_click_throughs asc' => ' ORDER BY `click_out` ASC',
        'sort_breakdown_click_throughs desc' => ' ORDER BY `click_out` DESC',
        'sort_breakdown_ctr asc' => ' ORDER BY `ctr` ASC',
        'sort_breakdown_ctr desc' => ' ORDER BY `ctr` DESC',
        'sort_breakdown_leads asc' => ' ORDER BY `leads` ASC',
        'sort_breakdown_leads desc' => ' ORDER BY `leads` DESC',
        'sort_breakdown_su_ratio asc' => ' ORDER BY `su_ratio` ASC',
        'sort_breakdown_su_ratio desc' => ' ORDER BY `su_ratio` DESC',
        'sort_breakdown_payout asc' => ' ORDER BY `payout` ASC',
        'sort_breakdown_payout desc' => ' ORDER BY `payout` DESC',
        'sort_breakdown_epc asc' => ' ORDER BY `epc` ASC',
        'sort_breakdown_epc desc' => ' ORDER BY `epc` DESC',
        'sort_breakdown_cpc asc' => ' ORDER BY `cpc` ASC',
        'sort_breakdown_cpc desc' => ' ORDER BY `cpc` DESC',
        'sort_breakdown_income asc' => ' ORDER BY `income` ASC',
        'sort_breakdown_income desc' => ' ORDER BY `income` DESC',
        'sort_breakdown_cost asc' => ' ORDER BY `cost` ASC',
        'sort_breakdown_cost desc' => ' ORDER BY `cost` DESC',
        'sort_breakdown_net asc' => ' ORDER BY `net` ASC',
        'sort_breakdown_net desc' => ' ORDER BY `net` DESC',
        'sort_breakdown_roi asc' => ' ORDER BY `roi` ASC',
        'sort_breakdown_roi desc' => ' ORDER BY `roi` DESC',
    ];

    public static function orderByClause(string $sortKey): string
    {
        return self::ORDER_MAP[$sortKey] ?? self::DEFAULT_ORDER;
    }

    /**
     * Returns the sort key unchanged if it is whitelisted, '' otherwise.
     * Use this when reflecting a posted sort key back into markup (e.g.
     * pagination onclick handlers): browsers decode HTML entities in event
     * handler attributes before executing them, so escaping alone cannot
     * make arbitrary input safe in that context — only known keys may pass.
     */
    public static function canonicalKey(string $sortKey): string
    {
        return isset(self::ORDER_MAP[$sortKey]) ? $sortKey : '';
    }

    private function __construct()
    {
    }
}
