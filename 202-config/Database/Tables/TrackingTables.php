<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Tracking and UTM table definitions.
 */
final class TrackingTables
{
    /**
     * Get all tracking-related table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::trackingC1(),
            self::trackingC2(),
            self::trackingC3(),
            self::trackingC4(),
            self::trackers(),
            self::cpaTrackers(),
            self::keywords(),
            self::google(),
            self::bing(),
            self::facebook(),
            self::utmCampaign(),
            self::utmContent(),
            self::utmMedium(),
            self::utmSource(),
            self::utmTerm(),
            self::customVariables(),
            self::ppcNetworkVariables(),
            self::variableSets(),
            self::variableSets2(),
        ];
    }

    public static function trackingC1(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::TRACKING_C1,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::TRACKING_C1 . "` (
                `c1_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `c1` varchar(350) NOT NULL,
                PRIMARY KEY (`c1_id`),
                KEY `c1` (`c1`(191)) KEY_BLOCK_SIZE=350
            ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function trackingC2(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::TRACKING_C2,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::TRACKING_C2 . "` (
                `c2_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `c2` varchar(350) NOT NULL,
                PRIMARY KEY (`c2_id`),
                KEY `c2` (`c2`(191)) KEY_BLOCK_SIZE=350
            ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function trackingC3(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::TRACKING_C3,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::TRACKING_C3 . "` (
                `c3_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `c3` varchar(350) NOT NULL,
                PRIMARY KEY (`c3_id`),
                KEY `c3` (`c3`(191)) KEY_BLOCK_SIZE=350
            ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function trackingC4(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::TRACKING_C4,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::TRACKING_C4 . "` (
                `c4_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `c4` varchar(350) NOT NULL,
                PRIMARY KEY (`c4_id`),
                KEY `c4` (`c4`(191)) KEY_BLOCK_SIZE=350
            ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function trackers(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::TRACKERS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::TRACKERS . "` (
                `tracker_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `tracker_id_public` bigint(20) unsigned NOT NULL,
                `aff_campaign_id` mediumint(8) unsigned NOT NULL,
                `text_ad_id` mediumint(8) unsigned NOT NULL,
                `ppc_account_id` mediumint(8) unsigned NOT NULL,
                `landing_page_id` mediumint(8) unsigned NOT NULL,
                `rotator_id` int(11) unsigned NOT NULL DEFAULT '0',
                `click_cpc` decimal(7,5) DEFAULT NULL,
                `click_cpa` decimal(7,5) DEFAULT NULL,
                `click_cloaking` tinyint(1) NOT NULL,
                `tracker_time` int(10) unsigned NOT NULL,
                PRIMARY KEY (`tracker_id`),
                KEY `tracker_id_public` (`tracker_id_public`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function cpaTrackers(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CPA_TRACKERS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CPA_TRACKERS . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `tracker_id_public` int(11) unsigned NOT NULL,
                PRIMARY KEY (`click_id`),
                KEY `tracker_id` (`tracker_id_public`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function keywords(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::KEYWORDS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::KEYWORDS . "` (
                `keyword_id` bigint(20) unsigned NOT NULL auto_increment,
                `keyword` varchar(150) NOT NULL,
                PRIMARY KEY (`keyword_id`),
                KEY `keyword` (`keyword`(150))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function google(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::GOOGLE,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::GOOGLE . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `gclid` varchar(150) NOT NULL,
                `utm_source_id` bigint(20) unsigned NOT NULL,
                `utm_medium_id` bigint(20) unsigned NOT NULL,
                `utm_campaign_id` bigint(20) unsigned NOT NULL,
                `utm_term_id` bigint(20) unsigned NOT NULL,
                `utm_content_id` bigint(20) unsigned NOT NULL,
                PRIMARY KEY (`click_id`,`gclid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function bing(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::BING,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::BING . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `msclkid` varchar(150) NOT NULL,
                `utm_source_id` bigint(20) unsigned NOT NULL,
                `utm_medium_id` bigint(20) unsigned NOT NULL,
                `utm_campaign_id` bigint(20) unsigned NOT NULL,
                `utm_term_id` bigint(20) unsigned NOT NULL,
                `utm_content_id` bigint(20) unsigned NOT NULL,
                PRIMARY KEY (`click_id`,`msclkid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function facebook(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::FACEBOOK,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::FACEBOOK . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `fbclid` varchar(150) NOT NULL,
                `utm_source_id` bigint(20) unsigned NOT NULL,
                `utm_medium_id` bigint(20) unsigned NOT NULL,
                `utm_campaign_id` bigint(20) unsigned NOT NULL,
                `utm_term_id` bigint(20) unsigned NOT NULL,
                `utm_content_id` bigint(20) unsigned NOT NULL,
                PRIMARY KEY (`click_id`,`fbclid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function utmCampaign(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::UTM_CAMPAIGN,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::UTM_CAMPAIGN . "` (
                `utm_campaign_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `utm_campaign` varchar(350) NOT NULL DEFAULT '',
                PRIMARY KEY (`utm_campaign_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function utmContent(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::UTM_CONTENT,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::UTM_CONTENT . "` (
                `utm_content_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `utm_content` varchar(350) NOT NULL DEFAULT '',
                PRIMARY KEY (`utm_content_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function utmMedium(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::UTM_MEDIUM,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::UTM_MEDIUM . "` (
                `utm_medium_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `utm_medium` varchar(350) NOT NULL DEFAULT '',
                PRIMARY KEY (`utm_medium_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function utmSource(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::UTM_SOURCE,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::UTM_SOURCE . "` (
                `utm_source_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `utm_source` varchar(350) NOT NULL DEFAULT '',
                PRIMARY KEY (`utm_source_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function utmTerm(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::UTM_TERM,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::UTM_TERM . "` (
                `utm_term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `utm_term` varchar(350) NOT NULL DEFAULT '',
                PRIMARY KEY (`utm_term_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function customVariables(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CUSTOM_VARIABLES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CUSTOM_VARIABLES . "` (
                `custom_variable_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `ppc_variable_id` bigint(20) unsigned NOT NULL,
                `variable` varchar(350) NOT NULL DEFAULT '',
                PRIMARY KEY (`custom_variable_id`),
                KEY `variable` (`variable`(191)),
                KEY `ppc_variable_id` (`ppc_variable_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function ppcNetworkVariables(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::PPC_NETWORK_VARIABLES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::PPC_NETWORK_VARIABLES . "` (
                `ppc_variable_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `ppc_network_id` mediumint(8) NOT NULL,
                `name` varchar(255) NOT NULL DEFAULT '',
                `parameter` varchar(255) NOT NULL DEFAULT '',
                `placeholder` varchar(255) NOT NULL DEFAULT '',
                `deleted` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`ppc_variable_id`),
                KEY ppc_network_id (ppc_network_id,deleted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function variableSets(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::VARIABLE_SETS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::VARIABLE_SETS . "` (
                `variable_set_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `variables` varchar(255) NOT NULL DEFAULT '',
                KEY `custom_variable_id` (`variables`(191)),
                KEY `click_id` (`variable_set_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function variableSets2(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::VARIABLE_SETS2,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::VARIABLE_SETS2 . "` (
                `variable_set_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `variables` varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`variable_set_id`,`variables`(191)),
                KEY `custom_variable_id` (`variables`(191)),
                KEY `click_id` (`variable_set_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
