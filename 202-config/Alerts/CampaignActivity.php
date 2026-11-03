<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

/**
 * One campaign's last LOOKBACK_DAYS as the alerts read it: conversions by
 * the time they arrived (202_conversion_logs.conv_time), clicks and cost by
 * click time (202_dataengine, the reports' rows). Real clicks are the ones
 * the click filter left in; cost is every click's, filtered ones included,
 * since every one was paid for.
 */
final class CampaignActivity
{
    public function __construct(
        public readonly int $campaignId,
        public readonly string $name,
        public readonly int $conversions = 0,
        public readonly ?int $firstConversionAt = null,
        public readonly ?int $lastConversionAt = null,
        public readonly int $conversionsToday = 0,
        public readonly int $realClicks = 0,
        public readonly ?int $lastRealClickAt = null,
        public readonly int $clicksToday = 0,
        public readonly float $costToday = 0.0,
    ) {
    }

    /**
     * Seconds between conversions over the window: from the first to the
     * last, over the gaps between them. Null with fewer than two.
     */
    public function usualGap(): ?float
    {
        if ($this->conversions < 2 || $this->firstConversionAt === null || $this->lastConversionAt === null) {
            return null;
        }

        return ($this->lastConversionAt - $this->firstConversionAt) / ($this->conversions - 1);
    }

    /** Real clicks per conversion over the window; null with no conversion. */
    public function clicksPerConversion(): ?float
    {
        return $this->conversions > 0 ? $this->realClicks / $this->conversions : null;
    }
}
