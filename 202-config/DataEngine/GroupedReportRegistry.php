<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Registry of the single-dimension grouped reports. The SQL fragments are
 * preserved from the legacy per-report methods; only their duplication was
 * removed.
 */
final class GroupedReportRegistry
{
    /**
     * @param string $inet6Ntoa Name of the SQL function used to decode IPv6
     *                          addresses for display ('inet6_ntoa' when the
     *                          MySQL UDF is available, '' otherwise). Only
     *                          the 'ip' report uses it.
     */
    public static function definition(string $reportType, string $inet6Ntoa = ''): ?GroupedReportDefinition
    {
        return match ($reportType) {
            'keyword' => new GroupedReportDefinition(
                labelSelect: '`keyword`',
                joins: ' LEFT JOIN 202_keywords as 2k on (2st.keyword_id= 2k.keyword_id) ',
                groupBy: 'keyword',
                includeFilterJoin: false,
            ),
            'textad' => new GroupedReportDefinition(
                labelSelect: '`text_ad_name`',
                joins: ' LEFT JOIN 202_text_ads on (2st.text_ad_id= 202_text_ads.text_ad_id) ',
                groupBy: 'text_ad_name',
            ),
            'referer' => new GroupedReportDefinition(
                labelSelect: 'site_domain_host as referer_name',
                joins: ' LEFT JOIN 202_site_urls on (2st.click_referer_site_url_id = 202_site_urls.site_url_id)'
                    . ' LEFT JOIN 202_site_domains on (202_site_domains.site_domain_id = 202_site_urls.site_domain_id) ',
                groupBy: 'referer_name',
            ),
            'ip' => new GroupedReportDefinition(
                labelSelect: 'IFNULL(' . $inet6Ntoa . '(2i6.ip_address),2i.ip_address) as ip_address',
                joins: ' LEFT JOIN 202_ips AS 2i on (2st.ip_id = 2i.ip_id)'
                    . ' LEFT JOIN 202_ips_v6 AS 2i6 ON (2i6.ip_id = 2i.ip_address COLLATE utf8mb4_general_ci) ',
                groupBy: 'ip_address',
            ),
            'country' => new GroupedReportDefinition(
                labelSelect: 'country_name,country_code',
                joins: ' LEFT JOIN 202_locations_country on (2st.country_id = 202_locations_country.country_id) ',
                groupBy: 'country_name',
            ),
            'region' => new GroupedReportDefinition(
                labelSelect: 'region_name,country_code',
                joins: ' LEFT JOIN 202_locations_region on (2st.region_id = 202_locations_region.region_id)'
                    . ' LEFT JOIN 202_locations_country on (202_locations_region.main_country_id = 202_locations_country.country_id) ',
                groupBy: 'region_name',
            ),
            'city' => new GroupedReportDefinition(
                labelSelect: 'city_name,country_code',
                joins: ' LEFT JOIN 202_locations_city on (2st.city_id = 202_locations_city.city_id)'
                    . ' LEFT JOIN 202_locations_country on (202_locations_city.main_country_id = 202_locations_country.country_id) ',
                groupBy: 'city_name',
            ),
            'isp' => new GroupedReportDefinition(
                labelSelect: 'isp_name',
                joins: ' LEFT JOIN 202_locations_isp on (2st.isp_id = 202_locations_isp.isp_id) ',
                groupBy: 'isp_name',
            ),
            'landingpage' => new GroupedReportDefinition(
                labelSelect: 'landing_page_nickname',
                joins: ' LEFT JOIN 202_landing_pages on (2st.landing_page_id = 202_landing_pages.landing_page_id) ',
                groupBy: 'landing_page_nickname',
            ),
            'device' => new GroupedReportDefinition(
                labelSelect: 'device_name',
                joins: ' LEFT JOIN 202_device_models on (2st.device_id = 202_device_models.device_id) ',
                groupBy: 'device_name',
            ),
            'browser' => new GroupedReportDefinition(
                labelSelect: 'browser_name',
                joins: ' LEFT JOIN 202_browsers on (2st.browser_id = 202_browsers.browser_id) ',
                groupBy: 'browser_name',
            ),
            'platform' => new GroupedReportDefinition(
                labelSelect: 'platform_name',
                joins: ' LEFT JOIN 202_platforms on (2st.platform_id = 202_platforms.platform_id) ',
                groupBy: 'platform_name',
            ),
            default => null,
        };
    }

    private function __construct()
    {
    }
}
