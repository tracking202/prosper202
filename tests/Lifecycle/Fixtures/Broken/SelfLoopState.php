<?php

declare(strict_types=1);

namespace Tests\Lifecycle\Fixtures\Broken;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

enum SelfLoopState: string implements State
{
    use Moves;

    case A = 'a';

    public function next(): array
    {
        return [self::A];
    }

    public static function initial(): array
    {
        return [self::A];
    }
}
