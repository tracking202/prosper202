<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Analytics;

/**
 * Container aggregating the analytics response pieces served by the API.
 */
final readonly class AnalyticsSummary
{
    /**
     * @param array<string, float|null> $totals
     * @param AnalyticsSnapshot[] $snapshots
     * @param TouchpointMix[] $touchpointMix
     * @param AnomalyAlert[] $anomalies
     */
    public function __construct(
        public array $totals,
        public array $snapshots,
        public array $touchpointMix,
        public array $anomalies
    ) {
    }
}
