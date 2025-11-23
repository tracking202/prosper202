<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

/**
 * Groups conversions for a specific timeframe and scope for calculation.
 */
final class ConversionBatch
{
    /**
     * @param ConversionRecord[] $conversions
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $startTime,
        public readonly int $endTime,
        public readonly array $conversions
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->conversions === [];
    }
}
