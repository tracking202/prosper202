<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\OfflineConversion\Consent;

/**
 * An account's destinations (202_destinations): the one place they are
 * read and written, for the API, the Setup page and the worker.
 *
 * Credentials are sealed as IntegrityCredentialStore seals a service
 * account: AES-256-GCM under a key minted once in 202_deployment_secrets,
 * bound to the destination and its owner, and never served back; reads say
 * which credential fields are set.
 */
final class DestinationStore
{
    public const SECRET_NAME = 'destination_credential';
    public const MAX_PER_ACCOUNT = 25;
    /** The settings every type takes. */
    public const COMMON_SETTINGS = ['campaign_ids', 'send_user_data', 'consent'];
    private const AAD = 'p202-destination-credential-v1|';
    private const CIPHER = 'aes-256-gcm';
    private const COLUMNS = 'destination_id, user_id, type, name, enabled, settings, credentials, created_at, updated_at';

    /** @param array<string, DestinationType>|null $types */
    public function __construct(private readonly Connection $conn, private ?array $types = null)
    {
    }

    /** @return array<string, DestinationType> */
    public function types(): array
    {
        return $this->types ??= [Ga4Destination::TYPE => new Ga4Destination()];
    }

    public function type(string $type): DestinationType
    {
        return $this->types()[$type] ?? throw new DestinationInputException('type', 'type must be one of: ' . implode(', ', array_keys($this->types())) . '.');
    }

    /** @return list<array<string, mixed>> */
    public function list(int $userId): array
    {
        $stmt = $this->conn->prepareRead('SELECT ' . self::COLUMNS . ' FROM `' . TableRegistry::DESTINATIONS . '` WHERE user_id = ? AND deleted_at IS NULL ORDER BY destination_id');
        $this->conn->bind($stmt, 'i', [$userId]);

        return array_map(self::shape(...), $this->conn->fetchAll($stmt));
    }

    /** @return array<string, mixed>|null */
    public function get(int $userId, int $destinationId): ?array
    {
        $row = $this->row($userId, $destinationId);

        return $row === null ? null : self::shape($row);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $credentials
     * @return array<string, mixed> the destination as get() shapes it
     */
    public function create(int $userId, string $type, string $name, array $settings, array $credentials, int $now): array
    {
        $adapter = $this->type($type);
        $name = self::name($name);
        [$settings, $credentials] = $this->settings($userId, $adapter, $settings, $credentials);
        $id = $this->conn->transaction(function () use ($userId, $type, $name, $settings, $credentials, $now): int {
            $count = $this->conn->prepareWrite('SELECT COUNT(*) AS n FROM `' . TableRegistry::DESTINATIONS . '` WHERE user_id = ? AND deleted_at IS NULL FOR UPDATE');
            $this->conn->bind($count, 'i', [$userId]);
            if ((int) ($this->conn->fetchOne($count)['n'] ?? 0) >= self::MAX_PER_ACCOUNT) {
                throw new DestinationInputException('type', 'An account can have at most ' . self::MAX_PER_ACCOUNT . ' destinations; delete one first.');
            }
            $stmt = $this->conn->prepareWrite('INSERT INTO `' . TableRegistry::DESTINATIONS
                . "` (user_id, type, name, enabled, settings, credentials, created_at, updated_at) VALUES (?, ?, ?, 1, ?, '', ?, ?)");
            $this->conn->bind($stmt, 'isssii', [$userId, $type, $name, self::json($settings), $now, $now]);
            $id = $this->conn->executeInsert($stmt);
            $this->writeCredentials($userId, $id, (array) $credentials);

            return $id;
        });

        return $this->get($userId, $id) ?? throw new \RuntimeException('destination ' . $id . ' was created but cannot be read back');
    }

    /**
     * Change what $patch names: name, enabled, settings (replaced whole) and
     * credentials (replaced whole). Null when the account has no such destination.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $destinationId, array $patch, int $now): ?array
    {
        $row = $this->row($userId, $destinationId);
        if ($row === null) {
            return null;
        }
        $name = (string) $row['name'];
        if (array_key_exists('name', $patch)) {
            $name = self::name(is_string($patch['name']) ? $patch['name'] : '');
        }
        $enabled = (int) $row['enabled'] === 1;
        if (array_key_exists('enabled', $patch)) {
            if (!is_bool($patch['enabled'])) {
                throw new DestinationInputException('enabled', 'enabled must be true or false.');
            }
            $enabled = $patch['enabled'];
        }
        $settings = self::decode((string) $row['settings']);
        $credentials = null;
        if (array_key_exists('settings', $patch) || array_key_exists('credentials', $patch)) {
            // A null is refused below, never read as "keep" (CLAUDE.md #4).
            $settings = array_key_exists('settings', $patch) ? $patch['settings'] : $settings;
            if (array_key_exists('credentials', $patch) && !is_array($patch['credentials'])) {
                throw new DestinationInputException('credentials', 'credentials must be an object; leave it out to keep the stored ones.');
            }
            $given = $patch['credentials'] ?? null;
            if (!is_array($settings) || ($given !== null && !is_array($given))) {
                throw new DestinationInputException(is_array($settings) ? 'credentials' : 'settings', 'settings and credentials must be objects.');
            }
            [$settings, $credentials] = $this->settings($userId, $this->type((string) $row['type']), $settings, $given);
        }
        $this->conn->transaction(function () use ($name, $enabled, $settings, $now, $userId, $destinationId, $credentials): void {
            $update = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATIONS . '` SET name = ?, enabled = ?, settings = ?, updated_at = ?
                 WHERE destination_id = ? AND user_id = ? AND deleted_at IS NULL');
            $this->conn->bind($update, 'sisiii', [$name, $enabled ? 1 : 0, self::json($settings), $now, $destinationId, $userId]);
            $this->conn->execute($update);
            if ($credentials !== null) {
                $this->writeCredentials($userId, $destinationId, $credentials);
            }
        });

        return $this->get($userId, $destinationId);
    }

    /** Soft delete; what it had queued and not sent is cancelled. Whether there was one. */
    public function delete(int $userId, int $destinationId, int $now): bool
    {
        return $this->conn->transaction(function () use ($userId, $destinationId, $now): bool {
            $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATIONS . "` SET deleted_at = ?, enabled = 0, credentials = '' WHERE destination_id = ? AND user_id = ? AND deleted_at IS NULL");
            $this->conn->bind($stmt, 'iii', [$now, $destinationId, $userId]);
            if ($this->conn->executeUpdate($stmt) !== 1) {
                return false;
            }
            $cancel = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
                . "` SET status = 'cancelled', last_error = 'the destination was deleted before this was sent' WHERE destination_id = ? AND status = 'pending'");
            $this->conn->bind($cancel, 'i', [$destinationId]);
            $this->conn->executeUpdate($cancel);

            return true;
        });
    }

    /**
     * A destination as the worker sends with it: settings decoded and
     * credentials opened; null when it is gone.
     *
     * @return array{destination_id: int, user_id: int, type: string, enabled: bool, settings: array<string, mixed>, credentials: array<string, mixed>}|null
     * @throws \RuntimeException when a stored row cannot be read (CLAUDE.md #11)
     */
    public function forSending(int $userId, int $destinationId): ?array
    {
        $row = $this->row($userId, $destinationId);
        if ($row === null) {
            return null;
        }
        $sealed = (string) $row['credentials'];
        $credentials = $sealed === '' ? [] : self::decode(self::open($sealed, $this->key(false), $destinationId, $userId));

        return [
            'destination_id' => $destinationId,
            'user_id' => $userId,
            'type' => (string) $row['type'],
            'enabled' => (int) $row['enabled'] === 1,
            'settings' => self::decode((string) $row['settings']),
            'credentials' => $credentials,
        ];
    }

    /**
     * The framework's settings (campaign_ids, send_user_data, consent) and the type's.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed>|null $credentials
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function settings(int $userId, DestinationType $adapter, array $settings, ?array $credentials): array
    {
        // A key nobody reads is refused, not dropped (CLAUDE.md #4).
        foreach ([['settings', $settings, [...self::COMMON_SETTINGS, ...$adapter->settingKeys()]], ['credentials', $credentials ?? [], $adapter->credentialKeys()]] as [$what, $given, $known]) {
            foreach (array_keys($given) as $key) {
                if (!in_array($key, $known, true)) {
                    throw new DestinationInputException($what . '.' . $key, $what . '.' . $key . ' is not a field this destination takes; it takes ' . implode(', ', $known) . '.');
                }
            }
        }
        [$own, $credentials] = $adapter->validate($settings, $credentials);
        $campaigns = $settings['campaign_ids'] ?? [];
        if (!is_array($campaigns) || !array_is_list($campaigns) || count($campaigns) > 500) {
            throw new DestinationInputException('settings.campaign_ids', 'campaign_ids must be a list of campaign ids (empty for every campaign).');
        }
        $ids = [];
        foreach ($campaigns as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new DestinationInputException('settings.campaign_ids', 'campaign_ids must hold campaign ids (p202 campaign list).');
            }
            $ids[$id] = $id;
        }
        if ($ids !== []) {
            // A stored id is a claim about ownership (CLAUDE.md #27).
            $stmt = $this->conn->prepareRead('SELECT COUNT(*) AS n FROM 202_aff_campaigns WHERE user_id = ? AND aff_campaign_deleted = 0 AND aff_campaign_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $this->conn->bind($stmt, 'i' . str_repeat('i', count($ids)), [$userId, ...array_values($ids)]);
            if ((int) ($this->conn->fetchOne($stmt)['n'] ?? 0) !== count($ids)) {
                throw new DestinationInputException('settings.campaign_ids', 'campaign_ids names a campaign that is not one of yours (p202 campaign list).');
            }
        }
        $sendUserData = $settings['send_user_data'] ?? false;
        if (!is_bool($sendUserData)) {
            throw new DestinationInputException('settings.send_user_data', 'send_user_data must be true or false.');
        }
        $consent = $settings['consent'] ?? [];
        if (!is_array($consent) || array_diff(array_keys($consent), ['ad_user_data', 'ad_personalization']) !== []) {
            throw new DestinationInputException('settings.consent', 'consent may hold ad_user_data and ad_personalization.');
        }
        foreach ($consent as $signal => $value) {
            if ($value !== null && !in_array($value, Consent::values(), true)) {
                throw new DestinationInputException('settings.consent.' . $signal, 'consent.' . $signal . ' must be one of: ' . implode(', ', Consent::values()) . ', or null.');
            }
        }

        return [$own + [
            'campaign_ids' => array_values($ids),
            'send_user_data' => $sendUserData,
            'consent' => array_filter($consent, static fn ($v): bool => $v !== null),
        ], $credentials];
    }

    /** @return array<string, mixed>|null */
    private function row(int $userId, int $destinationId): ?array
    {
        $stmt = $this->conn->prepareRead('SELECT ' . self::COLUMNS . ' FROM `' . TableRegistry::DESTINATIONS . '` WHERE destination_id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$destinationId, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        try {
            $settings = self::decode((string) $row['settings']);
        } catch (\JsonException | \RuntimeException) {
            // One unreadable row is shown as such, not a failed list (CLAUDE.md #24).
            error_log('destinations: the settings of destination ' . (int) $row['destination_id'] . ' cannot be read');
            $settings = ['settings_invalid' => true];
        }

        return [
            'destination_id' => (int) $row['destination_id'],
            'type' => (string) $row['type'],
            'name' => (string) $row['name'],
            'enabled' => (int) $row['enabled'] === 1,
            'settings' => $settings === [] ? new \stdClass() : $settings,
            'credentials_set' => (string) $row['credentials'] !== '',
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $credentials */
    private function writeCredentials(int $userId, int $destinationId, array $credentials): void
    {
        $sealed = $credentials === [] ? '' : self::seal(self::json($credentials), $this->key(true), $destinationId, $userId);
        $update = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATIONS . '` SET credentials = ? WHERE destination_id = ? AND user_id = ? AND deleted_at IS NULL');
        $this->conn->bind($update, 'sii', [$sealed, $destinationId, $userId]);
        $this->conn->execute($update);
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DestinationInputException('name', 'name is required, at most 100 characters.');
        }

        return $name;
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value === [] ? new \stdClass() : $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('a stored destination field is not a JSON object');
        }

        return $decoded;
    }

    public static function seal(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $key, int $destinationId, int $userId): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD . $destinationId . '|' . $userId, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Encrypting the destination\'s credentials failed');
        }

        return 'v1.' . base64_encode($nonce . $tag . $ciphertext);
    }

    /** @throws \RuntimeException for a value that is not v1, or whose tag does not verify for this destination */
    public static function open(string $sealed, #[\SensitiveParameter] string $key, int $destinationId, int $userId): string
    {
        $raw = str_starts_with($sealed, 'v1.') ? base64_decode(substr($sealed, 3), true) : false;
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('The stored credentials of destination ' . $destinationId . ' are not a v1 ciphertext');
        }
        $plaintext = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), self::AAD . $destinationId . '|' . $userId);
        if ($plaintext === false) {
            throw new \RuntimeException('The stored credentials of destination ' . $destinationId . ' do not decrypt (wrong key, altered, or copied from another destination); set them again');
        }

        return $plaintext;
    }

    /** The key; minted first when $mint and absent. An existing key is never replaced. */
    private function key(bool $mint): string
    {
        if ($mint) {
            $insert = $this->conn->prepareWrite('INSERT INTO `' . TableRegistry::DEPLOYMENT_SECRETS . '` (secret_name, secret_value, created_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE secret_name = secret_name');
            $this->conn->bind($insert, 'ssi', [self::SECRET_NAME, bin2hex(random_bytes(32)), time()]);
            $this->conn->executeUpdate($insert);
        }
        $read = $this->conn->prepareWrite('SELECT secret_value FROM `' . TableRegistry::DEPLOYMENT_SECRETS . '` WHERE secret_name = ? LIMIT 1');
        $this->conn->bind($read, 's', [self::SECRET_NAME]);
        $hex = $this->conn->fetchOne($read)['secret_value'] ?? null;
        if (!is_string($hex) || preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new \RuntimeException('The destinations\' credential key is missing from 202_deployment_secrets or is not 64 lower-case hex characters; set the credentials again');
        }

        return (string) hex2bin($hex);
    }
}
