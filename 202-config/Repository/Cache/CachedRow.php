<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cache;

/** A row a RowCache kept, or the "no such row" it kept (row null). */
final readonly class CachedRow
{
    /** @param array<string, string|null>|null $row */
    public function __construct(public ?array $row)
    {
    }
}
