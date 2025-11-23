<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\Touchpoint;

/**
 * Handles persistence of per-conversion touchpoint allocations.
 */
interface TouchpointRepositoryInterface
{
    /**
     * @return Touchpoint[]
     */
    public function findBySnapshot(int $snapshotId): array;

    /**
     * Persists multiple touchpoints within a transaction.
     *
     * @param Touchpoint[] $touchpoints
     */
    public function saveBatch(array $touchpoints): void;

    /**
     * Removes touchpoints for the provided snapshot identifier.
     */
    public function deleteBySnapshot(int $snapshotId): void;
}
