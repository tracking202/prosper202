<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cache;

use Closure;

/**
 * The one read-through rule the cached repositories share. A key is
 * md5($subject . $namespace), the format the click path has always written,
 * so entries already in memcache keep answering.
 */
final readonly class CachedIds
{
    public function __construct(
        private IdCache $cache,
        private string $namespace,
    ) {
    }

    /**
     * The id kept for $subject, or the one $lookup finds, kept when it is a
     * row. A lookup that throws keeps nothing.
     *
     * @param Closure(): int $lookup
     */
    public function remember(string $subject, Closure $lookup): int
    {
        $key = md5($subject . $this->namespace);
        $hit = $this->cache->get($key);
        if ($hit !== null) {
            return $hit;
        }

        $id = $lookup();
        if ($id > 0) {
            $this->cache->set($key, $id);
        }

        return $id;
    }
}
