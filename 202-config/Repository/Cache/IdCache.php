<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cache;

/**
 * Where a lookup keeps the ids it found. Only a positive id is ever kept or
 * returned: 0 is the lookups' "no value", never a row.
 */
interface IdCache
{
    /** The id kept under $key, or null when there is none to trust. */
    public function get(string $key): ?int;

    /**
     * Keeps $id for $ttl seconds, or for the cache's own lifetime when null.
     *
     * @throws \InvalidArgumentException for an id below 1, or a lifetime
     *         CacheLifetime refuses
     */
    public function set(string $key, int $id, ?int $ttl = null): void;
}
