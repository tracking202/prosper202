<?php

declare(strict_types=1);

namespace Tests\Lifecycle\Fixtures\Broken;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

enum TwiceState: string implements State
{
    use Moves;

    case A = 'a';
    case B = 'b';

    public function next(): array
    {
        return match ($this) {
            self::A => [self::B, self::B],
            self::B => [],
        };
    }

    public static function initial(): array
    {
        return [self::A];
    }
}
