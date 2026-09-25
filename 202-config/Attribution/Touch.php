<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/** One click in a journey: its position (0 = oldest) and when it happened. */
final class Touch
{
    public function __construct(
        public readonly int $position,
        public readonly int $clickId,
        public readonly int $clickTime,
    ) {
    }
}
