<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\UserPrefFilters;

final class UserPrefFiltersTest extends TestCase
{
    /** @return array<string, mixed> */
    private function prefs(array $overrides = []): array
    {
        return $overrides + [
            'user_pref_show' => 'all',
            'user_pref_subid' => '0',
            'user_pref_device_id' => '0',
            'user_pref_browser_id' => '0',
            'user_pref_platform_id' => '0',
            'user_pref_country_id' => '0',
            'user_pref_region_id' => '0',
            'user_pref_isp_id' => '0',
            'user_pref_ppc_network_id' => '0',
            'user_pref_ppc_account_id' => '0',
            'user_pref_aff_network_id' => '0',
            'user_pref_aff_campaign_id' => '0',
            'user_pref_text_ad_id' => '0',
            'user_pref_landing_page_id' => '0',
            'user_pref_method_of_promotion' => 'all',
            'user_pref_keyword' => '',
            'user_pref_ip' => '',
            'user_pref_referer' => '',
            'user_pref_limit' => '50',
        ];
    }

    public function testDefaultPrefsProduceEmptyFilterAndFirstPage(): void
    {
        $result = UserPrefFilters::build($this->prefs(), 0, false);

        self::assertSame('', $result['join']);
        self::assertSame('', $result['filter']);
        self::assertSame(' Limit 0,50', $result['limit']);
    }

    public function testDownloadsAreNotPaginated(): void
    {
        $result = UserPrefFilters::build($this->prefs(), 3, true);

        self::assertSame('', $result['limit']);
    }

    public function testOffsetIsMultipliedByPageSize(): void
    {
        $result = UserPrefFilters::build($this->prefs(['user_pref_limit' => '25']), 2, false);

        self::assertSame(' Limit 50,25', $result['limit']);
    }

    public function testShowRealClicksFilter(): void
    {
        $result = UserPrefFilters::build($this->prefs(['user_pref_show' => 'real']), 0, false);

        self::assertSame(" AND click_filtered='0' ", $result['filter']);
    }

    public function testShowPreferenceReplacesSubidFilter(): void
    {
        // Legacy quirk preserved: real/filtered/bot/leads assignment discards
        // the subid filter accumulated before it.
        $result = UserPrefFilters::build(
            $this->prefs(['user_pref_show' => 'leads', 'user_pref_subid' => '777']),
            0,
            false
        );

        self::assertSame(" AND click_lead!='0' ", $result['filter']);
    }

    public function testSubidFilterSurvivesShowAll(): void
    {
        $result = UserPrefFilters::build($this->prefs(['user_pref_subid' => '777']), 0, false);

        self::assertSame(' AND 2st.click_id=777', $result['filter']);
    }

    public function testEntityFiltersAccumulate(): void
    {
        $result = UserPrefFilters::build(
            $this->prefs([
                'user_pref_browser_id' => '4',
                'user_pref_country_id' => '12',
                'user_pref_aff_campaign_id' => '9',
            ]),
            0,
            false
        );

        self::assertStringContainsString(' AND 2st.browser_id=4', $result['filter']);
        self::assertStringContainsString(' AND 2st.country_id=12', $result['filter']);
        self::assertStringContainsString(' AND 2st.aff_campaign_id=9', $result['filter']);
    }

    public function testDeviceFilterUsesDeviceTypeSubquery(): void
    {
        $result = UserPrefFilters::build($this->prefs(['user_pref_device_id' => '2']), 0, false);

        self::assertSame(
            ' AND 2st.device_id in (select device_id from 202_device_models where device_type=2)',
            $result['filter']
        );
    }

    public function testNoTrafficSourceSentinelFiltersForNull(): void
    {
        $result = UserPrefFilters::build($this->prefs(['user_pref_ppc_network_id' => '16777215']), 0, false);

        self::assertSame(' AND 2st.ppc_network_id IS NULL', $result['filter']);
    }

    public function testMethodOfPromotionFilters(): void
    {
        $directLink = UserPrefFilters::build($this->prefs(['user_pref_method_of_promotion' => 'directlink']), 0, false);
        $landingPage = UserPrefFilters::build($this->prefs(['user_pref_method_of_promotion' => 'landingpage']), 0, false);

        self::assertSame(' AND 2st.landing_page_id = 0', $directLink['filter']);
        self::assertSame(' AND 2st.landing_page_id != 0', $landingPage['filter']);
    }

    public function testKeywordPreferenceAddsJoinAndLikeFilter(): void
    {
        $result = UserPrefFilters::build($this->prefs(['user_pref_keyword' => 'shoes']), 0, false);

        self::assertSame(' LEFT OUTER JOIN 202_keywords AS 2k ON (2k.keyword_id=2st.keyword_id) ', $result['join']);
        self::assertSame(" AND 2k.keyword like '%shoes%'", $result['filter']);
    }

    public function testResolvedIpFilter(): void
    {
        $result = UserPrefFilters::build($this->prefs(['user_pref_ip' => '1.2.3.4']), 0, false, '42');

        self::assertSame(' AND 2st.ip_id=42', $result['filter']);
    }

    public function testUnresolvedIpFilterForcesEmptyResultSet(): void
    {
        $result = UserPrefFilters::build($this->prefs(['user_pref_ip' => '1.2.3.4']), 0, false, null);

        self::assertSame(" AND 2st.ip_id=''", $result['filter']);
    }

    public function testResolvedRefererFilterUsesInList(): void
    {
        $result = UserPrefFilters::build($this->prefs(['user_pref_referer' => 'example.com']), 0, false, null, '5,9');

        self::assertSame(' AND 2st.click_referer_site_url_id in (5,9)', $result['filter']);
    }

    public function testUnresolvedRefererFilterForcesEmptyResultSet(): void
    {
        $result = UserPrefFilters::build($this->prefs(['user_pref_referer' => 'example.com']), 0, false, null, null);

        self::assertSame(" AND 2st.click_referer_site_url_id=''", $result['filter']);
    }

    public function testShowFilterMapping(): void
    {
        self::assertSame('', UserPrefFilters::showFilter('all'));
        self::assertSame(" AND click_filtered='0' ", UserPrefFilters::showFilter('real'));
        self::assertSame(" AND click_filtered='1' ", UserPrefFilters::showFilter('filtered'));
        self::assertSame(" AND click_bot='1' ", UserPrefFilters::showFilter('filtered_bot'));
        self::assertSame(" AND click_lead!='0' ", UserPrefFilters::showFilter('leads'));
        self::assertSame('', UserPrefFilters::showFilter('unknown'));
    }
}
