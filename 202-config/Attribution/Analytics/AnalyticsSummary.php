<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Analytics;

/**
 * Container aggregating the analytics response pieces served by the API.
 */
final class AnalyticsSummary
{
    /**
     * @param array<string, float|null> $totals
     * @param AnalyticsSnapshot[] $snapshots
     * @param TouchpointMix[] $touchpointMix
     * @param AnomalyAlert[] $anomalies
     */
    public function __construct(
        public readonly array $totals,
        public readonly array $snapshots,
        public readonly array $touchpointMix,
        public readonly array $anomalies
    ) {
    }
}
