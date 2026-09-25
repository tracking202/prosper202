<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * The report filters one request draws under, when they are not the stored
 * ones.
 *
 * Every classic report reader — grab_timeframe(), query(), DataEngine, the
 * AJAX fragments and the *_download.php files — reads the filters from the
 * user's single 202_users_pref row. A v2 report page writes its URL's
 * filters to that row as it loads (so they are the default next time), but
 * the fragment, the poll and the download that page asks for run later, in
 * requests of their own; if another tab has written different filters in
 * between, they would read that tab's and draw a report the page does not
 * describe.
 *
 * So a page hands each of those requests the view it rendered, in the URL
 * (functions-report-prefs.php, p202_report_view_*), and the request installs
 * it here. Every reader passes the row it read through apply(), which lays
 * the installed columns over it — in memory, for this request only; nothing
 * is written. A request that installs nothing reads the stored row exactly
 * as before.
 *
 * tests/Report/ReportViewReadersTest checks that every read of a report
 * column from 202_users_pref goes through apply().
 */
final class ReportView
{
    /** The query parameter a view travels in. */
    public const PARAM = 'view';

    /** The columns a view may carry: the window and the report filters. */
    public const COLUMNS = [
        'user_pref_time_predefined', 'user_pref_time_from', 'user_pref_time_to',
        'user_pref_ppc_network_id', 'user_pref_ppc_account_id', 'user_pref_aff_network_id',
        'user_pref_aff_campaign_id', 'user_pref_text_ad_id', 'user_pref_landing_page_id',
        'user_pref_method_of_promotion', 'user_pref_country_id', 'user_pref_region_id',
        'user_pref_isp_id', 'user_pref_device_id', 'user_pref_browser_id', 'user_pref_platform_id',
        'user_pref_subid', 'user_pref_ip', 'user_pref_referer', 'user_pref_keyword',
        'user_pref_limit', 'user_pref_breakdown', 'user_pref_show', 'user_cpc_or_cpv',
        'user_pref_group_1', 'user_pref_group_2', 'user_pref_group_3', 'user_pref_group_4',
    ];

    private static ?int $userId = null;

    /** @var array<string, string|null> */
    private static array $columns = [];

    /**
     * Draw this request under these columns for this user.
     *
     * @param array<string, string|null> $columns  column => value, as
     *   p202_report_prefs_from_query() returns them (null is SQL NULL)
     */
    public static function install(int $userId, array $columns): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('ReportView::install(): no user to install a view for');
        }
        foreach ($columns as $column => $value) {
            if (!in_array($column, self::COLUMNS, true)) {
                throw new \InvalidArgumentException("ReportView::install(): '$column' is not a report column");
            }
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException("ReportView::install(): '$column' must be a string or null");
            }
        }
        if (self::$userId !== null && self::$userId !== $userId) {
            throw new \LogicException('ReportView::install(): a view for another user is already installed in this request');
        }
        self::$userId = $userId;
        self::$columns = $columns + self::$columns;
    }

    /**
     * A 202_users_pref row as this request should read it: the installed
     * columns laid over it when the row is the viewing user's, the row
     * unchanged otherwise. Only columns the row was read with are replaced,
     * so a reader that selected three columns still gets three.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function apply(array $row, int|string|null $userId): array
    {
        if (self::$userId === null || (string) self::$userId !== (string) $userId) {
            return $row;
        }
        $missing = $row === [];
        foreach (self::$columns as $column => $value) {
            if ($missing || array_key_exists($column, $row)) {
                $row[$column] = $value;
            }
        }
        return $row;
    }

    /** Whether this request draws under an installed view. */
    public static function active(): bool
    {
        return self::$userId !== null;
    }

    /** For tests: forget the installed view. */
    public static function reset(): void
    {
        self::$userId = null;
        self::$columns = [];
    }
}
