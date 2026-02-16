<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Server-side sync, change feed, and audit table definitions.
 */
final class SyncTables
{
    /**
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::syncJobs(),
            self::syncJobEvents(),
            self::syncJobItems(),
            self::changeLog(),
            self::deletedLog(),
            self::syncAudit(),
        ];
    }

    public static function syncJobs(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SYNC_JOBS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SYNC_JOBS . "` (
                `sync_job_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `job_uuid` char(32) NOT NULL,
                `actor_user_id` mediumint(8) unsigned NOT NULL,
                `source_label` varchar(255) NOT NULL,
                `target_label` varchar(255) NOT NULL,
                `source_url` varchar(500) NOT NULL,
                `target_url` varchar(500) NOT NULL,
                `entity` varchar(64) NOT NULL,
                `status` varchar(32) NOT NULL DEFAULT 'queued',
                `attempts` int(10) unsigned NOT NULL DEFAULT '0',
                `max_attempts` int(10) unsigned NOT NULL DEFAULT '3',
                `idempotency_key` varchar(128) DEFAULT NULL,
                `request_hash` char(40) DEFAULT NULL,
                `request_payload` mediumtext DEFAULT NULL,
                `result_payload` mediumtext DEFAULT NULL,
                `error_message` text DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                `next_run_at` int(10) unsigned DEFAULT NULL,
                `started_at` int(10) unsigned DEFAULT NULL,
                `completed_at` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`sync_job_id`),
                UNIQUE KEY `job_uuid` (`job_uuid`),
                KEY `status_next_run` (`status`,`next_run_at`),
                KEY `created_at` (`created_at`),
                KEY `source_target` (`source_label`,`target_label`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function syncJobEvents(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SYNC_JOB_EVENTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SYNC_JOB_EVENTS . "` (
                `event_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `sync_job_id` bigint(20) unsigned NOT NULL,
                `event_uuid` char(16) NOT NULL,
                `level` varchar(16) NOT NULL,
                `message` text NOT NULL,
                `event_data` mediumtext DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`event_id`),
                KEY `job_created` (`sync_job_id`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function syncJobItems(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SYNC_JOB_ITEMS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SYNC_JOB_ITEMS . "` (
                `item_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `sync_job_id` bigint(20) unsigned NOT NULL,
                `entity` varchar(64) NOT NULL,
                `natural_key` varchar(1024) NOT NULL,
                `action` varchar(32) NOT NULL,
                `status` varchar(32) NOT NULL,
                `source_id` varchar(64) DEFAULT NULL,
                `target_id` varchar(64) DEFAULT NULL,
                `source_checksum` char(40) DEFAULT NULL,
                `target_checksum` char(40) DEFAULT NULL,
                `error_message` text DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`item_id`),
                KEY `job_entity_status` (`sync_job_id`,`entity`,`status`),
                KEY `job_action` (`sync_job_id`,`action`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function changeLog(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CHANGE_LOG,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CHANGE_LOG . "` (
                `change_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `entity` varchar(64) NOT NULL,
                `entity_id` varchar(64) NOT NULL,
                `operation` enum('create','update','delete') NOT NULL,
                `natural_key_digest` char(40) NOT NULL,
                `actor_user_id` mediumint(8) unsigned NOT NULL,
                `changed_at` int(10) unsigned NOT NULL,
                `payload` mediumtext DEFAULT NULL,
                PRIMARY KEY (`change_id`),
                KEY `entity_changed` (`entity`,`changed_at`),
                KEY `digest_lookup` (`natural_key_digest`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function deletedLog(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DELETED_LOG,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DELETED_LOG . "` (
                `deleted_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `entity` varchar(64) NOT NULL,
                `entity_id` varchar(64) NOT NULL,
                `natural_key_digest` char(40) NOT NULL,
                `actor_user_id` mediumint(8) unsigned NOT NULL,
                `deleted_at` int(10) unsigned NOT NULL,
                `payload` mediumtext DEFAULT NULL,
                PRIMARY KEY (`deleted_id`),
                KEY `entity_deleted` (`entity`,`deleted_at`),
                KEY `digest_lookup` (`natural_key_digest`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function syncAudit(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SYNC_AUDIT,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SYNC_AUDIT . "` (
                `audit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `job_uuid` char(32) NOT NULL,
                `actor_user_id` mediumint(8) unsigned NOT NULL,
                `source_label` varchar(255) NOT NULL,
                `target_label` varchar(255) NOT NULL,
                `entity` varchar(64) NOT NULL,
                `status` varchar(32) NOT NULL,
                `options_json` mediumtext DEFAULT NULL,
                `result_summary_json` mediumtext DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `completed_at` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`audit_id`),
                UNIQUE KEY `job_uuid` (`job_uuid`),
                KEY `actor_created` (`actor_user_id`,`created_at`),
                KEY `source_target_status` (`source_label`,`target_label`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
