<?php

declare(strict_types=1);

namespace Tests\Lifecycle\Fixtures\Broken;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

enum NoStartState: string implements State
{
    use Moves;

    case A = 'a';

    public function next(): array
    {
        return [];
    }

    public static function initial(): array
    {
        return [];
    }
}
