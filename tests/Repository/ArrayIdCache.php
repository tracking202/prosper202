<?php

declare(strict_types=1);

namespace Tests\Repository;

use InvalidArgumentException;
use Prosper202\Repository\Cache\IdCache;

/** An IdCache in an array, recording what was asked of it. */
final class ArrayIdCache implements IdCache
{
    /** @var array<string, int> */
    public array $ids = [];

    /** @var list<string> */
    public array $reads = [];

    /** @var list<array{string, int}> */
    public array $writes = [];

    /** @var array<string, int|null> the lifetime each key was last kept for */
    public array $ttls = [];

    #[\Override]
    public function get(string $key): ?int
    {
        $this->reads[] = $key;

        return $this->ids[$key] ?? null;
    }

    #[\Override]
    public function set(string $key, int $id, ?int $ttl = null): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Only a row id is cached, not ' . $id);
        }
        $this->writes[] = [$key, $id];
        $this->ttls[$key] = $ttl;
        $this->ids[$key] = $id;
    }

    /** @return list<string> */
    public function writtenKeys(): array
    {
        return array_map(static fn (array $write): string => $write[0], $this->writes);
    }
}
