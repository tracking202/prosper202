<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * What the platform last said of a connection's key
 * (202_ad_connections.status), written only by AdConnectionStore.
 *
 * A read shows one more value, UNREADABLE, when the stored credentials do
 * not open. It is a reading of the row as it is read, never stored, so it is
 * not a state: shown() is the list a reader may meet.
 */
enum AdConnectionStatus: string implements State
{
    use Moves;

    case Connected = 'connected';
    case KeyRefused = 'key_refused';

    /** Shown in place of the stored state while the credentials cannot be opened. */
    public const UNREADABLE = 'credentials_unreadable';

    public function next(): array
    {
        return match ($this) {
            self::Connected => [self::KeyRefused],
            self::KeyRefused => [self::Connected],
        };
    }

    public static function initial(): array
    {
        return [self::Connected];
    }

    /**
     * Every value a read's `status` can hold.
     *
     * @return list<string>
     */
    public static function shown(): array
    {
        return [...array_column(self::cases(), 'value'), self::UNREADABLE];
    }
}
