<?php

declare(strict_types=1);

namespace Prosper202\Security;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The one sealing scheme for secrets stored in the database: AES-256-GCM
 * under a 32-byte key minted once per purpose in 202_deployment_secrets
 * (by one idempotent statement that never replaces an existing key), stored
 * as `v1.` + base64(nonce ‖ tag ‖ ciphertext). The associated data names the
 * record and its owner, so a ciphertext copied onto another record fails to
 * open instead of being used for the wrong one.
 *
 * Each store keeps its own key name, associated-data prefix and wording;
 * this class keeps the bytes. The key is in the same database, so a full
 * dump holds both: what this stops is the secret leaking through anything
 * that reads the store's table alone.
 */
final class SealedSecret
{
    private const CIPHER = 'aes-256-gcm';

    /** @throws \RuntimeException with $failure when encryption fails */
    public static function seal(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $key, string $aad, string $failure): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException($failure);
        }

        return 'v1.' . base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * @throws \RuntimeException with $notCiphertext for a value that is not v1, and with $doesNotOpen for one whose
     *                           tag does not verify under this key and associated data
     */
    public static function open(string $sealed, #[\SensitiveParameter] string $key, string $aad, string $notCiphertext, string $doesNotOpen): string
    {
        $raw = str_starts_with($sealed, 'v1.') ? base64_decode(substr($sealed, 3), true) : false;
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException($notCiphertext);
        }
        $plaintext = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        if ($plaintext === false) {
            throw new \RuntimeException($doesNotOpen);
        }

        return $plaintext;
    }

    /** Mint the key named $secretName unless one is there. An existing key is never replaced. */
    public static function mintKey(Connection $conn, string $secretName): void
    {
        $insert = $conn->prepareWrite('INSERT INTO `' . TableRegistry::DEPLOYMENT_SECRETS . '` (secret_name, secret_value, created_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE secret_name = secret_name');
        $conn->bind($insert, 'ssi', [$secretName, bin2hex(random_bytes(32)), time()]);
        $conn->executeUpdate($insert);
    }

    /**
     * The key named $secretName, read on the write connection (a key minted in this transaction is seen); null when
     * there is none. A failed read is the database's error, never null.
     *
     * @throws MalformedSecretKey for a stored key that is not 64 lower-case hex characters
     */
    public static function readKey(Connection $conn, string $secretName): ?string
    {
        $read = $conn->prepareWrite('SELECT secret_value FROM `' . TableRegistry::DEPLOYMENT_SECRETS . '` WHERE secret_name = ? LIMIT 1');
        $conn->bind($read, 's', [$secretName]);
        $row = $conn->fetchOne($read);
        if ($row === null) {
            return null;
        }
        $hex = $row['secret_value'] ?? null;
        if (!is_string($hex) || preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new MalformedSecretKey('The key ' . $secretName . ' in 202_deployment_secrets is not 64 lower-case hex characters');
        }

        return (string) hex2bin($hex);
    }
}
