<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * Integration records (202_ltv_integrations): named provider configurations
 * for inbound ESP / membership / billing pushes. Owns the validation and SQL
 * so the API controller and the settings UI cannot drift.
 */
final class MysqlIntegrationRepository
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * @return list<array<string, mixed>> config decoded to an array when set
     */
    public function list(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT integration_id, provider, name, config, status, created_at, updated_at
             FROM 202_ltv_integrations WHERE user_id = ? ORDER BY integration_id ASC'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $rows = $this->conn->fetchAll($stmt);
        foreach ($rows as &$row) {
            if (isset($row['config']) && is_string($row['config'])) {
                $decoded = json_decode($row['config'], true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    // Corrupt stored JSON must be visible, not presented as
                    // "no config set" (CLAUDE.md: no silent data loss).
                    error_log(
                        'LTV integration #' . (int) ($row['integration_id'] ?? 0)
                        . ' has undecodable config JSON: ' . json_last_error_msg()
                    );
                    $row['config'] = null;
                    $row['config_invalid'] = true;
                } else {
                    $row['config'] = $decoded;
                }
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $config provider-specific settings
     */
    public function create(int $userId, string $provider, string $name = '', ?array $config = null): int
    {
        $provider = strtolower(trim($provider));
        if ($provider === '' || preg_match('/^[a-z0-9_\-]{1,50}$/', $provider) !== 1) {
            throw new RuntimeException('provider is required (a-z, 0-9, dash/underscore, max 50 chars)');
        }
        $name = trim($name);
        if ($name === '') {
            $name = $provider;
        }

        $encodedConfig = null;
        if ($config !== null) {
            $encodedConfig = json_encode($config);
            if ($encodedConfig === false) {
                throw new RuntimeException('config could not be encoded as JSON');
            }
        }

        $now = time();
        $stmt = $this->conn->prepareWrite(
            "INSERT INTO 202_ltv_integrations (user_id, provider, name, config, api_key_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, NULL, 'active', ?, ?)"
        );
        $this->conn->bind($stmt, 'isssii', [$userId, $provider, $name, $encodedConfig, $now, $now]);

        return $this->conn->executeInsert($stmt);
    }

    public function delete(int $userId, int $integrationId): void
    {
        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_ltv_integrations WHERE integration_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$integrationId, $userId]);
        if ($this->conn->executeUpdate($stmt) === 0) {
            // Typed so callers map exactly this to 404; DB failures above
            // throw plain RuntimeException and must not read as "gone".
            throw new RecordNotFoundException('Integration not found');
        }
    }
}
