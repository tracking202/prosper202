<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Campaign and affiliate table definitions.
 */
final class CampaignTables
{
    /**
     * Get all campaign-related table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::affCampaigns(),
            self::affNetworks(),
            self::ppcAccounts(),
            self::ppcNetworks(),
            self::ppcAccountPixels(),
            self::landingPages(),
            self::textAds(),
        ];
    }

    public static function affCampaigns(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AFF_CAMPAIGNS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AFF_CAMPAIGNS . "` (
                `aff_campaign_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `aff_campaign_id_public` int(10) unsigned DEFAULT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `aff_network_id` mediumint(8) unsigned NOT NULL,
                `aff_campaign_deleted` tinyint(1) NOT NULL DEFAULT '0',
                `aff_campaign_name` varchar(50) NOT NULL,
                `aff_campaign_url` text NOT NULL,
                `aff_campaign_url_2` text DEFAULT NULL,
                `aff_campaign_url_3` text DEFAULT NULL,
                `aff_campaign_url_4` text DEFAULT NULL,
                `aff_campaign_url_5` text DEFAULT NULL,
                `aff_campaign_payout` decimal(8,2) NOT NULL,
                `aff_campaign_cloaking` tinyint(1) NOT NULL DEFAULT '0',
                `aff_campaign_time` int(10) unsigned NOT NULL,
                `aff_campaign_rotate` tinyint(1) NOT NULL DEFAULT '0',
                `aff_campaign_currency` char(3) NOT NULL DEFAULT 'USD',
                `aff_campaign_foreign_payout` decimal(8,2) NOT NULL,
                PRIMARY KEY (`aff_campaign_id`),
                KEY `aff_network_id` (`aff_network_id`),
                KEY `aff_campaign_deleted` (`aff_campaign_deleted`),
                KEY `user_id` (`user_id`),
                KEY `aff_campaign_name` (`aff_campaign_name`(5)),
                KEY `aff_campaign_id_public` (`aff_campaign_id_public`),
                KEY `aff_campaign_id` (`aff_campaign_id`,`aff_campaign_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function affNetworks(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AFF_NETWORKS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AFF_NETWORKS . "` (
                `aff_network_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `dni_network_id` mediumint(8) DEFAULT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `aff_network_name` varchar(50) NOT NULL,
                `aff_network_deleted` tinyint(1) NOT NULL DEFAULT '0',
                `aff_network_time` int(10) unsigned NOT NULL,
                PRIMARY KEY (`aff_network_id`),
                KEY `user_id` (`user_id`),
                KEY `aff_network_deleted` (`aff_network_deleted`),
                KEY `aff_network_name` (`aff_network_name`(5)),
                KEY `dni_network_id` (`dni_network_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function ppcAccounts(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::PPC_ACCOUNTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::PPC_ACCOUNTS . "` (
                `ppc_account_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `ppc_network_id` mediumint(8) unsigned NOT NULL,
                `ppc_account_name` varchar(50) NOT NULL,
                `ppc_account_deleted` tinyint(1) NOT NULL DEFAULT '0',
                `ppc_account_time` int(10) unsigned NOT NULL,
                `ppc_account_default` tinyint(1) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`ppc_account_id`),
                KEY `ppc_network_id` (`ppc_network_id`),
                KEY `ppc_account_deleted` (`ppc_account_deleted`),
                KEY `user_id` (`user_id`),
                KEY `ppc_account_name` (`ppc_account_name`(5)),
                KEY `ppc_account_default` (`ppc_account_default`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function ppcNetworks(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::PPC_NETWORKS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::PPC_NETWORKS . "` (
                `ppc_network_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `ppc_network_deleted` tinyint(1) NOT NULL DEFAULT '0',
                `ppc_network_name` varchar(50) NOT NULL,
                `ppc_network_time` int(10) unsigned NOT NULL,
                PRIMARY KEY (`ppc_network_id`),
                KEY `user_id` (`user_id`),
                KEY `ppc_network_deleted` (`ppc_network_deleted`),
                KEY `ppc_network_name` (`ppc_network_name`(5))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function ppcAccountPixels(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::PPC_ACCOUNT_PIXELS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::PPC_ACCOUNT_PIXELS . "` (
                `pixel_id` mediumint(8) unsigned NOT NULL auto_increment,
                `pixel_code` text NOT NULL,
                `pixel_type_id` mediumint(8) unsigned NOT NULL,
                `ppc_account_id` mediumint(8) unsigned NOT NULL,
                PRIMARY KEY (`pixel_id`),
                KEY `ppc_account_id` (`ppc_account_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function landingPages(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LANDING_PAGES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LANDING_PAGES . "` (
                `landing_page_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `landing_page_id_public` int(10) unsigned DEFAULT NULL,
                `aff_campaign_id` mediumint(8) unsigned NOT NULL,
                `landing_page_nickname` varchar(50) NOT NULL,
                `landing_page_url` varchar(255) NOT NULL,
                `leave_behind_page_url` varchar(255) DEFAULT '',
                `landing_page_deleted` tinyint(1) NOT NULL DEFAULT '0',
                `landing_page_time` int(10) unsigned NOT NULL,
                `landing_page_type` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`landing_page_id`),
                KEY `landing_page_id_public` (`landing_page_id_public`),
                KEY `aff_campaign_id` (`aff_campaign_id`),
                KEY `landing_page_deleted` (`landing_page_deleted`),
                KEY `user_id` (`user_id`),
                KEY `landing_page_type` (`landing_page_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function textAds(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::TEXT_ADS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::TEXT_ADS . "` (
                `text_ad_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `aff_campaign_id` mediumint(8) unsigned NOT NULL,
                `landing_page_id` mediumint(8) unsigned NOT NULL,
                `text_ad_deleted` tinyint(1) NOT NULL DEFAULT '0',
                `text_ad_name` varchar(100) NOT NULL,
                `text_ad_headline` varchar(100) NOT NULL,
                `text_ad_description` varchar(100) NOT NULL,
                `text_ad_display_url` varchar(100) NOT NULL,
                `text_ad_time` int(10) unsigned NOT NULL,
                `text_ad_type` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`text_ad_id`),
                KEY `aff_campaign_id` (`aff_campaign_id`),
                KEY `text_ad_deleted` (`text_ad_deleted`),
                KEY `user_id` (`user_id`),
                KEY `text_ad_type` (`text_ad_type`),
                KEY `landing_page_id` (`landing_page_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
