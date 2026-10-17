<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cache;

use InvalidArgumentException;

/** Keeps nothing: every lookup reads the database. */
final readonly class NullIdCache implements IdCache
{
    #[\Override]
    public function get(string $key): ?int
    {
        return null;
    }

    #[\Override]
    public function set(string $key, int $id, ?int $ttl = null): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException(
                'Only a row id is cached, not ' . $id
            );
        }
    }
}
