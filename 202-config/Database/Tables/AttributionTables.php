<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Attribution and conversion table definitions.
 */
final class AttributionTables
{
    /**
     * Get all attribution-related table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::attributionModels(),
            self::attributionSnapshots(),
            self::attributionTouchpoints(),
            self::attributionSettings(),
            self::attributionAudit(),
            self::attributionExports(),
            self::conversionLogs(),
            self::conversionTouchpoints(),
        ];
    }

    public static function attributionModels(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_MODELS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_MODELS . "` (
                `model_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `model_name` varchar(255) NOT NULL,
                `model_slug` varchar(191) NOT NULL,
                `model_type` varchar(50) NOT NULL,
                `weighting_config` longtext,
                `is_active` tinyint(1) NOT NULL DEFAULT '1',
                `is_default` tinyint(1) NOT NULL DEFAULT '0',
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`model_id`),
                UNIQUE KEY `model_slug_user` (`user_id`,`model_slug`),
                KEY `user_default` (`user_id`,`is_default`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function attributionSnapshots(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_SNAPSHOTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_SNAPSHOTS . "` (
                `snapshot_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `model_id` bigint(20) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `scope_type` varchar(50) NOT NULL,
                `scope_id` bigint(20) unsigned DEFAULT NULL,
                `date_hour` int(10) unsigned NOT NULL,
                `lookback_start` int(10) unsigned NOT NULL,
                `lookback_end` int(10) unsigned NOT NULL,
                `attributed_clicks` int(10) unsigned NOT NULL DEFAULT '0',
                `attributed_conversions` int(10) unsigned NOT NULL DEFAULT '0',
                `attributed_revenue` decimal(12,4) NOT NULL DEFAULT '0.0000',
                `attributed_cost` decimal(12,4) NOT NULL DEFAULT '0.0000',
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`snapshot_id`),
                KEY `model_hour_scope` (`model_id`,`date_hour`,`scope_type`,`scope_id`),
                KEY `user_hour` (`user_id`,`date_hour`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function attributionTouchpoints(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_TOUCHPOINTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_TOUCHPOINTS . "` (
                `touchpoint_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `snapshot_id` bigint(20) unsigned NOT NULL,
                `conv_id` int(11) unsigned NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                `position` smallint(5) unsigned NOT NULL DEFAULT '0',
                `credit` decimal(10,5) NOT NULL DEFAULT '0.00000',
                `weight` decimal(10,5) NOT NULL DEFAULT '0.00000',
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`touchpoint_id`),
                KEY `snapshot_conv` (`snapshot_id`,`conv_id`),
                KEY `click_lookup` (`click_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function attributionSettings(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_SETTINGS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_SETTINGS . "` (
                `setting_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `scope_type` varchar(50) NOT NULL,
                `scope_id` bigint(20) unsigned DEFAULT NULL,
                `model_id` bigint(20) unsigned NOT NULL,
                `multi_touch_enabled` tinyint(1) unsigned NOT NULL DEFAULT '1',
                `multi_touch_enabled_at` int(10) unsigned DEFAULT NULL,
                `multi_touch_disabled_at` int(10) unsigned DEFAULT NULL,
                `effective_at` int(10) unsigned NOT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`setting_id`),
                UNIQUE KEY `user_scope` (`user_id`,`scope_type`,`scope_id`),
                UNIQUE KEY `user_scope_model` (`user_id`,`scope_type`,`scope_id`,`model_id`),
                UNIQUE KEY `user_scope_multi_touch` (`user_id`,`scope_type`,`scope_id`,`multi_touch_enabled`),
                KEY `model_lookup` (`model_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function attributionAudit(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_AUDIT,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_AUDIT . "` (
                `audit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `model_id` bigint(20) unsigned DEFAULT NULL,
                `action` varchar(50) NOT NULL,
                `metadata` longtext,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`audit_id`),
                KEY `user_lookup` (`user_id`),
                KEY `model_lookup` (`model_id`),
                KEY `action_lookup` (`action`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function attributionExports(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_EXPORTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_EXPORTS . "` (
                `export_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `model_id` int(11) unsigned NOT NULL,
                `scope_type` varchar(32) NOT NULL,
                `scope_id` bigint(20) unsigned DEFAULT NULL,
                `start_hour` int(10) unsigned NOT NULL,
                `end_hour` int(10) unsigned NOT NULL,
                `requested_format` varchar(16) NOT NULL DEFAULT 'csv',
                `status` varchar(20) NOT NULL DEFAULT 'pending',
                `options` longtext DEFAULT NULL,
                `webhook_url` varchar(500) DEFAULT NULL,
                `webhook_secret` varchar(255) DEFAULT NULL,
                `webhook_headers` text DEFAULT NULL,
                `file_path` varchar(500) DEFAULT NULL,
                `rows_exported` int(11) unsigned DEFAULT NULL,
                `queued_at` int(10) unsigned NOT NULL,
                `started_at` int(10) unsigned DEFAULT NULL,
                `completed_at` int(10) unsigned DEFAULT NULL,
                `failed_at` int(10) unsigned DEFAULT NULL,
                `last_error` text DEFAULT NULL,
                `webhook_attempted_at` int(10) unsigned DEFAULT NULL,
                `webhook_status_code` int(11) DEFAULT NULL,
                `webhook_response_body` mediumtext DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`export_id`),
                KEY `model_status` (`model_id`,`status`),
                KEY `user_status` (`user_id`,`status`),
                KEY `queued_at` (`queued_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function conversionLogs(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CONVERSION_LOGS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CONVERSION_LOGS . "` (
                `conv_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `click_id` bigint(20) unsigned NOT NULL,
                `transaction_id` varchar(255) DEFAULT NULL,
                `campaign_id` mediumint(8) unsigned NOT NULL,
                `click_payout` decimal(11,5) NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `click_time` int(10) NOT NULL,
                `conv_time` int(10) NOT NULL,
                `time_difference` text NOT NULL,
                `ip` varchar(15) NOT NULL DEFAULT '',
                `pixel_type` int(11) unsigned NOT NULL,
                `user_agent` text NOT NULL,
                `deleted` tinyint(4) NOT NULL DEFAULT '0',
                PRIMARY KEY (`conv_id`),
                KEY `click_id` (`click_id`),
                KEY `user_id` (`user_id`),
                KEY `campaign_id` (`campaign_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function conversionTouchpoints(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CONVERSION_TOUCHPOINTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CONVERSION_TOUCHPOINTS . "` (
                `touchpoint_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `conv_id` int(11) unsigned NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                `click_time` int(10) unsigned NOT NULL,
                `position` smallint(5) unsigned NOT NULL DEFAULT '0',
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`touchpoint_id`),
                KEY `conv_id` (`conv_id`),
                KEY `click_lookup` (`click_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
