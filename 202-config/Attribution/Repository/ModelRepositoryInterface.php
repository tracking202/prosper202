<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;

/**
 * Persists and retrieves attribution model definitions.
 */
interface ModelRepositoryInterface
{
    /**
     * Fetches a model by identifier.
     */
    public function findById(int $modelId): ?ModelDefinition;

    /**
     * Returns the default model for a user scope.
     */
    public function findDefaultForUser(int $userId): ?ModelDefinition;

    /**
     * Lists models for a user filtered by type or status.
     *
     * @return ModelDefinition[]
     */
    public function findForUser(int $userId, ?ModelType $type = null, bool $onlyActive = true): array;

    public function findBySlug(int $userId, string $slug): ?ModelDefinition;

    /**
     * Persists a model definition and returns the saved instance.
     */
    public function save(ModelDefinition $model): ModelDefinition;

    /**
     * Marks the provided model as default for the owning user.
     */
    public function promoteToDefault(ModelDefinition $model): void;

    /**
     * Set a specific model as default for a user
     */
    public function setAsDefault(int $userId, int $modelId): bool;

    /**
     * Delete a model and its related data
     */
    public function delete(int $modelId, int $userId): void;
}
