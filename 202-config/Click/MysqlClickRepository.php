<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

final class MysqlClickRepository implements ClickRepositoryInterface
{
    public function __construct(private Connection $conn)
    {
    }

    public function recordClick(ClickRecord $click): int
    {
        return $this->conn->transaction(function () use ($click): int {
            // 1. Generate click_id via counter table
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_clicks_counter SET click_id = DEFAULT'
            );
            $clickId = $this->conn->executeInsert($stmt);
            $stmt->close();

            // 2. 202_clicks — core click data
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_clicks SET
                    click_id = ?, user_id = ?, aff_campaign_id = ?,
                    landing_page_id = ?, ppc_account_id = ?,
                    click_cpc = ?, click_payout = ?,
                    click_filtered = ?, click_bot = ?,
                    click_alp = ?, click_time = ?'
            );
            $this->conn->bind($stmt, 'iiiiissiiis', [
                $clickId, $click->userId, $click->affCampaignId,
                $click->landingPageId, $click->ppcAccountId,
                $click->clickCpc, $click->clickPayout,
                $click->clickFiltered, $click->clickBot,
                $click->clickAlp, $click->clickTime,
            ]);
            $this->conn->execute($stmt);
            $stmt->close();

            // 3. 202_clicks_variable
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_clicks_variable (click_id, variable_set_id) VALUES (?, ?)'
            );
            $this->conn->bind($stmt, 'ii', [$clickId, $click->variableSetId]);
            $this->conn->execute($stmt);
            $stmt->close();

            // 4. 202_google — UTM and gclid data
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_google SET
                    click_id = ?, gclid = ?,
                    utm_source_id = ?, utm_medium_id = ?,
                    utm_campaign_id = ?, utm_term_id = ?,
                    utm_content_id = ?'
            );
            $this->conn->bind($stmt, 'isiiiii', [
                $clickId, $click->gclid,
                $click->utmSourceId, $click->utmMediumId,
                $click->utmCampaignId, $click->utmTermId,
                $click->utmContentId,
            ]);
            $this->conn->execute($stmt);
            $stmt->close();

            // 5. 202_clicks_spy — denormalized copy of core click data
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_clicks_spy SET
                    click_id = ?, user_id = ?, aff_campaign_id = ?,
                    landing_page_id = ?, ppc_account_id = ?,
                    click_cpc = ?, click_payout = ?,
                    click_filtered = ?, click_bot = ?,
                    click_alp = ?, click_time = ?'
            );
            $this->conn->bind($stmt, 'iiiiissiiis', [
                $clickId, $click->userId, $click->affCampaignId,
                $click->landingPageId, $click->ppcAccountId,
                $click->clickCpc, $click->clickPayout,
                $click->clickFiltered, $click->clickBot,
                $click->clickAlp, $click->clickTime,
            ]);
            $this->conn->execute($stmt);
            $stmt->close();

            // 6. 202_clicks_advance — geo/device/keyword data
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_clicks_advance SET
                    click_id = ?, text_ad_id = ?, keyword_id = ?,
                    ip_id = ?, country_id = ?, region_id = ?,
                    isp_id = ?, city_id = ?,
                    platform_id = ?, browser_id = ?, device_id = ?'
            );
            $this->conn->bind($stmt, 'iiiiiiiiiii', [
                $clickId, $click->textAdId, $click->keywordId,
                $click->ipId, $click->countryId, $click->regionId,
                $click->ispId, $click->cityId,
                $click->platformId, $click->browserId, $click->deviceId,
            ]);
            $this->conn->execute($stmt);
            $stmt->close();

            // 7. 202_clicks_tracking — c1-c4 tracking params
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_clicks_tracking SET
                    click_id = ?, c1_id = ?, c2_id = ?, c3_id = ?, c4_id = ?'
            );
            $this->conn->bind($stmt, 'iiiii', [
                $clickId, $click->c1Id, $click->c2Id, $click->c3Id, $click->c4Id,
            ]);
            $this->conn->execute($stmt);
            $stmt->close();

            // 8. 202_clicks_record — public ID and cloaking state
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_clicks_record SET
                    click_id = ?, click_id_public = ?,
                    click_cloaking = ?, click_in = ?, click_out = ?'
            );
            $this->conn->bind($stmt, 'isiii', [
                $clickId, $click->clickIdPublic,
                $click->clickCloaking, $click->clickIn, $click->clickOut,
            ]);
            $this->conn->execute($stmt);
            $stmt->close();

            // 9. 202_clicks_site — URL references
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_clicks_site SET
                    click_id = ?,
                    click_referer_site_url_id = ?,
                    click_landing_site_url_id = ?,
                    click_outbound_site_url_id = ?,
                    click_cloaking_site_url_id = ?,
                    click_redirect_site_url_id = ?'
            );
            $this->conn->bind($stmt, 'iiiiii', [
                $clickId,
                $click->clickRefererSiteUrlId,
                $click->clickLandingSiteUrlId,
                $click->clickOutboundSiteUrlId,
                $click->clickCloakingSiteUrlId,
                $click->clickRedirectSiteUrlId,
            ]);
            $this->conn->execute($stmt);
            $stmt->close();

            return $clickId;
        });
    }
}
