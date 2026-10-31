<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Builds the WHERE/JOIN/LIMIT SQL fragments derived from a user's report
 * preferences (202_users_pref row). Pure string assembly: the keyword,
 * referer and IP filters are subqueries (TextFilterSql, the API's
 * ReportFilter), so nothing needs looking up first, and their text is
 * quoted through the escaper the caller passes (the connection's
 * real_escape_string); numeric preferences are integer-cast here.
 *
 * Faithful to the legacy DataEngine::getFilters(), including the quirk that
 * a "show" preference of real/filtered/filtered_bot/leads *replaces* any
 * subid filter accumulated before it.
 */
final class UserPrefFilters
{
    /**
     * @param array<string, mixed>     $userRow     Row from 202_users_pref.
     * @param int                      $offset      Page offset (from the request).
     * @param bool                     $forDownload Downloads are not paginated.
     * @param callable(string): string $escape      The connection's
     *                                              real_escape_string, for the
     *                                              text filters' literals.
     *
     * @return array{join: string, filter: string, limit: string} `join` is
     *   always '' now (the keyword filter joined 202_keywords for its LIKE);
     *   it stays in the shape for the reports that splice it in.
     */
    public static function build(
        array $userRow,
        int $offset,
        bool $forDownload,
        callable $escape,
    ): array {
        $filter = '';
        $join = '';

        if (!empty($userRow['user_pref_subid']) && $userRow['user_pref_subid'] != '0') {
            $filter .= " AND 2st.click_id=" . (int) $userRow['user_pref_subid'];
        }

        $showFilter = self::showFilter((string) ($userRow['user_pref_show'] ?? 'all'));
        if ($showFilter !== '') {
            $filter = $showFilter;
        }

        if (!empty($userRow['user_pref_device_id']) && $userRow['user_pref_device_id'] != '0') {
            $filter .= " AND 2st.device_id in (select device_id from 202_device_models where device_type=" . (int) $userRow['user_pref_device_id'] . ")";
        }
        if (!empty($userRow['user_pref_browser_id']) && $userRow['user_pref_browser_id'] != '0') {
            $filter .= " AND 2st.browser_id=" . (int) $userRow['user_pref_browser_id'];
        }
        if (!empty($userRow['user_pref_platform_id']) && $userRow['user_pref_platform_id'] != '0') {
            $filter .= " AND 2st.platform_id=" . (int) $userRow['user_pref_platform_id'];
        }
        if (!empty($userRow['user_pref_country_id']) && $userRow['user_pref_country_id'] != '0') {
            $filter .= " AND 2st.country_id=" . (int) $userRow['user_pref_country_id'];
        }
        if (!empty($userRow['user_pref_region_id']) && $userRow['user_pref_region_id'] != '0') {
            $filter .= " AND 2st.region_id=" . (int) $userRow['user_pref_region_id'];
        }
        if (!empty($userRow['user_pref_isp_id']) && $userRow['user_pref_isp_id'] != '0') {
            $filter .= " AND 2st.isp_id=" . (int) $userRow['user_pref_isp_id'];
        }

        // "No Traffic Source" is stored as 16777215 (the column maximum)
        // because 0 already means "all traffic sources".
        if (($userRow['user_pref_ppc_network_id'] ?? '0') == '16777215') {
            $filter .= ' AND ' . NoTrafficSource::condition('2st.ppc_network_id');
        } elseif (!empty($userRow['user_pref_ppc_network_id']) && $userRow['user_pref_ppc_network_id'] != '0') {
            $filter .= " AND 2st.ppc_network_id=" . (int) $userRow['user_pref_ppc_network_id'];
        }
        if (!empty($userRow['user_pref_ppc_account_id']) && $userRow['user_pref_ppc_account_id'] != '0') {
            $filter .= " AND 2st.ppc_account_id=" . (int) $userRow['user_pref_ppc_account_id'];
        }
        if (!empty($userRow['user_pref_aff_network_id']) && $userRow['user_pref_aff_network_id'] != '0') {
            $filter .= " AND 2st.aff_network_id=" . (int) $userRow['user_pref_aff_network_id'];
        }
        if (!empty($userRow['user_pref_aff_campaign_id']) && $userRow['user_pref_aff_campaign_id'] != '0') {
            $filter .= " AND 2st.aff_campaign_id=" . (int) $userRow['user_pref_aff_campaign_id'];
        }
        if (!empty($userRow['user_pref_text_ad_id']) && $userRow['user_pref_text_ad_id'] != '0') {
            $filter .= " AND 2st.text_ad_id=" . (int) $userRow['user_pref_text_ad_id'];
        }
        if (!empty($userRow['user_pref_landing_page_id']) && $userRow['user_pref_landing_page_id'] != '0') {
            $filter .= " AND 2st.landing_page_id=" . (int) $userRow['user_pref_landing_page_id'];
        }

        if (($userRow['user_pref_method_of_promotion'] ?? '') == 'directlink') {
            $filter .= " AND 2st.landing_page_id = 0";
        } elseif (($userRow['user_pref_method_of_promotion'] ?? '') == 'landingpage') {
            $filter .= " AND 2st.landing_page_id != 0";
        }

        $filter .= TextFilterSql::where($userRow, '2st', $escape);

        if (!empty($userRow['user_pref_limit']) && !$forDownload) {
            $pageSize = (int) $userRow['user_pref_limit'];
            $limit = ' Limit ' . ($offset * $pageSize) . ',' . $pageSize;
        } else {
            $limit = '';
        }

        return [
            'join' => $join,
            'filter' => $filter,
            'limit' => $limit,
        ];
    }

    /**
     * Canonical WHERE fragment for the "show real/filtered/bot/lead clicks"
     * user preference (user_pref_show column in 202_users_pref).
     *
     * Also used standalone by the account-overview reports via
     * DataEngine::getAccountOverviewFilters() in class-dataengine.php.
     *
     * The optional $columnPrefix parameter allows aliased call sites to qualify
     * column names (e.g. pass '2c.' for ReportSummaryForm which queries against
     * the 202_clicks alias). The default empty string keeps all existing
     * single-argument callers byte-identical to before.
     *
     * The scattered inline copies listed below are being converged to call this
     * method:
     *
     *   tracking202/ajax/sort_rotator.php,
     *   tracking202/ajax/account_overview.php,
     *   202-config/ReportSummaryForm.class.php (uses $columnPrefix = '2c.').
     *
     *   EXCEPTION — 202-config/functions-tracking202.php (visitor-log context)
     *   is deliberately left as-is: its 'leads' case adds an extra
     *   `AND click_filtered='0'` guard (real-leads-only semantics) that is
     *   intentionally different from this method and must not be replaced.
     */
    public static function showFilter(string $show, string $columnPrefix = ''): string
    {
        return match ($show) {
            'real' => " AND " . $columnPrefix . "click_filtered='0' ",
            'filtered' => " AND " . $columnPrefix . "click_filtered='1' ",
            'filtered_bot' => " AND " . $columnPrefix . "click_bot='1' ",
            'leads' => " AND " . $columnPrefix . "click_lead!='0' ",
            default => '',
        };
    }

    private function __construct()
    {
    }
}
