<?php

declare(strict_types=1);

namespace Tests\Attribution;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionService;
use Prosper202\Attribution\ExportJob;
use Prosper202\Attribution\ExportStatus;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\AuditRepositoryInterface;
use Prosper202\Attribution\Repository\ExportJobRepositoryInterface;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\NullSnapshotRepository;
use Prosper202\Attribution\Repository\NullTouchpointRepository;

final class AttributionServiceTest extends TestCase
{
    public function testCreateModelGeneratesUniqueSlugAndLogsAudit(): void
    {
        $modelRepo = new InMemoryModelRepository();
        $auditRepo = new InMemoryAuditRepository();
        $service = $this->makeService($modelRepo, $auditRepo);

        $first = $service->createModel(5, [
            'name' => 'Time Decay',
            'type' => 'time_decay',
            'weighting_config' => ['half_life_hours' => 12],
            'is_default' => true,
        ]);

        self::assertSame('time-decay', $first['slug']);
        self::assertTrue($first['is_default']);

        $second = $service->createModel(5, [
            'name' => 'Time Decay',
            'type' => 'time_decay',
            'weighting_config' => ['half_life_hours' => 24],
        ]);

        self::assertSame('time-decay-1', $second['slug']);
        self::assertCount(2, $auditRepo->entries);
        self::assertSame('model_create', $auditRepo->entries[0]['action']);
    }

    public function testUpdateModelMutatesFields(): void
    {
        $modelRepo = new InMemoryModelRepository();
        $auditRepo = new InMemoryAuditRepository();
        $service = $this->makeService($modelRepo, $auditRepo);

        $created = $service->createModel(9, [
            'name' => 'Assisted Model',
            'type' => 'assisted',
        ]);

        $updated = $service->updateModel(9, (int) $created['model_id'], [
            'name' => 'Assisted Refined',
            'is_active' => false,
        ]);

        self::assertSame('assisted-refined', $updated['slug']);
        self::assertFalse($updated['is_active']);
        self::assertSame('Assisted Refined', $updated['name']);
        self::assertSame('model_update', $auditRepo->entries[array_key_last($auditRepo->entries)]['action']);
    }

    public function testDeleteModelRemovesFromRepository(): void
    {
        $modelRepo = new InMemoryModelRepository();
        $auditRepo = new InMemoryAuditRepository();
        $service = $this->makeService($modelRepo, $auditRepo);

        $model = $service->createModel(3, [
            'name' => 'Disposable',
            'type' => 'last_touch',
        ]);

        $service->deleteModel(3, (int) $model['model_id']);

        self::assertNull($modelRepo->findById((int) $model['model_id']));
        self::assertSame('model_delete', $auditRepo->entries[array_key_last($auditRepo->entries)]['action']);
    }

    public function testRejectsInvalidType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $service = $this->makeService(new InMemoryModelRepository(), new InMemoryAuditRepository());
        $service->createModel(1, ['name' => 'Test', 'type' => 'unknown']);
    }

    private function makeService(ModelRepositoryInterface $modelRepo, AuditRepositoryInterface $auditRepo): AttributionService
    {
        $snapshotRepo = new NullSnapshotRepository();
        $touchpointRepo = new NullTouchpointRepository();
        $exportRepo = new InMemoryExportJobRepository();

        return new AttributionService($modelRepo, $snapshotRepo, $touchpointRepo, $auditRepo, $exportRepo);
    }
}

/**
 * @implements ModelRepositoryInterface
 */
final class InMemoryModelRepository implements ModelRepositoryInterface
{
    /** @var array<int, ModelDefinition> */
    public array $models = [];
    private int $nextId = 1;

    public function findById(int $modelId): ?ModelDefinition
    {
        return $this->models[$modelId] ?? null;
    }

    public function findDefaultForUser(int $userId): ?ModelDefinition
    {
        foreach ($this->models as $model) {
            if ($model->userId === $userId && $model->isDefault) {
                return $model;
            }
        }
        return null;
    }

    public function findForUser(int $userId, ?ModelType $type = null, bool $onlyActive = true): array
    {
        return array_values(array_filter($this->models, static function (ModelDefinition $model) use ($userId, $type, $onlyActive): bool {
            if ($model->userId !== $userId) {
                return false;
            }
            if ($onlyActive && !$model->isActive) {
                return false;
            }
            if ($type !== null && $model->type !== $type) {
                return false;
            }
            return true;
        }));
    }

    public function findBySlug(int $userId, string $slug): ?ModelDefinition
    {
        foreach ($this->models as $model) {
            if ($model->userId === $userId && $model->slug === $slug) {
                return $model;
            }
        }
        return null;
    }

    public function save(ModelDefinition $model): ModelDefinition
    {
        if ($model->modelId === null) {
            $assignedId = $this->nextId++;
            $model = new ModelDefinition(
                modelId: $assignedId,
                userId: $model->userId,
                name: $model->name,
                slug: $model->slug,
                type: $model->type,
                weightingConfig: $model->weightingConfig,
                isActive: $model->isActive,
                isDefault: $model->isDefault,
                createdAt: $model->createdAt,
                updatedAt: $model->updatedAt
            );
        }

        $this->models[$model->modelId] = $model;
        return $model;
    }

    public function promoteToDefault(ModelDefinition $model): void
    {
        foreach ($this->models as $key => $stored) {
            if ($stored->userId === $model->userId) {
                $this->models[$key] = new ModelDefinition(
                    modelId: $stored->modelId,
                    userId: $stored->userId,
                    name: $stored->name,
                    slug: $stored->slug,
                    type: $stored->type,
                    weightingConfig: $stored->weightingConfig,
                    isActive: $stored->isActive,
                    isDefault: $stored->modelId === $model->modelId,
                    createdAt: $stored->createdAt,
                    updatedAt: $stored->updatedAt
                );
            }
        }
    }

    public function delete(int $modelId, int $userId): void
    {
        unset($this->models[$modelId]);
    }

    public function setAsDefault(int $userId, int $modelId): bool
    {
        if (!isset($this->models[$modelId])) {
            return false;
        }

        foreach ($this->models as $key => $stored) {
            if ($stored->userId === $userId) {
                $this->models[$key] = new ModelDefinition(
                    modelId: $stored->modelId,
                    userId: $stored->userId,
                    name: $stored->name,
                    slug: $stored->slug,
                    type: $stored->type,
                    weightingConfig: $stored->weightingConfig,
                    isActive: $stored->isActive,
                    isDefault: $stored->modelId === $modelId,
                    createdAt: $stored->createdAt,
                    updatedAt: $stored->updatedAt
                );
            }
        }

        return true;
    }
}

final class InMemoryAuditRepository implements AuditRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $entries = [];

    public function record(int $userId, ?int $modelId, string $action, array $metadata = []): void
    {
        $this->entries[] = compact('userId', 'modelId', 'action', 'metadata');
    }
}

final class InMemoryExportJobRepository implements ExportJobRepositoryInterface
{
    /** @var array<int, ExportJob> */
    public array $jobs = [];

    private int $nextId = 1;

    public function create(ExportJob $job): ExportJob
    {
        $id = $this->nextId++;
        $created = $job->withStatus($job->status, [
            'export_id' => $id,
            'queued_at' => $job->queuedAt,
            'created_at' => $job->createdAt,
            'updated_at' => $job->updatedAt,
        ]);

        $this->jobs[$id] = $created;

        return $created;
    }

    public function findById(int $jobId): ?ExportJob
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function findPending(int $limit = 10): array
    {
        return array_slice(
            array_values(array_filter($this->jobs, static fn (ExportJob $job): bool => $job->status === ExportStatus::PENDING)),
            0,
            $limit
        );
    }

    public function markProcessing(int $jobId, int $timestamp): void
    {
        if (!isset($this->jobs[$jobId])) {
            return;
        }

        $this->jobs[$jobId] = $this->jobs[$jobId]->withStatus(ExportStatus::PROCESSING, [
            'started_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function markCompleted(int $jobId, string $filePath, int $rowsExported, int $timestamp): void
    {
        if (!isset($this->jobs[$jobId])) {
            return;
        }

        $this->jobs[$jobId] = $this->jobs[$jobId]->withStatus(ExportStatus::COMPLETED, [
            'file_path' => $filePath,
            'rows_exported' => $rowsExported,
            'completed_at' => $timestamp,
            'updated_at' => $timestamp,
            'last_error' => null,
        ]);
    }

    public function markFailed(int $jobId, string $error, int $timestamp): void
    {
        if (!isset($this->jobs[$jobId])) {
            return;
        }

        $this->jobs[$jobId] = $this->jobs[$jobId]->withStatus(ExportStatus::FAILED, [
            'failed_at' => $timestamp,
            'updated_at' => $timestamp,
            'last_error' => $error,
        ]);
    }

    public function recordWebhookAttempt(int $jobId, int $timestamp, ?int $statusCode, ?string $responseBody): void
    {
        if (!isset($this->jobs[$jobId])) {
            return;
        }

        $this->jobs[$jobId] = $this->jobs[$jobId]->withStatus($this->jobs[$jobId]->status, [
            'webhook_attempted_at' => $timestamp,
            'webhook_status_code' => $statusCode,
            'webhook_response_body' => $responseBody,
            'updated_at' => $timestamp,
        ]);
    }

    public function listRecentForModel(int $userId, int $modelId, int $limit = 20): array
    {
        $jobs = array_filter(
            $this->jobs,
            static function (ExportJob $job) use ($userId, $modelId): bool {
                return $job->userId === $userId && $job->modelId === $modelId;
            }
        );

        usort($jobs, static fn (ExportJob $a, ExportJob $b): int => $b->queuedAt <=> $a->queuedAt);

        return array_slice(array_values($jobs), 0, $limit);
    }
}
