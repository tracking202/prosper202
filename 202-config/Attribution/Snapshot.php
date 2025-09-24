<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * Represents a computed attribution snapshot for a specific model and scope.
 */
final class Snapshot
{
    public function __construct(
        public readonly ?int $snapshotId,
        public readonly int $modelId,
        public readonly int $userId,
        public readonly ScopeType $scopeType,
        public readonly ?int $scopeId,
        public readonly int $dateHour,
        public readonly int $lookbackStart,
        public readonly int $lookbackEnd,
        public readonly int $attributedClicks,
        public readonly int $attributedConversions,
        public readonly float $attributedRevenue,
        public readonly float $attributedCost,
        public readonly int $createdAt
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            snapshotId: isset($row['snapshot_id']) ? (int) $row['snapshot_id'] : null,
            modelId: (int) $row['model_id'],
            userId: (int) $row['user_id'],
            scopeType: ScopeType::from((string) $row['scope_type']),
            scopeId: isset($row['scope_id']) ? (int) $row['scope_id'] : null,
            dateHour: (int) $row['date_hour'],
            lookbackStart: (int) $row['lookback_start'],
            lookbackEnd: (int) $row['lookback_end'],
            attributedClicks: (int) $row['attributed_clicks'],
            attributedConversions: (int) $row['attributed_conversions'],
            attributedRevenue: (float) $row['attributed_revenue'],
            attributedCost: (float) $row['attributed_cost'],
            createdAt: (int) $row['created_at']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'snapshot_id' => $this->snapshotId,
            'model_id' => $this->modelId,
            'user_id' => $this->userId,
            'scope_type' => $this->scopeType->value,
            'scope_id' => $this->scopeId,
            'date_hour' => $this->dateHour,
            'lookback_start' => $this->lookbackStart,
            'lookback_end' => $this->lookbackEnd,
            'attributed_clicks' => $this->attributedClicks,
            'attributed_conversions' => $this->attributedConversions,
            'attributed_revenue' => $this->attributedRevenue,
            'attributed_cost' => $this->attributedCost,
            'created_at' => $this->createdAt,
        ];
    }
}
