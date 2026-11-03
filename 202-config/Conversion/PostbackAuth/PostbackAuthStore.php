<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\OfflineConversion\StoredRow;

/**
 * Each account's postback authentication, in 202_postback_auth.
 *
 * The token is shown again whenever Setup or the API hands out a postback
 * URL, so it is kept recoverable: AES-256-GCM under a random 32-byte key in
 * 202_deployment_secrets (`postback_token`), minted on first use by one
 * statement that never replaces an existing key, as the Play Integrity
 * credentials are (IntegrityCredentialStore). Stored as `v1.` +
 * base64(nonce ‖ tag ‖ ciphertext), with the account bound in as associated
 * data, so a value copied onto another account does not open. The key is in
 * the same database: what this stops is the token leaking through a read of
 * this table alone (an export, a debugging SELECT, a support bundle).
 *
 * Reads are tri-state (CLAUDE.md #11): settings, PostbackAuthSettings::none()
 * for an account with no row, and a throw for a row that cannot be read.
 * Every read is on the primary, so a token rotated a moment ago is refused
 * at once.
 */
final class PostbackAuthStore
{
    public const SECRET_NAME = 'postback_token';
    public const MAX_GRACE_SECONDS = 30 * 86400;
    public const DEFAULT_GRACE_SECONDS = 7 * 86400;
    private const AAD = 'p202-postback-token-v1|';
    private const CIPHER = 'aes-256-gcm';
    private const COLUMNS = 'token_required, token_sealed, token_created_at, previous_token_sealed, previous_expires_at';

    public function __construct(private readonly Connection $conn)
    {
    }

    public function settings(int $userId): PostbackAuthSettings
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT ' . self::COLUMNS . ' FROM `' . TableRegistry::POSTBACK_AUTH . '` WHERE user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? PostbackAuthSettings::none() : $this->fromRow($userId, $row);
    }

    /**
     * The account's settings with a token in them: the one it has, or one
     * minted now. What every place that hands out a postback URL calls.
     */
    public function ensureToken(int $userId, int $now): PostbackAuthSettings
    {
        $settings = $this->settings($userId);
        if ($settings->token !== null) {
            return $settings;
        }

        return $this->conn->transaction(function () use ($userId, $now): PostbackAuthSettings {
            $locked = $this->lockRow($userId, $now);
            if ($locked->token !== null) {
                return $locked;
            }
            $this->writeTokens($userId, PostbackToken::generate(), null, null, $now);

            return $this->settings($userId);
        });
    }

    /**
     * A new token. With $graceSeconds above 0 the one it replaces keeps
     * working that long, so the URLs already at the networks can be changed
     * one by one; with 0 it stops at once (a token that leaked). A token an
     * earlier rotation kept working is dropped: two work at most.
     */
    public function rotate(int $userId, int $graceSeconds, int $now): PostbackAuthSettings
    {
        if ($graceSeconds < 0 || $graceSeconds > self::MAX_GRACE_SECONDS) {
            throw new \InvalidArgumentException('The grace period is 0 to ' . self::MAX_GRACE_SECONDS . ' seconds');
        }

        return $this->conn->transaction(function () use ($userId, $graceSeconds, $now): PostbackAuthSettings {
            $locked = $this->lockRow($userId, $now);
            $keep = $graceSeconds > 0 && $locked->token !== null;
            $this->writeTokens(
                $userId,
                PostbackToken::generate(),
                $keep ? $locked->token : null,
                $keep ? $now + $graceSeconds : null,
                $now
            );

            return $this->settings($userId);
        });
    }

    public function setRequired(int $userId, bool $required, int $now): PostbackAuthSettings
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO `' . TableRegistry::POSTBACK_AUTH . '` (user_id, token_required, updated_at) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE token_required = VALUES(token_required), updated_at = VALUES(updated_at)'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $required ? 1 : 0, $now]);
        $this->conn->executeUpdate($stmt);

        return $this->settings($userId);
    }

    /** The account's row, made with the table's defaults when missing, locked for this transaction. */
    private function lockRow(int $userId, int $now): PostbackAuthSettings
    {
        $insert = $this->conn->prepareWrite(
            'INSERT INTO `' . TableRegistry::POSTBACK_AUTH . '` (user_id, updated_at) VALUES (?, ?)'
            . ' ON DUPLICATE KEY UPDATE user_id = user_id'
        );
        $this->conn->bind($insert, 'ii', [$userId, $now]);
        $this->conn->executeUpdate($insert);
        $stmt = $this->conn->prepareWrite(
            'SELECT ' . self::COLUMNS . ' FROM `' . TableRegistry::POSTBACK_AUTH . '` WHERE user_id = ? FOR UPDATE'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException('The postback settings of account ' . $userId . ' were written but cannot be read back');
        }

        return $this->fromRow($userId, $row);
    }

    private function writeTokens(int $userId, string $token, ?string $previous, ?int $previousExpiresAt, int $now): void
    {
        $key = $this->ensureKey();
        $stmt = $this->conn->prepareWrite(
            'UPDATE `' . TableRegistry::POSTBACK_AUTH . '` SET token_sealed = ?, token_created_at = ?,'
            . ' previous_token_sealed = ?, previous_expires_at = ?, updated_at = ? WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'sisiii', [
            self::seal($token, $key, $userId),
            $now,
            $previous === null ? null : self::seal($previous, $key, $userId),
            $previousExpiresAt,
            $now,
            $userId,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /** @param array<string, mixed> $row */
    private function fromRow(int $userId, array $row): PostbackAuthSettings
    {
        $required = StoredRow::int($row, 'token_required');
        if ($required !== 0 && $required !== 1) {
            throw new \UnexpectedValueException('token_required of account ' . $userId . ' is ' . $required . ', not 0 or 1');
        }
        $sealed = $row['token_sealed'] ?? null;
        $previousSealed = $row['previous_token_sealed'] ?? null;
        $key = ($sealed !== null || $previousSealed !== null) ? $this->loadKey() : null;
        if (($sealed !== null || $previousSealed !== null) && $key === null) {
            throw new \RuntimeException('Account ' . $userId . ' has a postback token but its encryption key is missing'
                . ' from 202_deployment_secrets; rotate the token to make a new one');
        }
        $token = $sealed === null ? null : self::open(StoredRow::text($row, 'token_sealed'), (string) $key, $userId);
        $previous = $previousSealed === null ? null : self::open(StoredRow::text($row, 'previous_token_sealed'), (string) $key, $userId);

        return new PostbackAuthSettings(
            $required === 1,
            $token,
            StoredRow::nullableInt($row, 'token_created_at'),
            $previous,
            StoredRow::nullableInt($row, 'previous_expires_at'),
        );
    }

    public static function seal(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $key, int $userId): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($token, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD . $userId, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Encrypting the postback token failed');
        }

        return 'v1.' . base64_encode($nonce . $tag . $ciphertext);
    }

    /** @throws \RuntimeException for a value that is not v1, does not verify for this account, or is not a token */
    public static function open(string $sealed, #[\SensitiveParameter] string $key, int $userId): string
    {
        $raw = str_starts_with($sealed, 'v1.') ? base64_decode(substr($sealed, 3), true) : false;
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('The stored postback token of account ' . $userId . ' is not a v1 ciphertext');
        }
        $token = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), self::AAD . $userId);
        if ($token === false || !PostbackToken::isWellFormed($token)) {
            throw new \RuntimeException('The stored postback token of account ' . $userId . ' does not decrypt'
                . ' (wrong key, altered, or copied from another account); rotate the token to make a new one');
        }

        return $token;
    }

    /** The key, minted first if absent. An existing key is never replaced. */
    private function ensureKey(): string
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO `' . TableRegistry::DEPLOYMENT_SECRETS . '` (secret_name, secret_value, created_at) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE secret_name = secret_name'
        );
        $this->conn->bind($stmt, 'ssi', [self::SECRET_NAME, bin2hex(random_bytes(32)), time()]);
        $this->conn->executeUpdate($stmt);

        return $this->loadKey() ?? throw new \RuntimeException('The postback token key was minted but cannot be read back');
    }

    /** @throws \RuntimeException for a stored key that is not 64 lower-case hex characters */
    private function loadKey(): ?string
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT secret_value FROM `' . TableRegistry::DEPLOYMENT_SECRETS . '` WHERE secret_name = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 's', [self::SECRET_NAME]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }
        $hex = $row['secret_value'] ?? null;
        if (!is_string($hex) || preg_match('/\A[0-9a-f]{64}\z/D', $hex) !== 1) {
            throw new \RuntimeException('The stored postback token key is not 64 lower-case hex characters');
        }

        return (string) hex2bin($hex);
    }
}
