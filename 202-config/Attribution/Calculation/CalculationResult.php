<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;

/**
 * Aggregated output for a strategy execution.
 */
final class CalculationResult
{
    /**
     * @param array<int, Snapshot>   $snapshotsByHour   Snapshots indexed by UNIX hour bucket.
     * @param array<int, Touchpoint[]> $touchpointsByHour Touchpoints (without snapshot IDs) grouped by hour bucket.
     */
    public function __construct(
        public readonly array $snapshotsByHour,
        public readonly array $touchpointsByHour
    ) {
    }
}
