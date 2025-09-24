<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * Enumerates the supported attribution model strategies.
 */
enum ModelType: string
{
    case LAST_TOUCH = 'last_touch';
    case TIME_DECAY = 'time_decay';
    case POSITION_BASED = 'position_based';
    case ALGORITHMIC = 'algorithmic';
    case ASSISTED = 'assisted';

    /**
     * Returns a human-friendly label for UI presentation.
     */
    public function label(): string
    {
        return match ($this) {
            self::LAST_TOUCH => 'Last Touch',
            self::TIME_DECAY => 'Time Decay',
            self::POSITION_BASED => 'Position Based',
            self::ALGORITHMIC => 'Algorithmic',
            self::ASSISTED => 'Assisted Conversions',
        };
    }

    /**
     * Indicates whether the strategy expects weighting configuration data.
     */
    public function requiresWeighting(): bool
    {
        return match ($this) {
            self::LAST_TOUCH => false,
            self::ASSISTED => false,
            self::TIME_DECAY, self::POSITION_BASED, self::ALGORITHMIC => true,
        };
    }
}
