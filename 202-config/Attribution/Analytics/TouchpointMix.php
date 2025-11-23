<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Analytics;

/**
 * Aggregated representation of touchpoint credit distribution for charting.
 */
final class TouchpointMix
{
    public function __construct(
        public readonly string $bucket,
        public readonly string $label,
        public readonly float $totalCredit,
        public readonly int $touchCount,
        public readonly float $share
    ) {
    }
}
