<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * LTV (customer lifetime value) table definitions: customer identity + CRM,
 * alias resolution, custom fields, the unified revenue ledger, products/line
 * items, subscriptions, and integration/webhook configuration.
 *
 * Keep these definitions in sync with 202-config/migrations/create_ltv_tables.sql
 * and the corresponding block in functions-upgrade.php, which converge existing
 * installs onto the same schema.
 */
final class LtvTables
{
    /**
     * Get all LTV-related table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::customers(),
            self::customerAliases(),
            self::customerFields(),
            self::customerFieldValues(),
            self::revenueEvents(),
            self::products(),
            self::revenueLineItems(),
            self::subscriptions(),
            self::ltvIntegrations(),
            self::ltvWebhooks(),
            self::ltvWebhookDeliveries(),
            self::personalizationTokens(),
            self::offerTransitions(),
        ];
    }

    /**
     * Account-scoped offer-transition counts ("customers who converted on A
     * later converted on B"), rebuilt from the revenue ledger by the
     * ltv_maintenance cronjob. Drives the deterministic next-offer
     * recommendation.
     */
    public static function offerTransitions(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::OFFER_TRANSITIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::OFFER_TRANSITIONS . "` (
                `transition_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `from_campaign_id` mediumint(8) unsigned NOT NULL,
                `to_campaign_id` mediumint(8) unsigned NOT NULL,
                `transition_count` int(10) unsigned NOT NULL DEFAULT '0',
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`transition_id`),
                UNIQUE KEY `uniq_transition` (`user_id`,`from_campaign_id`,`to_campaign_id`),
                KEY `from_lookup` (`user_id`,`from_campaign_id`,`transition_count`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Landing-page personalization tokens: random bearer capabilities minted
     * at redirect time for cookie-verified customers. The raw token is never
     * stored — only its SHA-256. On first redemption the response payload is
     * sealed into `snapshot`; replays return the snapshot verbatim until
     * replay_until, so a leaked token can never reveal anything beyond what
     * the visitor already saw.
     */
    public static function personalizationTokens(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::PERSONALIZATION_TOKENS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::PERSONALIZATION_TOKENS . "` (
                `p13n_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `token_hash` binary(32) NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `customer_id` bigint(20) unsigned NOT NULL,
                `click_id` bigint(20) unsigned DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `first_use_deadline` int(10) unsigned NOT NULL,
                `replay_until` int(10) unsigned NOT NULL,
                `redeemed_at` int(10) unsigned DEFAULT NULL,
                `snapshot` text DEFAULT NULL,
                PRIMARY KEY (`p13n_id`),
                UNIQUE KEY `uniq_token_hash` (`token_hash`),
                KEY `customer_lookup` (`customer_id`),
                KEY `purge_sweep` (`replay_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Customer identity + CRM fields + cached rollups.
     *
     * The rollup columns (order_count, total_revenue, refunded_amount,
     * active_subscription_count, mrr) are a derived cache of 202_revenue_events
     * — bumped transactionally on ingest for freshness and reconciled from the
     * ledger by the ltv_maintenance cronjob. The ledger is the source of truth.
     */
    public static function customers(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CUSTOMERS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CUSTOMERS . "` (
                `customer_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `merged_into_customer_id` bigint(20) unsigned DEFAULT NULL,
                `primary_ref` varchar(255) NOT NULL,
                `first_name` varchar(100) DEFAULT NULL,
                `last_name` varchar(100) DEFAULT NULL,
                `email` varchar(255) DEFAULT NULL,
                `email_hash` binary(32) DEFAULT NULL,
                `phone` varchar(50) DEFAULT NULL,
                `company` varchar(255) DEFAULT NULL,
                `address_line1` varchar(255) DEFAULT NULL,
                `address_line2` varchar(255) DEFAULT NULL,
                `city` varchar(100) DEFAULT NULL,
                `region` varchar(100) DEFAULT NULL,
                `postal_code` varchar(20) DEFAULT NULL,
                `country` char(2) DEFAULT NULL,
                `first_seen_time` int(10) unsigned NOT NULL,
                `last_activity_time` int(10) unsigned NOT NULL,
                `first_click_id` bigint(20) unsigned DEFAULT NULL,
                `order_count` int(10) unsigned NOT NULL DEFAULT '0',
                `total_revenue` decimal(16,5) NOT NULL DEFAULT '0.00000',
                `refunded_amount` decimal(16,5) NOT NULL DEFAULT '0.00000',
                `active_subscription_count` int(10) unsigned NOT NULL DEFAULT '0',
                `mrr` decimal(14,5) NOT NULL DEFAULT '0.00000',
                `status` varchar(20) NOT NULL DEFAULT 'active',
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`customer_id`),
                KEY `user_email_hash` (`user_id`,`email_hash`),
                KEY `user_last_activity` (`user_id`,`last_activity_time`),
                KEY `user_first_click` (`user_id`,`first_click_id`),
                KEY `merged_into` (`merged_into_customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Resolution index: many external identifiers map to one internal customer.
     * alias_hash is SHA-256 of alias_value so the unique key stays inside the
     * InnoDB index-size limit regardless of value length or charset.
     */
    public static function customerAliases(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CUSTOMER_ALIASES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CUSTOMER_ALIASES . "` (
                `alias_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `customer_id` bigint(20) unsigned NOT NULL,
                `alias_type` varchar(50) NOT NULL,
                `alias_value` varchar(255) NOT NULL,
                `alias_hash` binary(32) NOT NULL,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`alias_id`),
                UNIQUE KEY `uniq_user_alias` (`user_id`,`alias_type`,`alias_hash`),
                KEY `customer_lookup` (`customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * User-defined custom field definitions for customers.
     */
    public static function customerFields(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CUSTOMER_FIELDS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CUSTOMER_FIELDS . "` (
                `field_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `field_key` varchar(64) NOT NULL,
                `label` varchar(255) NOT NULL,
                `field_type` enum('text','number','date','boolean','select','email','url') NOT NULL DEFAULT 'text',
                `options` json DEFAULT NULL,
                `is_required` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `sort_order` int(10) unsigned NOT NULL DEFAULT '0',
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`field_id`),
                UNIQUE KEY `uniq_user_field_key` (`user_id`,`field_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Typed custom field values (one row per customer per field). Number and
     * date filters are index-backed; text filters scan within the
     * (user_id, field_id) slice.
     */
    public static function customerFieldValues(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CUSTOMER_FIELD_VALUES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CUSTOMER_FIELD_VALUES . "` (
                `value_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `customer_id` bigint(20) unsigned NOT NULL,
                `field_id` bigint(20) unsigned NOT NULL,
                `value_text` varchar(1000) DEFAULT NULL,
                `value_number` decimal(20,5) DEFAULT NULL,
                `value_date` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`value_id`),
                UNIQUE KEY `uniq_customer_field` (`customer_id`,`field_id`),
                KEY `user_field_number` (`user_id`,`field_id`,`value_number`),
                KEY `user_field_date` (`user_id`,`field_id`,`value_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Unified revenue ledger — the single source of truth for money.
     *
     * Idempotency is per source: conversion-sourced events are unique per
     * conv_id (one purchase event per conversion; upstream dedup on
     * (click_id, transaction_id) already gates replays); api/import events
     * dedup on the caller-supplied idempotency_key. MySQL unique keys ignore
     * NULLs, so rows without the respective key are unaffected.
     */
    public static function revenueEvents(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::REVENUE_EVENTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::REVENUE_EVENTS . "` (
                `event_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `customer_id` bigint(20) unsigned NOT NULL,
                `event_type` enum('purchase','renewal','one_time','refund','chargeback','adjustment') NOT NULL,
                `amount` decimal(16,5) NOT NULL,
                `currency` char(3) NOT NULL DEFAULT 'USD',
                `occurred_at` int(10) unsigned NOT NULL,
                `source` enum('conversion','subscription','api','import') NOT NULL,
                `conv_id` int(11) unsigned DEFAULT NULL,
                `subscription_id` bigint(20) unsigned DEFAULT NULL,
                `click_id` bigint(20) unsigned DEFAULT NULL,
                `external_ref` varchar(255) DEFAULT NULL,
                `transaction_id` varchar(255) DEFAULT NULL,
                `idempotency_key` varchar(191) DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`event_id`),
                UNIQUE KEY `uniq_conv` (`conv_id`),
                UNIQUE KEY `uniq_user_idempotency` (`user_id`,`idempotency_key`),
                KEY `user_customer_time` (`user_id`,`customer_id`,`occurred_at`),
                KEY `user_time` (`user_id`,`occurred_at`),
                KEY `subscription_lookup` (`subscription_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Product catalog, upserted on ingest (external_product_id is the
     * merchant-side id, e.g. a Shopify variant id).
     */
    public static function products(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::PRODUCTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::PRODUCTS . "` (
                `product_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `external_product_id` varchar(191) NOT NULL,
                `sku` varchar(191) DEFAULT NULL,
                `name` varchar(255) DEFAULT NULL,
                `price` decimal(14,5) DEFAULT NULL,
                `currency` char(3) NOT NULL DEFAULT 'USD',
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`product_id`),
                UNIQUE KEY `uniq_user_external_product` (`user_id`,`external_product_id`),
                KEY `user_sku` (`user_id`,`sku`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Per-order product line items, attached to ledger events. product_name is
     * a snapshot at sale time — later catalog renames must not rewrite history.
     */
    public static function revenueLineItems(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::REVENUE_LINE_ITEMS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::REVENUE_LINE_ITEMS . "` (
                `line_item_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `event_id` bigint(20) unsigned NOT NULL,
                `product_id` bigint(20) unsigned DEFAULT NULL,
                `sku` varchar(191) DEFAULT NULL,
                `product_name` varchar(255) DEFAULT NULL,
                `quantity` decimal(12,3) NOT NULL DEFAULT '1.000',
                `unit_price` decimal(14,5) DEFAULT NULL,
                `amount` decimal(16,5) NOT NULL DEFAULT '0.00000',
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`line_item_id`),
                KEY `event_lookup` (`event_id`),
                KEY `user_product` (`user_id`,`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Subscription lifecycle. mrr is the amount normalized to a monthly figure
     * at write time. billing_interval is deliberately not named `interval`
     * (MySQL reserved word).
     */
    public static function subscriptions(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SUBSCRIPTIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SUBSCRIPTIONS . "` (
                `subscription_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `customer_id` bigint(20) unsigned NOT NULL,
                `external_sub_id` varchar(191) NOT NULL,
                `plan_name` varchar(255) DEFAULT NULL,
                `amount` decimal(14,5) NOT NULL DEFAULT '0.00000',
                `currency` char(3) NOT NULL DEFAULT 'USD',
                `billing_interval` enum('day','week','month','year') NOT NULL DEFAULT 'month',
                `billing_interval_count` int(10) unsigned NOT NULL DEFAULT '1',
                `status` enum('trialing','active','past_due','paused','canceled') NOT NULL DEFAULT 'active',
                `mrr` decimal(14,5) NOT NULL DEFAULT '0.00000',
                `started_at` int(10) unsigned NOT NULL,
                `current_period_start` int(10) unsigned NOT NULL,
                `current_period_end` int(10) unsigned NOT NULL,
                `grace_days` int(10) unsigned NOT NULL DEFAULT '3',
                `canceled_at` int(10) unsigned DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`subscription_id`),
                UNIQUE KEY `uniq_user_external_sub` (`user_id`,`external_sub_id`),
                KEY `user_status` (`user_id`,`status`),
                KEY `customer_lookup` (`customer_id`),
                KEY `period_end_sweep` (`status`,`current_period_end`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Connected 3rd-party providers (ESP, membership, billing). config holds
     * the field/hash mapping JSON (e.g. which alias_type the provider sends).
     */
    public static function ltvIntegrations(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LTV_INTEGRATIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LTV_INTEGRATIONS . "` (
                `integration_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `provider` varchar(50) NOT NULL,
                `name` varchar(255) NOT NULL,
                `config` json DEFAULT NULL,
                `api_key_id` varchar(250) DEFAULT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'active',
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`integration_id`),
                KEY `user_provider` (`user_id`,`provider`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Outbound webhook endpoints. Payloads are signed HMAC-SHA256 with
     * webhook_secret; URLs are validated against SSRF (https only, no
     * private/loopback/link-local hosts) at registration and dispatch.
     */
    public static function ltvWebhooks(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LTV_WEBHOOKS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LTV_WEBHOOKS . "` (
                `webhook_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `webhook_url` varchar(500) NOT NULL,
                `webhook_secret` varchar(255) NOT NULL,
                `webhook_headers` text DEFAULT NULL,
                `subscribed_events` varchar(500) NOT NULL DEFAULT '',
                `status` enum('active','disabled','dead') NOT NULL DEFAULT 'active',
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`webhook_id`),
                KEY `user_status` (`user_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Outbound webhook delivery queue, dispatched by the ltv_webhooks cronjob
     * (never inline in ingest). Retries with exponential backoff; after the
     * final attempt the delivery is marked failed and the webhook may be
     * marked dead. Response bodies are stored truncated.
     */
    public static function ltvWebhookDeliveries(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LTV_WEBHOOK_DELIVERIES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LTV_WEBHOOK_DELIVERIES . "` (
                `delivery_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `webhook_id` bigint(20) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `event_name` varchar(100) NOT NULL,
                `payload` mediumtext NOT NULL,
                `status` enum('pending','delivered','failed') NOT NULL DEFAULT 'pending',
                `attempts` int(10) unsigned NOT NULL DEFAULT '0',
                `next_attempt_at` int(10) unsigned NOT NULL DEFAULT '0',
                `last_status_code` int(11) DEFAULT NULL,
                `last_response_body` varchar(1000) DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`delivery_id`),
                KEY `dispatch_queue` (`status`,`next_attempt_at`),
                KEY `webhook_lookup` (`webhook_id`),
                KEY `user_lookup` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
