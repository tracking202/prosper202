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
 * `app_key` is compared byte for byte (utf8mb4_bin): Android application
 * ids are case-sensitive, so com.Example.app and com.example.app are two
 * apps, and the table default collation would fold them into one UNIQUE
 * slot (CLAUDE.md #17).
 *
 * 202_app_installs is the Android signal source's store (plan §5.2–§5.4):
 * one row per install an SDK reported, idempotent on (registration_id,
 * install_uuid), with the referrer it carried, the MatchState it was
 * classified into and the `trusted` bit that state is worth. An attributed,
 * trusted row names its click and the ledger row of the built-in install
 * goal (`conversion_id`). `install_uuid` is ascii_bin: the intake accepts
 * only the canonical lower-case form, and a case-folding collation would
 * let two spellings of one id share a slot. `body_hash` is the fingerprint
 * of the body that created the row, so a replay of the same id with other
 * content is told from a retry (CLAUDE.md #15).
 *
 * Play Integrity (plan §5.6, §5.11): `integrity_mode` on an install is the
 * registration's mode *when the install arrived* — a later change of mode
 * never re-judges an install already received — and the `integrity_*`
 * columns are the verdict worker's queue and its result: the state, why,
 * the attempt count and next attempt, when Google last answered, a summary
 * of the decoded verdict (never the token), and the SHA-256 of the token,
 * so a token already owned by a verified install is refused as a replay
 * without spending quota.
 *
 * 202_app_integrity_credentials holds the operator's Google service
 * account for a registration, AES-256-GCM encrypted under an installation
 * key (202_deployment_secrets) with the registration bound in as associated
 * data, so a ciphertext copied onto another registration does not decrypt.
 * Only the account's email, key id and project are stored in the clear, to
 * be shown; the private key never leaves the ciphertext except to sign.
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
            self::appInstalls(),
            self::appIntegrityCredentials(),
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
                `attribution_window_days` smallint(5) unsigned NOT NULL DEFAULT '7',
                `trust_client_revenue` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `integrity_mode` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'off',
                `integrity_cloud_project_number` bigint(20) unsigned DEFAULT NULL,
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

    public static function appInstalls(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::APP_INSTALLS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::APP_INSTALLS . "` (
                `install_row_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `registration_id` int(10) unsigned NOT NULL,
                `install_uuid` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `body_hash` char(64) NOT NULL,
                `store` varchar(16) NOT NULL,
                `click_id` bigint(20) unsigned DEFAULT NULL,
                `conversion_id` int(11) unsigned DEFAULT NULL,
                `match_state` varchar(20) NOT NULL,
                `match_reason` varchar(255) NOT NULL,
                `trusted` tinyint(1) unsigned DEFAULT NULL,
                `is_test` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `has_events` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `referrer_status` varchar(24) NOT NULL,
                `referrer_raw` varchar(2048) DEFAULT NULL,
                `referrer_truncated` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `utm_source` varchar(255) DEFAULT NULL,
                `utm_medium` varchar(255) DEFAULT NULL,
                `utm_campaign` varchar(255) DEFAULT NULL,
                `utm_term` varchar(255) DEFAULT NULL,
                `utm_content` varchar(255) DEFAULT NULL,
                `gclid` varchar(255) DEFAULT NULL,
                `referrer_click_at` int(10) unsigned DEFAULT NULL,
                `install_begin_at` int(10) unsigned DEFAULT NULL,
                `referrer_click_server_at` int(10) unsigned DEFAULT NULL,
                `install_begin_server_at` int(10) unsigned DEFAULT NULL,
                `install_version` varchar(64) DEFAULT NULL,
                `google_play_instant` tinyint(1) unsigned DEFAULT NULL,
                `app_version` varchar(64) DEFAULT NULL,
                `sdk_version` varchar(32) DEFAULT NULL,
                `os_version` varchar(32) DEFAULT NULL,
                `integrity_mode` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'off',
                `integrity_state` varchar(16) NOT NULL DEFAULT 'not_requested',
                `integrity_reason` varchar(255) DEFAULT NULL,
                `integrity_token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `integrity_attempts` tinyint(3) unsigned NOT NULL DEFAULT '0',
                `integrity_next_at` int(10) unsigned DEFAULT NULL,
                `integrity_checked_at` int(10) unsigned DEFAULT NULL,
                `integrity_verdict` varchar(1024) DEFAULT NULL,
                `first_open_at` int(10) unsigned DEFAULT NULL,
                `received_at` int(10) unsigned NOT NULL,
                `settled_at` int(10) unsigned DEFAULT NULL,
                `raw_payload` text NOT NULL,
                `remote_ip` varchar(45) NOT NULL DEFAULT '',
                PRIMARY KEY (`install_row_id`),
                UNIQUE KEY `registration_install` (`registration_id`,`install_uuid`),
                KEY `click_state` (`click_id`,`match_state`),
                KEY `user_received` (`user_id`,`received_at`),
                KEY `registration_received` (`registration_id`,`received_at`),
                KEY `state_received` (`match_state`,`received_at`),
                KEY `trusted_received` (`trusted`,`received_at`),
                KEY `integrity_due` (`integrity_state`,`integrity_next_at`),
                KEY `registration_integrity_token` (`registration_id`,`integrity_token_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Android installs reported by the SDK: the Play referrer, its MatchState and trust'"
        );
    }

    public static function appIntegrityCredentials(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::APP_INTEGRITY_CREDENTIALS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::APP_INTEGRITY_CREDENTIALS . "` (
                `registration_id` int(10) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `client_email` varchar(255) NOT NULL,
                `private_key_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `project_id` varchar(64) DEFAULT NULL,
                `ciphertext` text CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`registration_id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Play Integrity service-account credentials, one per Android registration, encrypted at rest'"
        );
    }
}
