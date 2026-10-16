<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cache;

/** Keeps nothing: every read goes to the database. */
final readonly class NullRowCache implements RowCache
{
    #[\Override]
    public function get(string $key): ?CachedRow
    {
        return null;
    }

    #[\Override]
    public function set(string $key, ?array $row): void
    {
    }
}
