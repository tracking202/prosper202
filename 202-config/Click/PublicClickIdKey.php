<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The key cloaked clicks' public ids are made with (PublicClickId): 32
 * random bytes per installation, in 202_deployment_secrets as
 * `click_public_id`, minted on first use by one statement that never
 * replaces a key already there, so ids already handed out keep verifying.
 * A key of its own rather than the Android install-token key, so the edge
 * can be given this one and nothing else.
 *
 * Reading is tri-state (CLAUDE.md #11): a key, null for "no row", a throw
 * for a failed read or a row that is not 64 lower-case hex characters.
 */
final class PublicClickIdKey
{
    public const SECRET_NAME = 'click_public_id';

    /** @var \WeakMap<\mysqli, string>|null */
    private static ?\WeakMap $loaded = null;

    private function __construct()
    {
    }

    /**
     * A new click's public id. A key that cannot be had (the table missing
     * between a deploy and its upgrade, a failed read) gives an id with a
     * random suffix, logged: the visitor goes on, and the id is as hard to
     * guess and resolves the same way.
     */
    public static function forNewClick(\mysqli $db, int $clickId): string
    {
        try {
            $key = self::ensure($db);
        } catch (\Throwable $e) {
            error_log('Prosper202 click ' . $clickId . ': the click public id key could not be read, so its public id'
                . ' has a random suffix: ' . $e->getMessage());

            return PublicClickId::issueUnkeyed($clickId);
        }

        return PublicClickId::issue($clickId, $key);
    }

    /**
     * The key, minted if there is none.
     *
     * @throws \RuntimeException when it cannot be read, minted or read back
     */
    public static function ensure(\mysqli $db): string
    {
        self::$loaded ??= new \WeakMap();
        if (isset(self::$loaded[$db])) {
            return self::$loaded[$db];
        }
        $key = self::load($db);
        if ($key === null) {
            try {
                $conn = new Connection($db);
                $stmt = $conn->prepareWrite(
                    'INSERT INTO `' . TableRegistry::DEPLOYMENT_SECRETS . '`'
                    . ' (secret_name, secret_value, created_at) VALUES (?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE secret_name = secret_name'
                );
                $conn->bind($stmt, 'ssi', [self::SECRET_NAME, bin2hex(random_bytes(32)), time()]);
                $conn->executeUpdate($stmt);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not mint the click public id key: ' . $e->getMessage(), 0, $e);
            }
            $key = self::load($db)
                ?? throw new \RuntimeException('The click public id key was minted but cannot be read back');
        }
        self::$loaded[$db] = $key;

        return $key;
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
            $stmt = $conn->prepareWrite(
                'SELECT secret_value FROM `' . TableRegistry::DEPLOYMENT_SECRETS . '` WHERE secret_name = ? LIMIT 1'
            );
            $conn->bind($stmt, 's', [self::SECRET_NAME]);
            $row = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            throw new \RuntimeException('click public id key lookup failed: ' . $e->getMessage(), 0, $e);
        }
        if ($row === null) {
            return null;
        }
        $hex = $row['secret_value'] ?? null;
        if (!is_string($hex) || preg_match('/\A[0-9a-f]{64}\z/D', $hex) !== 1) {
            throw new \RuntimeException('The stored click public id key is not 64 lower-case hex characters');
        }

        return (string) hex2bin($hex);
    }
}
