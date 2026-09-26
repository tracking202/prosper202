<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Multi-touch attribution (plan §6.3–6.4): models, the journey each
 * conversion was built from, and the credit every model gives each touch.
 *
 * The conversion ledger and its outbox (202_attribution_pending) are
 * ConversionTables; the identity graph a journey is read from is
 * IdentityTables. Nothing here is written on the conversion path: the
 * attribution worker (Prosper202\Attribution\AttributionWorker) owns every
 * row below except the models, which the API writes.
 */
final class AttributionTables
{
    /**
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::attributionModels(),
            self::attributionJourneys(),
            self::attributionJourneyMeta(),
            self::attributionCredits(),
            self::attributionAudit(),
            self::attributionExports(),
            self::attributionRollup(),
            self::attributionRollupState(),
            self::attributionRollupOverrides(),
            self::attributionRollupDirty(),
            self::attributionRollupDirtyClicks(),
        ];
    }

    /**
     * One row per model an account defined.
     *
     * - model_type is the enum Prosper202\Attribution\ModelType, and nothing
     *   else: the column refuses any other value.
     * - weighting_config is JSON validated by ModelConfig on write and on
     *   load; a stored config that fails is marked status='invalid' with the
     *   reason, and that model alone stops computing.
     * - is_default is 1 or NULL, never 0, so UNIQUE (user_id, is_default)
     *   holds at most one default per account while any number of rows are
     *   not the default.
     * - recompute_requested_at / recompute_cursor: a change to what the model
     *   computes asks the worker to re-derive its credits, which it fans out
     *   in batches from the cursor.
     */
    public static function attributionModels(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_MODELS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_MODELS . "` (
                `model_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `model_name` varchar(255) NOT NULL,
                `model_slug` varchar(191) NOT NULL,
                `model_type` enum('last_touch','first_touch','linear','time_decay','position_based') NOT NULL,
                `weighting_config` text NOT NULL,
                `lookback_days` smallint(5) unsigned NOT NULL DEFAULT '30',
                `status` enum('active','inactive','invalid') NOT NULL DEFAULT 'active',
                `status_reason` varchar(255) DEFAULT NULL,
                `is_default` tinyint(1) unsigned DEFAULT NULL,
                `recompute_requested_at` int(10) unsigned DEFAULT NULL,
                `recompute_cursor` int(11) unsigned NOT NULL DEFAULT '0',
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`model_id`),
                UNIQUE KEY `model_slug_user` (`user_id`,`model_slug`),
                UNIQUE KEY `one_default` (`user_id`,`is_default`),
                KEY `recompute` (`recompute_requested_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The touches a conversion's credit was computed from, oldest first; the
     * converting click is always the last position. Built once per
     * conversion (not per model) at the journey lookback, and rewritten with
     * the credits in one transaction.
     */
    public static function attributionJourneys(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_JOURNEYS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_JOURNEYS . "` (
                `conv_id` int(11) unsigned NOT NULL,
                `position` smallint(5) unsigned NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                `click_time` int(10) unsigned NOT NULL,
                PRIMARY KEY (`conv_id`,`position`),
                KEY `click_id` (`click_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * What each stored journey was built under: the lookback it covers (a
     * model reading wider than this forces a rebuild), whether the touch cap
     * cut it, and whether the converting click had a visitor key at all (a
     * click without one is a one-touch journey by construction, and the
     * reports say so).
     */
    public static function attributionJourneyMeta(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_JOURNEY_META,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_JOURNEY_META . "` (
                `conv_id` int(11) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `conv_time` int(10) unsigned NOT NULL,
                `touches` smallint(5) unsigned NOT NULL,
                `built_lookback_days` smallint(5) unsigned NOT NULL,
                `built_at` int(10) unsigned NOT NULL,
                `truncated` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `identified` tinyint(1) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`conv_id`),
                KEY `user_conv_time` (`user_id`,`conv_time`),
                KEY `user_lookback` (`user_id`,`built_lookback_days`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Per-conversion, per-model credit. A conversion's credits under one
     * model sum to exactly 1 and its revenue sums to exactly the
     * conversion's counted amount; the rounding remainder goes to the last
     * touch. conv_time is carried so a report reads a date range from the
     * index instead of joining back to the ledger for it.
     */
    public static function attributionCredits(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_CREDITS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_CREDITS . "` (
                `conv_id` int(11) unsigned NOT NULL,
                `model_id` bigint(20) unsigned NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                `position` smallint(5) unsigned NOT NULL,
                `credit` decimal(9,8) NOT NULL,
                `revenue` decimal(11,5) NOT NULL,
                `conv_time` int(10) unsigned NOT NULL,
                PRIMARY KEY (`conv_id`,`model_id`,`click_id`),
                KEY `model_click` (`model_id`,`click_id`),
                KEY `model_conv_time` (`model_id`,`conv_time`)
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

    /**
     * Export jobs: one column set (the 1.9.56 and 1.9.58 rungs used to define
     * two that disagreed). The pipeline that fills and sends them is PR 10;
     * the shape is fixed here so the schema is final in one release.
     */
    public static function attributionExports(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_EXPORTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_EXPORTS . "` (
                `export_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `model_id` bigint(20) unsigned NOT NULL,
                `compare_model_id` bigint(20) unsigned DEFAULT NULL,
                `group_by` varchar(32) NOT NULL,
                `range_start` int(10) unsigned NOT NULL,
                `range_end` int(10) unsigned NOT NULL,
                `status` enum('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
                `file_path` varchar(500) DEFAULT NULL,
                `rows_exported` int(11) unsigned DEFAULT NULL,
                `webhook_url` varchar(500) DEFAULT NULL,
                `webhook_secret` varchar(255) DEFAULT NULL,
                `webhook_status_code` smallint(5) unsigned DEFAULT NULL,
                `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0',
                `last_error` text DEFAULT NULL,
                `queued_at` int(10) unsigned NOT NULL,
                `started_at` int(10) unsigned DEFAULT NULL,
                `completed_at` int(10) unsigned DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`export_id`),
                KEY `user_status` (`user_id`,`status`),
                KEY `status_queued` (`status`,`queued_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The report rollup (plan §7.3, PR 13): what AttributionReports sums,
     * pre-summed per account, part, dimension, model and time bucket, so a
     * breakdown reads thousands of rows instead of joining millions.
     *
     * - part: 1 credits (Σ credit, Σ revenue, by conv_time), 2 cost (clicks,
     *   Σ click_cpc, by click_time), 3 assists (distinct conversions, by
     *   conv_time), 4 totals (distinct conversions, Σ credit, Σ revenue);
     * - dim: AttributionRollup::DIMENSION_CODES; 0 for totals;
     * - model_id: the model the credits are under, 0 for "each
     *   conversion's effective model" and for the parts no model shapes;
     * - grain 0 is an hour (bucket = unix time DIV 3600), grain 1 a UTC day
     *   (bucket = DIV 86400) summed from its 24 hours;
     * - key_null/dim_key: the dimension value, with a NULL value (no
     *   clicks_advance row, say) kept apart from a real 0 so the report can
     *   still tell whether a group had a name to look up.
     *
     * Names are not stored: a campaign renamed after its hour was summed
     * would otherwise keep its old name. The rows are only ever read for
     * hours AttributionRollup says are built and clean.
     */
    public static function attributionRollup(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_ROLLUP,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_ROLLUP . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `part` tinyint(3) unsigned NOT NULL,
                `dim` tinyint(3) unsigned NOT NULL,
                `model_id` bigint(20) unsigned NOT NULL,
                `grain` tinyint(3) unsigned NOT NULL,
                `bucket` int(10) unsigned NOT NULL,
                `key_null` tinyint(1) unsigned NOT NULL,
                `dim_key` bigint(20) unsigned NOT NULL,
                `n` bigint(20) unsigned NOT NULL,
                `credit` decimal(30,8) NOT NULL,
                `revenue` decimal(30,5) NOT NULL,
                `cost` decimal(30,5) NOT NULL,
                PRIMARY KEY (`user_id`,`part`,`dim`,`model_id`,`grain`,`bucket`,`key_null`,`dim_key`),
                KEY `user_bucket` (`user_id`,`grain`,`bucket`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * One row per account the rollup covers: every hour below
     * built_through_hour has been summed (an hour with no data has no
     * rows), and default_model_id is the default the effective rows were
     * summed under.
     */
    public static function attributionRollupState(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_ROLLUP_STATE,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_ROLLUP_STATE . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `built_through_hour` int(10) unsigned NOT NULL DEFAULT '0',
                `default_model_id` bigint(20) unsigned DEFAULT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The per-campaign model overrides the effective rows were summed under:
     * every campaign whose attribution_model_id names an active model of the
     * account. A report compares this with the live overrides and reads the
     * effective rows only when the two sets are equal.
     */
    public static function attributionRollupOverrides(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_ROLLUP_OVERRIDES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_ROLLUP_OVERRIDES . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `campaign_id` int(10) unsigned NOT NULL,
                `model_id` bigint(20) unsigned NOT NULL,
                PRIMARY KEY (`user_id`,`campaign_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Hours whose sums are stale: a range of hours an account's data
     * changed in, written in the same transaction as the change (the
     * worker's credit rewrites, a CPC update, the rollup's own resolution
     * of a changed click). A report computes a dirty hour exactly; the
     * rollup re-sums it and deletes the row.
     */
    public static function attributionRollupDirty(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_ROLLUP_DIRTY,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_ROLLUP_DIRTY . "` (
                `dirty_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `hour_from` int(10) unsigned NOT NULL,
                `hour_to` int(10) unsigned NOT NULL,
                PRIMARY KEY (`dirty_id`),
                KEY `user_hour` (`user_id`,`hour_from`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Clicks changed after the fact (a rotator re-click rewriting its click,
     * a CPC set on one click). A click's change reaches the hours of every
     * conversion whose journey holds it, which the writer does not look up:
     * the rollup resolves the click into dirty hours, and until it has, the
     * account's reports are computed exactly.
     */
    public static function attributionRollupDirtyClicks(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_ROLLUP_DIRTY_CLICKS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_ROLLUP_DIRTY_CLICKS . "` (
                `dirty_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                PRIMARY KEY (`dirty_id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
