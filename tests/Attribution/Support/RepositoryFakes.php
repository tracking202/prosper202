<?php

declare(strict_types=1);

namespace Tests\Attribution\Support;

use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\Repository\ExportJobRepositoryInterface;
use Prosper202\Attribution\ExportJob;
use Prosper202\Attribution\ExportStatus;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;

/**
 * Shared in-memory fakes for attribution repositories used by the test suite.
 */
final class InMemoryModelRepository implements ModelRepositoryInterface
{
    /**
     * @var array<int, ModelDefinition>
     */
    private array $models = [];

    public function __construct()
    {
        $now = time();
        $this->models = [
            1 => new ModelDefinition(
                modelId: 1,
                userId: 1,
                name: 'Test Position Model',
                slug: 'test-position-model',
                type: ModelType::POSITION_BASED,
                weightingConfig: ['first' => 0.3, 'last' => 0.4],
                isActive: true,
                isDefault: true,
                createdAt: $now,
                updatedAt: $now
            ),
        ];
    }

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
        return array_values(array_filter(
            $this->models,
            static function (ModelDefinition $model) use ($userId, $type, $onlyActive): bool {
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
            }
        ));
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
            $model = new ModelDefinition(
                modelId: count($this->models) + 1,
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
        foreach ($this->models as $id => $existing) {
            $this->models[$id] = new ModelDefinition(
                modelId: $existing->modelId,
                userId: $existing->userId,
                name: $existing->name,
                slug: $existing->slug,
                type: $existing->type,
                weightingConfig: $existing->weightingConfig,
                isActive: $existing->isActive,
                isDefault: $existing->modelId === $model->modelId,
                createdAt: $existing->createdAt,
                updatedAt: $existing->updatedAt
            );
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

        foreach ($this->models as $id => $existing) {
            if ($existing->userId === $userId) {
                $this->models[$id] = new ModelDefinition(
                    modelId: $existing->modelId,
                    userId: $existing->userId,
                    name: $existing->name,
                    slug: $existing->slug,
                    type: $existing->type,
                    weightingConfig: $existing->weightingConfig,
                    isActive: $existing->isActive,
                    isDefault: $existing->modelId === $modelId,
                    createdAt: $existing->createdAt,
                    updatedAt: $existing->updatedAt
                );
            }
        }

        return true;
    }
}

final class InMemorySnapshotRepository implements SnapshotRepositoryInterface
{
    /**
     * @var Snapshot[]
     */
    private array $snapshots = [];

    public function __construct()
    {
        $baseHour = (int) floor(time() / 3600) * 3600;
        $this->snapshots = [
            new Snapshot(
                snapshotId: 101,
                modelId: 1,
                userId: 1,
                scopeType: ScopeType::GLOBAL,
                scopeId: null,
                dateHour: $baseHour - 7200,
                lookbackStart: $baseHour - 172800,
                lookbackEnd: $baseHour - 3600,
                attributedClicks: 20,
                attributedConversions: 6,
                attributedRevenue: 120.0,
                attributedCost: 40.0,
                createdAt: $baseHour - 3600
            ),
            new Snapshot(
                snapshotId: 102,
                modelId: 1,
                userId: 1,
                scopeType: ScopeType::GLOBAL,
                scopeId: null,
                dateHour: $baseHour - 3600,
                lookbackStart: $baseHour - 172800,
                lookbackEnd: $baseHour - 1800,
                attributedClicks: 30,
                attributedConversions: 4,
                attributedRevenue: 130.0,
                attributedCost: 45.0,
                createdAt: $baseHour - 1800
            ),
            new Snapshot(
                snapshotId: 103,
                modelId: 1,
                userId: 1,
                scopeType: ScopeType::GLOBAL,
                scopeId: null,
                dateHour: $baseHour,
                lookbackStart: $baseHour - 172800,
                lookbackEnd: $baseHour,
                attributedClicks: 40,
                attributedConversions: 8,
                attributedRevenue: 200.0,
                attributedCost: 50.0,
                createdAt: $baseHour
            ),
        ];
    }

    public function findForRange(int $modelId, ScopeType $scopeType, ?int $scopeId, int $startHour, int $endHour, int $limit = 500, int $offset = 0): array
    {
        return array_values(array_filter(
            $this->snapshots,
            static function (Snapshot $snapshot) use ($modelId): bool {
                return $snapshot->modelId === $modelId;
            }
        ));
    }

    public function findLatest(int $modelId, ScopeType $scopeType, ?int $scopeId): ?Snapshot
    {
        $snapshots = $this->findForRange($modelId, $scopeType, $scopeId, 0, time());
        return $snapshots !== [] ? end($snapshots) : null;
    }

    public function save(Snapshot $snapshot): Snapshot
    {
        $this->snapshots[] = $snapshot;
        return $snapshot;
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $before = count($this->snapshots);
        $this->snapshots = array_values(array_filter(
            $this->snapshots,
            static fn (Snapshot $snapshot): bool => $snapshot->dateHour >= $timestamp
        ));

        return $before - count($this->snapshots);
    }
}

final class InMemoryTouchpointRepository implements TouchpointRepositoryInterface
{
    /**
     * @var array<int, Touchpoint[]>
     */
    private array $touchpoints;

    public function __construct()
    {
        $this->touchpoints = [
            101 => [
                new Touchpoint(1, 101, 501, 2001, 0, 0.4, 1.0, time()),
                new Touchpoint(2, 101, 501, 2002, 1, 0.6, 1.0, time()),
            ],
            102 => [
                new Touchpoint(3, 102, 502, 2003, 0, 0.2, 1.0, time()),
                new Touchpoint(4, 102, 502, 2004, 1, 0.8, 1.0, time()),
            ],
            103 => [
                new Touchpoint(5, 103, 503, 2005, 0, 0.1, 1.0, time()),
                new Touchpoint(6, 103, 503, 2006, 1, 0.9, 1.0, time()),
            ],
        ];
    }

    public function findBySnapshot(int $snapshotId): array
    {
        return $this->touchpoints[$snapshotId] ?? [];
    }

    public function saveBatch(array $touchpoints): void
    {
        // No-op for the fake implementation.
    }

    public function deleteBySnapshot(int $snapshotId): void
    {
        unset($this->touchpoints[$snapshotId]);
    }
}

final class InMemoryExportRepository implements ExportJobRepositoryInterface
{
    /**
     * @var array<int, ExportJob>
     */
    private array $jobs = [];

    private int $nextId = 1;

    /** @var callable|null */
    private $clock;

    /**
     * @param callable():int|null $clock
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

    public function create(ExportJob $job): ExportJob
    {
        $id = $this->nextId++;
        $created = new ExportJob(
            exportId: $id,
            userId: $job->userId,
            modelId: $job->modelId,
            scopeType: $job->scopeType,
            scopeId: $job->scopeId,
            startHour: $job->startHour,
            endHour: $job->endHour,
            format: $job->format,
            status: $job->status,
            webhook: $job->webhook,
            createdAt: $job->createdAt
        );
        $this->jobs[$id] = $created;

        return clone $created;
    }

    public function findById(int $jobId): ?ExportJob
    {
        return isset($this->jobs[$jobId]) ? clone $this->jobs[$jobId] : null;
    }

    /**
     * @return ExportJob[]
     */
    public function findPending(int $limit = 10): array
    {
        $pending = array_filter(
            $this->jobs,
            static fn (ExportJob $job): bool => $job->status === ExportStatus::PENDING
        );

        usort($pending, static fn (ExportJob $a, ExportJob $b): int => ($a->createdAt ?? 0) <=> ($b->createdAt ?? 0));
        $batch = array_slice($pending, 0, max(1, $limit));

        return array_map(static fn (ExportJob $job): ExportJob => clone $job, $batch);
    }

    public function markProcessing(int $jobId, int $timestamp): void
    {
        if (!isset($this->jobs[$jobId])) {
            return;
        }

        $job = $this->jobs[$jobId];
        $this->jobs[$jobId] = new ExportJob(
            exportId: $job->exportId,
            userId: $job->userId,
            modelId: $job->modelId,
            scopeType: $job->scopeType,
            scopeId: $job->scopeId,
            startHour: $job->startHour,
            endHour: $job->endHour,
            format: $job->format,
            status: ExportStatus::PROCESSING,
            webhook: $job->webhook,
            createdAt: $job->createdAt,
            processedAt: $timestamp
        );
    }

    public function markCompleted(int $jobId, string $filePath, int $rowsExported, int $timestamp): void
    {
        if (!isset($this->jobs[$jobId])) {
            return;
        }

        $job = $this->jobs[$jobId];
        $this->jobs[$jobId] = new ExportJob(
            exportId: $job->exportId,
            userId: $job->userId,
            modelId: $job->modelId,
            scopeType: $job->scopeType,
            scopeId: $job->scopeId,
            startHour: $job->startHour,
            endHour: $job->endHour,
            format: $job->format,
            status: ExportStatus::COMPLETED,
            webhook: $job->webhook,
            createdAt: $job->createdAt,
            processedAt: $job->processedAt,
            completedAt: $timestamp,
            filePath: $filePath,
            rowsExported: $rowsExported
        );
    }

    public function markFailed(int $jobId, string $error, int $timestamp): void
    {
        if (!isset($this->jobs[$jobId])) {
            return;
        }

        $job = $this->jobs[$jobId];
        $this->jobs[$jobId] = new ExportJob(
            exportId: $job->exportId,
            userId: $job->userId,
            modelId: $job->modelId,
            scopeType: $job->scopeType,
            scopeId: $job->scopeId,
            startHour: $job->startHour,
            endHour: $job->endHour,
            format: $job->format,
            status: ExportStatus::FAILED,
            webhook: $job->webhook,
            createdAt: $job->createdAt,
            processedAt: $job->processedAt,
            failedAt: $timestamp,
            errorMessage: $error
        );
    }

    public function recordWebhookAttempt(int $jobId, int $timestamp, ?int $statusCode, ?string $responseBody): void
    {
        if (!isset($this->jobs[$jobId])) {
            return;
        }

        $job = $this->jobs[$jobId];
        $this->jobs[$jobId] = new ExportJob(
            exportId: $job->exportId,
            userId: $job->userId,
            modelId: $job->modelId,
            scopeType: $job->scopeType,
            scopeId: $job->scopeId,
            startHour: $job->startHour,
            endHour: $job->endHour,
            format: $job->format,
            status: $job->status,
            webhook: $job->webhook,
            createdAt: $job->createdAt,
            processedAt: $job->processedAt,
            completedAt: $job->completedAt,
            failedAt: $job->failedAt,
            filePath: $job->filePath,
            rowsExported: $job->rowsExported,
            errorMessage: $job->errorMessage,
            webhookAttemptedAt: $timestamp,
            webhookStatusCode: $statusCode,
            webhookResponseBody: $responseBody
        );
    }

    /**
     * @return ExportJob[]
     */
    public function listRecentForModel(int $userId, int $modelId, int $limit = 20): array
    {
        $filtered = array_filter(
            $this->jobs,
            static function (ExportJob $job) use ($userId, $modelId): bool {
                return $job->userId === $userId && $job->modelId === $modelId;
            }
        );

        usort($filtered, static fn (ExportJob $a, ExportJob $b): int => ($b->createdAt ?? 0) <=> ($a->createdAt ?? 0));
        $slice = array_slice($filtered, 0, max(1, $limit));

        return array_map(static fn (ExportJob $job): ExportJob => clone $job, $slice);
    }

    /**
     * @return array<int, ExportJob>
     */
    public function all(): array
    {
        return array_map(static fn (ExportJob $job): ExportJob => clone $job, $this->jobs);
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }
}
