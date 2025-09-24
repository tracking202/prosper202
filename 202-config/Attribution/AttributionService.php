<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use InvalidArgumentException;
use Prosper202\Attribution\Repository\AuditRepositoryInterface;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Snapshot;

/**
 * High-level façade for attribution operations consumed by controllers and CLI jobs.
 */
final class AttributionService
{
    public function __construct(
        private readonly ModelRepositoryInterface $modelRepository,
        private readonly SnapshotRepositoryInterface $snapshotRepository,
        private readonly TouchpointRepositoryInterface $touchpointRepository,
        private readonly AuditRepositoryInterface $auditRepository
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listModels(int $userId, ?ModelType $filter = null): array
    {
        $models = $this->modelRepository->findForUser($userId, $filter);

        return array_map(static function (ModelDefinition $model): array {
            return self::formatModel($model);
        }, $models);
    }

    /**
     * Returns attribution snapshots for charting needs.
     *
     * @param int $userId
     * @param int $modelId
     * @return array<int, array<string, mixed>>
     */
    public function getSnapshots(int $userId, int $modelId, ScopeType $scope, ?int $scopeId, int $startHour, int $endHour, int $limit = 500, int $offset = 0): array
    {
        $this->requireOwnedModel($userId, $modelId);

        $snapshots = $this->snapshotRepository->findForRange($modelId, $scope, $scopeId, $startHour, $endHour, $limit, $offset);

        return array_map(static function (Snapshot $snapshot): array {
            return [
                'snapshot_id' => $snapshot->snapshotId,
                'date_hour' => $snapshot->dateHour,
                'attributed_clicks' => $snapshot->attributedClicks,
                'attributed_conversions' => $snapshot->attributedConversions,
                'attributed_revenue' => $snapshot->attributedRevenue,
                'attributed_cost' => $snapshot->attributedCost,
            ];
        }, $snapshots);
    }

    /**
     * Builds a sandbox comparison payload. Actual weighting logic is pending implementation.
     *
     * @param string[] $modelSlugs
     *
     * @return array<string, mixed>
     */
    public function runSandboxComparison(
        int $userId,
        array $modelSlugs,
        ScopeType $scope,
        ?int $scopeId,
        int $startHour,
        int $endHour
    ): array {
        $models = array_filter(
            $this->modelRepository->findForUser($userId, null, true),
            static function (ModelDefinition $model) use ($modelSlugs): bool {
                return in_array($model->slug, $modelSlugs, true);
            }
        );

        return [
            'models' => array_map(static fn (ModelDefinition $model): array => self::formatModel($model), $models),
            'summary' => [
                'message' => 'Attribution sandbox scaffolding ready. Computation engine will populate metrics in a subsequent iteration.',
                'scope' => $scope->value,
                'scope_id' => $scopeId,
                'start_hour' => $startHour,
                'end_hour' => $endHour,
            ],
            'comparisons' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createModel(int $userId, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Model name is required.');
        }

        $typeValue = strtolower((string) ($payload['type'] ?? ''));
        $modelType = ModelType::tryFrom($typeValue);
        if ($modelType === null) {
            throw new InvalidArgumentException('Invalid model type supplied.');
        }

        $weightingConfig = $this->normaliseWeightingConfig($payload['weighting_config'] ?? []);
        $slugInput = (string) ($payload['slug'] ?? '');
        $slug = $slugInput !== '' ? $this->slugify($slugInput) : $this->slugify($name);
        $slug = $this->ensureUniqueSlug($userId, $slug, null);

        $timestamp = time();
        $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;
        $isDefaultRequested = array_key_exists('is_default', $payload) ? (bool) $payload['is_default'] : false;

        $definition = new ModelDefinition(
            modelId: null,
            userId: $userId,
            name: $name,
            slug: $slug,
            type: $modelType,
            weightingConfig: $weightingConfig,
            isActive: $isActive,
            isDefault: $isDefaultRequested,
            createdAt: $timestamp,
            updatedAt: $timestamp
        );

        $saved = $this->modelRepository->save($definition);
        if ($isDefaultRequested) {
            $this->modelRepository->promoteToDefault($saved);
            $saved = $this->requireModel($saved->modelId ?? 0);
        }

        $this->auditRepository->record($userId, $saved->modelId, 'model_create', [
            'name' => $saved->name,
            'slug' => $saved->slug,
            'type' => $saved->type->value,
        ]);

        return self::formatModel($saved);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateModel(int $userId, int $modelId, array $payload): array
    {
        $existing = $this->requireOwnedModel($userId, $modelId);

        $name = trim((string) ($payload['name'] ?? $existing->name));
        if ($name === '') {
            throw new InvalidArgumentException('Model name is required.');
        }

        $type = $existing->type;
        if (array_key_exists('type', $payload)) {
            $typeCandidate = ModelType::tryFrom(strtolower((string) $payload['type']));
            if ($typeCandidate === null) {
                throw new InvalidArgumentException('Invalid model type supplied.');
            }
            $type = $typeCandidate;
        }

        $weightingConfig = array_key_exists('weighting_config', $payload)
            ? $this->normaliseWeightingConfig($payload['weighting_config'])
            : $existing->weightingConfig;

        $slugInput = array_key_exists('slug', $payload) ? (string) $payload['slug'] : '';
        $slugSeed = $slugInput !== '' ? $slugInput : $name;
        $slug = $this->slugify($slugSeed);
        $slug = $this->ensureUniqueSlug($userId, $slug, $existing->slug);

        $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : $existing->isActive;
        $isDefaultRequested = array_key_exists('is_default', $payload) ? (bool) $payload['is_default'] : $existing->isDefault;

        $updated = new ModelDefinition(
            modelId: $existing->modelId,
            userId: $userId,
            name: $name,
            slug: $slug,
            type: $type,
            weightingConfig: $weightingConfig,
            isActive: $isActive,
            isDefault: $isDefaultRequested,
            createdAt: $existing->createdAt,
            updatedAt: time()
        );

        $saved = $this->modelRepository->save($updated);
        if ($isDefaultRequested) {
            $this->modelRepository->promoteToDefault($saved);
            $saved = $this->requireModel($saved->modelId ?? 0);
        }

        $this->auditRepository->record($userId, $saved->modelId, 'model_update', [
            'name' => $saved->name,
            'slug' => $saved->slug,
            'type' => $saved->type->value,
            'is_active' => $saved->isActive,
            'is_default' => $saved->isDefault,
        ]);

        return self::formatModel($saved);
    }

    public function deleteModel(int $userId, int $modelId): void
    {
        $existing = $this->requireOwnedModel($userId, $modelId);
        $this->modelRepository->delete($modelId, $userId);
        $this->auditRepository->record($userId, $modelId, 'model_delete', [
            'name' => $existing->name,
            'slug' => $existing->slug,
        ]);
    }

    private static function formatModel(ModelDefinition $model): array
    {
        return [
            'model_id' => $model->modelId,
            'user_id' => $model->userId,
            'name' => $model->name,
            'slug' => $model->slug,
            'type' => $model->type->value,
            'is_active' => $model->isActive,
            'is_default' => $model->isDefault,
            'created_at' => $model->createdAt,
            'updated_at' => $model->updatedAt,
            'weighting_config' => $model->weightingConfig,
        ];
    }

    private function ensureUniqueSlug(int $userId, string $slug, ?string $currentSlug): string
    {
        $base = substr($slug, 0, 191);
        if ($currentSlug !== null && $currentSlug === $base) {
            return $base;
        }

        $candidate = $base;
        $suffix = 1;
        while (true) {
            $existing = $this->modelRepository->findBySlug($userId, $candidate);
            if ($existing === null || ($currentSlug !== null && $existing->slug === $currentSlug)) {
                return $candidate;
            }

            $candidate = substr($base, 0, 180) . '-' . $suffix;
            $suffix++;
        }
    }

    /**
     * @param mixed $config
     * @return array<string, mixed>
     */
    private function normaliseWeightingConfig(mixed $config): array
    {
        if ($config === null) {
            return [];
        }

        if (!is_array($config)) {
            throw new InvalidArgumentException('Weighting configuration must be an object.');
        }

        return $config;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug ?? '');
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            $slug = 'model-' . time();
        }

        return substr($slug, 0, 191);
    }

    private function requireModel(int $modelId): ModelDefinition
    {
        $model = $this->modelRepository->findById($modelId);
        if ($model === null) {
            throw new InvalidArgumentException('Attribution model not found.');
        }

        return $model;
    }

    private function requireOwnedModel(int $userId, int $modelId): ModelDefinition
    {
        $model = $this->requireModel($modelId);
        if ($model->userId !== $userId) {
            throw new InvalidArgumentException('Attribution model not found.');
        }

        return $model;
    }
}
