<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Analytics;

/**
 * Immutable view model for a computed attribution snapshot exposed to the API.
 */
final readonly class AnalyticsSnapshot
{
    public function __construct(
        public ?int $snapshotId,
        public int $dateHour,
        public int $attributedClicks,
        public int $attributedConversions,
        public float $attributedRevenue,
        public float $attributedCost
    ) {
    }
}
