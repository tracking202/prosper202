<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * The attribution models the engine computes. This enum is the only list:
 * the column type, the API's validation, both CLIs, the OpenAPI spec and the
 * docs are checked against it by tests/Attribution/ModelListIsTheEnumTest,
 * so a surface that offers a model the engine cannot compute fails the build
 * instead of failing a user.
 *
 * `algorithmic` is gone rather than aliased (a model that claims one thing
 * and computes another is worse than an absent one), and `assisted` is a
 * report over non-last touches, not a model.
 */
enum ModelType: string
{
    case LAST_TOUCH = 'last_touch';
    case FIRST_TOUCH = 'first_touch';
    case LINEAR = 'linear';
    case TIME_DECAY = 'time_decay';
    case POSITION_BASED = 'position_based';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::LAST_TOUCH => 'Last touch',
            self::FIRST_TOUCH => 'First touch',
            self::LINEAR => 'Linear',
            self::TIME_DECAY => 'Time decay',
            self::POSITION_BASED => 'Position based',
        };
    }
}
