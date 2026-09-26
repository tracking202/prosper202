<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * The clicks one person made before a conversion, oldest first, with the
 * converting click always last.
 *
 * Built once per conversion at the journey lookback (the widest lookback of
 * every active model, never less than 30 days) and read by every model,
 * each through its own window. `truncated` says the 25-touch cap cut older
 * clicks off; `identified` is false when the converting click had no
 * visitor key, which makes the journey one touch by construction.
 */
final class Journey
{
    /** The fixed touch cap (plan §7.3): newest kept, converting click included. */
    public const MAX_TOUCHES = 25;

    /** @param list<Touch> $touches */
    public function __construct(
        public readonly array $touches,
        public readonly int $builtLookbackDays,
        public readonly bool $truncated,
        public readonly bool $identified,
    ) {
        if ($touches === []) {
            throw new \InvalidArgumentException('a journey has at least the converting click');
        }
        foreach ($touches as $i => $touch) {
            if ($touch->position !== $i) {
                throw new \InvalidArgumentException('journey positions must be 0..n-1 in order');
            }
        }
        if (count($touches) > self::MAX_TOUCHES) {
            throw new \InvalidArgumentException('a journey holds at most ' . self::MAX_TOUCHES . ' touches');
        }
    }

    public function convertingTouch(): Touch
    {
        return $this->touches[count($this->touches) - 1];
    }
}
