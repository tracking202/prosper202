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

    /**
     * Every account-owned lookup is joined within the click's own account
     * (`x.user_id = 2c.user_id`): a click names its campaign, traffic source
     * account, landing page and text ad by id, and nothing stopped a tracker
     * naming another account's before 229df10. Such a click is still rolled
     * up and counted; the campaign, category, traffic source and text ad it
     * names read as none (NULL), as one that no longer exists always did
     * (CLAUDE.md #27).
     * landing_page_id and ppc_account_id are the click's own ids, which the
     * readers join within the account themselves.
     */
    private const JOINS = <<<'SQL'
FROM 202_clicks AS 2c
LEFT OUTER JOIN 202_clicks_record AS 2cr ON (2c.click_id = 2cr.click_id)
LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id AND 2ac.user_id = 2c.user_id)
LEFT OUTER JOIN 202_clicks_advance AS 2ca ON (2c.click_id = 2ca.click_id)
LEFT OUTER JOIN 202_browsers AS 2b ON (2ca.browser_id = 2b.browser_id)
LEFT OUTER JOIN 202_platforms AS 2p ON (2ca.platform_id = 2p.platform_id)
LEFT OUTER JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id AND 2an.user_id = 2c.user_id)
LEFT OUTER JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id AND 2pa.user_id = 2c.user_id)
LEFT OUTER JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id AND 2pn.user_id = 2c.user_id)
LEFT OUTER JOIN 202_keywords AS 2k ON (2ca.keyword_id = 2k.keyword_id)
LEFT OUTER JOIN 202_google AS 2gg ON (2c.click_id = 2gg.click_id)
LEFT OUTER JOIN 202_landing_pages AS 2lp ON (2c.landing_page_id = 2lp.landing_page_id AND 2lp.user_id = 2c.user_id)
LEFT OUTER JOIN 202_text_ads AS 2ta ON (2ca.text_ad_id = 2ta.text_ad_id AND 2ta.user_id = 2c.user_id)
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
     * The rollup row's key (202_dataengine's PRIMARY KEY): one row per
     * 202_clicks row, a rotator re-click being a second row of its click.
     */
    private const KEY = ['click_id', 'click_time'];

    /**
     * Build the full INSERT ... SELECT ... ON DUPLICATE KEY UPDATE statement.
     *
     * A row that is already there takes every column the SELECT derives, so
     * re-rolling a click converges its row to what a fresh rollup of it
     * computes, whichever path re-rolls it (the redirects and pixels, a
     * conversion, the dirty-hours queue, the hourly job). The update list
     * named fourteen of the forty-two columns, so the rest kept whatever the
     * row's first rollup saw: a traffic source account moved to another
     * traffic source left its clicks' ppc_network_id on the old one for good
     * (measured live: after the move and a re-run of the hour, the API's
     * breakdown by traffic source put six of seven clicks under the old
     * source while the Overview, which looks the account's source up,
     * showed all seven under the new one), and a row rolled up before the
     * joins were tied to the click's account (CLAUDE.md #27) kept another
     * account's ppc_network_id and text_ad_id, which the "[No traffic
     * source]" filter (`ppc_network_id IS NULL`) and the text-ad groups then
     * told apart from the NULL a fresh rollup writes. landing_page_id was
     * refreshed on the tracking path only, a difference kept "until verified
     * safe to unify": it is the 202_clicks row's own column, which nothing
     * rewrites, so every path refreshes it now.
     *
     * A row's dimensions are its click's: a re-click rewrites the click's
     * one 202_clicks_advance, _tracking, _site and _variable rows, and a
     * re-roll of the click's earlier row reads them as a fresh rollup of it
     * always did.
     *
     * @param string $table       Target table (202_dataengine or 202_dataengine_new).
     * @param string $whereClause SQL condition on the 202_clicks side, already
     *                            escaped by the caller (e.g. "2c.click_id=123").
     */
    public static function insertSelect(string $table, string $whereClause): string
    {
        $updates = implode(",\n", array_map(
            static fn(string $column): string => $column . '=values(' . $column . ')',
            self::refreshedColumns()
        ));

        return 'insert into ' . $table . '(' . implode(",\n", array_keys(self::COLUMNS)) . ")\n"
            . "SELECT\n" . implode(",\n", array_values(self::COLUMNS)) . "\n"
            . self::JOINS . "\n"
            . 'WHERE ' . $whereClause . "\n"
            . "on duplicate key update\n" . $updates;
    }

    /**
     * Every column a duplicate key refreshes: all the SELECT derives but the key.
     *
     * @return list<string>
     */
    public static function refreshedColumns(): array
    {
        return array_values(array_diff(array_keys(self::COLUMNS), self::KEY));
    }
}
