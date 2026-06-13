<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Builds the WHERE/JOIN/LIMIT SQL fragments derived from a user's report
 * preferences (202_users_pref row). Pure string assembly — the caller
 * resolves anything that needs a database lookup (IP and referer ids) and
 * passes the result in, which keeps this fully unit-testable. The caller
 * is also responsible for escaping the free-text keyword preference;
 * numeric preferences are integer-cast here as defense in depth.
 *
 * Faithful to the legacy DataEngine::getFilters(), including the quirk that
 * a "show" preference of real/filtered/filtered_bot/leads *replaces* any
 * subid filter accumulated before it.
 */
final class UserPrefFilters
{
    /**
     * @param array<string, mixed> $userRow      Row from 202_users_pref.
     * @param int                  $offset       Page offset (from the request).
     * @param bool                 $forDownload  Downloads are not paginated.
     * @param ?string              $ipIdList     Resolved ip_id list when the
     *                                           user filters by IP; null when
     *                                           the lookup found nothing.
     * @param ?string              $refererIdList Resolved site_url_id list when
     *                                           the user filters by referer.
     *
     * @return array{join: string, filter: string, limit: string}
     */
    public static function build(
        array $userRow,
        int $offset,
        bool $forDownload,
        ?string $ipIdList = null,
        ?string $refererIdList = null,
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
            $filter .= " AND 2st.ppc_network_id IS NULL";
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

        if (!empty($userRow['user_pref_keyword'])) {
            $filter .= " AND 2k.keyword like '%" . $userRow['user_pref_keyword'] . "%'";
            $join = ' LEFT OUTER JOIN 202_keywords AS 2k ON (2k.keyword_id=2st.keyword_id) ';
        }

        if (!empty($userRow['user_pref_ip'])) {
            if (($ipIdList ?? '') !== '') {
                $filter .= " AND 2st.ip_id=" . $ipIdList;
            } else {
                // Filter matched nothing: force an empty result set.
                $filter .= " AND 2st.ip_id=''";
            }
        }

        if (!empty($userRow['user_pref_referer'])) {
            if (($refererIdList ?? '') !== '') {
                $filter .= " AND 2st.click_referer_site_url_id in (" . $refererIdList . ")";
            } else {
                $filter .= " AND 2st.click_referer_site_url_id=''";
            }
        }

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
     * DIVERGENT COPIES — a follow-up should converge these to call showFilter():
     *
     *   202-config/functions-tracking202.php ~line 1345 (visitor-log context):
     *     real:          " AND click_filtered='0' "     [matches]
     *     filtered:      " AND click_filtered='1' "     [matches]
     *     filtered_bot:  " AND click_bot='1'"           [matches, trailing space differs]
     *     leads:         " AND click_filtered='0' AND click_lead='1' "
     *                    *** DIVERGES — adds click_filtered='0' guard ***
     *
     *   202-Mobile/mini-stats/202-ministats.php ~line 18,
     *   tracking202/ajax/sort_browsers.php ~line 26,
     *   tracking202/ajax/sort_cities.php ~line 26,
     *   tracking202/ajax/sort_isp.php ~line 26,
     *   tracking202/ajax/sort_ips.php ~line 26,
     *   tracking202/ajax/sort_referers.php ~line 26,
     *   tracking202/ajax/sort_rotator.php ~line 25,
     *   tracking202/ajax/account_overview.php ~line 48,
     *   tracking202/ajax/sort_landing_pages.php ~line 26:
     *     real:          " AND click_filtered='0' "     [matches]
     *     filtered:      " AND click_filtered='1' "     [matches]
     *     filtered_bot:  " AND click_bot='1' "          [matches]
     *     leads:         " AND click_lead='1' "
     *                    (functionally equivalent to click_lead!='0' on tinyint(1))
     *
     *   202-config/ReportSummaryForm.class.php ~line 928:
     *     real:          AND 2c.click_filtered=0        [unquoted, different alias]
     *     filtered:      AND 2c.click_filtered=1        [unquoted, different alias]
     *     filtered_bot:  AND 2c.click_bot=1             [unquoted, different alias]
     *     leads:         AND 2c.click_lead!=0           [unquoted; same logic as here]
     */
    public static function showFilter(string $show): string
    {
        return match ($show) {
            'real' => " AND click_filtered='0' ",
            'filtered' => " AND click_filtered='1' ",
            'filtered_bot' => " AND click_bot='1' ",
            'leads' => " AND click_lead!='0' ",
            default => '',
        };
    }

    private function __construct()
    {
    }
}
