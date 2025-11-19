<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\ScopeType;

interface JourneyMaintenanceRepositoryInterface
{
    public function purgeByScope(int $userId, ScopeType $scopeType, ?int $scopeId = null): int;

    public function hydrateScope(
        int $userId,
        ScopeType $scopeType,
        ?int $scopeId = null,
        ?int $startTime = null,
        ?int $endTime = null,
        int $batchSize = 500
    ): int;
}
