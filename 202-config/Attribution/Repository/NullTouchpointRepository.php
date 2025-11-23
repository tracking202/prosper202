<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\Touchpoint;

/**
 * Placeholder implementation until database-backed touchpoints land.
 */
final class NullTouchpointRepository implements TouchpointRepositoryInterface
{
    public function findBySnapshot(int $snapshotId): array
    {
        return [];
    }

    public function saveBatch(array $touchpoints): void
    {
        // intentionally blank
    }

    public function deleteBySnapshot(int $snapshotId): void
    {
        // intentionally blank
    }
}
