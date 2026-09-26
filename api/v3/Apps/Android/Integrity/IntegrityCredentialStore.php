<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The Play Integrity service accounts, one per Android registration, in
 * 202_app_integrity_credentials, encrypted at rest.
 *
 * Encryption: AES-256-GCM under K_credential, a random 32-byte key kept in
 * 202_deployment_secrets (`play_integrity_credential`), minted on first use
 * by one idempotent statement that never replaces an existing key. Stored as
 * `v1.` + base64(nonce ‖ tag ‖ ciphertext), with
 * `p202-integrity-credential-v1|<registration_id>|<user_id>` as associated
 * data, so a ciphertext moved onto another registration or owner fails to
 * decrypt instead of signing for the wrong app.
 *
 * What this protects, stated plainly: the key is in the same database, so
 * someone holding a full dump of it holds both. What it stops is the
 * private key leaking through anything that reads the credential table
 * alone — a table export, a debugging SELECT, a query log, an API or CLI
 * path that serialises a row — and it means the key is only ever
 * plaintext in the worker's memory while it signs.
 *
 * Reading is tri-state (CLAUDE.md #11): a credential, null for "none
 * configured", and a throw for a row that exists but cannot be read (a
 * failed query, a missing key, a tag that does not verify). The worker
 * records the throw as the reason it could not decode and retries; it never
 * treats an unreadable credential as an absent one.
 */
final class IntegrityCredentialStore
{
    public const SECRET_NAME = 'play_integrity_credential';
    private const AAD = 'p202-integrity-credential-v1|';
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly Connection $conn)
    {
    }

    public function set(int $userId, int $registrationId, ServiceAccountCredential $credential, int $now): void
    {
        $key = $this->ensureKey();
        $sealed = self::seal($credential->toStoredJson(), $key, $registrationId, $userId);
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO `' . TableRegistry::APP_INTEGRITY_CREDENTIALS . '`
                (registration_id, user_id, client_email, private_key_id, project_id, ciphertext, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), client_email = VALUES(client_email), private_key_id = VALUES(private_key_id),
                project_id = VALUES(project_id), ciphertext = VALUES(ciphertext), updated_at = VALUES(updated_at)'
        );
        $this->conn->bind($stmt, 'iissssii', [
            $registrationId, $userId, $credential->clientEmail, $credential->privateKeyId, $credential->projectId, $sealed, $now, $now,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /** Whether a row was removed. */
    public function clear(int $userId, int $registrationId): bool
    {
        $stmt = $this->conn->prepareWrite('DELETE FROM `' . TableRegistry::APP_INTEGRITY_CREDENTIALS . '` WHERE registration_id = ? AND user_id = ?');
        $this->conn->bind($stmt, 'ii', [$registrationId, $userId]);

        return $this->conn->executeUpdate($stmt) > 0;
    }

    /**
     * Whether a credential is stored, read on the primary with a locking
     * read: the check a mode write makes under the registration's lock, so
     * it sees the credential as a concurrent clear committed it — never a
     * replica's lag, never an earlier snapshot.
     */
    public function existsLocked(int $userId, int $registrationId): bool
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT registration_id FROM `' . TableRegistry::APP_INTEGRITY_CREDENTIALS . '` WHERE registration_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
        );
        $this->conn->bind($stmt, 'ii', [$registrationId, $userId]);

        return $this->conn->fetchOne($stmt) !== null;
    }

    /**
     * What may be shown about the credential: never the key.
     *
     * @return array{client_email: string, private_key_id: string, project_id: string|null, created_at: int, updated_at: int}|null
     */
    public function summary(int $userId, int $registrationId): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT client_email, private_key_id, project_id, created_at, updated_at FROM `' . TableRegistry::APP_INTEGRITY_CREDENTIALS
            . '` WHERE registration_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$registrationId, $userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return [
            'client_email' => (string) $row['client_email'],
            'private_key_id' => (string) $row['private_key_id'],
            'project_id' => $row['project_id'] === null ? null : (string) $row['project_id'],
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
        ];
    }

    /**
     * The registration's credential, decrypted; null when none is configured.
     *
     * @throws \RuntimeException when a row exists and cannot be read
     */
    public function load(int $userId, int $registrationId): ?ServiceAccountCredential
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT ciphertext FROM `' . TableRegistry::APP_INTEGRITY_CREDENTIALS . '` WHERE registration_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$registrationId, $userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }
        $key = $this->loadKey();
        if ($key === null) {
            throw new \RuntimeException('A Play Integrity credential is stored but its encryption key is missing from 202_deployment_secrets; set the credential again');
        }

        return ServiceAccountCredential::fromStoredJson(self::open((string) $row['ciphertext'], $key, $registrationId, $userId));
    }

    public static function seal(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $key, int $registrationId, int $userId): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD . $registrationId . '|' . $userId, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Encrypting the Play Integrity credential failed');
        }

        return 'v1.' . base64_encode($nonce . $tag . $ciphertext);
    }

    /** @throws \RuntimeException for a value that is not v1, or whose tag does not verify under this registration */
    public static function open(string $sealed, #[\SensitiveParameter] string $key, int $registrationId, int $userId): string
    {
        $raw = str_starts_with($sealed, 'v1.') ? base64_decode(substr($sealed, 3), true) : false;
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('The stored Play Integrity credential is not a v1 ciphertext');
        }
        $plaintext = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), self::AAD . $registrationId . '|' . $userId);
        if ($plaintext === false) {
            throw new \RuntimeException('The stored Play Integrity credential does not decrypt for registration ' . $registrationId
                . ' (wrong key, altered, or copied from another registration); set the credential again');
        }

        return $plaintext;
    }

    /** The key, minted first if absent. An existing key is never replaced. */
    private function ensureKey(): string
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO `' . TableRegistry::DEPLOYMENT_SECRETS . '` (secret_name, secret_value, created_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE secret_name = secret_name'
        );
        $this->conn->bind($stmt, 'ssi', [self::SECRET_NAME, bin2hex(random_bytes(32)), time()]);
        $this->conn->executeUpdate($stmt);
        $key = $this->loadKey();
        if ($key === null) {
            throw new \RuntimeException('The Play Integrity credential key was minted but cannot be read back');
        }

        return $key;
    }

    /** @throws \RuntimeException for a stored key that is not 64 lower-case hex characters */
    private function loadKey(): ?string
    {
        $stmt = $this->conn->prepareWrite('SELECT secret_value FROM `' . TableRegistry::DEPLOYMENT_SECRETS . '` WHERE secret_name = ? LIMIT 1');
        $this->conn->bind($stmt, 's', [self::SECRET_NAME]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }
        $hex = $row['secret_value'] ?? null;
        if (!is_string($hex) || preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new \RuntimeException('The stored Play Integrity credential key is not 64 lower-case hex characters');
        }

        return (string) hex2bin($hex);
    }
}
