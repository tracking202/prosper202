<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

/**
 * Lightweight value object describing a conversion and its associated click context.
 *
 * @psalm-type JourneyTouch = ConversionTouchpoint
 */
final class ConversionRecord
{
    public function __construct(
        public readonly int $conversionId,
        public readonly int $clickId,
        public readonly int $userId,
        public readonly int $campaignId,
        public readonly int $ppcAccountId,
        public readonly int $convTime,
        public readonly int $clickTime,
        public readonly float $clickPayout,
        public readonly float $clickCost,
        /** @var ConversionTouchpoint[] */
        public readonly array $journey = []
    ) {
    }

    /**
     * Returns the ordered journey for this conversion, ensuring the converting click is present.
     *
     * @return ConversionTouchpoint[]
     */
    public function getJourney(): array
    {
        $journey = array_values(array_filter(
            $this->journey,
            static fn (mixed $touch): bool => $touch instanceof ConversionTouchpoint
        ));

        $hasPrimary = false;
        foreach ($journey as $touch) {
            if ($touch->clickId === $this->clickId) {
                $hasPrimary = true;
                break;
            }
        }

        if (!$hasPrimary) {
            $journey[] = new ConversionTouchpoint($this->clickId, $this->clickTime);
        }

        usort(
            $journey,
            static function (ConversionTouchpoint $a, ConversionTouchpoint $b): int {
                if ($a->clickTime === $b->clickTime) {
                    return $a->clickId <=> $b->clickId;
                }

                return $a->clickTime <=> $b->clickTime;
            }
        );

        return $journey;
    }
}
