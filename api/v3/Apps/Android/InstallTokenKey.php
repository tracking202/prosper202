<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * K_install, the key install tokens are signed with (plan §5.1): 32 random
 * bytes, one per installation, kept in 202_deployment_secrets.
 *
 * Minted by one idempotent statement (mintStatement()), run from both
 * paths that create a 1.9.76 schema — INSTALL::install_databases() and the
 * 1.9.75 → 1.9.76 rung — because a fresh install never runs a rung and a key
 * minted only there would leave every new deployment with none. ON
 * DUPLICATE KEY on the primary key makes a second run a no-op: an existing
 * key is never replaced, so the links already in circulation keep
 * verifying.
 *
 * Reading is tri-state (CLAUDE.md #11): a key (32 bytes), null for "no key
 * row", and a throw for a query that failed or a row that is not 64 hex
 * characters. The redirect turns both of the last two into an empty token
 * (the install is then recorded unattributed); the intake answers 503 for a
 * token it cannot verify, so the device retries once the key is back.
 */
final class InstallTokenKey
{
    public const SECRET_NAME = 'android_install_token';

    private function __construct()
    {
    }

    /** The statement that mints the key if it is absent. Contains a fresh random key. */
    public static function mintStatement(): string
    {
        return 'INSERT INTO `' . TableRegistry::DEPLOYMENT_SECRETS . '` (secret_name, secret_value, created_at) VALUES ('
            . "'" . self::SECRET_NAME . "', '" . bin2hex(random_bytes(32)) . "', " . time() . ')'
            . ' ON DUPLICATE KEY UPDATE secret_name = secret_name';
    }

    /** Mint the key on this connection if it is absent; throws when the write fails. */
    public static function ensure(\mysqli $db): void
    {
        try {
            $ok = $db->query(self::mintStatement());
        } catch (\mysqli_sql_exception $e) {
            throw new \RuntimeException('Could not mint the install-token key: ' . $e->getMessage(), 0, $e);
        }
        if ($ok === false) {
            throw new \RuntimeException('Could not mint the install-token key: ' . $db->error);
        }
        if (self::load($db) === null) {
            throw new \RuntimeException('The install-token key was minted but cannot be read back');
        }
    }

    /**
     * The key, or null when none was minted.
     *
     * @throws \RuntimeException when the lookup failed or the stored value is corrupt
     */
    public static function load(\mysqli $db): ?string
    {
        try {
            $conn = new Connection($db);
            $stmt = $conn->prepareWrite('SELECT secret_value FROM `' . TableRegistry::DEPLOYMENT_SECRETS . '` WHERE secret_name = ? LIMIT 1');
            $conn->bind($stmt, 's', [self::SECRET_NAME]);
            $row = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            throw new \RuntimeException('install-token key lookup failed: ' . $e->getMessage(), 0, $e);
        }
        if ($row === null) {
            return null;
        }
        $hex = $row['secret_value'] ?? null;
        if (!is_string($hex) || preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new \RuntimeException('The stored install-token key is not 64 lower-case hex characters');
        }

        return (string) hex2bin($hex);
    }
}
