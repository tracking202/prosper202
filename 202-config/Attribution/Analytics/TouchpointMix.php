<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Analytics;

/**
 * Aggregated representation of touchpoint credit distribution for charting.
 */
final readonly class TouchpointMix
{
    public function __construct(
        public string $bucket,
        public string $label,
        public float $totalCredit,
        public int $touchCount,
        public float $share
    ) {
    }
}
