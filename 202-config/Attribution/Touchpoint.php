<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * Captures a single touchpoint's credit contribution within a snapshot run.
 */
final class Touchpoint
{
    public function __construct(
        public readonly ?int $touchpointId,
        public readonly ?int $snapshotId,
        public readonly int $conversionId,
        public readonly int $clickId,
        public readonly int $position,
        public readonly float $credit,
        public readonly float $weight,
        public readonly int $createdAt
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            touchpointId: isset($row['touchpoint_id']) ? (int) $row['touchpoint_id'] : null,
            snapshotId: isset($row['snapshot_id']) ? (int) $row['snapshot_id'] : null,
            conversionId: (int) $row['conv_id'],
            clickId: (int) $row['click_id'],
            position: (int) $row['position'],
            credit: (float) $row['credit'],
            weight: (float) $row['weight'],
            createdAt: (int) $row['created_at']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'touchpoint_id' => $this->touchpointId,
            'snapshot_id' => $this->snapshotId,
            'conv_id' => $this->conversionId,
            'click_id' => $this->clickId,
            'position' => $this->position,
            'credit' => $this->credit,
            'weight' => $this->weight,
            'created_at' => $this->createdAt,
        ];
    }
}
