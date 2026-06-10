-- Attribution Models Table Migration
-- Creates the 202_attribution_models table for storing user attribution model configurations

CREATE TABLE IF NOT EXISTS `202_attribution_models` (
  `model_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `model_name` varchar(100) NOT NULL,
  `model_slug` varchar(100) NOT NULL,
  `model_type` enum('last_touch','time_decay','position_based','algorithmic','assisted') NOT NULL,
  `weighting_config` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`model_id`),
  UNIQUE KEY `user_slug_unique` (`user_id`, `model_slug`),
  UNIQUE KEY `user_default_unique` (`user_id`, `is_default`),
  KEY `user_active` (`user_id`, `is_active`),
  KEY `user_type` (`user_id`, `model_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Attribution model definitions for multi-touch attribution';

-- Create default "Last Touch" model for existing users
INSERT IGNORE INTO `202_attribution_models` (
    `user_id`, 
    `model_name`, 
    `model_slug`, 
    `model_type`, 
    `weighting_config`, 
    `is_active`, 
    `is_default`, 
    `created_at`, 
    `updated_at`
)
SELECT 
    `user_id`,
    'Last Touch Attribution' as model_name,
    'last-touch-default' as model_slug,
    'last_touch' as model_type,
    NULL as weighting_config,
    1 as is_active,
    1 as is_default,
    UNIX_TIMESTAMP() as created_at,
    UNIX_TIMESTAMP() as updated_at
FROM `202_users` 
WHERE `user_id` > 0;

-- NOTE: 202_attribution_snapshots, 202_attribution_touchpoints and
-- 202_attribution_settings are intentionally NOT created here. The canonical schema for
-- those tables is owned by the installer (Prosper202\Database\Tables\AttributionTables via
-- SchemaInstaller) and the upgrade path (functions-upgrade.php). Earlier revisions of this
-- migration created them with a DIFFERENT, incompatible schema (e.g. snapshots with
-- conversion_id/snapshot_date/attribution_data instead of the live
-- scope_type/date_hour/attributed_* columns), which the application code cannot read. Because
-- those used CREATE TABLE IF NOT EXISTS, running this migration on a fresh database before the
-- installer would have silently created the wrong tables and broken the attribution feature.
-- Create these tables via the installer/upgrade path, not this script.

-- Add attribution model reference to campaigns table
ALTER TABLE `202_aff_campaigns` 
ADD COLUMN `attribution_model_id` int(11) DEFAULT NULL 
AFTER `aff_campaign_cloaking`;

-- Create index for attribution model lookups
ALTER TABLE `202_aff_campaigns` 
ADD INDEX `idx_attribution_model` (`attribution_model_id`);

-- Add foreign key constraint (optional, for data integrity)
ALTER TABLE `202_aff_campaigns` 
ADD CONSTRAINT `fk_campaign_attribution_model` 
FOREIGN KEY (`attribution_model_id`) 
REFERENCES `202_attribution_models` (`model_id`) 
ON DELETE SET NULL;