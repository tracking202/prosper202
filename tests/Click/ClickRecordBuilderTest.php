<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\ClickRecordBuilder;

final class ClickRecordBuilderTest extends TestCase
{
    public function testFromLegacyArrayMapsAllCoreFields(): void
    {
        $mysql = [
            'user_id' => '42',
            'aff_campaign_id' => '100',
            'landing_page_id' => '5',
            'ppc_account_id' => '3',
            'click_cpc' => '1.50',
            'click_payout' => '3.00',
            'click_filtered' => '0',
            'click_bot' => '1',
            'click_alp' => '0',
            'click_time' => '1709251200',
        ];

        $click = ClickRecordBuilder::fromLegacyArray($mysql);

        self::assertSame(42, $click->userId);
        self::assertSame(100, $click->affCampaignId);
        self::assertSame(5, $click->landingPageId);
        self::assertSame(3, $click->ppcAccountId);
        self::assertSame('1.50', $click->clickCpc);
        self::assertSame('3.00', $click->clickPayout);
        self::assertSame(0, $click->clickFiltered);
        self::assertSame(1, $click->clickBot);
        self::assertSame(0, $click->clickAlp);
        self::assertSame('1709251200', $click->clickTime);
    }

    public function testFromLegacyArrayMapsAdvanceFields(): void
    {
        $mysql = [
            'text_ad_id' => '10',
            'keyword_id' => '20',
            'ip_id' => '30',
            'country_id' => '40',
            'region_id' => '50',
            'isp_id' => '60',
            'city_id' => '70',
            'platform_id' => '80',
            'browser_id' => '90',
            'device_id' => '100',
        ];

        $click = ClickRecordBuilder::fromLegacyArray($mysql);

        self::assertSame(10, $click->textAdId);
        self::assertSame(20, $click->keywordId);
        self::assertSame(30, $click->ipId);
        self::assertSame(40, $click->countryId);
        self::assertSame(50, $click->regionId);
        self::assertSame(60, $click->ispId);
        self::assertSame(70, $click->cityId);
        self::assertSame(80, $click->platformId);
        self::assertSame(90, $click->browserId);
        self::assertSame(100, $click->deviceId);
    }

    public function testFromLegacyArrayMapsTrackingFields(): void
    {
        $mysql = [
            'c1_id' => '11',
            'c2_id' => '22',
            'c3_id' => '33',
            'c4_id' => '44',
        ];

        $click = ClickRecordBuilder::fromLegacyArray($mysql);

        self::assertSame(11, $click->c1Id);
        self::assertSame(22, $click->c2Id);
        self::assertSame(33, $click->c3Id);
        self::assertSame(44, $click->c4Id);
    }

    public function testFromLegacyArrayMapsUtmFields(): void
    {
        $mysql = [
            'gclid' => 'CjwKCAtest',
            'utm_source_id' => '1',
            'utm_medium_id' => '2',
            'utm_campaign_id' => '3',
            'utm_term_id' => '4',
            'utm_content_id' => '5',
        ];

        $click = ClickRecordBuilder::fromLegacyArray($mysql);

        self::assertSame('CjwKCAtest', $click->gclid);
        self::assertSame(1, $click->utmSourceId);
        self::assertSame(2, $click->utmMediumId);
        self::assertSame(3, $click->utmCampaignId);
        self::assertSame(4, $click->utmTermId);
        self::assertSame(5, $click->utmContentId);
    }

    public function testFromLegacyArrayMapsRecordFields(): void
    {
        $mysql = [
            'click_id_public' => '31429',
            'click_cloaking' => '1',
            'click_in' => '1',
            'click_out' => '0',
        ];

        $click = ClickRecordBuilder::fromLegacyArray($mysql);

        self::assertSame('31429', $click->clickIdPublic);
        self::assertSame(1, $click->clickCloaking);
        self::assertSame(1, $click->clickIn);
        self::assertSame(0, $click->clickOut);
    }

    public function testFromLegacyArrayMapsSiteFields(): void
    {
        $mysql = [
            'click_referer_site_url_id' => '101',
            'click_landing_site_url_id' => '102',
            'click_outbound_site_url_id' => '103',
            'click_cloaking_site_url_id' => '104',
            'click_redirect_site_url_id' => '105',
        ];

        $click = ClickRecordBuilder::fromLegacyArray($mysql);

        self::assertSame(101, $click->clickRefererSiteUrlId);
        self::assertSame(102, $click->clickLandingSiteUrlId);
        self::assertSame(103, $click->clickOutboundSiteUrlId);
        self::assertSame(104, $click->clickCloakingSiteUrlId);
        self::assertSame(105, $click->clickRedirectSiteUrlId);
    }

    public function testFromLegacyArrayDefaultsForMissingKeys(): void
    {
        $click = ClickRecordBuilder::fromLegacyArray([]);

        self::assertSame(0, $click->userId);
        self::assertSame(0, $click->affCampaignId);
        self::assertSame('0', $click->clickCpc);
        self::assertSame('0', $click->clickPayout);
        self::assertSame('', $click->clickTime);
        self::assertSame('', $click->gclid);
        self::assertSame('', $click->clickIdPublic);
        self::assertSame(0, $click->clickId);
    }

    public function testFromLegacyArrayMapsVariableSetId(): void
    {
        $mysql = ['variable_set_id' => '77'];

        $click = ClickRecordBuilder::fromLegacyArray($mysql);

        self::assertSame(77, $click->variableSetId);
    }

    public function testFromLegacyArrayCastsStringIntsToInt(): void
    {
        $mysql = [
            'user_id' => '  42  ',
            'aff_campaign_id' => 'abc',
            'click_filtered' => '0',
        ];

        $click = ClickRecordBuilder::fromLegacyArray($mysql);

        // PHP (int) cast trims whitespace
        self::assertSame(42, $click->userId);
        // Non-numeric strings cast to 0
        self::assertSame(0, $click->affCampaignId);
        self::assertSame(0, $click->clickFiltered);
    }

    public function testClickIdDefaultsToZero(): void
    {
        $click = ClickRecordBuilder::fromLegacyArray([]);

        self::assertSame(0, $click->clickId, 'clickId should default to 0 (auto-generate)');
    }
}
