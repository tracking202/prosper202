<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;

/**
 * Placeholder snapshot repository returning empty collections.
 */
final class NullSnapshotRepository implements SnapshotRepositoryInterface
{
    public function findForRange(int $modelId, ScopeType $scopeType, ?int $scopeId, int $startHour, int $endHour, int $limit = 500, int $offset = 0): array
    {
        return [];
    }

    public function findLatest(int $modelId, ScopeType $scopeType, ?int $scopeId): ?Snapshot
    {
        return null;
    }

    public function save(Snapshot $snapshot): Snapshot
    {
        return $snapshot;
    }

    public function purgeOlderThan(int $timestamp): int
    {
        return 0;
    }
}
