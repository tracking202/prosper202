<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use Prosper202\Attribution\Repository\SettingsRepositoryInterface;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Settings\Setting;
use Prosper202\Database\Connection;
use RuntimeException;

final class MysqlSettingRepository implements SettingsRepositoryInterface
{
    private readonly Connection $conn;

    /**
     * @param Connection|mysqli $connection Connection instance or legacy mysqli for backwards compatibility
     */
    public function __construct(Connection|mysqli $connection, ?mysqli $readConnection = null)
    {
        if ($connection instanceof Connection) {
            $this->conn = $connection;
        } else {
            $this->conn = new Connection($connection, $readConnection);
        }
    }

    public function findByScope(int $userId, ScopeType $scopeType, ?int $scopeId): ?Setting
    {
        $scopes = $this->findForScopes($userId, [
            ['type' => $scopeType, 'id' => $scopeId],
        ]);

        $key = $this->buildScopeKey($scopeType, $scopeId);

        return $scopes[$key] ?? null;
    }

    public function findForScopes(int $userId, array $scopes): array
    {
        if ($scopes === []) {
            return [];
        }

        $parts = [];
        $types = 'i';
        $params = [$userId];

        foreach ($scopes as $scope) {
            $scopeType = $scope['type'];
            $scopeId = $scope['id'];

            if (!$scopeType instanceof ScopeType) {
                throw new RuntimeException('Invalid scope type supplied to settings lookup.');
            }

            if ($scopeId === null) {
                $parts[] = '(scope_type = ? AND scope_id IS NULL)';
                $types .= 's';
                $params[] = $scopeType->value;
            } else {
                $parts[] = '(scope_type = ? AND scope_id = ?)';
                $types .= 'si';
                $params[] = $scopeType->value;
                $params[] = $scopeId;
            }
        }

        $sql = 'SELECT * FROM 202_attribution_settings WHERE user_id = ? AND (' . implode(' OR ', $parts) . ')';
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $params);
        $rows = $this->conn->fetchAll($stmt);

        $settings = [];
        foreach ($rows as $row) {
            $setting = Setting::fromDatabaseRow($row);
            $key = $this->buildScopeKey($setting->scopeType, $setting->scopeId);
            $settings[$key] = $setting;
        }

        return $settings;
    }

    public function save(Setting $setting): Setting
    {
        $row = $setting->toDatabaseRow();
        $enabled = $row['multi_touch_enabled'] ? 1 : 0;
        $enabledAt = $row['multi_touch_enabled_at'] !== null ? (int) $row['multi_touch_enabled_at'] : null;
        $disabledAt = $row['multi_touch_disabled_at'] !== null ? (int) $row['multi_touch_disabled_at'] : null;
        $scopeId = $row['scope_id'] !== null ? (int) $row['scope_id'] : null;
        $settingId = $row['setting_id'];

        if ($settingId === null) {
            // Build dynamic INSERT to handle nullable columns with SQL NULL
            $columns = ['user_id', 'scope_type', 'model_id', 'multi_touch_enabled', 'effective_at', 'created_at'];
            $placeholders = ['?', '?', '?', '?', '?', '?'];
            $types = 'isiiii';
            $params = [(int) $row['user_id'], (string) $row['scope_type'], (int) $row['model_id'], $enabled, (int) $row['effective_at'], (int) $row['created_at']];

            // scope_id — nullable int
            if ($scopeId !== null) {
                $columns[] = 'scope_id';
                $placeholders[] = '?';
                $types .= 'i';
                $params[] = $scopeId;
            } else {
                $columns[] = 'scope_id';
                $placeholders[] = 'NULL';
            }

            // multi_touch_enabled_at — nullable int
            if ($enabledAt !== null) {
                $columns[] = 'multi_touch_enabled_at';
                $placeholders[] = '?';
                $types .= 'i';
                $params[] = $enabledAt;
            } else {
                $columns[] = 'multi_touch_enabled_at';
                $placeholders[] = 'NULL';
            }

            // multi_touch_disabled_at — nullable int
            if ($disabledAt !== null) {
                $columns[] = 'multi_touch_disabled_at';
                $placeholders[] = '?';
                $types .= 'i';
                $params[] = $disabledAt;
            } else {
                $columns[] = 'multi_touch_disabled_at';
                $placeholders[] = 'NULL';
            }

            $columns[] = 'updated_at';
            $placeholders[] = '?';
            $params[] = (int) $row['updated_at'];
            $types .= 'i';

            $sql = 'INSERT INTO 202_attribution_settings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $this->conn->prepareWrite($sql);
            $this->conn->bind($stmt, $types, $params);

            $insertId = $this->conn->executeInsert($stmt);

            return $this->requireById($insertId);
        }

        // Build dynamic UPDATE to handle nullable columns with SQL NULL
        $sets = ['model_id = ?', 'multi_touch_enabled = ?'];
        $types = 'ii';
        $params = [(int) $row['model_id'], $enabled];

        if ($enabledAt !== null) {
            $sets[] = 'multi_touch_enabled_at = ?';
            $types .= 'i';
            $params[] = $enabledAt;
        } else {
            $sets[] = 'multi_touch_enabled_at = NULL';
        }

        if ($disabledAt !== null) {
            $sets[] = 'multi_touch_disabled_at = ?';
            $types .= 'i';
            $params[] = $disabledAt;
        } else {
            $sets[] = 'multi_touch_disabled_at = NULL';
        }

        $sets[] = 'effective_at = ?';
        $sets[] = 'updated_at = ?';
        $types .= 'ii';
        $params[] = (int) $row['effective_at'];
        $params[] = (int) $row['updated_at'];

        $params[] = (int) $settingId;
        $types .= 'i';

        $sql = 'UPDATE 202_attribution_settings SET ' . implode(', ', $sets) . ' WHERE setting_id = ? LIMIT 1';
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $params);

        $this->conn->execute($stmt);
        $stmt->close();

        return $this->requireById((int) $settingId);
    }

    public function findDisabledSettings(): array
    {
        $sql = 'SELECT * FROM 202_attribution_settings WHERE multi_touch_enabled = 0';
        $stmt = $this->conn->prepareRead($sql);
        $rows = $this->conn->fetchAll($stmt);

        $settings = [];
        foreach ($rows as $row) {
            $settings[] = Setting::fromDatabaseRow($row);
        }

        return $settings;
    }

    private function requireById(int $settingId): Setting
    {
        $stmt = $this->conn->prepareRead('SELECT * FROM 202_attribution_settings WHERE setting_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$settingId]);
        $row = $this->conn->fetchOne($stmt);

        if ($row === null) {
            throw new RuntimeException('Unable to load attribution setting #' . $settingId);
        }

        return Setting::fromDatabaseRow($row);
    }

    private function buildScopeKey(ScopeType $scopeType, ?int $scopeId): string
    {
        return sprintf('%s:%s', $scopeType->value, $scopeId === null ? 'null' : $scopeId);
    }
}
