<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

final class NullAuditRepository implements AuditRepositoryInterface
{
    public function record(int $userId, ?int $modelId, string $action, array $metadata = []): void
    {
        // no-op
    }
}
