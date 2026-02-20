<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Analytics;

/**
 * Lightweight DTO describing an anomaly detected for the dashboard.
 */
final readonly class AnomalyAlert
{
    public function __construct(
        public string $metric,
        public string $severity,
        public string $direction,
        public float $deltaPercent,
        public string $message
    ) {
    }
}
