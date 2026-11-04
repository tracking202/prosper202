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
 * The mode is read without opening anything (mode()), and settings() opens
 * only the credentials asked for, so a value that cannot be read stops only
 * a request that presents that credential; ensureCredentials(), rotate()
 * and setMode() replace it with a new one.
 * Every read is on the primary, so a credential rotated a moment ago is
 * refused at once.
 */
final class PostbackAuthStore
{
    public const SECRET_NAME = 'postback_token';
    public const MAX_GRACE_SECONDS = 30 * 86400;
    public const DEFAULT_GRACE_SECONDS = 7 * 86400;
    private const CIPHER = 'aes-256-gcm';
    /** How an owner recovers from a credential that cannot be read; each of these replaces it. */
    private const RECOVERY = 'rotate both credentials (Account › API Integrations › Postback authentication, or'
        . ' POST /conversions/postback-auth/rotate) to make new ones';
    private const COLUMNS = 'auth_mode, token_sealed, token_created_at, previous_token_sealed, previous_token_expires_at,'
        . ' secret_sealed, secret_created_at, previous_secret_sealed, previous_secret_expires_at';
    /** Each credential's columns: current, made at, the one it replaced, that one's end. */
    private const COLUMN = [
        'token' => ['token_sealed', 'token_created_at', 'previous_token_sealed', 'previous_token_expires_at'],
        'signing_secret' => ['secret_sealed', 'secret_created_at', 'previous_secret_sealed', 'previous_secret_expires_at'],
    ];

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

    /**
     * The account's mode, read without opening any credential: what deciding
     * a request that presents none needs, so an unreadable credential never
     * stands in the way of an account in mode off.
     */
    public function mode(int $userId): PostbackAuthMode
    {
        $row = $this->row($userId, false);

        return $row === null ? PostbackAuthMode::TOKEN : self::modeOf($userId, $row);
    }

    /**
     * The account's settings with $credentials opened (both by default); the
     * others are left null, so checking a token never needs the signing
     * secret to open. A sealed value that does not open throws.
     *
     * @param list<PostbackCredential>|null $credentials
     */
    public function settings(int $userId, ?array $credentials = null): PostbackAuthSettings
    {
        $row = $this->row($userId, false);
        if ($row === null) {
            return PostbackAuthSettings::none();
        }

        return $this->fromRow($userId, $row, $credentials ?? PostbackCredential::cases());
    }

    /**
     * The account's settings with a token and a signing secret in them: the
     * ones it has, or ones minted now. One stored but unreadable (its key
     * missing, or a value that does not open) is replaced by a new one, as
     * is a missing key; the result says so (replacedUnreadable,
     * keyRecreated, recoveryNotice()). What every place that hands out a
     * postback URL or shows the secret calls.
     */
    public function ensureCredentials(int $userId, int $now): PostbackAuthSettings
    {
        $row = $this->row($userId, false);
        if ($row !== null) {
            self::modeOf($userId, $row);
            $key = self::hasSealed($row) ? $this->loadKey() : null;
            if ($key !== null && $this->allReadable($userId, $row, $key)) {
                return $this->fromRow($userId, $row, PostbackCredential::cases());
            }
        }

        return $this->conn->transaction(fn (): PostbackAuthSettings => $this->ensureLocked($userId, $now, $this->lockRow($userId, $now)));
    }

    /**
     * A new token or signing secret. With $graceSeconds above 0 the one it
     * replaces keeps working that long, so the URLs or the senders already
     * using it can be moved one by one; with 0 it stops at once (one that
     * leaked). One an earlier rotation kept working is dropped: two work at
     * most. One that cannot be read is replaced without a grace period (it
     * already works nowhere), and so is the other credential when it cannot
     * be read: rotating is how an owner recovers from an unreadable one.
     */
    public function rotate(int $userId, PostbackCredential $credential, int $graceSeconds, int $now): PostbackAuthSettings
    {
        if ($graceSeconds < 0 || $graceSeconds > self::MAX_GRACE_SECONDS) {
            throw new \InvalidArgumentException('The grace period is 0 to ' . self::MAX_GRACE_SECONDS . ' seconds');
        }

        return $this->conn->transaction(function () use ($userId, $credential, $graceSeconds, $now): PostbackAuthSettings {
            $row = $this->lockRow($userId, $now);
            $hadKey = $this->loadKey();
            $current = self::COLUMN[$credential->value][0];
            $old = $hadKey === null ? null : self::tryOpen($row[$current] ?? null, $hadKey, $userId, $credential);
            $keep = $graceSeconds > 0 && $old !== null;
            $this->writeCredential(
                $userId,
                $credential,
                self::generate($credential),
                $keep ? $old : null,
                $keep ? $now + $graceSeconds : null,
                $now,
                $now
            );
            $unreadable = ($row[$current] ?? null) !== null && $old === null ? [$credential] : [];
            $settings = $this->ensureLocked($userId, $now, $this->lockRow($userId, $now), $hadKey === null && self::hasSealed($row));

            return $settings->withRecovery(array_merge($unreadable, $settings->replacedUnreadable), $settings->keyRecreated);
        });
    }

    /**
     * The mode, and credentials to go with it, in one transaction: either
     * both are stored or neither is, so a failure leaves the mode as it was.
     */
    public function setMode(int $userId, PostbackAuthMode $mode, int $now): PostbackAuthSettings
    {
        return $this->conn->transaction(function () use ($userId, $mode, $now): PostbackAuthSettings {
            $this->lockRow($userId, $now);
            $stmt = $this->conn->prepareWrite(
                'UPDATE `' . TableRegistry::POSTBACK_AUTH . '` SET auth_mode = ?, updated_at = ? WHERE user_id = ?'
            );
            $this->conn->bind($stmt, 'sii', [$mode->value, $now, $userId]);
            $this->conn->executeUpdate($stmt);

            return $this->ensureLocked($userId, $now, $this->lockRow($userId, $now));
        });
    }

    /**
     * ensureCredentials() inside the caller's transaction, on the account's
     * locked row as read: each credential that is missing or does not open
     * is minted anew, and a previous one that does not open is dropped.
     *
     * @param array<string, mixed> $row
     */
    private function ensureLocked(int $userId, int $now, array $row, ?bool $keyWasMissing = null): PostbackAuthSettings
    {
        self::modeOf($userId, $row);
        $hadKey = $this->loadKey();
        $replaced = [];
        foreach (PostbackCredential::cases() as $credential) {
            [$currentColumn, $createdColumn, $previousColumn] = self::COLUMN[$credential->value];
            $current = self::tryOpen($row[$currentColumn] ?? null, $hadKey, $userId, $credential);
            if ($current === null) {
                if (($row[$currentColumn] ?? null) !== null) {
                    $replaced[] = $credential;
                }
                $this->writeCredential($userId, $credential, self::generate($credential), null, null, $now, $now);
                continue;
            }
            if (($row[$previousColumn] ?? null) !== null
                && self::tryOpen($row[$previousColumn], $hadKey, $userId, $credential) === null) {
                // Its grace cannot be honoured: it opens nowhere.
                $this->writeCredential($userId, $credential, $current, null, null,
                    StoredRow::nullableInt($row, $createdColumn) ?? $now, $now);
            }
        }
        $keyRecreated = $keyWasMissing ?? ($hadKey === null && self::hasSealed($row));
        $stored = $this->row($userId, true) ?? throw new \RuntimeException(
            'The postback settings of account ' . $userId . ' were written but cannot be read back'
        );

        return $this->fromRow($userId, $stored, PostbackCredential::cases())->withRecovery($replaced, $keyRecreated);
    }

    private static function generate(PostbackCredential $credential): string
    {
        return $credential === PostbackCredential::TOKEN ? PostbackToken::generate() : PostbackSigningSecret::generate();
    }

    /** @return array<string, mixed>|null the account's row as stored, locked when $forUpdate */
    private function row(int $userId, bool $forUpdate): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT ' . self::COLUMNS . ' FROM `' . TableRegistry::POSTBACK_AUTH . '` WHERE user_id = ? '
            . ($forUpdate ? 'FOR UPDATE' : 'LIMIT 1')
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        return $this->conn->fetchOne($stmt);
    }

    /**
     * The account's row, made with the table's defaults when missing, locked
     * for this transaction, as stored: nothing in it is opened.
     *
     * @return array<string, mixed>
     */
    private function lockRow(int $userId, int $now): array
    {
        $insert = $this->conn->prepareWrite(
            'INSERT INTO `' . TableRegistry::POSTBACK_AUTH . '` (user_id, updated_at) VALUES (?, ?)'
            . ' ON DUPLICATE KEY UPDATE user_id = user_id'
        );
        $this->conn->bind($insert, 'ii', [$userId, $now]);
        $this->conn->executeUpdate($insert);

        return $this->row($userId, true) ?? throw new \RuntimeException(
            'The postback settings of account ' . $userId . ' were written but cannot be read back'
        );
    }

    private function writeCredential(
        int $userId,
        PostbackCredential $credential,
        string $value,
        ?string $previous,
        ?int $previousExpiresAt,
        int $createdAt,
        int $now
    ): void {
        $key = $this->ensureKey();
        $stmt = $this->conn->prepareWrite(self::WRITES[$credential->value]);
        $this->conn->bind($stmt, 'sisiii', [
            self::seal($value, $key, $userId, $credential),
            $createdAt,
            $previous === null ? null : self::seal($previous, $key, $userId, $credential),
            $previousExpiresAt,
            $now,
            $userId,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /** @param array<string, mixed> $row */
    private static function modeOf(int $userId, array $row): PostbackAuthMode
    {
        $mode = PostbackAuthMode::tryFrom(StoredRow::text($row, 'auth_mode'));
        if ($mode === null) {
            // Not read as off (CLAUDE.md #11): an unreadable mode refuses.
            throw new \UnexpectedValueException('auth_mode of account ' . $userId . ' is not one of '
                . implode(', ', PostbackAuthMode::values()));
        }

        return $mode;
    }

    /** @param array<string, mixed> $row */
    private static function hasSealed(array $row): bool
    {
        foreach (self::COLUMN as [$current, , $previous]) {
            if (($row[$current] ?? null) !== null || ($row[$previous] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function allReadable(int $userId, array $row, string $key): bool
    {
        foreach (PostbackCredential::cases() as $credential) {
            [$current, , $previous] = self::COLUMN[$credential->value];
            if (self::tryOpen($row[$current] ?? null, $key, $userId, $credential) === null) {
                return false;
            }
            if (($row[$previous] ?? null) !== null && self::tryOpen($row[$previous], $key, $userId, $credential) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<PostbackCredential> $credentials the ones to open; the others are left null
     */
    private function fromRow(int $userId, array $row, array $credentials): PostbackAuthSettings
    {
        $mode = self::modeOf($userId, $row);
        $sealed = [];
        foreach ($credentials as $credential) {
            foreach ([self::COLUMN[$credential->value][0], self::COLUMN[$credential->value][2]] as $column) {
                if (($row[$column] ?? null) !== null) {
                    $sealed[$column] = StoredRow::text($row, $column);
                }
            }
        }
        $key = $sealed === [] ? null : $this->loadKey();
        if ($sealed !== [] && $key === null) {
            throw new \RuntimeException('Account ' . $userId . ' has postback credentials but their encryption key is missing'
                . ' from 202_deployment_secrets; ' . self::RECOVERY);
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
        if (!str_starts_with($sealed, 'v1.')) {
            throw new \RuntimeException('The stored postback ' . $credential->value . ' of account ' . $userId
                . ' is not a v1 ciphertext; ' . self::RECOVERY);
        }

        return self::tryOpen($sealed, $key, $userId, $credential) ?? throw new \RuntimeException('The stored postback '
            . $credential->value . ' of account ' . $userId . ' does not decrypt (wrong key, altered, or copied from'
            . ' another account); ' . self::RECOVERY);
    }

    /** The value, or null when there is none, no key, or it does not open as this account's $credential. */
    private static function tryOpen(mixed $sealed, #[\SensitiveParameter] ?string $key, int $userId, PostbackCredential $credential): ?string
    {
        if (!is_string($sealed) || $key === null || !str_starts_with($sealed, 'v1.')) {
            return null;
        }
        $raw = base64_decode(substr($sealed, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $value = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), self::aad($userId, $credential));
        $wellFormed = is_string($value) && ($credential === PostbackCredential::TOKEN
            ? PostbackToken::isWellFormed($value) : PostbackSigningSecret::isWellFormed($value));

        return $wellFormed ? $value : null;
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
