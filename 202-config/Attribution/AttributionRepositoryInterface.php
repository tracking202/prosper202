<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

interface AttributionRepositoryInterface
{
    // --- Models ---

    /**
     * @param array<string, mixed> $filters Optional type filter
     * @return list<array<string, mixed>>
     */
    public function listModels(int $userId, array $filters, int $offset, int $limit): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findModel(int $id, int $userId): ?array;

    /**
     * @param array<string, mixed> $data model_name, model_type, weighting_config, is_active, is_default
     */
    public function createModel(int $userId, array $data): int;

    /**
     * @param array<string, mixed> $data
     */
    public function updateModel(int $id, int $userId, array $data): void;

    /**
     * Cascade-delete model and all snapshots, touchpoints, exports.
     */
    public function deleteModel(int $id, int $userId): void;

    // --- Snapshots ---

    /**
     * @param array<string, mixed> $filters Optional scope_type filter
     * @return list<array<string, mixed>>
     */
    public function listSnapshots(int $modelId, int $userId, array $filters, int $offset, int $limit): array;

    // --- Exports ---

    /**
     * @return list<array<string, mixed>>
     */
    public function listExports(int $modelId, int $userId): array;

    /**
     * @param array<string, mixed> $data scope_type, scope_id, start_hour, end_hour, format, webhook_url
     */
    public function scheduleExport(int $modelId, int $userId, array $data): int;
}
