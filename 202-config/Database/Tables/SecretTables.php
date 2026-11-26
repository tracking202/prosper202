<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Secrets that belong to the installation rather than to any user.
 *
 * 202_deployment_secrets holds one row per named secret. The first is the
 * Android install-token key (plan §5.1): the HMAC key the redirect signs
 * `[[p202_install_token]]` with and the install intake verifies it with.
 * It is minted by one idempotent function (InstallTokenKey::ensure()) from
 * both paths that create a 1.9.76 schema — the installer and the
 * 1.9.75 → 1.9.76 rung — because a fresh install never runs a rung.
 * The install's id (Prosper202\Database\InstallId), which names the API
 * state directory, is minted the same way, by the installer and by every
 * rung that reconciles this table (the 1.9.78 → 1.9.79 rung among them).
 *
 * No user owns a row of those, so the user-deletion purge never touches
 * them. The integration secrets beside them are each account's, and are
 * purged with it.
 */
final class SecretTables
{
    /**
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::deploymentSecrets(),
            self::geoipRefresh(),
            self::licenceAnswers(),
            self::egressProxy(),
            self::integrationSecrets(),
            self::integrationSecretEvents(),
        ];
    }

    /**
     * The egress proxy this install's outbound requests go through
     * (Prosper202\Http\EgressProxyStore), set on Account › Settings. One
     * row, proxy_id 1: the proxy URL as http(s)://host:port, and its user
     * name and password, which are never served back. P202_EGRESS_PROXY in
     * 202-config.php overrides it. From 1.9.81, added by its rung.
     */
    public static function egressProxy(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::EGRESS_PROXY,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::EGRESS_PROXY . "` (
                `proxy_id` tinyint(3) unsigned NOT NULL,
                `proxy_url` varchar(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
                `proxy_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
                `proxy_password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
                `updated_at` int(10) unsigned DEFAULT NULL,
                `updated_by` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`proxy_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
              COMMENT='The egress proxy outbound requests go through, and its credentials'"
        );
    }

    /**
     * The GeoIP database refresh (Prosper202\GeoIp\GeoIpRefresh): the
     * MaxMind account ID and licence key it downloads with, stored as the
     * network secrets on 202_users_pref are and never served back, and how
     * its last run ended. One row, refresh_id 1, for the installation: the
     * database file is the install's, not a user's.
     */
    public static function geoipRefresh(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::GEOIP_REFRESH,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::GEOIP_REFRESH . "` (
                `refresh_id` tinyint(3) unsigned NOT NULL,
                `account_id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
                `license_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
                `last_attempt_at` int(10) unsigned DEFAULT NULL,
                `last_outcome` varchar(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `last_error` varchar(1000) DEFAULT NULL,
                `last_checked_at` int(10) unsigned DEFAULT NULL,
                `last_refreshed_at` int(10) unsigned DEFAULT NULL,
                `archive_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `installed_build_epoch` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`refresh_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
              COMMENT='The GeoIP database refresh: MaxMind credentials and the last run'"
        );
    }

    /**
     * ClickServer's last licence answer per key, named by the key's SHA-256:
     * whether the key was valid and whether its licence was paid, each with
     * when it was answered. The grace period reads it while ClickServer gives
     * no answer (Prosper202\License\ShellAccessCache::validWithinGrace()).
     * From 1.9.79, added by its rung.
     */
    public static function licenceAnswers(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LICENCE_ANSWERS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LICENCE_ANSWERS . "` (
                `key_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `valid_yes` tinyint(1) unsigned DEFAULT NULL,
                `valid_at` int(10) unsigned DEFAULT NULL,
                `paid_yes` tinyint(1) unsigned DEFAULT NULL,
                `paid_at` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`key_sha256`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
              COMMENT='The last licence answer per ClickServer key, for the grace period'"
        );
    }

    /**
     * Each account's integration secrets (Prosper202\Security\
     * IntegrationSecretStore): sealed under a data key of the row's own,
     * which is sealed under the key `key_id` names, with the value it
     * replaced while that is still accepted. From 1.9.81, added by its rung;
     * the Shopify webhook secret is the first to move here from
     * 202_users_pref. Purged with the account (UserDataPurge).
     */
    public static function integrationSecrets(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::INTEGRATION_SECRETS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::INTEGRATION_SECRETS . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `secret_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `key_id` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `wrapped_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `sealed` varchar(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `set_at` int(10) unsigned NOT NULL,
                `previous_sealed` varchar(2048) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `previous_expires_at` int(10) unsigned DEFAULT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`user_id`,`secret_name`),
                KEY `secret_name` (`secret_name`),
                KEY `key_id` (`key_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Integration secrets, sealed; never served'"
        );
    }

    /**
     * Every change of an integration secret: set, replaced, rotated (with
     * how long the old one is still accepted), cleared or migrated, who
     * asked and how, and the key it was sealed with. Never the value.
     */
    public static function integrationSecretEvents(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::INTEGRATION_SECRET_EVENTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::INTEGRATION_SECRET_EVENTS . "` (
                `event_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `secret_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `action` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `actor_user_id` mediumint(8) unsigned DEFAULT NULL,
                `via` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `key_id` varchar(40) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `previous_expires_at` int(10) unsigned DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`event_id`),
                KEY `user_secret` (`user_id`,`secret_name`,`event_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Changes of integration secrets; never a value'"
        );
    }

    public static function deploymentSecrets(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DEPLOYMENT_SECRETS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DEPLOYMENT_SECRETS . "` (
                `secret_name` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `secret_value` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`secret_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Installation-wide secrets (the Android install-token key)'"
        );
    }
}
