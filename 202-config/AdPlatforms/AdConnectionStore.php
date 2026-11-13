<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\Security\MalformedSecretKey;
use Prosper202\Security\SealedSecret;

/**
 * An account's connections to an ad platform's own API (202_ad_connections):
 * the one place they are read and written, for the API, the Setup page and
 * the platform's setup steps.
 *
 * Credentials (the platform key, and what setup created with it) are sealed
 * by SealedSecret under their own key, bound to the connection and its
 * owner, and never served: reads say which are set. What the platform says
 * of its account (name, currency, time zone) is read again at each check.
 */
final class AdConnectionStore
{
    public const SECRET_NAME = 'ad_connection_credential';
    public const MAX_PER_ACCOUNT = 10;
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_KEY_REFUSED = 'key_refused';
    public const STATUS_UNREADABLE = 'credentials_unreadable';
    /** The credentials a connection can hold, by platform. */
    public const CREDENTIALS = ['openai_ads' => ['advertiser_api_key', 'conversions_api_key']];
    private const AAD = 'p202-ad-connection-credential-v1|';
    private const COLUMNS = 'connection_id, user_id, platform, ppc_account_id, account_ref, name, currency, timezone, credentials, setup, status, last_error, checked_at, created_at, updated_at';

    private ?string $key = null;

    public function __construct(private readonly Connection $conn)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $userId): array
    {
        $stmt = $this->conn->prepareRead('SELECT ' . self::COLUMNS . ' FROM `' . TableRegistry::AD_CONNECTIONS . '` WHERE user_id = ? AND deleted_at IS NULL ORDER BY connection_id');
        $this->conn->bind($stmt, 'i', [$userId]);
        $rows = $this->conn->fetchAll($stmt);
        $currency = $rows === [] ? 'USD' : $this->accountCurrency($userId);

        return array_map(fn (array $row): array => $this->shape($row, $currency), $rows);
    }

    /** @return array<string, mixed>|null */
    public function get(int $userId, int $connectionId): ?array
    {
        $row = $this->row($userId, $connectionId);

        return $row === null ? null : $this->shape($row, $this->accountCurrency($userId));
    }

    /**
     * A new connection to the account $account describes (as the platform answered for the key).
     *
     * @param array{ref: string, name: string, currency: string, timezone: string} $account
     * @param array<string, string> $credentials
     * @return array<string, mixed> as get() shapes it
     */
    public function create(int $userId, string $platform, ?int $ppcAccountId, array $account, #[\SensitiveParameter] array $credentials, int $now): array
    {
        self::platform($platform);
        self::refuseUnknownCredentials($platform, $credentials);
        $this->ownPpcAccount($userId, $ppcAccountId);
        $account = self::account($account);
        $id = $this->conn->transaction(function () use ($userId, $platform, $ppcAccountId, $account, $credentials, $now): int {
            // The account's rows are locked, so two connects of one ad account cannot both pass the check.
            $mine = $this->conn->prepareWrite('SELECT connection_id, platform, account_ref FROM `' . TableRegistry::AD_CONNECTIONS . '` WHERE user_id = ? AND deleted_at IS NULL FOR UPDATE');
            $this->conn->bind($mine, 'i', [$userId]);
            $rows = $this->conn->fetchAll($mine);
            foreach ($rows as $row) {
                if ((string) $row['platform'] === $platform && (string) $row['account_ref'] === $account['ref']) {
                    throw new AdConnectionInputException('api_key', 'That key reaches ad account ' . $account['ref'] . ', which is already connected as connection ' . (int) $row['connection_id'] . '; replace its key there instead.');
                }
            }
            if (count($rows) >= self::MAX_PER_ACCOUNT) {
                throw new AdConnectionInputException('api_key', 'An account can have at most ' . self::MAX_PER_ACCOUNT . ' ad platform connections; delete one first.');
            }
            $stmt = $this->conn->prepareWrite('INSERT INTO `' . TableRegistry::AD_CONNECTIONS
                . "` (user_id, platform, ppc_account_id, account_ref, name, currency, timezone, credentials, setup, status, last_error, checked_at, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, '', '{}', ?, NULL, ?, ?, ?)");
            $this->conn->bind($stmt, 'isisssssiii', [
                $userId, $platform, $ppcAccountId, $account['ref'], $account['name'], $account['currency'], $account['timezone'],
                self::STATUS_CONNECTED, $now, $now, $now,
            ]);
            $id = $this->conn->executeInsert($stmt);
            $this->writeCredentials($userId, $id, $credentials);

            return $id;
        });

        return $this->get($userId, $id) ?? throw new \RuntimeException('ad connection ' . $id . ' was created but cannot be read back');
    }

    /** Link the connection to a traffic source account (or none). Null when the account has no such connection. */
    public function setPpcAccount(int $userId, int $connectionId, ?int $ppcAccountId, int $now): ?array
    {
        $this->ownPpcAccount($userId, $ppcAccountId);
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::AD_CONNECTIONS . '` SET ppc_account_id = ?, updated_at = ? WHERE connection_id = ? AND user_id = ? AND deleted_at IS NULL');
        $this->conn->bind($stmt, 'iiii', [$ppcAccountId, $now, $connectionId, $userId]);
        $this->conn->executeUpdate($stmt);

        return $this->get($userId, $connectionId);
    }

    /**
     * What a check found: the account as the platform describes it now (null when it was not read), the status, and
     * the error to show (null clears it). False when the account has no such connection.
     *
     * @param array{ref: string, name: string, currency: string, timezone: string}|null $account
     */
    public function recordCheck(int $userId, int $connectionId, ?array $account, string $status, ?string $error, int $now): bool
    {
        $error = $error === null ? null : mb_substr($error, 0, 255);
        if ($account === null) {
            $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::AD_CONNECTIONS . '` SET status = ?, last_error = ?, checked_at = ?, updated_at = ? WHERE connection_id = ? AND user_id = ? AND deleted_at IS NULL');
            $this->conn->bind($stmt, 'ssiiii', [$status, $error, $now, $now, $connectionId, $userId]);
        } else {
            $account = self::account($account);
            $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::AD_CONNECTIONS . '` SET name = ?, currency = ?, timezone = ?, status = ?, last_error = ?, checked_at = ?, updated_at = ?
                 WHERE connection_id = ? AND user_id = ? AND account_ref = ? AND deleted_at IS NULL');
            $this->conn->bind($stmt, 'sssssiiiis', [$account['name'], $account['currency'], $account['timezone'], $status, $error, $now, $now, $connectionId, $userId, $account['ref']]);
        }
        $this->conn->executeUpdate($stmt);

        return $this->row($userId, $connectionId) !== null;
    }

    /**
     * Set one credential (sealed with the rest), or remove it with null. False when the account has no such connection.
     */
    public function putCredential(int $userId, int $connectionId, string $name, #[\SensitiveParameter] ?string $value, int $now): bool
    {
        return $this->conn->transaction(function () use ($userId, $connectionId, $name, $value, $now): bool {
            $row = $this->locked($userId, $connectionId);
            if ($row === null) {
                return false;
            }
            self::refuseUnknownCredentials((string) $row['platform'], [$name => (string) $value]);
            // A new Advertiser API key is the repair for stored credentials that no longer open (forCalling() says
            // so): it starts from what can be read, or from nothing, and is sealed under a key minted if there is none.
            $credentials = $name === 'advertiser_api_key' && $value !== null
                ? ($this->openedOrNull($row) ?? [])
                : $this->opened($row);
            if ($value === null) {
                unset($credentials[$name]);
            } else {
                $credentials[$name] = $value;
            }
            $this->writeCredentials($userId, $connectionId, $credentials);
            $this->touch($userId, $connectionId, $now);

            return true;
        });
    }

    /**
     * Merge what setup made into the stored record: top-level keys replaced, `event_settings` merged by event type.
     * False when the account has no such connection.
     *
     * @param array<string, mixed> $patch
     */
    public function mergeSetup(int $userId, int $connectionId, array $patch, int $now): bool
    {
        return $this->conn->transaction(function () use ($userId, $connectionId, $patch, $now): bool {
            $row = $this->locked($userId, $connectionId);
            if ($row === null) {
                return false;
            }
            $setup = self::setupOf((string) $row['setup']) ?? [];
            foreach ($patch as $key => $value) {
                if ($key === 'event_settings' && is_array($value)) {
                    $setup['event_settings'] = array_replace(is_array($setup['event_settings'] ?? null) ? $setup['event_settings'] : [], $value);
                } elseif ($value === null) {
                    unset($setup[$key]);
                } else {
                    $setup[$key] = $value;
                }
            }
            $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::AD_CONNECTIONS . '` SET setup = ?, updated_at = ? WHERE connection_id = ? AND user_id = ? AND deleted_at IS NULL');
            $this->conn->bind($stmt, 'siii', [self::json($setup), $now, $connectionId, $userId]);
            $this->conn->execute($stmt);

            return true;
        });
    }

    /**
     * The connection as the platform's calls need it: its row, setup decoded and credentials opened. Null when it is
     * gone.
     *
     * @return array{connection_id: int, user_id: int, platform: string, account_ref: string, name: string, setup: array<string, mixed>, credentials: array<string, string>}|null
     * @throws AdConnectionInputException when stored credentials cannot be opened (CLAUDE.md #11): replacing the key mends it
     */
    public function forCalling(int $userId, int $connectionId): ?array
    {
        $row = $this->row($userId, $connectionId);
        if ($row === null) {
            return null;
        }

        return [
            'connection_id' => $connectionId,
            'user_id' => $userId,
            'platform' => (string) $row['platform'],
            'account_ref' => (string) $row['account_ref'],
            'name' => (string) $row['name'],
            'setup' => self::setupOf((string) $row['setup']) ?? throw new \RuntimeException('The setup record of ad connection ' . $connectionId . ' cannot be read'),
            'credentials' => $this->openedForCalling($row),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function openedForCalling(array $row): array
    {
        // The key is read first and outside the catch: a database error is the caller's, never "unreadable".
        try {
            $this->key(false);
        } catch (AdConnectionKeyMissing $e) {
            throw new AdConnectionInputException('api_key', $e->getMessage() . '; replace the Advertiser API key (p202 ad-connection update --api-key-file).');
        }
        try {
            return $this->opened($row);
        } catch (\JsonException | \RuntimeException $e) {
            throw new AdConnectionInputException('api_key', $e->getMessage() . ' (p202 ad-connection update --api-key-file).');
        }
    }

    /** Soft delete; the stored credentials are wiped. Whether there was one. */
    public function delete(int $userId, int $connectionId, int $now): bool
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::AD_CONNECTIONS . "` SET deleted_at = ?, credentials = '', updated_at = ? WHERE connection_id = ? AND user_id = ? AND deleted_at IS NULL");
        $this->conn->bind($stmt, 'iiii', [$now, $now, $connectionId, $userId]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    public static function platform(string $platform): string
    {
        if (!isset(self::CREDENTIALS[$platform])) {
            throw new AdConnectionInputException('platform', 'platform must be one of: ' . implode(', ', array_keys(self::CREDENTIALS)) . '.');
        }

        return $platform;
    }

    /** @param array<string, string> $credentials */
    private static function refuseUnknownCredentials(string $platform, #[\SensitiveParameter] array $credentials): void
    {
        foreach (array_keys($credentials) as $name) {
            if (!in_array($name, self::CREDENTIALS[$platform] ?? [], true)) {
                throw new \LogicException('credential ' . $name . ' is not one a ' . $platform . ' connection holds');
            }
        }
    }

    /**
     * @param array{ref: string, name: string, currency: string, timezone: string} $account
     * @return array{ref: string, name: string, currency: string, timezone: string}
     */
    private static function account(array $account): array
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $account['ref']) !== 1) {
            throw new \UnexpectedValueException('the platform named its account with an id that is not 1 to 64 letters, digits, _ or -');
        }
        if (preg_match('/\A[A-Z]{3}\z/D', $account['currency']) !== 1) {
            throw new \UnexpectedValueException('the platform gave its account a currency that is not a three-letter code');
        }
        if (preg_match('#\A[A-Za-z0-9_+/-]{1,64}\z#D', $account['timezone']) !== 1) {
            throw new \UnexpectedValueException('the platform gave its account a time zone that is not a zone name');
        }
        $name = trim($account['name']);

        return ['ref' => $account['ref'], 'name' => mb_substr($name === '' ? $account['ref'] : $name, 0, 100), 'currency' => $account['currency'], 'timezone' => $account['timezone']];
    }

    /** A traffic source account named by id is the caller's (CLAUDE.md #27). */
    public function ownPpcAccount(int $userId, ?int $ppcAccountId): void
    {
        if ($ppcAccountId === null) {
            return;
        }
        $stmt = $this->conn->prepareRead('SELECT ppc_account_id FROM `' . TableRegistry::PPC_ACCOUNTS . '` WHERE ppc_account_id = ? AND user_id = ? AND ppc_account_deleted = 0 LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$ppcAccountId, $userId]);
        if ($this->conn->fetchOne($stmt) === null) {
            throw new AdConnectionInputException('ppc_account_id', 'ppc_account_id ' . $ppcAccountId . ' is not one of your traffic source accounts (p202 ppc-account list).');
        }
    }

    private function accountCurrency(int $userId): string
    {
        $stmt = $this->conn->prepareRead('SELECT user_account_currency FROM `' . TableRegistry::USERS_PREF . '` WHERE user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$userId]);
        $currency = $this->conn->fetchOne($stmt)['user_account_currency'] ?? null;

        // The column's own default, which an account without a row reads as.
        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'USD';
    }

    /** @return array<string, mixed>|null */
    private function row(int $userId, int $connectionId): ?array
    {
        $stmt = $this->conn->prepareRead('SELECT ' . self::COLUMNS . ' FROM `' . TableRegistry::AD_CONNECTIONS . '` WHERE connection_id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$connectionId, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    /** @return array<string, mixed>|null */
    private function locked(int $userId, int $connectionId): ?array
    {
        $stmt = $this->conn->prepareWrite('SELECT ' . self::COLUMNS . ' FROM `' . TableRegistry::AD_CONNECTIONS . '` WHERE connection_id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        $this->conn->bind($stmt, 'ii', [$connectionId, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    private function touch(int $userId, int $connectionId, int $now): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::AD_CONNECTIONS . '` SET updated_at = ? WHERE connection_id = ? AND user_id = ? AND deleted_at IS NULL');
        $this->conn->bind($stmt, 'iii', [$now, $connectionId, $userId]);
        $this->conn->execute($stmt);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row, string $accountCurrency): array
    {
        $id = (int) $row['connection_id'];
        $setup = self::setupOf((string) $row['setup']);
        if ($setup === null) {
            // One unreadable row is shown as such, not a failed list (CLAUDE.md #24).
            error_log('ad connections: the setup record of connection ' . $id . ' cannot be read');
        }
        $status = (string) $row['status'];
        $set = array_fill_keys(self::CREDENTIALS[(string) $row['platform']] ?? [], false);
        if ((string) $row['credentials'] !== '') {
            $opened = $this->openedOrNull($row);
            if ($opened === null) {
                $status = self::STATUS_UNREADABLE;
            } else {
                foreach (array_keys($set) as $name) {
                    $set[$name] = is_string($opened[$name] ?? null) && $opened[$name] !== '';
                }
            }
        }
        $currency = (string) $row['currency'];

        return [
            'connection_id' => $id,
            'platform' => (string) $row['platform'],
            'ppc_account_id' => $row['ppc_account_id'] === null ? null : (int) $row['ppc_account_id'],
            'account' => [
                'id' => (string) $row['account_ref'],
                'name' => (string) $row['name'],
                'currency' => $currency,
                'timezone' => (string) $row['timezone'],
            ],
            'currency_matches_account' => $currency === $accountCurrency,
            'status' => $status,
            'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
            'checked_at' => $row['checked_at'] === null ? null : (int) $row['checked_at'],
            'credentials_set' => $set,
            'setup' => $setup === null ? ['setup_invalid' => true] : ($setup === [] ? new \stdClass() : $setup),
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
        ];
    }

    /** @return array<string, mixed>|null null when it is not a JSON object */
    private static function setupOf(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : null;
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value === [] ? new \stdClass() : $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $credentials */
    private function writeCredentials(int $userId, int $connectionId, #[\SensitiveParameter] array $credentials): void
    {
        $sealed = $credentials === [] ? '' : SealedSecret::seal(self::json($credentials), $this->key(true), self::AAD . $connectionId . '|' . $userId, 'Encrypting the connection\'s credentials failed');
        $update = $this->conn->prepareWrite('UPDATE `' . TableRegistry::AD_CONNECTIONS . '` SET credentials = ? WHERE connection_id = ? AND user_id = ? AND deleted_at IS NULL');
        $this->conn->bind($update, 'sii', [$sealed, $connectionId, $userId]);
        $this->conn->execute($update);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     * @throws \RuntimeException when they cannot be opened
     */
    private function opened(array $row): array
    {
        $sealed = (string) $row['credentials'];
        if ($sealed === '') {
            return [];
        }
        $id = (int) $row['connection_id'];
        $json = SealedSecret::open(
            $sealed,
            $this->key(false),
            self::AAD . $id . '|' . (int) $row['user_id'],
            'The stored credentials of ad connection ' . $id . ' are not a v1 ciphertext; replace the Advertiser API key',
            'The stored credentials of ad connection ' . $id . ' do not decrypt (wrong key, altered, or copied from another connection); replace the Advertiser API key'
        );
        $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The stored credentials of ad connection ' . $id . ' are not a JSON object; replace the Advertiser API key');
        }
        $credentials = [];
        foreach ($decoded as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $credentials[$name] = $value;
            }
        }

        return $credentials;
    }

    /**
     * Opened credentials, or null when they cannot be. The key is read outside the forgiving catch, so a database
     * error reaches the caller and is never shown as unreadable credentials.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>|null
     */
    private function openedOrNull(array $row): ?array
    {
        try {
            $this->key(false);
        } catch (AdConnectionKeyMissing) {
            return null;
        }
        try {
            return $this->opened($row);
        } catch (\JsonException | \RuntimeException) {
            return null;
        }
    }

    /**
     * The key; minted first when $mint and absent. An existing key is never replaced.
     *
     * @throws AdConnectionKeyMissing when there is no well-formed key (a database error is thrown as itself)
     */
    private function key(bool $mint): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if ($mint) {
            SealedSecret::mintKey($this->conn, self::SECRET_NAME);
        }
        try {
            $key = SealedSecret::readKey($this->conn, self::SECRET_NAME);
        } catch (MalformedSecretKey) {
            $key = null;
        }
        if ($key === null) {
            throw new AdConnectionKeyMissing('The ad connections\' credential key is missing from 202_deployment_secrets or is not 64 lower-case hex characters; connect again');
        }

        // Only a plain read is kept: a key minted in a transaction may be rolled back with it (CLAUDE.md #11).
        return $mint ? $key : $this->key = $key;
    }
}
