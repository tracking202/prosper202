<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * The status of an attribution model (202_attribution_models.status), written
 * only by ModelRepository and DefaultModel's seed.
 *
 * Only an active model is computed, and only it has credits. The engine marks
 * an active model invalid when its stored definition no longer validates; a
 * request never asks for that state.
 */
enum ModelStatus: string implements State
{
    use Moves;

    case Active = 'active';
    case Inactive = 'inactive';
    case Invalid = 'invalid';

    public function next(): array
    {
        return match ($this) {
            self::Active => [self::Inactive, self::Invalid],
            self::Inactive => [self::Active],
            // Left only by an edit, which stores a definition that validates.
            self::Invalid => [self::Active, self::Inactive],
        };
    }

    public static function initial(): array
    {
        return [self::Active, self::Inactive];
    }

    /**
     * The states a request may ask for.
     *
     * @return list<self>
     */
    public static function requestable(): array
    {
        return [self::Active, self::Inactive];
    }

    /** Whether the worker computes credits for a model in this state. */
    public function computes(): bool
    {
        return $this === self::Active;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
