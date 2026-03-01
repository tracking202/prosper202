<?php

declare(strict_types=1);

namespace Prosper202\Click;

final class ClickRecord
{
    // Pre-allocated click ID (0 = auto-generate from counter table)
    public int $clickId = 0;

    // Core click data (202_clicks)
    public int $userId = 0;
    public int $affCampaignId = 0;
    public int $landingPageId = 0;
    public int $ppcAccountId = 0;
    public string $clickCpc = '0';
    public string $clickPayout = '0';
    public int $clickFiltered = 0;
    public int $clickBot = 0;
    public int $clickAlp = 0;
    public string $clickTime = '';

    // Advance data (202_clicks_advance)
    public int $textAdId = 0;
    public int $keywordId = 0;
    public int $ipId = 0;
    public int $countryId = 0;
    public int $regionId = 0;
    public int $ispId = 0;
    public int $cityId = 0;
    public int $platformId = 0;
    public int $browserId = 0;
    public int $deviceId = 0;

    // Tracking data (202_clicks_tracking)
    public int $c1Id = 0;
    public int $c2Id = 0;
    public int $c3Id = 0;
    public int $c4Id = 0;

    // Variable data (202_clicks_variable)
    public int $variableSetId = 0;

    // Google/UTM data (202_google)
    public string $gclid = '';
    public int $utmSourceId = 0;
    public int $utmMediumId = 0;
    public int $utmCampaignId = 0;
    public int $utmTermId = 0;
    public int $utmContentId = 0;

    // Record data (202_clicks_record)
    public string $clickIdPublic = '';
    public int $clickCloaking = 0;
    public int $clickIn = 1;
    public int $clickOut = 0;

    // Site data (202_clicks_site)
    public int $clickRefererSiteUrlId = 0;
    public int $clickLandingSiteUrlId = 0;
    public int $clickOutboundSiteUrlId = 0;
    public int $clickCloakingSiteUrlId = 0;
    public int $clickRedirectSiteUrlId = 0;
}
