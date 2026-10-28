<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cache;

use InvalidArgumentException;

/**
 * How long memcache keeps an entry: 1 second to 30 days. memcache reads 0
 * as "never expire" and anything past 30 days as a Unix time, long past, so
 * neither can stand for a lifetime.
 */
final class CacheLifetime
{
    public const int MAX_SECONDS = 2592000;

    /** @throws InvalidArgumentException for a lifetime memcache would not read as one */
    public static function seconds(int $ttl): int
    {
        if ($ttl < 1 || $ttl > self::MAX_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'A cache lifetime is 1 to %d seconds, not %d',
                self::MAX_SECONDS,
                $ttl
            ));
        }

        return $ttl;
    }
}
