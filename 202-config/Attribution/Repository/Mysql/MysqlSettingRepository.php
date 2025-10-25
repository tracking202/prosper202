<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use mysqli_stmt;
use Prosper202\Attribution\Repository\SettingsRepositoryInterface;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Settings\Setting;
use RuntimeException;

final class MysqlSettingRepository implements SettingsRepositoryInterface
{
    public function __construct(
        private readonly mysqli $writeConnection,
        private readonly ?mysqli $readConnection = null
    ) {
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
        $stmt = $this->prepareRead($sql);
        $this->bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $settings = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $setting = Setting::fromDatabaseRow($row);
                $key = $this->buildScopeKey($setting->scopeType, $setting->scopeId);
                $settings[$key] = $setting;
            }

            $result->free();
        }

        $stmt->close();

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
            $sql = 'INSERT INTO 202_attribution_settings (user_id, scope_type, scope_id, model_id, multi_touch_enabled, multi_touch_enabled_at, multi_touch_disabled_at, effective_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $this->prepareWrite($sql);

            $userId = (int) $row['user_id'];
            $scopeType = (string) $row['scope_type'];
            $modelId = (int) $row['model_id'];
            $effectiveAt = (int) $row['effective_at'];
            $createdAt = (int) $row['created_at'];
            $updatedAt = (int) $row['updated_at'];

            $stmt->bind_param(
                'isiiiiiiii',
                $userId,
                $scopeType,
                $scopeId,
                $modelId,
                $enabled,
                $enabledAt,
                $disabledAt,
                $effectiveAt,
                $createdAt,
                $updatedAt
            );

            $stmt->execute();
            $insertId = $stmt->insert_id ?: $this->writeConnection->insert_id;
            $stmt->close();

            return $this->requireById((int) $insertId);
        }

        $sql = 'UPDATE 202_attribution_settings SET model_id = ?, multi_touch_enabled = ?, multi_touch_enabled_at = ?, multi_touch_disabled_at = ?, effective_at = ?, updated_at = ? WHERE setting_id = ? LIMIT 1';
        $stmt = $this->prepareWrite($sql);

        $modelId = (int) $row['model_id'];
        $effectiveAt = (int) $row['effective_at'];
        $updatedAt = (int) $row['updated_at'];
        $settingIdInt = (int) $settingId;

        $stmt->bind_param(
            'iiiiiii',
            $modelId,
            $enabled,
            $enabledAt,
            $disabledAt,
            $effectiveAt,
            $updatedAt,
            $settingIdInt
        );
        $stmt->execute();
        $stmt->close();

        return $this->requireById($settingIdInt);
    }

    public function findDisabledSettings(): array
    {
        $sql = 'SELECT * FROM 202_attribution_settings WHERE multi_touch_enabled = 0';
        $stmt = $this->prepareRead($sql);
        $stmt->execute();
        $result = $stmt->get_result();

        $settings = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings[] = Setting::fromDatabaseRow($row);
            }
            $result->free();
        }

        $stmt->close();

        return $settings;
    }

    private function prepareRead(string $sql): mysqli_stmt
    {
        return $this->prepare($this->readConnection ?? $this->writeConnection, $sql);
    }

    private function prepareWrite(string $sql): mysqli_stmt
    {
        return $this->prepare($this->writeConnection, $sql);
    }

    private function prepare(mysqli $connection, string $sql): mysqli_stmt
    {
        $statement = $connection->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare MySQL statement: ' . $connection->error);
        }

        return $statement;
    }

    private function requireById(int $settingId): Setting
    {
        $stmt = $this->prepareRead('SELECT * FROM 202_attribution_settings WHERE setting_id = ? LIMIT 1');
        $stmt->bind_param('i', $settingId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('Unable to load attribution setting #' . $settingId);
        }

        return Setting::fromDatabaseRow($row);
    }

    private function buildScopeKey(ScopeType $scopeType, ?int $scopeId): string
    {
        return sprintf('%s:%s', $scopeType->value, $scopeId === null ? 'null' : $scopeId);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function bind(mysqli_stmt $statement, string $types, array $values): void
    {
        $refs = [];
        foreach ($values as $index => $value) {
            $refs[$index] = &$values[$index];
        }
        $params = array_merge([$types], $refs);

        if (!call_user_func_array([$statement, 'bind_param'], $params)) {
            throw new RuntimeException('Failed to bind MySQL parameters.');
        }
    }
}
