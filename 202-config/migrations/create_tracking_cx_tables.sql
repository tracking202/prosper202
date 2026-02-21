-- Migration: Create EAV tracking tables (cx)
-- Replaces per-variable tables (c1–c4) with unified name/value storage.
-- Safe to re-run (IF NOT EXISTS).

-- Unified value lookup table
CREATE TABLE IF NOT EXISTS `202_tracking_cx` (
    `cx_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `cx_name` varchar(50) NOT NULL,
    `cx_value` varchar(350) NOT NULL,
    PRIMARY KEY (`cx_id`),
    UNIQUE KEY `cx_name_value` (`cx_name`, `cx_value`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- Junction table: click_id → cx_id (many-to-many)
CREATE TABLE IF NOT EXISTS `202_clicks_tracking_cx` (
    `click_id` bigint(20) unsigned NOT NULL,
    `cx_id` bigint(20) unsigned NOT NULL,
    PRIMARY KEY (`click_id`, `cx_id`),
    KEY `cx_id` (`cx_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Migration state tracker (resumable batch jobs)
CREATE TABLE IF NOT EXISTS `202_migration_state` (
    `migration_name` varchar(100) NOT NULL,
    `last_processed_id` bigint(20) unsigned NOT NULL DEFAULT 0,
    `total_rows` bigint(20) unsigned NOT NULL DEFAULT 0,
    `started_at` datetime NOT NULL,
    `updated_at` datetime NOT NULL,
    `completed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`migration_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
