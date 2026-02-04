<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Settings;

use Prosper202\Attribution\ScopeType;

/**
 * Value object describing attribution settings for a given scope.
 */
final class Setting
{
    public function __construct(
        public readonly ?int $settingId,
        public readonly int $userId,
        public readonly ScopeType $scopeType,
        public readonly ?int $scopeId,
        public readonly int $modelId,
        public readonly bool $multiTouchEnabled,
        public readonly ?int $multiTouchEnabledAt,
        public readonly ?int $multiTouchDisabledAt,
        public readonly int $effectiveAt,
        public readonly int $createdAt,
        public readonly int $updatedAt
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            settingId: isset($row['setting_id']) ? (int) $row['setting_id'] : null,
            userId: (int) $row['user_id'],
            scopeType: ScopeType::from((string) $row['scope_type']),
            scopeId: array_key_exists('scope_id', $row) && $row['scope_id'] !== null
                ? (int) $row['scope_id']
                : null,
            modelId: (int) $row['model_id'],
            multiTouchEnabled: (bool) (int) $row['multi_touch_enabled'],
            multiTouchEnabledAt: isset($row['multi_touch_enabled_at']) ? (int) $row['multi_touch_enabled_at'] : null,
            multiTouchDisabledAt: isset($row['multi_touch_disabled_at']) ? (int) $row['multi_touch_disabled_at'] : null,
            effectiveAt: (int) $row['effective_at'],
            createdAt: (int) $row['created_at'],
            updatedAt: (int) $row['updated_at']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'setting_id' => $this->settingId,
            'user_id' => $this->userId,
            'scope_type' => $this->scopeType->value,
            'scope_id' => $this->scopeId,
            'model_id' => $this->modelId,
            'multi_touch_enabled' => $this->multiTouchEnabled ? 1 : 0,
            'multi_touch_enabled_at' => $this->multiTouchEnabledAt,
            'multi_touch_disabled_at' => $this->multiTouchDisabledAt,
            'effective_at' => $this->effectiveAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function withMultiTouchState(bool $enabled, int $timestamp): self
    {
        return new self(
            settingId: $this->settingId,
            userId: $this->userId,
            scopeType: $this->scopeType,
            scopeId: $this->scopeId,
            modelId: $this->modelId,
            multiTouchEnabled: $enabled,
            multiTouchEnabledAt: $enabled ? $timestamp : $this->multiTouchEnabledAt,
            multiTouchDisabledAt: $enabled ? null : $timestamp,
            effectiveAt: $timestamp,
            createdAt: $this->createdAt,
            updatedAt: $timestamp
        );
    }
}
