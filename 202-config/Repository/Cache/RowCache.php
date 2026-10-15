<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cache;

/**
 * Where the click path keeps the setup rows it read (a landing page, a
 * tracker, a campaign) for a few minutes: the row, or that there was none.
 * Never a click's row, which the click path rewrites while it is read.
 */
interface RowCache
{
    /** What is kept under $key, or null when nothing is kept that can be trusted. */
    public function get(string $key): ?CachedRow;

    /** @param array<string, string|null>|null $row null keeps "no such row" */
    public function set(string $key, ?array $row): void;
}
