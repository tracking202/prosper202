<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\OfflineConversion\StoredRow;

/**
 * Each account's postback authentication, in 202_postback_auth: its mode,
 * its URL token and its signing secret.
 *
 * Both credentials are shown again (the token in every postback URL handed
 * out, the secret to the account that signs with it), so they are kept
 * recoverable: AES-256-GCM under a random 32-byte key in
 * 202_deployment_secrets (`postback_token`), minted on first use by one
 * statement that never replaces an existing key, as the Play Integrity
 * credentials are (IntegrityCredentialStore). Stored as `v1.` +
 * base64(nonce ‖ tag ‖ ciphertext), with the credential's kind and the
 * account bound in as associated data, so a value copied onto another
 * account, or from the token's column to the secret's, does not open. The
 * key is in the same database: what this stops is a credential leaking
 * through a read of this table alone (an export, a debugging SELECT, a
 * support bundle).
 *
 * Reads are tri-state (CLAUDE.md #11): settings, PostbackAuthSettings::none()
 * for an account with no row, and a throw for a row that cannot be read.
 * Every read is on the primary, so a credential rotated a moment ago is
 * refused at once.
 */
final class PostbackAuthStore
{
    public const SECRET_NAME = 'postback_token';
    public const MAX_GRACE_SECONDS = 30 * 86400;
    public const DEFAULT_GRACE_SECONDS = 7 * 86400;
    private const CIPHER = 'aes-256-gcm';
    private const COLUMNS = 'auth_mode, token_sealed, token_created_at, previous_token_sealed, previous_token_expires_at,'
        . ' secret_sealed, secret_created_at, previous_secret_sealed, previous_secret_expires_at';
    /** Each credential's write, its columns spelled out: current, made at, the one it replaced, that one's end. */
    private const WRITES = [
        'token' => 'UPDATE `202_postback_auth` SET token_sealed = ?, token_created_at = ?, previous_token_sealed = ?,'
            . ' previous_token_expires_at = ?, updated_at = ? WHERE user_id = ?',
        'signing_secret' => 'UPDATE `202_postback_auth` SET secret_sealed = ?, secret_created_at = ?, previous_secret_sealed = ?,'
            . ' previous_secret_expires_at = ?, updated_at = ? WHERE user_id = ?',
    ];

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
     * The account's settings with a token and a signing secret in them: the
     * ones it has, or ones minted now. What every place that hands out a
     * postback URL or shows the secret calls.
     */
    public function ensureCredentials(int $userId, int $now): PostbackAuthSettings
    {
        $settings = $this->settings($userId);
        if ($settings->token !== null && $settings->secret !== null) {
            return $settings;
        }

        return $this->conn->transaction(function () use ($userId, $now): PostbackAuthSettings {
            $locked = $this->lockRow($userId, $now);
            foreach ([PostbackCredential::TOKEN, PostbackCredential::SIGNING_SECRET] as $credential) {
                if (self::current($locked, $credential) === null) {
                    $this->writeCredential($userId, $credential, self::generate($credential), null, null, $now);
                }
            }

            return $this->settings($userId);
        });
    }

    /**
     * A new token or signing secret. With $graceSeconds above 0 the one it
     * replaces keeps working that long, so the URLs or the senders already
     * using it can be moved one by one; with 0 it stops at once (one that
     * leaked). One an earlier rotation kept working is dropped: two work at
     * most.
     */
    public function rotate(int $userId, PostbackCredential $credential, int $graceSeconds, int $now): PostbackAuthSettings
    {
        if ($graceSeconds < 0 || $graceSeconds > self::MAX_GRACE_SECONDS) {
            throw new \InvalidArgumentException('The grace period is 0 to ' . self::MAX_GRACE_SECONDS . ' seconds');
        }

        return $this->conn->transaction(function () use ($userId, $credential, $graceSeconds, $now): PostbackAuthSettings {
            $locked = $this->lockRow($userId, $now);
            $old = self::current($locked, $credential);
            $keep = $graceSeconds > 0 && $old !== null;
            $this->writeCredential(
                $userId,
                $credential,
                self::generate($credential),
                $keep ? $old : null,
                $keep ? $now + $graceSeconds : null,
                $now
            );

            return $this->settings($userId);
        });
    }

    public function setMode(int $userId, PostbackAuthMode $mode, int $now): PostbackAuthSettings
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO `' . TableRegistry::POSTBACK_AUTH . '` (user_id, auth_mode, updated_at) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE auth_mode = VALUES(auth_mode), updated_at = VALUES(updated_at)'
        );
        $this->conn->bind($stmt, 'isi', [$userId, $mode->value, $now]);
        $this->conn->executeUpdate($stmt);

        return $this->settings($userId);
    }

    private static function current(PostbackAuthSettings $settings, PostbackCredential $credential): ?string
    {
        return $credential === PostbackCredential::TOKEN ? $settings->token : $settings->secret;
    }

    private static function generate(PostbackCredential $credential): string
    {
        return $credential === PostbackCredential::TOKEN ? PostbackToken::generate() : PostbackSigningSecret::generate();
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

    private function writeCredential(
        int $userId,
        PostbackCredential $credential,
        string $value,
        ?string $previous,
        ?int $previousExpiresAt,
        int $now
    ): void {
        $key = $this->ensureKey();
        $stmt = $this->conn->prepareWrite(self::WRITES[$credential->value]);
        $this->conn->bind($stmt, 'sisiii', [
            self::seal($value, $key, $userId, $credential),
            $now,
            $previous === null ? null : self::seal($previous, $key, $userId, $credential),
            $previousExpiresAt,
            $now,
            $userId,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /** @param array<string, mixed> $row */
    private function fromRow(int $userId, array $row): PostbackAuthSettings
    {
        $mode = PostbackAuthMode::tryFrom(StoredRow::text($row, 'auth_mode'));
        if ($mode === null) {
            // Not read as off (CLAUDE.md #11): an unreadable mode refuses.
            throw new \UnexpectedValueException('auth_mode of account ' . $userId . ' is not one of '
                . implode(', ', PostbackAuthMode::values()));
        }
        $sealed = [];
        foreach (['token_sealed', 'previous_token_sealed', 'secret_sealed', 'previous_secret_sealed'] as $column) {
            if (($row[$column] ?? null) !== null) {
                $sealed[$column] = StoredRow::text($row, $column);
            }
        }
        $key = $sealed === [] ? null : $this->loadKey();
        if ($sealed !== [] && $key === null) {
            throw new \RuntimeException('Account ' . $userId . ' has postback credentials but their encryption key is missing'
                . ' from 202_deployment_secrets; rotate the token and the signing secret to make new ones');
        }
        $open = static function (string $column, PostbackCredential $credential) use ($sealed, $key, $userId): ?string {
            return isset($sealed[$column]) ? self::open($sealed[$column], (string) $key, $userId, $credential) : null;
        };
        $token = PostbackCredential::TOKEN;
        $secret = PostbackCredential::SIGNING_SECRET;

        return new PostbackAuthSettings(
            $mode,
            $open('token_sealed', $token),
            StoredRow::nullableInt($row, 'token_created_at'),
            $open('previous_token_sealed', $token),
            StoredRow::nullableInt($row, 'previous_token_expires_at'),
            $open('secret_sealed', $secret),
            StoredRow::nullableInt($row, 'secret_created_at'),
            $open('previous_secret_sealed', $secret),
            StoredRow::nullableInt($row, 'previous_secret_expires_at'),
        );
    }

    private static function aad(int $userId, PostbackCredential $credential): string
    {
        return ($credential === PostbackCredential::TOKEN ? 'p202-postback-token-v1|' : 'p202-postback-secret-v1|') . $userId;
    }

    public static function seal(#[\SensitiveParameter] string $value, #[\SensitiveParameter] string $key, int $userId, PostbackCredential $credential): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, self::aad($userId, $credential), 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Encrypting the postback ' . $credential->value . ' failed');
        }

        return 'v1.' . base64_encode($nonce . $tag . $ciphertext);
    }

    /** @throws \RuntimeException for a value that is not v1, does not verify for this account and kind, or is not of its shape */
    public static function open(string $sealed, #[\SensitiveParameter] string $key, int $userId, PostbackCredential $credential): string
    {
        $raw = str_starts_with($sealed, 'v1.') ? base64_decode(substr($sealed, 3), true) : false;
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('The stored postback ' . $credential->value . ' of account ' . $userId . ' is not a v1 ciphertext');
        }
        $value = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), self::aad($userId, $credential));
        $wellFormed = $value !== false && ($credential === PostbackCredential::TOKEN
            ? PostbackToken::isWellFormed($value) : PostbackSigningSecret::isWellFormed($value));
        if (!$wellFormed) {
            throw new \RuntimeException('The stored postback ' . $credential->value . ' of account ' . $userId . ' does not decrypt'
                . ' (wrong key, altered, or copied from another account); rotate it to make a new one');
        }

        return (string) $value;
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

        return $this->loadKey() ?? throw new \RuntimeException('The postback credential key was minted but cannot be read back');
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
            throw new \RuntimeException('The stored postback credential key is not 64 lower-case hex characters');
        }

        return (string) hex2bin($hex);
    }
}
