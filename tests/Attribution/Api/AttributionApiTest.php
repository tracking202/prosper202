<?php

declare(strict_types=1);

namespace {
    $_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

    require_once __DIR__ . '/../../../api/v2/app.php';
}

namespace Tests\Attribution\Api {

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionService;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\Repository\NullAuditRepository;
use Prosper202\Attribution\Repository\ExportJobRepositoryInterface;
use Prosper202\Attribution\ExportJob;
use Prosper202\Attribution\ExportFormat;
use Prosper202\Attribution\ExportStatus;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;
use Slim\Environment;

final class AttributionApiTest extends TestCase
{
    private AttributionService $service;
    private InMemoryExportJobRepository $exportRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $modelRepository = new InMemoryModelRepository();
        $snapshotRepository = new InMemorySnapshotRepository();
        $touchpointRepository = new InMemoryTouchpointRepository();
        $auditRepository = new NullAuditRepository();
        $this->exportRepository = new InMemoryExportJobRepository();

        $this->service = new AttributionService(
            $modelRepository,
            $snapshotRepository,
            $touchpointRepository,
            $auditRepository,
            $this->exportRepository
        );

        override_attribution_authorization(1, ['view_attribution_reports', 'manage_attribution_models']);
    }

    protected function tearDown(): void
    {
        override_attribution_authorization(null);
        parent::tearDown();
    }

    public function testMetricsEndpointReturnsAggregatedPayload(): void
    {
        [$status, $payload] = $this->performGet('/attribution/metrics', [
            'model_id' => 1,
            'scope' => ScopeType::GLOBAL->value,
        ]);

        $this->assertSame(200, $status);

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($decoded['error']);
        $this->assertArrayHasKey('data', $decoded);

        $data = $decoded['data'];
        $this->assertSame(3, count($data['snapshots']));
        $this->assertSame(450.0, $data['totals']['revenue']);
        $this->assertSame(18.0, $data['totals']['conversions']);
        $this->assertSame(90.0, $data['totals']['clicks']);
        $this->assertGreaterThan(0, count($data['touchpoint_mix']));
    }

    public function testAnomaliesEndpointHighlightsSpikes(): void
    {
        [$status, $payload] = $this->performGet('/attribution/anomalies', [
            'model_id' => 1,
            'scope' => ScopeType::GLOBAL->value,
        ]);

        $this->assertSame(200, $status);

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($decoded['error']);
        $this->assertArrayHasKey('data', $decoded);

        $alerts = $decoded['data']['anomalies'];
        $this->assertNotEmpty($alerts);
        $this->assertArrayHasKey('metric', $alerts[0]);
    }

    public function testExportSchedulingEndpointQueuesJob(): void
    {
        $now = time();
        [$status, $payload] = $this->performPost('/attribution/models/1/exports', [
            'scope' => ScopeType::GLOBAL->value,
            'start_hour' => $now - 3600,
            'end_hour' => $now,
            'format' => 'csv',
        ]);

        $this->assertSame(201, $status);

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($decoded['error']);
        $this->assertNotEmpty($decoded['data']);
        $this->assertCount(1, $this->exportRepository->jobs);
    }

    /**
     * @param array<string, scalar> $query
     * @return array{int,string}
     */
    private function performGet(string $path, array $query = []): array
    {
        $queryString = http_build_query($query);
        Environment::mock([
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/api/v2/index.php',
            'PATH_INFO' => $path,
            'QUERY_STRING' => $queryString,
        ]);

        $app = create_attribution_app($this->service);

        ob_start();
        $app->run();
        $body = (string) ob_get_clean();

        return [$app->response()->getStatus(), $body];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{int,string}
     */
    private function performPost(string $path, array $payload): array
    {
        Environment::mock([
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/api/v2/index.php',
            'PATH_INFO' => $path,
            'CONTENT_TYPE' => 'application/json',
            'slim.input' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $app = create_attribution_app($this->service);

        ob_start();
        $app->run();
        $body = (string) ob_get_clean();

        return [$app->response()->getStatus(), $body];
    }
}

final class InMemoryModelRepository implements ModelRepositoryInterface
{
    /**
     * @var array<int, ModelDefinition>
     */
    private array $models;

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
    private array $snapshots;

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
        // no-op for in-memory storage
    }

    public function deleteBySnapshot(int $snapshotId): void
    {
        unset($this->touchpoints[$snapshotId]);
    }
}

final class InMemoryExportJobRepository implements ExportJobRepositoryInterface
{
    /**
     * @var array<int, ExportJob>
     */
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

}
