<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

/**
 * Groups conversions for a specific timeframe and scope for calculation.
 */
final readonly class ConversionBatch
{
    /**
     * @param ConversionRecord[] $conversions
     */
    public function __construct(
        public int $userId,
        public int $startTime,
        public int $endTime,
        public array $conversions
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->conversions === [];
    }
}
