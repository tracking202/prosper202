<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * App measurement table definitions.
 *
 * 202_app_registrations is the registry both platforms share: one row per
 * app, identified by (platform, app_key) — the App Store item id as
 * canonical decimal for iOS, the application id (package name) for Android.
 * `registration_id` is the key every other app table links through; nothing
 * links to a registration by a raw app id.
 *
 * 202_app_postbacks is the Apple signal source's store. Devices POST signed
 * SKAdNetwork and AdAttributionKit postbacks to the /.well-known/ endpoints
 * and every accepted one lands here with its `protocol` stamped, the
 * registration that claimed it (NULL while nobody has), the verifier's
 * `signature_state` and the `trusted` bit the registration's policy derives
 * from it (1 trusted, 0 refuted, NULL unvouched). The raw `app_id` stays as
 * the forensic record of what the postback named.
 *
 * 202_app_skan_encodings says which 6-bit fine or low/medium/high coarse
 * conversion value means which goal was reached (202_goals, plan §4.5), per
 * registration; registration_id = 0 is the account-wide set. What the goal
 * is worth comes from the goal; `revenue_override` keeps tiered decoding
 * (two values meaning one goal at two prices). registration_id can never
 * be a real registration (AUTO_INCREMENT starts at 1) and is NOT NULL on
 * purpose: UNIQUE admits any number of NULLs, so a NULL "account-wide"
 * would let duplicate account-wide rules in.
 *
 * An encoding is versioned (plan §5.5): a device applies the document it
 * fetched, and a postback arrives up to 35 days later, so the report has to
 * decode with every meaning a value had inside that horizon.
 * 202_app_skan_encodings holds each encoding's CURRENT meaning, in force
 * since `effective_at`; every edit and every delete first copies the meaning
 * it replaces into 202_app_skan_encoding_history with the time it stopped
 * applying (`retired_at`). The history is never edited and never deleted
 * except by the user purge. An `(effective_at, retired_at)` pair is a
 * half-open span, so a meaning replaced in the same second as it began
 * applied at no instant.
 *
 * `app_key` is compared byte for byte (utf8mb4_bin): Android application
 * ids are case-sensitive, so com.Example.app and com.example.app are two
 * apps, and the table default collation would fold them into one UNIQUE
 * slot (CLAUDE.md #17).
 */
final class AppTables
{
    /**
     * Get all app-measurement table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::appRegistrations(),
            self::appPostbacks(),
            self::appSkanEncodings(),
            self::appSkanEncodingHistory(),
        ];
    }

    public static function appRegistrations(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::APP_REGISTRATIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::APP_REGISTRATIONS . "` (
                `registration_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `platform` varchar(16) NOT NULL,
                `app_key` varchar(255) COLLATE utf8mb4_bin NOT NULL,
                `app_name` varchar(255) NOT NULL,
                `notes` varchar(500) DEFAULT NULL,
                `accept_test_signals` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `app_token` varchar(64) NOT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`registration_id`),
                UNIQUE KEY `platform_app_key` (`platform`,`app_key`),
                UNIQUE KEY `app_token` (`app_token`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Registered apps (iOS and Android), one per (platform, app_key), and their measurement policy'"
        );
    }

    public static function appPostbacks(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::APP_POSTBACKS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::APP_POSTBACKS . "` (
                `postback_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                `registration_id` int(10) unsigned DEFAULT NULL,
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
                `trusted` tinyint(1) unsigned DEFAULT NULL,
                `key_id` varchar(64) DEFAULT NULL,
                `dedupe_hash` char(40) NOT NULL,
                `raw_payload` text NOT NULL,
                `remote_ip` varchar(45) NOT NULL DEFAULT '',
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`postback_id`),
                UNIQUE KEY `dedupe_hash` (`dedupe_hash`),
                KEY `user_received` (`user_id`,`received_at`),
                KEY `user_protocol` (`user_id`,`protocol`),
                KEY `user_registration` (`user_id`,`registration_id`),
                KEY `user_app` (`user_id`,`app_id`),
                KEY `user_ad_network` (`user_id`,`ad_network_id`),
                KEY `registration_state` (`registration_id`,`signature_state`),
                KEY `transaction` (`transaction_id`),
                KEY `trusted_received` (`trusted`,`received_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Platform-signed app postbacks (SKAdNetwork, AdAttributionKit) received from Apple devices'"
        );
    }

    public static function appSkanEncodings(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::APP_SKAN_ENCODINGS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::APP_SKAN_ENCODINGS . "` (
                `encoding_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `registration_id` int(10) unsigned NOT NULL DEFAULT '0',
                `fine_value` tinyint(3) unsigned DEFAULT NULL,
                `coarse_value` varchar(6) DEFAULT NULL,
                `goal_id` int(10) unsigned NOT NULL,
                `revenue_override` decimal(11,5) DEFAULT NULL,
                `effective_at` int(10) unsigned NOT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`encoding_id`),
                UNIQUE KEY `user_registration_fine` (`user_id`,`registration_id`,`fine_value`),
                UNIQUE KEY `user_registration_coarse` (`user_id`,`registration_id`,`coarse_value`),
                KEY `registration_id` (`registration_id`),
                KEY `goal_id` (`goal_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='SKAdNetwork/AdAttributionKit conversion values: which value means which goal was reached, per registration (0 = account-wide)'"
        );
    }

    public static function appSkanEncodingHistory(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::APP_SKAN_ENCODING_HISTORY,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::APP_SKAN_ENCODING_HISTORY . "` (
                `history_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `encoding_id` int(10) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `registration_id` int(10) unsigned NOT NULL DEFAULT '0',
                `fine_value` tinyint(3) unsigned DEFAULT NULL,
                `coarse_value` varchar(6) DEFAULT NULL,
                `goal_id` int(10) unsigned NOT NULL,
                `revenue_override` decimal(11,5) DEFAULT NULL,
                `effective_at` int(10) unsigned NOT NULL,
                `retired_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`history_id`),
                KEY `user_retired` (`user_id`,`retired_at`),
                KEY `encoding_id` (`encoding_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='What each SKAN encoding meant before an edit or delete, and until when (decoded within the 35-day postback horizon)'"
        );
    }
}
