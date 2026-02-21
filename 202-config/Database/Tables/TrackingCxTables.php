<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * EAV tracking table definitions (cx).
 *
 * Replaces the per-variable tables (c1–c4) with a single
 * name/value lookup table and a click↔value junction table.
 */
final class TrackingCxTables
{
    /**
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::trackingCx(),
            self::clicksTrackingCx(),
            self::migrationState(),
        ];
    }

    /**
     * Unified value lookup table.
     *
     * Each row is a unique (cx_name, cx_value) pair.
     * cx_name holds 'c1', 'c2', … 'cN'.
     */
    public static function trackingCx(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::TRACKING_CX,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::TRACKING_CX . "` (
                `cx_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `cx_name` varchar(50) NOT NULL,
                `cx_value` varchar(350) NOT NULL,
                PRIMARY KEY (`cx_id`),
                UNIQUE KEY `cx_name_value` (`cx_name`, `cx_value`)
            ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC"
        );
    }

    /**
     * Junction table mapping clicks to their cx values.
     *
     * A click with c1='foo' and c3='bar' gets two rows here,
     * each pointing at the corresponding cx_id.
     */
    public static function clicksTrackingCx(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_TRACKING_CX,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_TRACKING_CX . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `cx_id` bigint(20) unsigned NOT NULL,
                PRIMARY KEY (`click_id`, `cx_id`),
                KEY `cx_id` (`cx_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Tracks progress for long-running migrations so they can resume.
     */
    public static function migrationState(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::MIGRATION_STATE,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::MIGRATION_STATE . "` (
                `migration_name` varchar(100) NOT NULL,
                `last_processed_id` bigint(20) unsigned NOT NULL DEFAULT 0,
                `total_rows` bigint(20) unsigned NOT NULL DEFAULT 0,
                `started_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                `completed_at` datetime DEFAULT NULL,
                PRIMARY KEY (`migration_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
