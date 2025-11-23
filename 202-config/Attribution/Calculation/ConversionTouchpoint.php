<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

/**
 * Describes an individual touch within a conversion journey.
 */
final class ConversionTouchpoint
{
    public function __construct(
        public readonly int $clickId,
        public readonly int $clickTime
    ) {
    }
}
