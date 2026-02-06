<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Ad network feed and token table definitions.
 */
final class AdNetworkTables
{
    /**
     * Get all ad network-related table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::adNetworkFeeds(),
            self::adNetworkAds(),
            self::adNetworkTitles(),
            self::adNetworkBodies(),
            self::adFeedContentadTokens(),
            self::adFeedOutbrainTokens(),
            self::adFeedTaboolaTokens(),
            self::adFeedCustomTokens(),
            self::adFeedRevcontentTokens(),
            self::adFeedFacebookTokens(),
        ];
    }

    public static function adNetworkFeeds(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AD_NETWORK_FEEDS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AD_NETWORK_FEEDS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) NOT NULL,
                `creative_group` varchar(255) NOT NULL DEFAULT '',
                `story_url` text NOT NULL,
                `feed_name` char(12) NOT NULL DEFAULT '',
                `revcontent_boost_id` text,
                `facebook_ad_set_id` int(11) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function adNetworkAds(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AD_NETWORK_ADS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AD_NETWORK_ADS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `feed_id` int(11) NOT NULL,
                `ad` text NOT NULL,
                PRIMARY KEY (`id`),
                KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function adNetworkTitles(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AD_NETWORK_TITLES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AD_NETWORK_TITLES . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `feed_id` int(11) NOT NULL,
                `title` char(100) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function adNetworkBodies(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AD_NETWORK_BODIES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AD_NETWORK_BODIES . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `feed_id` int(11) NOT NULL,
                `body_text` char(90) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function adFeedContentadTokens(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AD_FEED_CONTENTAD_TOKENS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AD_FEED_CONTENTAD_TOKENS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `feed_id` int(11) NOT NULL,
                `utm_campaign` varchar(350) DEFAULT NULL,
                `utm_source` varchar(350) DEFAULT NULL,
                `utm_medium` varchar(350) DEFAULT NULL,
                `utm_content` varchar(350) DEFAULT NULL,
                `utm_term` varchar(350) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function adFeedOutbrainTokens(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AD_FEED_OUTBRAIN_TOKENS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AD_FEED_OUTBRAIN_TOKENS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `feed_id` int(11) NOT NULL,
                `utm_campaign` varchar(350) DEFAULT NULL,
                `utm_source` varchar(350) DEFAULT NULL,
                `utm_medium` varchar(350) DEFAULT NULL,
                `utm_content` varchar(350) DEFAULT NULL,
                `utm_term` varchar(350) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function adFeedTaboolaTokens(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AD_FEED_TABOOLA_TOKENS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AD_FEED_TABOOLA_TOKENS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `feed_id` int(11) NOT NULL,
                `utm_campaign` varchar(350) DEFAULT NULL,
                `utm_source` varchar(350) DEFAULT NULL,
                `utm_medium` varchar(350) DEFAULT NULL,
                `utm_content` varchar(350) DEFAULT NULL,
                `utm_term` varchar(350) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function adFeedCustomTokens(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AD_FEED_CUSTOM_TOKENS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AD_FEED_CUSTOM_TOKENS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `feed_id` int(11) NOT NULL,
                `network` varchar(255) NOT NULL,
                `custom_token` varchar(350) NOT NULL DEFAULT '',
                `value` varchar(350) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function adFeedRevcontentTokens(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AD_FEED_REVCONTENT_TOKENS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AD_FEED_REVCONTENT_TOKENS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `feed_id` int(11) NOT NULL,
                `utm_campaign` varchar(350) DEFAULT NULL,
                `utm_source` varchar(350) DEFAULT NULL,
                `utm_medium` varchar(350) DEFAULT NULL,
                `utm_content` varchar(350) DEFAULT NULL,
                `utm_term` varchar(350) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function adFeedFacebookTokens(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AD_FEED_FACEBOOK_TOKENS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AD_FEED_FACEBOOK_TOKENS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `feed_id` int(11) NOT NULL,
                `utm_campaign` varchar(350) DEFAULT NULL,
                `utm_source` varchar(350) DEFAULT NULL,
                `utm_medium` varchar(350) DEFAULT NULL,
                `utm_content` varchar(350) DEFAULT NULL,
                `utm_term` varchar(350) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
