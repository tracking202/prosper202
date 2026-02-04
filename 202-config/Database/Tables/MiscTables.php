<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Miscellaneous table definitions (locations, browsers, devices, export, dataengine, etc.).
 */
final class MiscTables
{
    /**
     * Get all miscellaneous table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            // Location tables
            self::ips(),
            self::ipsV6(),
            self::lastIps(),
            self::locationsCity(),
            self::locationsCountry(),
            self::locationsRegion(),
            self::locationsIsp(),
            // Browser/device tables
            self::browsers(),
            self::platforms(),
            self::deviceTypes(),
            self::deviceModels(),
            self::pixelTypes(),
            // Site tables
            self::siteDomains(),
            self::siteUrls(),
            // Data engine tables
            self::dataengine(),
            self::dataengineJob(),
            self::dirtyHours(),
            self::sortBreakdowns(),
            self::charts(),
            // Export tables
            self::exportAdgroups(),
            self::exportCampaigns(),
            self::exportKeywords(),
            self::exportSessions(),
            self::exportTextads(),
            // DNI tables
            self::dniNetworks(),
            // Bot202 Facebook Pixel tables
            self::bot202FacebookPixelAssistant(),
            self::bot202FacebookPixelContentType(),
            self::bot202FacebookPixelClickEvents(),
        ];
    }

    public static function ips(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::IPS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::IPS . "` (
                `ip_id` bigint(20) unsigned NOT NULL auto_increment,
                `ip_address` varchar(15) NOT NULL,
                `location_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`ip_id`),
                KEY `ip_address` (`ip_address`),
                KEY `location_id` (`location_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function ipsV6(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::IPS_V6,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::IPS_V6 . "` (
                `ip_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `ip_address` varbinary(16) NOT NULL DEFAULT '',
                `location_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`ip_id`),
                KEY `ip_address` (`ip_address`),
                KEY `location_id` (`location_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function lastIps(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LAST_IPS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LAST_IPS . "` (
                `user_id` mediumint(9) NOT NULL,
                `ip_id` bigint(20) NOT NULL,
                `time` int(10) unsigned NOT NULL,
                KEY `ip_index` (`user_id`,`ip_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function locationsCity(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LOCATIONS_CITY,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LOCATIONS_CITY . "` (
                `city_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `main_country_id` mediumint(8) unsigned NOT NULL,
                `city_name` varchar(50) NOT NULL DEFAULT '',
                PRIMARY KEY (`city_id`),
                KEY `city_name` (`city_name`,`main_country_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function locationsCountry(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LOCATIONS_COUNTRY,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LOCATIONS_COUNTRY . "` (
                `country_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `country_code` varchar(3) NOT NULL,
                `country_name` varchar(50) NOT NULL,
                PRIMARY KEY (`country_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function locationsRegion(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LOCATIONS_REGION,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LOCATIONS_REGION . "` (
                `region_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `main_country_id` mediumint(8) unsigned NOT NULL,
                `region_name` varchar(50) NOT NULL,
                PRIMARY KEY (`region_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function locationsIsp(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LOCATIONS_ISP,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LOCATIONS_ISP . "` (
                `isp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `isp_name` varchar(50) NOT NULL DEFAULT '',
                PRIMARY KEY (`isp_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function browsers(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::BROWSERS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::BROWSERS . "` (
                `browser_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `browser_name` varchar(50) NOT NULL,
                PRIMARY KEY (`browser_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function platforms(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::PLATFORMS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::PLATFORMS . "` (
                `platform_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `platform_name` varchar(50) NOT NULL,
                PRIMARY KEY (`platform_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function deviceTypes(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DEVICE_TYPES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DEVICE_TYPES . "` (
                `type_id` tinyint(1) unsigned NOT NULL,
                `type_name` varchar(50) NOT NULL,
                PRIMARY KEY (`type_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function deviceModels(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DEVICE_MODELS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DEVICE_MODELS . "` (
                `device_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `device_name` varchar(50) NOT NULL,
                `device_type` tinyint(1) NOT NULL,
                PRIMARY KEY (`device_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function pixelTypes(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::PIXEL_TYPES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::PIXEL_TYPES . "` (
                `pixel_type_id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `pixel_type` VARCHAR(45) NULL,
                PRIMARY KEY (`pixel_type_id`),
                UNIQUE INDEX `pixel_type_UNIQUE` (`pixel_type` ASC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function siteDomains(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SITE_DOMAINS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SITE_DOMAINS . "` (
                `site_domain_id` bigint(20) unsigned NOT NULL auto_increment,
                `site_domain_host` varchar(100) NOT NULL,
                PRIMARY KEY (`site_domain_id`),
                KEY `site_domain_host` (`site_domain_host`(10))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function siteUrls(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SITE_URLS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SITE_URLS . "` (
                `site_url_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `site_domain_id` bigint(20) unsigned NOT NULL,
                `site_url_address` text NOT NULL,
                PRIMARY KEY (`site_url_id`),
                KEY `site_url_address` (`site_url_address`(191)),
                KEY `site_domain_id` (`site_domain_id`,`site_url_address`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function dataengine(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DATAENGINE,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DATAENGINE . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                `click_time` int(10) NOT NULL DEFAULT '0',
                `ppc_network_id` mediumint(8) unsigned DEFAULT '0',
                `ppc_account_id` mediumint(8) unsigned NOT NULL,
                `aff_network_id` mediumint(8) unsigned DEFAULT '0',
                `aff_campaign_id` mediumint(8) unsigned DEFAULT '0',
                `landing_page_id` mediumint(8) unsigned NOT NULL,
                `keyword_id` bigint(20) unsigned DEFAULT '0',
                `utm_medium_id` bigint(20) unsigned DEFAULT '0',
                `utm_source_id` bigint(20) unsigned DEFAULT '0',
                `utm_campaign_id` bigint(20) unsigned DEFAULT '0',
                `utm_term_id` bigint(20) unsigned DEFAULT '0',
                `utm_content_id` bigint(20) unsigned DEFAULT '0',
                `text_ad_id` mediumint(8) unsigned DEFAULT '0',
                `click_referer_site_url_id` bigint(20) unsigned DEFAULT NULL,
                `country_id` bigint(20) unsigned DEFAULT '0',
                `region_id` bigint(20) unsigned DEFAULT '0',
                `city_id` bigint(20) unsigned DEFAULT '0',
                `isp_id` bigint(20) unsigned DEFAULT '0',
                `browser_id` bigint(20) unsigned DEFAULT '0',
                `device_id` bigint(20) unsigned DEFAULT '0',
                `platform_id` bigint(20) unsigned DEFAULT '0',
                `ip_id` bigint(20) unsigned DEFAULT NULL,
                `c1_id` bigint(20) unsigned DEFAULT '0',
                `c2_id` bigint(20) unsigned DEFAULT '0',
                `c3_id` bigint(20) unsigned DEFAULT '0',
                `c4_id` bigint(20) unsigned DEFAULT '0',
                `variable_set_id` varchar(255) CHARACTER SET utf8mb4 DEFAULT '0',
                `rotator_id` bigint(20) unsigned DEFAULT '0',
                `rule_id` bigint(20) unsigned DEFAULT '0',
                `rule_redirect_id` bigint(20) unsigned DEFAULT '0',
                `click_lead` tinyint(1) NOT NULL DEFAULT '0',
                `click_filtered` tinyint(1) NOT NULL DEFAULT '0',
                `click_bot` tinyint(1) NOT NULL DEFAULT '0',
                `click_alp` tinyint(1) NOT NULL DEFAULT '0',
                `clicks` bigint(21) NOT NULL DEFAULT '0',
                `click_out` decimal(25,0) DEFAULT NULL,
                `leads` decimal(25,0) DEFAULT NULL,
                `payout` decimal(8,2) NOT NULL,
                `income` decimal(35,5) DEFAULT NULL,
                `cost` decimal(29,5) DEFAULT NULL,
                PRIMARY KEY (`click_id`,`click_time`),
                KEY `user_id` (`user_id`,`click_time`),
                KEY `dataenginejob` (`click_time`,`ppc_network_id`,`aff_network_id`,`keyword_id`,`click_referer_site_url_id`,`country_id`,`region_id`,`city_id`,`browser_id`,`device_id`,`platform_id`,`ip_id`,`c1_id`,`c2_id`,`c3_id`,`c4_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function dataengineJob(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DATAENGINE_JOB,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DATAENGINE_JOB . "` (
                `time_from` int(10) unsigned NOT NULL DEFAULT '0',
                `time_to` int(10) unsigned NOT NULL DEFAULT '0',
                `processing` tinyint(1) NOT NULL DEFAULT '0',
                `processed` tinyint(1) NOT NULL DEFAULT '0'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function dirtyHours(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DIRTY_HOURS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DIRTY_HOURS . "` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `ppc_account_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                `aff_campaign_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                `user_id` mediumint(8) unsigned NOT NULL,
                `click_time_from` int(10) unsigned NOT NULL,
                `click_time_to` int(10) unsigned NOT NULL,
                `deleted` bit(1) NOT NULL DEFAULT b'0',
                `processed` bit(1) NOT NULL DEFAULT b'0',
                `ppc_network_id` mediumint(8) unsigned DEFAULT '0',
                `aff_network_id` mediumint(8) unsigned DEFAULT '0',
                `landing_page_id` mediumint(8) unsigned NOT NULL,
                `keyword_id` bigint(20) unsigned DEFAULT '0',
                `utm_medium_id` bigint(20) unsigned DEFAULT '0',
                `utm_source_id` bigint(20) unsigned DEFAULT '0',
                `utm_campaign_id` bigint(20) unsigned DEFAULT '0',
                `utm_term_id` bigint(20) unsigned DEFAULT '0',
                `utm_content_id` bigint(20) unsigned DEFAULT '0',
                `text_ad_id` mediumint(8) unsigned DEFAULT '0',
                `click_referer_site_url_id` bigint(20) unsigned DEFAULT '0',
                `country_id` bigint(20) unsigned DEFAULT '0',
                `region_id` bigint(20) unsigned DEFAULT '0',
                `city_id` bigint(20) unsigned DEFAULT '0',
                `isp_id` bigint(20) unsigned DEFAULT '0',
                `browser_id` bigint(20) unsigned DEFAULT '0',
                `device_id` bigint(20) unsigned DEFAULT '0',
                `platform_id` bigint(20) unsigned DEFAULT '0',
                `ip_id` bigint(20) unsigned DEFAULT '0',
                `c1_id` bigint(20) unsigned DEFAULT '0',
                `c2_id` bigint(20) unsigned DEFAULT '0',
                `c3_id` bigint(20) unsigned DEFAULT '0',
                `c4_id` bigint(20) unsigned DEFAULT '0',
                `variable_set_id` varchar(255) DEFAULT '0',
                `click_filtered` tinyint(1) NOT NULL DEFAULT '0',
                `click_bot` tinyint(1) NOT NULL DEFAULT '0',
                `click_alp` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`ppc_account_id`,`aff_campaign_id`,`user_id`,`click_time_from`,`click_time_to`),
                UNIQUE KEY `id` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function sortBreakdowns(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SORT_BREAKDOWNS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SORT_BREAKDOWNS . "` (
                `sort_breakdown_id` int(10) unsigned NOT NULL auto_increment,
                `sort_breakdown_from` int(10) unsigned NOT NULL,
                `sort_breakdown_to` int(10) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `sort_breakdown_clicks` mediumint(8) unsigned NOT NULL,
                `sort_breakdown_click_throughs` mediumint(8) unsigned NOT NULL,
                `sort_breakdown_ctr` decimal(10,2) NOT NULL,
                `sort_breakdown_leads` mediumint(8) unsigned NOT NULL,
                `sort_breakdown_su_ratio` decimal(10,2) NOT NULL,
                `sort_breakdown_payout` decimal(6,2) NOT NULL,
                `sort_breakdown_epc` decimal(10,2) NOT NULL,
                `sort_breakdown_avg_cpc` decimal(7,5) NOT NULL,
                `sort_breakdown_income` decimal(10,2) NOT NULL,
                `sort_breakdown_cost` decimal(13,5) NOT NULL,
                `sort_breakdown_net` decimal(13,5) NOT NULL,
                `sort_breakdown_roi` decimal(10,2) NOT NULL,
                PRIMARY KEY (`sort_breakdown_id`),
                KEY `user_id` (`user_id`),
                KEY `sort_keyword_clicks` (`sort_breakdown_clicks`),
                KEY `sort_breakdown_click_throughs` (`sort_breakdown_click_throughs`),
                KEY `sort_breakdown_ctr` (`sort_breakdown_ctr`),
                KEY `sort_keyword_leads` (`sort_breakdown_leads`),
                KEY `sort_keyword_signup_ratio` (`sort_breakdown_su_ratio`),
                KEY `sort_keyword_payout` (`sort_breakdown_payout`),
                KEY `sort_keyword_epc` (`sort_breakdown_epc`),
                KEY `sort_keyword_cpc` (`sort_breakdown_avg_cpc`),
                KEY `sort_keyword_income` (`sort_breakdown_income`),
                KEY `sort_keyword_cost` (`sort_breakdown_cost`),
                KEY `sort_keyword_net` (`sort_breakdown_net`),
                KEY `sort_keyword_roi` (`sort_breakdown_roi`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function charts(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CHARTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CHARTS . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `data` text NOT NULL,
                `chart_time_range` varchar(255) NOT NULL DEFAULT '',
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function exportAdgroups(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::EXPORT_ADGROUPS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::EXPORT_ADGROUPS . "` (
                `export_session_id` mediumint(8) unsigned NOT NULL,
                `export_campaign_id` mediumint(8) unsigned NOT NULL,
                `export_adgroup_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `export_adgroup_name` varchar(255) NOT NULL,
                `export_adgroup_status` tinyint(1) NOT NULL,
                `export_adgroup_max_search_cpc` decimal(10,2) NOT NULL,
                `export_adgroup_max_content_cpc` decimal(10,2) NOT NULL,
                `export_adgroup_search` tinyint(1) NOT NULL,
                `export_adgroup_content` tinyint(1) NOT NULL,
                PRIMARY KEY (`export_adgroup_id`),
                KEY `export_campaign_id` (`export_campaign_id`),
                KEY `export_session_id` (`export_session_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function exportCampaigns(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::EXPORT_CAMPAIGNS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::EXPORT_CAMPAIGNS . "` (
                `export_session_id` mediumint(8) unsigned NOT NULL,
                `export_campaign_id` mediumint(9) NOT NULL AUTO_INCREMENT,
                `export_campaign_name` varchar(255) NOT NULL,
                `export_campaign_status` tinyint(1) NOT NULL,
                `export_campaign_daily_budget` decimal(10,2) unsigned NOT NULL,
                PRIMARY KEY (`export_campaign_id`),
                KEY `export_session_id` (`export_session_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function exportKeywords(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::EXPORT_KEYWORDS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::EXPORT_KEYWORDS . "` (
                `export_session_id` mediumint(8) unsigned NOT NULL,
                `export_campaign_id` mediumint(8) unsigned NOT NULL,
                `export_adgroup_id` mediumint(8) unsigned NOT NULL,
                `export_keyword_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `export_keyword_status` tinyint(1) NOT NULL,
                `export_keyword` varchar(255) NOT NULL,
                `export_keyword_match` varchar(10) NOT NULL,
                `export_keyword_watchlist` tinyint(1) NOT NULL,
                `export_keyword_max_cpc` decimal(10,2) NOT NULL,
                `export_keyword_destination_url` varchar(255) NOT NULL,
                PRIMARY KEY (`export_keyword_id`),
                KEY `export_session_id` (`export_session_id`),
                KEY `export_campaign_id` (`export_campaign_id`),
                KEY `export_adgroup_id` (`export_adgroup_id`),
                KEY `export_keyword_match` (`export_keyword_match`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function exportSessions(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::EXPORT_SESSIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::EXPORT_SESSIONS . "` (
                `export_session_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `export_session_id_public` varchar(255) NOT NULL,
                `export_session_time` int(10) unsigned NOT NULL,
                `export_session_ip` varchar(255) NOT NULL,
                PRIMARY KEY (`export_session_id`),
                KEY `session_id_public` (`export_session_id_public`(5))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function exportTextads(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::EXPORT_TEXTADS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::EXPORT_TEXTADS . "` (
                `export_session_id` mediumint(8) unsigned NOT NULL,
                `export_campaign_id` mediumint(8) unsigned NOT NULL,
                `export_adgroup_id` mediumint(8) unsigned NOT NULL,
                `export_textad_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `export_textad_name` varchar(255) NOT NULL,
                `export_textad_title` varchar(255) NOT NULL,
                `export_textad_description_full` varchar(255) NOT NULL,
                `export_textad_description_line1` varchar(255) NOT NULL,
                `export_textad_description_line2` varchar(255) NOT NULL,
                `export_textad_display_url` varchar(255) NOT NULL,
                `export_textad_destination_url` varchar(255) NOT NULL,
                `export_textad_status` tinyint(1) NOT NULL,
                PRIMARY KEY (`export_textad_id`),
                KEY `export_session_id` (`export_session_id`),
                KEY `export_campaign_id` (`export_campaign_id`),
                KEY `export_adgroup_id` (`export_adgroup_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function dniNetworks(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DNI_NETWORKS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DNI_NETWORKS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `networkId` varchar(255) NOT NULL DEFAULT '',
                `shortDescription` varchar(255) NOT NULL,
                `favIcon` varchar(255) NOT NULL,
                `apiKey` varchar(255) NOT NULL,
                `affiliateId` int(11) unsigned DEFAULT NULL,
                `name` varchar(255) NOT NULL DEFAULT '',
                `type` varchar(255) NOT NULL DEFAULT '',
                `time` int(10) unsigned NOT NULL,
                `processed` smallint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `networkId` (`networkId`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function bot202FacebookPixelAssistant(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::BOT202_FACEBOOK_PIXEL_ASSISTANT,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::BOT202_FACEBOOK_PIXEL_ASSISTANT . "` (
                `b202_fbpa_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `landing_page_id` mediumint(8) unsigned NOT NULL,
                `b202_fbpa_status` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `b202_fbpa_dynamic_epv` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `b202_fbpa_content_name` varchar(100) COLLATE utf8mb4_bin DEFAULT NULL,
                `b202_fbpa_content_type` tinyint(3) unsigned NOT NULL DEFAULT '0',
                `b202_fbpa_outbound_clicks` tinyint(1) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`b202_fbpa_id`),
                UNIQUE KEY `landing_page_id` (`landing_page_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"
        );
    }

    public static function bot202FacebookPixelContentType(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::BOT202_FACEBOOK_PIXEL_CONTENT_TYPE,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::BOT202_FACEBOOK_PIXEL_CONTENT_TYPE . "` (
                `content_type_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `content_type` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
                `content_type_description` varchar(100) COLLATE utf8mb4_bin DEFAULT NULL,
                PRIMARY KEY (`content_type_id`),
                KEY `content_type_id` (`content_type_id`,`content_type_description`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"
        );
    }

    public static function bot202FacebookPixelClickEvents(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::BOT202_FACEBOOK_PIXEL_CLICK_EVENTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::BOT202_FACEBOOK_PIXEL_CLICK_EVENTS . "` (
                `event_type_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `event_type` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
                `event_type_description` varchar(100) COLLATE utf8mb4_bin DEFAULT NULL,
                PRIMARY KEY (`event_type_id`),
                KEY `event_type_id` (`event_type_id`,`event_type_description`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"
        );
    }
}
