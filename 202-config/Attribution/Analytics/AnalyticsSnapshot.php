<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Analytics;

/**
 * Immutable view model for a computed attribution snapshot exposed to the API.
 */
final class AnalyticsSnapshot
{
    public function __construct(
        public readonly ?int $snapshotId,
        public readonly int $dateHour,
        public readonly int $attributedClicks,
        public readonly int $attributedConversions,
        public readonly float $attributedRevenue,
        public readonly float $attributedCost
    ) {
    }
}
