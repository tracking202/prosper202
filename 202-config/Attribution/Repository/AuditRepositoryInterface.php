<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

interface AuditRepositoryInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function record(int $userId, ?int $modelId, string $action, array $metadata = []): void;
}
