<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Analytics;

/**
 * Lightweight DTO describing an anomaly detected for the dashboard.
 */
final class AnomalyAlert
{
    public function __construct(
        public readonly string $metric,
        public readonly string $severity,
        public readonly string $direction,
        public readonly float $deltaPercent,
        public readonly string $message
    ) {
    }
}
