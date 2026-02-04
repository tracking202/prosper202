<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;

/**
 * No-op repository used while the persistence layer is under construction.
 */
final class NullModelRepository implements ModelRepositoryInterface
{
    public function findById(int $modelId): ?ModelDefinition
    {
        return null;
    }

    public function findDefaultForUser(int $userId): ?ModelDefinition
    {
        return null;
    }

    public function findForUser(int $userId, ?ModelType $type = null, bool $onlyActive = true): array
    {
        return [];
    }

    public function findBySlug(int $userId, string $slug): ?ModelDefinition
    {
        return null;
    }

    public function save(ModelDefinition $model): ModelDefinition
    {
        return $model;
    }

    public function promoteToDefault(ModelDefinition $model): void
    {
        // intentionally blank
    }

    public function setAsDefault(int $userId, int $modelId): bool
    {
        return false;
    }

    public function delete(int $modelId): bool
    {
        return false;
    }
}
