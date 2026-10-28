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
 *
 * No user owns a row here, so the user-deletion purge never touches it.
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
        ];
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
