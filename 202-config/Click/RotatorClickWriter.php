<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\Report\RollupDirty;

/**
 * Writes a redirector (rotator) click's rows, all in one transaction, for
 * tracking202/redirect/rtr.php.
 *
 * The rotator wrote its seven rows as seven autocommitted statements built
 * with real_escape_string(), so a failure part way left a click with some of
 * its rows and not others. They are prepared and commit or roll back
 * together now.
 *
 * They are REPLACE, as they always were, and that is what a re-click needs:
 * `rtr.php?lpr=` rewrites the click it found rather than recording a new
 * one. 202_clicks and 202_clicks_spy have no key on click_id (they are
 * partitioned by time), so there the REPLACE adds the click a second row at
 * the time of the re-click, which ClickRetention and SubidBatch count on;
 * the tables keyed on click_id have theirs replaced. A re-click marks the
 * click for the attribution rollup (RollupDirty::click()) in the same
 * transaction.
 *
 * The redirect URL is resolved inside the transaction, after the rows are
 * written: its placeholders ([[c1]], [[t202kw]], [[country]]…) are read
 * back from this click's rows (replaceTrackerPlaceholders()), and its site
 * URL id is what 202_clicks_site stores.
 */
final class RotatorClickWriter
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * @param callable(): int $redirectSiteUrlId resolves the redirect URL from
     *        the rows already written and answers its site URL id
     * @throws \Throwable whatever failed, after the transaction is rolled back
     */
    public function write(
        ClickRecord $click,
        int $rotatorId,
        int $ruleId,
        int $ruleRedirectId,
        bool $rewritesClick,
        callable $redirectSiteUrlId
    ): void {
        $write = function () use (
            $click,
            $rotatorId,
            $ruleId,
            $ruleRedirectId,
            $rewritesClick,
            $redirectSiteUrlId
        ): void {
            if ($rewritesClick) {
                RollupDirty::click($this->conn, $click->userId, $click->clickId);
            }

            // 202_clicks.rule_id holds the rule's redirect, as it always has;
            // 202_clicks_rotator holds the rule and its redirect apart.
            $this->replace(
                'REPLACE INTO 202_clicks SET
                    click_id = ?, user_id = ?, aff_campaign_id = ?, landing_page_id = ?,
                    ppc_account_id = ?, click_cpc = ?, click_payout = ?, click_alp = ?,
                    click_filtered = ?, click_bot = ?, click_time = ?, rotator_id = ?, rule_id = ?',
                'iiiiissiiiiii',
                [
                    $click->clickId, $click->userId, $click->affCampaignId, $click->landingPageId,
                    $click->ppcAccountId, $click->clickCpc, $click->clickPayout, $click->clickAlp,
                    $click->clickFiltered, $click->clickBot, $click->clickTime, $rotatorId, $ruleRedirectId,
                ]
            );

            $this->replace(
                'REPLACE INTO 202_clicks_spy SET
                    click_id = ?, user_id = ?, aff_campaign_id = ?, ppc_account_id = ?,
                    click_cpc = ?, click_payout = ?, click_filtered = ?, click_bot = ?,
                    click_alp = ?, click_time = ?',
                'iiiissiiii',
                [
                    $click->clickId, $click->userId, $click->affCampaignId, $click->ppcAccountId,
                    $click->clickCpc, $click->clickPayout, $click->clickFiltered, $click->clickBot,
                    $click->clickAlp, $click->clickTime,
                ]
            );

            $this->replace(
                'REPLACE INTO 202_clicks_advance SET
                    click_id = ?, text_ad_id = ?, keyword_id = ?, ip_id = ?, country_id = ?,
                    region_id = ?, isp_id = ?, city_id = ?, platform_id = ?, browser_id = ?, device_id = ?',
                'iiiiiiiiiii',
                [
                    $click->clickId, $click->textAdId, $click->keywordId, $click->ipId, $click->countryId,
                    $click->regionId, $click->ispId, $click->cityId, $click->platformId, $click->browserId,
                    $click->deviceId,
                ]
            );

            $this->replace(
                'REPLACE INTO 202_clicks_tracking SET click_id = ?, c1_id = ?, c2_id = ?, c3_id = ?, c4_id = ?',
                'iiiii',
                [$click->clickId, $click->c1Id, $click->c2Id, $click->c3Id, $click->c4Id]
            );

            $this->replace(
                'REPLACE INTO 202_clicks_rotator SET click_id = ?, rotator_id = ?, rule_id = ?, rule_redirect_id = ?',
                'iiii',
                [$click->clickId, $rotatorId, $ruleId, $ruleRedirectId]
            );

            // An uncloaked click has no public id: 0, as the '' it was
            // written as became under the app's empty sql_mode.
            $this->replace(
                'REPLACE INTO 202_clicks_record SET
                    click_id = ?, click_id_public = ?, click_cloaking = ?, click_in = ?, click_out = ?',
                'iiiii',
                [
                    $click->clickId, (int) $click->clickIdPublic, $click->clickCloaking,
                    $click->clickIn, $click->clickOut,
                ]
            );

            $redirectId = $redirectSiteUrlId();

            $this->replace(
                'REPLACE INTO 202_clicks_site SET
                    click_id = ?, click_referer_site_url_id = ?, click_outbound_site_url_id = ?,
                    click_redirect_site_url_id = ?',
                'iiii',
                [$click->clickId, $click->clickRefererSiteUrlId, $click->clickOutboundSiteUrlId, $redirectId]
            );
        };

        $this->conn->transaction($write);
    }

    /** @param list<int|string> $values */
    private function replace(string $sql, string $types, array $values): void
    {
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $values);
        $this->conn->executeUpdate($stmt);
    }
}
