<?php

declare(strict_types=1);

namespace Prosper202\Security;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Each account's integration secrets, by name, in 202_integration_secrets:
 * sealed at rest, never served back, replaceable with an overlap window,
 * and every change recorded in 202_integration_secret_events (who, how,
 * when, under which key; never the value).
 *
 * Envelope encryption, on the scheme SealedSecret keeps for the Play
 * Integrity credentials: each row has its own random 32-byte data key, which
 * seals the value (and the previous one) and is itself sealed under the key
 * SecretsKeyring resolves, which is kept outside the database when the
 * operator configures one. The row records that key's id, so it opens only
 * under the key that sealed it. Associated data binds the account, the
 * secret's name and the slot (current or previous): a ciphertext copied onto
 * another account, secret or slot does not open.
 *
 * Reads are tri-state (CLAUDE.md #11): the secret, null for none stored, and
 * UnreadableSecret for one stored that cannot be opened. Reads are on the
 * primary, so a secret replaced a moment ago is not verified with.
 *
 * Writes join the caller's transaction when there is one; otherwise each is
 * one transaction of its own. A write sealed with the database's key is
 * refused while any secret is sealed with an external key: a process that
 * lost P202_SECRETS_KEY never quietly seals with the weaker one.
 */
final class IntegrationSecretStore
{
    public const MAX_GRACE_SECONDS = 30 * 86400;

    /** What a change did; what 202_integration_secret_events.action holds. */
    public const SET = 'set';
    public const REPLACED = 'replaced';
    public const ROTATED = 'rotated';
    public const CLEARED = 'cleared';
    public const MIGRATED = 'migrated';

    /** How the change was asked for. */
    public const VIA = ['page', 'api', 'cli', 'upgrade'];

    private const TABLE = TableRegistry::INTEGRATION_SECRETS;
    private const EVENTS = TableRegistry::INTEGRATION_SECRET_EVENTS;
    private const COLUMNS = 'key_id, wrapped_key, sealed, set_at, previous_sealed, previous_expires_at';

    private readonly SecretsKeyring $keyring;

    public function __construct(private readonly Connection $conn, ?SecretsKeyring $keyring = null)
    {
        $this->keyring = $keyring ?? SecretsKeyring::fromEnvironment($conn);
    }

    /**
     * Store $value as the account's $name. With $graceSeconds above 0 the
     * value it replaces keeps being accepted that long (rotated); with 0 it
     * stops at once (replaced). One an earlier rotation kept is dropped: two
     * are accepted at most. A value that cannot be opened is replaced with
     * no overlap, since it is accepted nowhere already.
     *
     * @return string what was done: SET, REPLACED or ROTATED
     */
    public function put(
        int $userId,
        string $name,
        #[\SensitiveParameter] string $value,
        int $graceSeconds,
        ?int $actorUserId,
        string $via,
        int $now
    ): string {
        self::checkName($name);
        self::checkVia($via);
        if ($value === '') {
            throw new \InvalidArgumentException('An empty ' . $name . ' is not stored; clear() removes one');
        }
        if ($graceSeconds < 0 || $graceSeconds > self::MAX_GRACE_SECONDS) {
            throw new \InvalidArgumentException('The overlap window is 0 to ' . self::MAX_GRACE_SECONDS . ' seconds');
        }

        return $this->inTransaction(function () use ($userId, $name, $value, $graceSeconds, $actorUserId, $via, $now): string {
            $row = $this->row($userId, $name, true);
            $previous = null;
            $action = self::SET;
            if ($row !== null) {
                $action = self::REPLACED;
                if ($graceSeconds > 0) {
                    try {
                        $old = $this->openRow($userId, $name, $row, $now)->current;
                    } catch (UnreadableSecret) {
                        $old = null;
                    }
                    if ($old !== null && !hash_equals($old, $value)) {
                        $previous = $old;
                        $action = self::ROTATED;
                    }
                }
            }
            $expires = $previous === null ? null : $now + $graceSeconds;
            $this->write($userId, $name, $value, $now, $previous, $expires, $now);
            $this->recordChange($userId, $name, $action, $actorUserId, $via, $expires, $now);

            return $action;
        });
    }

    /** Remove the account's $name, recorded; whether one was there. */
    public function clear(int $userId, string $name, ?int $actorUserId, string $via, int $now): bool
    {
        self::checkName($name);
        self::checkVia($via);

        return $this->inTransaction(function () use ($userId, $name, $actorUserId, $via, $now): bool {
            if ($this->row($userId, $name, true) === null) {
                return false;
            }
            $stmt = $this->conn->prepareWrite('DELETE FROM `' . self::TABLE . '` WHERE user_id = ? AND secret_name = ?');
            $this->conn->bind($stmt, 'is', [$userId, $name]);
            $this->conn->executeUpdate($stmt);
            $this->recordChange($userId, $name, self::CLEARED, $actorUserId, $via, null, $now);

            return true;
        });
    }

    /**
     * A value kept elsewhere before this store (a plaintext column), stored
     * as it is unless the account already has one here, which is newer and
     * wins. Whether it was written.
     */
    public function migrate(int $userId, string $name, #[\SensitiveParameter] string $value, int $now): bool
    {
        self::checkName($name);

        return $this->inTransaction(function () use ($userId, $name, $value, $now): bool {
            if ($this->row($userId, $name, true) !== null) {
                return false;
            }
            $this->write($userId, $name, $value, $now, null, null, $now);
            $this->recordChange($userId, $name, self::MIGRATED, null, 'upgrade', null, $now);

            return true;
        });
    }

    /**
     * What may be shown about the secret, read without opening it: when it
     * was set and until when the one it replaced is still accepted.
     *
     * @return array{set_at: int, previous_expires_at: int|null}|null null when none is stored
     */
    public function marker(int $userId, string $name, int $now): ?array
    {
        self::checkName($name);
        $row = $this->row($userId, $name, false);
        if ($row === null) {
            return null;
        }
        $expires = self::liveExpiry($row, $now);

        return ['set_at' => (int) $row['set_at'], 'previous_expires_at' => $expires];
    }

    /**
     * The account's $name, opened; null when none is stored.
     *
     * @throws UnreadableSecret when one is stored and cannot be opened
     */
    public function open(int $userId, string $name, int $now): ?IntegrationSecret
    {
        self::checkName($name);
        $row = $this->row($userId, $name, false);

        return $row === null ? null : $this->openRow($userId, $name, $row, $now);
    }

    /**
     * Every live account's $name, by account id in account order: each one
     * opened, or the reason it could not be. A deleted account owns no
     * secret (Prosper202\PaymentNotification\AccountSecrets says why), and
     * one account's unreadable secret does not stop the others'.
     *
     * @return array<int, IntegrationSecret|UnreadableSecret>
     */
    public function openEach(string $name, int $now): array
    {
        self::checkName($name);
        $stmt = $this->conn->prepareWrite(
            'SELECT s.user_id, s.key_id, s.wrapped_key, s.sealed, s.set_at, s.previous_sealed, s.previous_expires_at'
            . ' FROM 202_integration_secrets AS s INNER JOIN 202_users AS u ON u.user_id = s.user_id'
            . ' WHERE s.secret_name = ? AND u.user_deleted = 0 ORDER BY s.user_id'
        );
        $this->conn->bind($stmt, 's', [$name]);
        $secrets = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $userId = (int) $row['user_id'];
            try {
                $secrets[$userId] = $this->openRow($userId, $name, $row, $now);
            } catch (UnreadableSecret $e) {
                $secrets[$userId] = $e;
            }
        }

        return $secrets;
    }

    /** @param array<string, mixed> $row */
    private function openRow(int $userId, string $name, array $row, int $now): IntegrationSecret
    {
        try {
            $key = $this->keyring->keyFor((string) $row['key_id']);
        } catch (SecretKeyUnavailable | MalformedSecretKey $e) {
            // Not a failed query: that is the database's error, passed on as it is.
            throw new UnreadableSecret($name, $userId, $e->getMessage(), $e);
        }
        try {
            $dataKey = SealedSecret::open(
                (string) $row['wrapped_key'],
                $key,
                self::keyAad($name, $userId, (string) $row['key_id']),
                'its data key is not a v1 ciphertext',
                'its data key does not open under ' . $row['key_id'] . ' (altered, or copied from another account or secret)'
            );
            if (strlen($dataKey) !== 32) {
                throw new \RuntimeException('its data key is not 32 bytes');
            }
            $current = self::openValue((string) $row['sealed'], $dataKey, $name, $userId, 'current');
            $expires = self::liveExpiry($row, $now);
            $previous = $expires === null || !is_string($row['previous_sealed'] ?? null)
                ? null : self::openValue($row['previous_sealed'], $dataKey, $name, $userId, 'previous');
        } catch (\RuntimeException $e) {
            throw new UnreadableSecret($name, $userId, $e->getMessage(), $e);
        }

        return new IntegrationSecret($current, (int) $row['set_at'], $previous, $previous === null ? null : $expires);
    }

    private static function openValue(string $sealed, #[\SensitiveParameter] string $dataKey, string $name, int $userId, string $slot): string
    {
        return SealedSecret::open(
            $sealed,
            $dataKey,
            self::valueAad($name, $userId, $slot),
            'its ' . $slot . ' value is not a v1 ciphertext',
            'its ' . $slot . ' value does not open (altered, or copied from another account, secret or slot)'
        );
    }

    /** @param array<string, mixed> $row */
    private static function liveExpiry(array $row, int $now): ?int
    {
        $expires = $row['previous_expires_at'] ?? null;
        if ($expires === null || !is_string($row['previous_sealed'] ?? null)) {
            return null;
        }

        return (int) $expires > $now ? (int) $expires : null;
    }

    private function write(
        int $userId,
        string $name,
        #[\SensitiveParameter] string $value,
        int $setAt,
        #[\SensitiveParameter] ?string $previous,
        ?int $previousExpiresAt,
        int $now
    ): void {
        [$keyId, $key] = $this->keyring->sealingKey();
        if (SecretsKeyring::kindOf($keyId) === SecretsKeyring::DATABASE) {
            $this->refuseDowngrade($name, $userId);
        }
        $dataKey = random_bytes(32);
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO `' . self::TABLE . '` (user_id, secret_name, ' . self::COLUMNS . ', updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE key_id = VALUES(key_id), wrapped_key = VALUES(wrapped_key), sealed = VALUES(sealed),'
            . ' set_at = VALUES(set_at), previous_sealed = VALUES(previous_sealed),'
            . ' previous_expires_at = VALUES(previous_expires_at), updated_at = VALUES(updated_at)'
        );
        $this->conn->bind($stmt, 'issssisii', [
            $userId,
            $name,
            $keyId,
            SealedSecret::seal($dataKey, $key, self::keyAad($name, $userId, $keyId), 'Sealing the data key of ' . $name . ' failed'),
            SealedSecret::seal($value, $dataKey, self::valueAad($name, $userId, 'current'), 'Encrypting ' . $name . ' failed'),
            $setAt,
            $previous === null ? null
                : SealedSecret::seal($previous, $dataKey, self::valueAad($name, $userId, 'previous'), 'Encrypting ' . $name . ' failed'),
            $previousExpiresAt,
            $now,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    private function refuseDowngrade(string $name, int $userId): void
    {
        $stmt = $this->conn->prepareWrite('SELECT key_id FROM `' . self::TABLE . "` WHERE key_id LIKE 'external:%' LIMIT 1");
        $row = $this->conn->fetchOne($stmt);
        if ($row !== null) {
            throw new \RuntimeException('The ' . $name . ' of account ' . $userId . ' was not saved: this install\'s integration'
                . ' secrets are sealed with the external key ' . $row['key_id'] . ', and this process has none ('
                . SecretsKeyring::ENV_KEY . ' or ' . SecretsKeyring::ENV_FILE . '); it does not seal with the database\'s key instead');
        }
    }

    private function recordChange(int $userId, string $name, string $action, ?int $actorUserId, string $via, ?int $previousExpiresAt, int $now): void
    {
        $row = $this->row($userId, $name, false);
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO `' . self::EVENTS . '` (user_id, secret_name, action, actor_user_id, via, key_id, previous_expires_at, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'ississii', [
            $userId, $name, $action, $actorUserId, $via, $row === null ? null : (string) $row['key_id'], $previousExpiresAt, $now,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /** @return array<string, mixed>|null the row as stored, locked for this transaction when $forUpdate */
    private function row(int $userId, string $name, bool $forUpdate): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT ' . self::COLUMNS . ' FROM `' . self::TABLE . '` WHERE user_id = ? AND secret_name = ? '
            . ($forUpdate ? 'FOR UPDATE' : 'LIMIT 1')
        );
        $this->conn->bind($stmt, 'is', [$userId, $name]);

        return $this->conn->fetchOne($stmt);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function inTransaction(callable $fn): mixed
    {
        return $this->conn->inTransaction() ? $this->conn->joinTransaction($fn) : $this->conn->transaction($fn);
    }

    private static function keyAad(string $name, int $userId, string $keyId): string
    {
        return 'p202-integration-dek-v1|' . $name . '|' . $userId . '|' . $keyId;
    }

    private static function valueAad(string $name, int $userId, string $slot): string
    {
        return 'p202-integration-secret-v1|' . $name . '|' . $userId . '|' . $slot;
    }

    private static function checkName(string $name): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name) !== 1) {
            throw new \InvalidArgumentException('"' . $name . '" is not an integration secret name');
        }
    }

    private static function checkVia(string $via): void
    {
        if (!in_array($via, self::VIA, true)) {
            throw new \InvalidArgumentException('"' . $via . '" is not one of ' . implode(', ', self::VIA));
        }
    }
}
