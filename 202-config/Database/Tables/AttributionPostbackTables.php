<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Platform-signed attribution postback table definitions.
 *
 * Prosper202 acts as the developer-side postback endpoint for Apple's
 * privacy-preserving attribution frameworks: devices POST signed postbacks
 * to /.well-known/skadnetwork/report-attribution/ (SKAdNetwork) or
 * /.well-known/appattribution/report-attribution/ (AdAttributionKit), and
 * every accepted postback lands in 202_attribution_postbacks with its
 * `protocol` stamped. The generic columns (identity, window, win/loss,
 * signature verdict, conversion type, interaction type) are what reports
 * aggregate across protocols; the protocol-specific ones (SKAdNetwork's
 * version, campaign id, fidelity type and redownload flag; AdAttributionKit's
 * marketplace and signing key) are kept so nothing the platform said is lost.
 *
 * 202_attribution_apps maps an advertised App Store id to the Prosper202
 * user who owns its reporting, and 202_attribution_conversion_values maps
 * the 6-bit fine / low-medium-high coarse conversion values — the same
 * value space in both frameworks — to named events and revenue for decoding
 * in reports.
 */
final class AttributionPostbackTables
{
    /**
     * Get all attribution-postback table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::attributionPostbacks(),
            self::attributionApps(),
            self::attributionConversionValues(),
        ];
    }

    public static function attributionPostbacks(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_POSTBACKS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_POSTBACKS . "` (
                `postback_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                `received_at` int(10) unsigned NOT NULL,
                `protocol` varchar(24) NOT NULL,
                `version` varchar(8) DEFAULT NULL,
                `ad_network_id` varchar(100) NOT NULL,
                `transaction_id` varchar(64) NOT NULL,
                `app_id` bigint(20) unsigned NOT NULL,
                `source_identifier` varchar(4) DEFAULT NULL,
                `campaign_id` bigint(20) unsigned DEFAULT NULL,
                `conversion_value` tinyint(3) unsigned DEFAULT NULL,
                `coarse_conversion_value` varchar(6) DEFAULT NULL,
                `postback_sequence_index` tinyint(3) unsigned DEFAULT NULL,
                `conversion_type` varchar(16) DEFAULT NULL,
                `redownload` tinyint(1) unsigned DEFAULT NULL,
                `did_win` tinyint(1) unsigned DEFAULT NULL,
                `ad_interaction_type` varchar(5) DEFAULT NULL,
                `source_app_id` bigint(20) unsigned DEFAULT NULL,
                `source_domain` varchar(255) DEFAULT NULL,
                `marketplace_id` varchar(255) DEFAULT NULL,
                `fidelity_type` tinyint(3) unsigned DEFAULT NULL,
                `country_code` varchar(8) DEFAULT NULL,
                `attribution_signature` text NOT NULL,
                `signature_state` varchar(16) NOT NULL,
                `signature_valid` tinyint(1) unsigned DEFAULT NULL,
                `key_id` varchar(64) DEFAULT NULL,
                `dedupe_hash` char(40) NOT NULL,
                `raw_payload` text NOT NULL,
                `remote_ip` varchar(45) NOT NULL DEFAULT '',
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`postback_id`),
                UNIQUE KEY `dedupe_hash` (`dedupe_hash`),
                KEY `user_received` (`user_id`,`received_at`),
                KEY `user_protocol` (`user_id`,`protocol`),
                KEY `user_app` (`user_id`,`app_id`),
                KEY `user_ad_network` (`user_id`,`ad_network_id`),
                KEY `transaction` (`transaction_id`),
                KEY `signature_received` (`signature_valid`,`received_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Platform-signed attribution postbacks (SKAdNetwork, AdAttributionKit) received from Apple devices'"
        );
    }

    public static function attributionApps(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_APPS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_APPS . "` (
                `attribution_app_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `app_id` bigint(20) unsigned NOT NULL,
                `app_name` varchar(255) NOT NULL,
                `notes` varchar(500) DEFAULT NULL,
                `accept_development_postbacks` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `schema_token` varchar(64) NOT NULL DEFAULT '',
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`attribution_app_id`),
                UNIQUE KEY `app_id` (`app_id`),
                UNIQUE KEY `schema_token` (`schema_token`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Advertised App Store apps whose attribution postbacks belong to a user'"
        );
    }

    public static function attributionConversionValues(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_CONVERSION_VALUES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_CONVERSION_VALUES . "` (
                `rule_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `app_id` bigint(20) unsigned NOT NULL DEFAULT '0',
                `fine_value` tinyint(3) unsigned DEFAULT NULL,
                `coarse_value` varchar(6) DEFAULT NULL,
                `event_name` varchar(255) NOT NULL,
                `revenue` decimal(11,5) NOT NULL DEFAULT '0.00000',
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`rule_id`),
                UNIQUE KEY `user_app_fine` (`user_id`,`app_id`,`fine_value`),
                UNIQUE KEY `user_app_coarse` (`user_id`,`app_id`,`coarse_value`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Decoding rules mapping SKAdNetwork/AdAttributionKit conversion values to events and revenue'"
        );
    }
}
