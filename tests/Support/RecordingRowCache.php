<?php

declare(strict_types=1);

namespace Tests\Support;

use Prosper202\Repository\Cache\CachedRow;
use Prosper202\Repository\Cache\RowCache;

/** Keeps entries in memory and records every call. */
final class RecordingRowCache implements RowCache
{
    /** @var list<array{0: string, 1: string, 2?: array<string, string|null>|null}> */
    public array $calls = [];

    /** @var array<string, CachedRow> */
    private array $kept = [];

    #[\Override]
    public function get(string $key): ?CachedRow
    {
        $this->calls[] = ['get', $key];

        return $this->kept[$key] ?? null;
    }

    #[\Override]
    public function set(string $key, ?array $row): void
    {
        $this->calls[] = ['set', $key, $row];
        $this->kept[$key] = new CachedRow($row);
    }
}
