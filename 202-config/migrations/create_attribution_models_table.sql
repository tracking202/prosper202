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

-- Attribution Snapshots Table (if not exists)
CREATE TABLE IF NOT EXISTS `202_attribution_snapshots` (
  `snapshot_id` int(11) NOT NULL AUTO_INCREMENT,
  `model_id` int(11) NOT NULL,
  `conversion_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `snapshot_date` int(11) NOT NULL,
  `total_touchpoints` int(11) NOT NULL DEFAULT 0,
  `attribution_data` text DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`snapshot_id`),
  KEY `model_conversion` (`model_id`, `conversion_id`),
  KEY `user_date` (`user_id`, `snapshot_date`),
  FOREIGN KEY (`model_id`) REFERENCES `202_attribution_models` (`model_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Attribution calculation snapshots';

-- Attribution Touchpoints Table (if not exists)  
CREATE TABLE IF NOT EXISTS `202_attribution_touchpoints` (
  `touchpoint_id` int(11) NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) NOT NULL,
  `click_id` int(11) NOT NULL,
  `ppc_account_id` int(11) DEFAULT NULL,
  `aff_campaign_id` int(11) DEFAULT NULL,
  `landing_page_id` int(11) DEFAULT NULL,
  `touchpoint_position` int(11) NOT NULL,
  `touchpoint_timestamp` int(11) NOT NULL,
  `attribution_credit` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `touchpoint_data` text DEFAULT NULL,
  PRIMARY KEY (`touchpoint_id`),
  KEY `snapshot_position` (`snapshot_id`, `touchpoint_position`),
  KEY `click_attribution` (`click_id`, `attribution_credit`),
  FOREIGN KEY (`snapshot_id`) REFERENCES `202_attribution_snapshots` (`snapshot_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Individual touchpoints in attribution journeys';

-- Attribution Settings/Audit Table (if not exists)
CREATE TABLE IF NOT EXISTS `202_attribution_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `model_id` int(11) NOT NULL,
  `setting_name` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `model_setting_unique` (`model_id`, `setting_name`),
  FOREIGN KEY (`model_id`) REFERENCES `202_attribution_models` (`model_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Additional settings and audit data for attribution models';

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