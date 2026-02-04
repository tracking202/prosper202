<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Click tracking table definitions.
 */
final class ClickTables
{
    /**
     * Get all click-related table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::clicks(),
            self::clicksAdvance(),
            self::clicksCounter(),
            self::clicksRecord(),
            self::clicksSite(),
            self::clicksSpy(),
            self::clicksTracking(),
            self::clicksVariable(),
            self::clicksRotator(),
            self::clicksTotal(),
        ];
    }

    public static function clicks(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `aff_campaign_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                `landing_page_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                `ppc_account_id` mediumint(8) unsigned NOT NULL,
                `click_cpc` decimal(7,5) NOT NULL,
                `click_payout` decimal(11,5) NOT NULL DEFAULT '0.00000',
                `click_lead` tinyint(1) NOT NULL DEFAULT '0',
                `click_filtered` tinyint(1) NOT NULL DEFAULT '0',
                `click_bot` tinyint(1) NOT NULL DEFAULT '0',
                `click_alp` tinyint(1) NOT NULL DEFAULT '0',
                `click_time` int(10) unsigned NOT NULL,
                `rotator_id` int(10) unsigned NOT NULL DEFAULT '0',
                `rule_id` int(10) unsigned NOT NULL DEFAULT '0',
                KEY `aff_campaign_id` (`aff_campaign_id`),
                KEY `ppc_account_id` (`ppc_account_id`),
                KEY `click_lead` (`click_lead`),
                KEY `click_filtered` (`click_filtered`),
                KEY `click_id` (`click_id`),
                KEY `overview_index` (`user_id`,`click_filtered`,`aff_campaign_id`,`ppc_account_id`),
                KEY `user_id` (`user_id`,`click_lead`),
                KEY `click_alp` (`click_alp`),
                KEY `landing_page_id` (`landing_page_id`),
                KEY `overview_index2` (`user_id`,`click_filtered`,`landing_page_id`,`aff_campaign_id`),
                KEY `rotator_id` (`rotator_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function clicksAdvance(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_ADVANCE,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_ADVANCE . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `text_ad_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                `keyword_id` bigint(20) unsigned NOT NULL DEFAULT '0',
                `ip_id` bigint(20) unsigned NOT NULL,
                `country_id` bigint(20) unsigned NOT NULL,
                `region_id` bigint(20) unsigned NOT NULL,
                `city_id` bigint(20) unsigned NOT NULL,
                `platform_id` bigint(20) unsigned NOT NULL,
                `browser_id` bigint(20) unsigned NOT NULL,
                `device_id` bigint(20) unsigned NOT NULL,
                `isp_id` bigint(20) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`click_id`),
                KEY `text_ad_id` (`text_ad_id`),
                KEY `keyword_id` (`keyword_id`),
                KEY `ip_id` (`ip_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function clicksCounter(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_COUNTER,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_COUNTER . "` (
                `click_id` bigint(20) unsigned NOT NULL auto_increment,
                PRIMARY KEY (`click_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function clicksRecord(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_RECORD,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_RECORD . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `click_id_public` bigint(20) unsigned NOT NULL,
                `click_cloaking` tinyint(1) NOT NULL default '0',
                `click_in` tinyint(1) NOT NULL default '0',
                `click_out` tinyint(1) NOT NULL default '0',
                `click_reviewed` tinyint(1) NOT NULL default '0',
                PRIMARY KEY (`click_id`),
                KEY `click_id_public` (`click_id_public`),
                KEY `click_in` (`click_in`),
                KEY `click_out` (`click_out`),
                KEY `click_cloak` (`click_cloaking`),
                KEY `click_reviewed` (`click_reviewed`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function clicksSite(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_SITE,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_SITE . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `click_referer_site_url_id` bigint(20) unsigned NOT NULL DEFAULT '0',
                `click_landing_site_url_id` bigint(20) unsigned NOT NULL DEFAULT '0',
                `click_outbound_site_url_id` bigint(20) unsigned NOT NULL DEFAULT '0',
                `click_cloaking_site_url_id` bigint(20) unsigned NOT NULL DEFAULT '0',
                `click_redirect_site_url_id` bigint(20) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`click_id`),
                KEY `click_referer_site_url_id` (`click_referer_site_url_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function clicksSpy(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_SPY,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_SPY . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `aff_campaign_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                `landing_page_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                `ppc_account_id` mediumint(8) unsigned NOT NULL,
                `click_cpc` decimal(4,2) NOT NULL,
                `click_payout` decimal(8,2) NOT NULL DEFAULT '0.00000',
                `click_lead` tinyint(1) NOT NULL default '0',
                `click_filtered` tinyint(1) NOT NULL default '0',
                `click_bot` tinyint(1) NOT NULL default '0',
                `click_alp` tinyint(1) NOT NULL default '0',
                `click_time` int(10) unsigned NOT NULL,
                KEY `ppc_account_id` (`ppc_account_id`),
                KEY `click_lead` (`click_lead`),
                KEY `click_filtered` (`click_filtered`),
                KEY `click_id` (`click_id`),
                KEY `aff_campaign_id` (`aff_campaign_id`),
                KEY `overview_index` (`user_id`,`click_filtered`,`aff_campaign_id`,`ppc_account_id`,`click_lead`),
                KEY `user_lead` (`user_id`,`click_lead`),
                KEY `click_alp` (`click_alp`),
                KEY `landing_page_id` (`landing_page_id`),
                KEY `overview_index2` (`user_id`,`click_filtered`,`landing_page_id`,`aff_campaign_id`),
                INDEX (click_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function clicksTracking(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_TRACKING,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_TRACKING . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `c1_id` bigint(20) NOT NULL,
                `c2_id` bigint(20) NOT NULL,
                `c3_id` bigint(20) NOT NULL,
                `c4_id` bigint(20) NOT NULL,
                PRIMARY KEY (`click_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function clicksVariable(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_VARIABLE,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_VARIABLE . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `variable_set_id` bigint(20) unsigned NOT NULL,
                KEY `custom_variable_id` (`variable_set_id`),
                KEY `click_id` (`click_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function clicksRotator(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_ROTATOR,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_ROTATOR . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `rotator_id` bigint(20) unsigned NOT NULL,
                `rule_id` bigint(20) unsigned NOT NULL,
                `rule_redirect_id` bigint(20) unsigned NOT NULL,
                PRIMARY KEY (`click_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function clicksTotal(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_TOTAL,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_TOTAL . "` (
                `click_count` int(20) unsigned NOT NULL default '0',
                PRIMARY KEY (`click_count`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
