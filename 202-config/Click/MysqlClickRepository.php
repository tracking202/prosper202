<?php

declare(strict_types=1);

namespace Prosper202\Click;

use LogicException;
use Prosper202\Database\Connection;
use Prosper202\Report\RollupDirty;

/**
 * The one writer of a click's rows, for every path that records one.
 *
 * 202_clicks and 202_clicks_spy have no key on click_id (they are
 * partitioned by time), so a re-click gives the click a second row there at
 * the time of the re-click, which ClickRetention, SubidBatch and
 * LastClickFromAddress count on. The tables keyed on click_id are upserted
 * with only the columns the redirector sets: the REPLACE this replaced
 * deleted the row first, so a re-click reset the click's customer, its
 * reviewed flag, its public id (and with it every cl.php and pci.php link
 * already handed out) and its landing and cloaking URLs.
 *
 * A redirect URL resolved inside the transaction (rtr.php) reads the click's
 * rows back for its placeholders, so every row but 202_clicks_site is
 * written before it runs. rtr.php looks it up uncached (it is the click's
 * own) and runs the write inside p202WithHeldCacheWrites(), so a cached
 * lookup made there later cannot leave the cache naming a row a rollback
 * took back.
 */
final class MysqlClickRepository implements ClickRepositoryInterface
{
    public function __construct(private Connection $conn)
    {
    }

    #[\Override]
    public function allocateClickId(): int
    {
        $stmt = $this->conn->prepareWrite('INSERT INTO 202_clicks_counter SET click_id = DEFAULT');
        $clickId = $this->conn->executeInsert($stmt);
        RecordedClicks::note($clickId);

        return $clickId;
    }

    #[\Override]
    public function recordClick(ClickRecord $click, ?\Closure $redirectSiteUrlId = null): void
    {
        if ($redirectSiteUrlId !== null && $click->clickRedirectSiteUrlId !== 0) {
            throw new LogicException('A click carries its redirect URL id or has it resolved, not both');
        }
        $this->conn->transaction(function () use ($click, $redirectSiteUrlId): void {
            $this->writeClickRows($click);
            $this->write(
                'INSERT INTO 202_clicks_advance (click_id, text_ad_id, keyword_id, ip_id, country_id, region_id,'
                    . ' isp_id, city_id, platform_id, browser_id, device_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'iiiiiiiiiii',
                self::advanceValues($click)
            );
            $this->write(
                'INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES (?, ?, ?, ?, ?)',
                'iiiii',
                [$click->clickId, $click->c1Id, $click->c2Id, $click->c3Id, $click->c4Id]
            );
            if ($click->recordsCampaignRows) {
                $this->write(
                    'INSERT INTO 202_clicks_variable (click_id, variable_set_id) VALUES (?, ?)',
                    'ii',
                    [$click->clickId, $click->variableSetId]
                );
                $this->write(
                    'INSERT INTO 202_google (click_id, gclid, utm_source_id, utm_medium_id, utm_campaign_id,'
                        . ' utm_term_id, utm_content_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    'isiiiii',
                    [
                        $click->clickId, $click->gclid, $click->utmSourceId, $click->utmMediumId,
                        $click->utmCampaignId, $click->utmTermId, $click->utmContentId,
                    ]
                );
            }
            if ($click->rotator !== null) {
                $this->write(
                    'INSERT INTO 202_clicks_rotator (click_id, rotator_id, rule_id, rule_redirect_id)'
                        . ' VALUES (?, ?, ?, ?)',
                    'iiii',
                    self::rotatorValues($click, $click->rotator)
                );
            }
            $this->write(
                'INSERT INTO 202_clicks_record (click_id, click_id_public, click_cloaking, click_in, click_out)'
                    . ' VALUES (?, ?, ?, ?, ?)',
                'isiii',
                self::recordValues($click)
            );
            $redirectId = $redirectSiteUrlId !== null ? $redirectSiteUrlId() : $click->clickRedirectSiteUrlId;
            $this->write(
                'INSERT INTO 202_clicks_site (click_id, click_referer_site_url_id, click_landing_site_url_id,'
                    . ' click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id)'
                    . ' VALUES (?, ?, ?, ?, ?, ?)',
                'iiiiii',
                [
                    $click->clickId, $click->clickRefererSiteUrlId, $click->clickLandingSiteUrlId,
                    $click->clickOutboundSiteUrlId, $click->clickCloakingSiteUrlId, $redirectId,
                ]
            );
        });
        $this->link($click);
    }

    #[\Override]
    public function recordReClick(ClickRecord $click, \Closure $redirectSiteUrlId): void
    {
        $route = $click->rotator;
        if ($route === null || $click->recordsCampaignRows) {
            throw new LogicException('Only the redirector re-clicks a click');
        }
        if ($click->clickRedirectSiteUrlId !== 0) {
            throw new LogicException('A click carries its redirect URL id or has it resolved, not both');
        }
        $this->conn->transaction(function () use ($click, $route, $redirectSiteUrlId): void {
            // The rollup has summed the click into its hours and those of
            // every conversion whose journey holds it; the mark sends them
            // back to exact (AttributionRollup rule 2).
            RollupDirty::click($this->conn, $click->userId, $click->clickId);
            $this->writeClickRows($click);
            // The redirector records no text ad: the click's is kept unless the re-click brings one.
            $this->write(
                'INSERT INTO 202_clicks_advance (click_id, text_ad_id, keyword_id, ip_id, country_id, region_id,'
                    . ' isp_id, city_id, platform_id, browser_id, device_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE'
                    . ' text_ad_id = IF(VALUES(text_ad_id) = 0, text_ad_id, VALUES(text_ad_id)),'
                    . ' keyword_id = VALUES(keyword_id),'
                    . ' ip_id = VALUES(ip_id), country_id = VALUES(country_id), region_id = VALUES(region_id),'
                    . ' isp_id = VALUES(isp_id), city_id = VALUES(city_id), platform_id = VALUES(platform_id),'
                    . ' browser_id = VALUES(browser_id), device_id = VALUES(device_id)',
                'iiiiiiiiiii',
                self::advanceValues($click)
            );
            // customer_id is the click's, set by a conversion: kept.
            $this->write(
                'INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id) VALUES (?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE c1_id = VALUES(c1_id), c2_id = VALUES(c2_id),'
                    . ' c3_id = VALUES(c3_id), c4_id = VALUES(c4_id)',
                'iiiii',
                [$click->clickId, $click->c1Id, $click->c2Id, $click->c3Id, $click->c4Id]
            );
            $this->write(
                'INSERT INTO 202_clicks_rotator (click_id, rotator_id, rule_id, rule_redirect_id) VALUES (?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE rotator_id = VALUES(rotator_id), rule_id = VALUES(rule_id),'
                    . ' rule_redirect_id = VALUES(rule_redirect_id)',
                'iiii',
                self::rotatorValues($click, $route)
            );
            // The public id is kept unless the re-click brings one (rtr.php
            // reuses the click's own when it has one), and click_reviewed
            // always.
            $this->write(
                'INSERT INTO 202_clicks_record (click_id, click_id_public, click_cloaking, click_in, click_out)'
                    . ' VALUES (?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE'
                    . ' click_id_public = IF(VALUES(click_id_public) = 0, click_id_public, VALUES(click_id_public)),'
                    . ' click_cloaking = VALUES(click_cloaking), click_in = VALUES(click_in),'
                    . ' click_out = VALUES(click_out)',
                'isiii',
                self::recordValues($click)
            );
            $redirectId = $redirectSiteUrlId();
            // The landing URL is the page the click was made on: kept, as is
            // the cloaking URL unless the re-click brings one.
            $this->write(
                'INSERT INTO 202_clicks_site (click_id, click_referer_site_url_id, click_landing_site_url_id,'
                    . ' click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id)'
                    . ' VALUES (?, ?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE click_referer_site_url_id = VALUES(click_referer_site_url_id),'
                    . ' click_outbound_site_url_id = VALUES(click_outbound_site_url_id),'
                    . ' click_cloaking_site_url_id = IF(VALUES(click_cloaking_site_url_id) = 0,'
                    . ' click_cloaking_site_url_id, VALUES(click_cloaking_site_url_id)),'
                    . ' click_redirect_site_url_id = VALUES(click_redirect_site_url_id)',
                'iiiiii',
                [
                    $click->clickId, $click->clickRefererSiteUrlId, $click->clickLandingSiteUrlId,
                    $click->clickOutboundSiteUrlId, $click->clickCloakingSiteUrlId, $redirectId,
                ]
            );
        });
        $this->link($click);
    }

    /** 202_clicks (with the route, 0 off the redirector) and its 202_clicks_spy copy. */
    private function writeClickRows(ClickRecord $click): void
    {
        $this->write(
            'INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id,'
                . ' click_cpc, click_payout, click_filtered, click_bot, click_alp, click_time, rotator_id, rule_id)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iiiiissiiiiii',
            [
                $click->clickId, $click->userId, $click->affCampaignId, $click->landingPageId,
                $click->ppcAccountId, $click->clickCpc, $click->clickPayout, $click->clickFiltered,
                $click->clickBot, $click->clickAlp, $click->clickTime,
                // rule_id holds the rule's redirect, as it always has.
                $click->rotator->rotatorId ?? 0, $click->rotator->ruleRedirectId ?? 0,
            ]
        );
        $this->write(
            'INSERT INTO 202_clicks_spy (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id,'
                . ' click_cpc, click_payout, click_filtered, click_bot, click_alp, click_time)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iiiiissiiii',
            [
                $click->clickId, $click->userId, $click->affCampaignId, $click->landingPageId,
                $click->ppcAccountId, $click->clickCpc, $click->clickPayout, $click->clickFiltered,
                $click->clickBot, $click->clickAlp, $click->clickTime,
            ]
        );
    }

    /**
     * Linking runs in its own transaction once the click is committed: a
     * failure there is logged by ClickIdentity and never costs the click.
     */
    private function link(ClickRecord $click): void
    {
        $click->identity?->attach($this->conn, $click->userId, $click->clickId, $click->clickTime);
    }

    /** @return list<int> */
    private static function advanceValues(ClickRecord $click): array
    {
        return [
            $click->clickId, $click->textAdId, $click->keywordId, $click->ipId, $click->countryId,
            $click->regionId, $click->ispId, $click->cityId, $click->platformId, $click->browserId,
            $click->deviceId,
        ];
    }

    /** @return list<int> */
    private static function rotatorValues(ClickRecord $click, RotatorRoute $route): array
    {
        return [$click->clickId, $route->rotatorId, $route->ruleId, $route->ruleRedirectId];
    }

    /** @return list<int|string> */
    private static function recordValues(ClickRecord $click): array
    {
        return [$click->clickId, $click->clickIdPublic, $click->clickCloaking, $click->clickIn, $click->clickOut];
    }

    /** @param list<int|string> $values */
    private function write(string $sql, string $types, array $values): void
    {
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $values);
        $this->conn->executeUpdate($stmt);
    }
}
