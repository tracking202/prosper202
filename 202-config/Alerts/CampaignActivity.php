<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

/**
 * One campaign's last LOOKBACK_DAYS as the alerts read it: conversions by
 * the time they arrived (202_conversion_logs.conv_time), clicks and cost by
 * click time (202_dataengine, the reports' rows). Real clicks are the ones
 * the click filter left in; cost is every click's, filtered ones included,
 * since every one was paid for.
 *
 * The rhythm (conversions, arrivalHours, first and last) is live arrivals
 * only: an upload records a batch in one second, which says nothing about
 * when its conversions happened (AccountActivity::BATCH_SOURCES). It is
 * measured in distinct arrival hours, so a burst is one arrival.
 * conversionsToday counts every conversion recorded today, uploads included.
 */
final class CampaignActivity
{
    public function __construct(
        public readonly int $campaignId,
        public readonly string $name,
        public readonly int $conversions = 0,
        public readonly int $arrivalHours = 0,
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
     * Seconds between converting hours over the window: from the first hour
     * to the last, over the gaps between them. Null with fewer than two.
     */
    public function usualGap(): ?float
    {
        if ($this->arrivalHours < 2 || $this->firstConversionAt === null || $this->lastConversionAt === null) {
            return null;
        }
        $span = (intdiv($this->lastConversionAt, 3600) - intdiv($this->firstConversionAt, 3600)) * 3600;

        return $span / ($this->arrivalHours - 1);
    }

    /** Real clicks per converting hour over the window; null with none. */
    public function clicksPerArrival(): ?float
    {
        return $this->arrivalHours > 0 ? $this->realClicks / $this->arrivalHours : null;
    }
}
