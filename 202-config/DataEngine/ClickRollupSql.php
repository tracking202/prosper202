<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Single source of truth for the click-rollup statement that copies a click
 * (and all of its dimension lookups) from the normalized click tables into
 * the denormalized 202_dataengine reporting table.
 *
 * Historically this ~120 line INSERT ... SELECT was copy-pasted in three
 * places (DataEngine::setDirtyHour, DataEngine::getSummary and the slim
 * engine used by the tracking hot path) and the copies had drifted apart:
 * one copy swapped the utm_source_id/utm_medium_id insert columns against
 * the SELECT list, silently writing each value into the other's column.
 * Generating the statement from one column map makes that class of bug
 * impossible.
 */
final class ClickRollupSql
{
    /**
     * Insert column => SELECT expression. Order is significant: the INSERT
     * column list and the SELECT list are generated from the same map, so
     * they can never drift out of alignment.
     */
    private const COLUMNS = [
        'user_id' => '2c.user_id',
        'click_id' => '2c.click_id',
        'click_time' => '2c.click_time',
        'ppc_network_id' => '2pn.ppc_network_id',
        'ppc_account_id' => '2c.ppc_account_id',
        'aff_network_id' => '2an.aff_network_id',
        'aff_campaign_id' => '2ac.aff_campaign_id',
        'landing_page_id' => '2c.landing_page_id',
        'keyword_id' => '2k.keyword_id',
        'utm_source_id' => '2gg.utm_source_id',
        'utm_medium_id' => '2gg.utm_medium_id',
        'utm_campaign_id' => '2gg.utm_campaign_id',
        'utm_term_id' => '2gg.utm_term_id',
        'utm_content_id' => '2gg.utm_content_id',
        'text_ad_id' => '2ta.text_ad_id',
        'click_referer_site_url_id' => '2cs.click_referer_site_url_id',
        'country_id' => '2cy.country_id',
        'region_id' => '2rg.region_id',
        'city_id' => '2ci.city_id',
        'isp_id' => '2is.isp_id',
        'browser_id' => '2b.browser_id',
        'device_id' => '2dm.device_id',
        'platform_id' => '2p.platform_id',
        'ip_id' => '2ca.ip_id',
        'c1_id' => '2tc1.c1_id',
        'c2_id' => '2tc2.c2_id',
        'c3_id' => '2tc3.c3_id',
        'c4_id' => '2tc4.c4_id',
        'variable_set_id' => '2cv.variable_set_id',
        'rotator_id' => '2rc.rotator_id',
        'rule_id' => '2rc.rule_id',
        'rule_redirect_id' => '2rc.rule_redirect_id',
        'click_lead' => '2c.`click_lead`',
        'click_filtered' => '2c.`click_filtered`',
        'click_bot' => '2c.`click_bot`',
        'click_alp' => '2c.`click_alp`',
        'clicks' => '1 AS clicks',
        'click_out' => '2cr.click_out AS click_out',
        'leads' => '2c.click_lead AS leads',
        'payout' => '2c.click_payout AS payout',
        'income' => 'IF (2c.click_lead>0,2c.click_payout,0) AS income',
        'cost' => '2c.click_cpc AS cost',
    ];

    private const JOINS = <<<'SQL'
FROM 202_clicks AS 2c
LEFT OUTER JOIN 202_clicks_record AS 2cr ON (2c.click_id = 2cr.click_id)
LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
LEFT OUTER JOIN 202_clicks_advance AS 2ca ON (2c.click_id = 2ca.click_id)
LEFT OUTER JOIN 202_browsers AS 2b ON (2ca.browser_id = 2b.browser_id)
LEFT OUTER JOIN 202_platforms AS 2p ON (2ca.platform_id = 2p.platform_id)
LEFT OUTER JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id)
LEFT OUTER JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id)
LEFT OUTER JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id)
LEFT OUTER JOIN 202_keywords AS 2k ON (2ca.keyword_id = 2k.keyword_id)
LEFT OUTER JOIN 202_google AS 2gg ON (2c.click_id = 2gg.click_id)
LEFT OUTER JOIN 202_landing_pages AS 2lp ON (2c.landing_page_id = 2lp.landing_page_id)
LEFT OUTER JOIN 202_text_ads AS 2ta ON (2ca.text_ad_id = 2ta.text_ad_id)
LEFT OUTER JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id)
LEFT OUTER JOIN 202_clicks_tracking AS 2ct ON (2c.click_id = 2ct.click_id)
LEFT OUTER JOIN 202_site_urls AS 2suf ON (2cs.click_referer_site_url_id = 2suf.site_url_id)
LEFT OUTER JOIN 202_locations_country AS 2cy ON (2ca.country_id = 2cy.country_id)
LEFT OUTER JOIN 202_locations_region AS 2rg ON (2ca.region_id = 2rg.region_id)
LEFT OUTER JOIN 202_locations_city AS 2ci ON (2ca.city_id = 2ci.city_id)
LEFT OUTER JOIN 202_locations_isp AS 2is ON (2ca.isp_id = 2is.isp_id)
LEFT OUTER JOIN 202_device_models AS 2dm ON (2ca.device_id = 2dm.device_id)
LEFT OUTER JOIN 202_ips AS 2i ON (2ca.ip_id = 2i.ip_id)
LEFT OUTER JOIN 202_tracking_c1 AS 2tc1 ON (2ct.c1_id = 2tc1.c1_id)
LEFT OUTER JOIN 202_tracking_c2 AS 2tc2 ON (2ct.c2_id = 2tc2.c2_id)
LEFT OUTER JOIN 202_tracking_c3 AS 2tc3 ON (2ct.c3_id = 2tc3.c3_id)
LEFT OUTER JOIN 202_tracking_c4 AS 2tc4 ON (2ct.c4_id = 2tc4.c4_id)
LEFT OUTER JOIN 202_clicks_variable AS 2cv ON (2c.click_id = 2cv.click_id)
LEFT OUTER JOIN 202_clicks_rotator AS 2rc ON (2c.click_id = 2rc.click_id)
SQL;

    /**
     * Columns refreshed when the click already exists in the rollup table
     * (e.g. a conversion postback arrives after the click was recorded).
     */
    private const UPDATED_ON_DUPLICATE = [
        'click_lead',
        'click_bot',
        'click_out',
        'click_filtered',
        'landing_page_id',
        'leads',
        'payout',
        'income',
        'cost',
        'rotator_id',
        'rule_id',
        'rule_redirect_id',
        'aff_campaign_id',
        'aff_network_id',
    ];

    /**
     * Build the full INSERT ... SELECT ... ON DUPLICATE KEY UPDATE statement.
     *
     * @param string $table       Target table (202_dataengine or 202_dataengine_new).
     * @param string $whereClause SQL condition on the 202_clicks side, already
     *                            escaped by the caller (e.g. "2c.click_id=123").
     * @param bool   $updateLandingPageId Whether ON DUPLICATE KEY UPDATE also
     *                            refreshes landing_page_id. The tracking hot
     *                            path does; the batch re-aggregation paths
     *                            historically did not, and that difference is
     *                            preserved until verified safe to unify.
     */
    public static function insertSelect(string $table, string $whereClause, bool $updateLandingPageId = false): string
    {
        $updateColumns = self::UPDATED_ON_DUPLICATE;
        if (!$updateLandingPageId) {
            $updateColumns = array_values(array_diff($updateColumns, ['landing_page_id']));
        }

        $updates = implode(",\n", array_map(
            static fn(string $column): string => $column . '=values(' . $column . ')',
            $updateColumns
        ));

        return 'insert into ' . $table . '(' . implode(",\n", array_keys(self::COLUMNS)) . ")\n"
            . "SELECT\n" . implode(",\n", array_values(self::COLUMNS)) . "\n"
            . self::JOINS . "\n"
            . 'WHERE ' . $whereClause . "\n"
            . "on duplicate key update\n" . $updates;
    }
}
