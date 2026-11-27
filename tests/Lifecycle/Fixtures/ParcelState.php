<?php

declare(strict_types=1);

namespace Tests\Lifecycle\Fixtures;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/** A lifecycle for the tests of Moves: a retry loop, a branch and two terminal states. */
enum ParcelState: string implements State
{
    use Moves;

    case Packed = 'packed';
    case Sent = 'sent';
    case Returned = 'returned';
    case Delivered = 'delivered';
    case Lost = 'lost';

    public function next(): array
    {
        return match ($this) {
            self::Packed => [self::Sent],
            self::Sent => [self::Delivered, self::Returned, self::Lost],
            self::Returned => [self::Sent],
            self::Delivered, self::Lost => [],
        };
    }

    public static function initial(): array
    {
        return [self::Packed];
    }
}
