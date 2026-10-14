<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cache;

use Closure;
use InvalidArgumentException;
use Prosper202\Repository\PositiveId;

/**
 * The bootstrap's getCache()/setCache(), handed in as closures so a cache
 * write still goes through setCache() and is held while a click's
 * transaction is open (p202WithHeldCacheWrites()).
 *
 * One lifetime, 30 days, for every subject, where INDEXES gave a site URL 7.
 * No served code deletes a lookup row (the only ones that ever did are old,
 * frozen upgrade steps that truncated 202_browsers and 202_platforms), so a
 * cached id goes stale only when the insert that made it is rolled back, and
 * the one lookup made inside a caller's transaction (rtr.php's redirect URL)
 * holds its writes until the commit. Code that deletes lookup rows has to
 * make the entries naming them unreachable too (a new key subject).
 */
final readonly class MemcacheIdCache implements IdCache
{
    /**
     * @param Closure(string): mixed $read
     * @param Closure(string, int, int): mixed $write
     */
    public function __construct(
        private Closure $read,
        private Closure $write,
        private int $ttl = 2592000,
    ) {
    }

    /**
     * Anything but a positive id is a miss: memcache hands an int back as a
     * string, and an entry written by older code can hold 0 or junk, which
     * used to be returned as the id.
     */
    #[\Override]
    public function get(string $key): ?int
    {
        return PositiveId::of(($this->read)($key));
    }

    #[\Override]
    public function set(string $key, int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException(
                'Only a row id is cached, not ' . $id
            );
        }
        // A write the cache refuses costs a miss on the next lookup, which
        // reads the database; it is not an answer to check.
        ($this->write)($key, $id, $this->ttl);
    }
}
