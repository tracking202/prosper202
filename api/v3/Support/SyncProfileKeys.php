<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\SyncException;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The API keys of a queued sync job's two profiles, sealed while the job
 * waits in the state directory (ServerStateStore), and gone once it ends.
 *
 * A job is a file in a temp directory, kept after it runs, and each holds
 * the keys of two installs: they were stored as sent. Each is now
 * AES-256-GCM under a random 32-byte key kept in 202_deployment_secrets
 * (`sync_profile_api_key`), minted on first use by one statement that never
 * replaces an existing key, as the Play Integrity credentials are
 * (IntegrityCredentialStore). Stored as `api_key_sealed`:
 * `v1.` + base64(nonce ‖ tag ‖ ciphertext), with the side and the profile's
 * url as associated data, so a sealed key moved to another url in the file
 * does not open, and is never sent there.
 *
 * What this protects: the key is in the database, so whoever holds both
 * the database and the state directory holds the keys. What it stops is a
 * key read from the state directory alone: a backup of it, another account
 * on a shared temp directory, a support bundle. A finished job keeps no key
 * at all (forget()).
 *
 * A job queued before keys were sealed holds `api_key` as it was sent; it is
 * read as written (CLAUDE.md #25) and sealed by its run.
 */
final class SyncProfileKeys
{
    public const SECRET_NAME = 'sync_profile_api_key';
    public const SEALED = 'api_key_sealed';
    private const AAD = 'p202-sync-api-key-v1|';
    private const CIPHER = 'aes-256-gcm';

    private function __construct(private readonly ?Connection $conn, private ?string $key)
    {
    }

    public static function fromDatabase(\mysqli $db): self
    {
        return new self(new Connection($db), null);
    }

    /** With a key of its own, never the database's: for tests. */
    public static function withKey(#[\SensitiveParameter] string $key): self
    {
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException('A sync key is 32 bytes');
        }

        return new self(null, $key);
    }

    /**
     * The profile as a job stores it: its api_key sealed, bound to the side
     * and the url. A profile with no key is stored with none.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function seal(array $profile, string $side): array
    {
        $plain = (string) ($profile['api_key'] ?? '');
        unset($profile['api_key'], $profile[self::SEALED]);
        if ($plain === '') {
            return $profile;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $key = $this->key(true) ?? throw new \RuntimeException('There is no key to seal a sync API key with');
        $aad = self::aad($side, $profile);
        $ciphertext = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Encrypting a sync profile\'s API key failed');
        }
        $profile[self::SEALED] = 'v1.' . base64_encode($nonce . $tag . $ciphertext);

        return $profile;
    }

    /**
     * The profile with the api_key to call it with: opened from a sealed
     * one, or as stored by a job queued before keys were sealed.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     * @throws SyncException for a sealed key that does not open
     */
    public function open(array $profile, string $side): array
    {
        $again = ' Queue the sync again with the profiles\' API keys.';
        $whose = 'The ' . $side . ' profile\'s';
        if (!array_key_exists(self::SEALED, $profile)) {
            if (!self::holdsPlainKey($profile)) {
                throw SyncException::keyUnreadable('The job holds no API key for its ' . $side . ' profile.' . $again);
            }

            return $profile;
        }
        $sealed = $profile[self::SEALED];
        unset($profile[self::SEALED]);
        $raw = is_string($sealed) && str_starts_with($sealed, 'v1.') ? base64_decode(substr($sealed, 3), true) : false;
        if ($raw === false || strlen($raw) < 29) {
            throw SyncException::keyUnreadable($whose . ' stored API key is not a sealed key.' . $again);
        }
        $key = $this->key(false);
        if ($key === null) {
            throw SyncException::keyUnreadable($whose . ' API key was sealed with a key that is missing from '
                . '202_deployment_secrets.' . $again);
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $aad = self::aad($side, $profile);
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($plain === false) {
            throw SyncException::keyUnreadable($whose . ' stored API key does not open for its url (another '
                . 'install\'s key, or the job file was altered).' . $again);
        }
        $profile['api_key'] = $plain;

        return $profile;
    }

    /** @param array<string, mixed> $profile */
    public static function holdsPlainKey(array $profile): bool
    {
        return isset($profile['api_key']) && $profile['api_key'] !== '';
    }

    /**
     * The profile with no key, sealed or not: what a finished job keeps.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function forget(array $profile): array
    {
        unset($profile['api_key'], $profile[self::SEALED]);

        return $profile;
    }

    /** @param array<string, mixed> $profile */
    private static function aad(string $side, array $profile): string
    {
        return self::AAD . $side . '|' . (string) ($profile['url'] ?? '');
    }

    /**
     * The install's key; minted first when $mint and it is absent.
     *
     * @throws \RuntimeException for a stored key that is not 64 lower-case hex characters, or a failed query
     */
    private function key(bool $mint): ?string
    {
        if ($this->key !== null || $this->conn === null) {
            return $this->key;
        }
        if ($mint) {
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO `' . TableRegistry::DEPLOYMENT_SECRETS . '`
                    (secret_name, secret_value, created_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE secret_name = secret_name'
            );
            $this->conn->bind($stmt, 'ssi', [self::SECRET_NAME, bin2hex(random_bytes(32)), time()]);
            $this->conn->executeUpdate($stmt);
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT secret_value FROM `' . TableRegistry::DEPLOYMENT_SECRETS . '` WHERE secret_name = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 's', [self::SECRET_NAME]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            if ($mint) {
                throw new \RuntimeException('The sync key was minted but cannot be read back');
            }

            return null;
        }
        $hex = $row['secret_value'] ?? null;
        if (!is_string($hex) || preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new \RuntimeException('The stored sync key is not 64 lower-case hex characters');
        }
        $this->key = (string) hex2bin($hex);

        return $this->key;
    }
}
