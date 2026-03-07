<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * Builds a ClickRecord from the legacy $mysql associative array
 * used by record_simple.php, record_adv.php, and dl.php.
 *
 * This bridges the legacy hot path code with the repository layer,
 * allowing incremental migration without rewriting the entire file.
 */
final class ClickRecordBuilder
{
    /**
     * @param array<string, string|int> $mysql The legacy $mysql array with all resolved IDs
     */
    public static function fromLegacyArray(array $mysql): ClickRecord
    {
        $click = new ClickRecord();

        // Core (202_clicks)
        $click->userId = (int) ($mysql['user_id'] ?? 0);
        $click->affCampaignId = (int) ($mysql['aff_campaign_id'] ?? 0);
        $click->landingPageId = (int) ($mysql['landing_page_id'] ?? 0);
        $click->ppcAccountId = (int) ($mysql['ppc_account_id'] ?? 0);
        $click->clickCpc = (string) ($mysql['click_cpc'] ?? '0');
        $click->clickPayout = (string) ($mysql['click_payout'] ?? '0');
        $click->clickFiltered = (int) ($mysql['click_filtered'] ?? 0);
        $click->clickBot = (int) ($mysql['click_bot'] ?? 0);
        $click->clickAlp = (int) ($mysql['click_alp'] ?? 0);
        $click->clickTime = (int) ($mysql['click_time'] ?? 0);

        // Advance (202_clicks_advance)
        $click->textAdId = (int) ($mysql['text_ad_id'] ?? 0);
        $click->keywordId = (int) ($mysql['keyword_id'] ?? 0);
        $click->ipId = (int) ($mysql['ip_id'] ?? 0);
        $click->countryId = (int) ($mysql['country_id'] ?? 0);
        $click->regionId = (int) ($mysql['region_id'] ?? 0);
        $click->ispId = (int) ($mysql['isp_id'] ?? 0);
        $click->cityId = (int) ($mysql['city_id'] ?? 0);
        $click->platformId = (int) ($mysql['platform_id'] ?? 0);
        $click->browserId = (int) ($mysql['browser_id'] ?? 0);
        $click->deviceId = (int) ($mysql['device_id'] ?? 0);

        // Tracking (202_clicks_tracking)
        $click->c1Id = (int) ($mysql['c1_id'] ?? 0);
        $click->c2Id = (int) ($mysql['c2_id'] ?? 0);
        $click->c3Id = (int) ($mysql['c3_id'] ?? 0);
        $click->c4Id = (int) ($mysql['c4_id'] ?? 0);

        // Variable (202_clicks_variable)
        $click->variableSetId = (int) ($mysql['variable_set_id'] ?? 0);

        // Google/UTM (202_google)
        $click->gclid = (string) ($mysql['gclid'] ?? '');
        $click->utmSourceId = (int) ($mysql['utm_source_id'] ?? 0);
        $click->utmMediumId = (int) ($mysql['utm_medium_id'] ?? 0);
        $click->utmCampaignId = (int) ($mysql['utm_campaign_id'] ?? 0);
        $click->utmTermId = (int) ($mysql['utm_term_id'] ?? 0);
        $click->utmContentId = (int) ($mysql['utm_content_id'] ?? 0);

        // Record (202_clicks_record)
        $click->clickIdPublic = (string) ($mysql['click_id_public'] ?? '');
        $click->clickCloaking = (int) ($mysql['click_cloaking'] ?? 0);
        $click->clickIn = (int) ($mysql['click_in'] ?? 1);
        $click->clickOut = (int) ($mysql['click_out'] ?? 0);

        // Site (202_clicks_site)
        $click->clickRefererSiteUrlId = (int) ($mysql['click_referer_site_url_id'] ?? 0);
        $click->clickLandingSiteUrlId = (int) ($mysql['click_landing_site_url_id'] ?? 0);
        $click->clickOutboundSiteUrlId = (int) ($mysql['click_outbound_site_url_id'] ?? 0);
        $click->clickCloakingSiteUrlId = (int) ($mysql['click_cloaking_site_url_id'] ?? 0);
        $click->clickRedirectSiteUrlId = (int) ($mysql['click_redirect_site_url_id'] ?? 0);

        return $click;
    }
}
