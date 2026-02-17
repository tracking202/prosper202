<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

/**
 * Lightweight value object describing a conversion and its associated click context.
 *
 * @psalm-type JourneyTouch = ConversionTouchpoint
 */
final readonly class ConversionRecord
{
    public function __construct(
        public int $conversionId,
        public int $clickId,
        public int $userId,
        public int $campaignId,
        public int $ppcAccountId,
        public int $convTime,
        public int $clickTime,
        public float $clickPayout,
        public float $clickCost,
        /** @var ConversionTouchpoint[] */
        public array $journey = []
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
