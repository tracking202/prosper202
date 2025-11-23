START TRANSACTION;

ALTER TABLE `202_attribution_settings`
    ADD COLUMN IF NOT EXISTS `multi_touch_enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT '1' AFTER `model_id`,
    ADD COLUMN IF NOT EXISTS `multi_touch_enabled_at` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `multi_touch_enabled`,
    ADD COLUMN IF NOT EXISTS `multi_touch_disabled_at` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `multi_touch_enabled_at`;

CREATE UNIQUE INDEX IF NOT EXISTS `user_scope_model`
    ON `202_attribution_settings` (`user_id`, `scope_type`, `scope_id`, `model_id`);

CREATE UNIQUE INDEX IF NOT EXISTS `user_scope_multi_touch`
    ON `202_attribution_settings` (`user_id`, `scope_type`, `scope_id`, `multi_touch_enabled`);

UPDATE `202_attribution_settings`
SET `multi_touch_enabled` = COALESCE(`multi_touch_enabled`, 1),
    `multi_touch_enabled_at` = CASE
        WHEN `multi_touch_enabled` = 1 AND `multi_touch_enabled_at` IS NULL THEN `created_at`
        ELSE `multi_touch_enabled_at`
    END,
    `multi_touch_disabled_at` = CASE
        WHEN `multi_touch_enabled` = 0 AND `multi_touch_disabled_at` IS NULL THEN `updated_at`
        ELSE `multi_touch_disabled_at`
    END;

COMMIT;
