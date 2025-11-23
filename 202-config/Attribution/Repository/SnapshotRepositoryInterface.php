<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;

/**
 * Stores computed attribution snapshots for reporting.
 */
interface SnapshotRepositoryInterface
{
    /**
     * @return Snapshot[]
     */
    public function findForRange(int $modelId, ScopeType $scopeType, ?int $scopeId, int $startHour, int $endHour, int $limit = 500, int $offset = 0): array;

    /**
     * Retrieves the latest snapshot for a model and scope.
     */
    public function findLatest(int $modelId, ScopeType $scopeType, ?int $scopeId): ?Snapshot;

    /**
     * Persists a snapshot entity.
     */
    public function save(Snapshot $snapshot): Snapshot;

    /**
     * Deletes snapshots older than the specified timestamp.
     */
    public function purgeOlderThan(int $timestamp): int;
}
