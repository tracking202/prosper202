<?php

declare(strict_types=1);

namespace Tests\Attribution;

require_once __DIR__ . '/Support/RepositoryFakes.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionService;
use Prosper202\Attribution\ExportFormat;
use Prosper202\Attribution\ExportJob;
use Prosper202\Attribution\ExportStatus;
use Prosper202\Attribution\Repository\ExportJobRepositoryInterface;
use Prosper202\Attribution\Repository\NullAuditRepository;
use Prosper202\Attribution\ScopeType;
use Tests\Attribution\Support\InMemoryModelRepository;
use Tests\Attribution\Support\InMemorySnapshotRepository;
use Tests\Attribution\Support\InMemoryTouchpointRepository;

final class AttributionServiceExportTest extends TestCase
{
    private AttributionService $service;
    private ExportJobRepositoryInterface $exportRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $modelRepository = new InMemoryModelRepository();
        $snapshotRepository = new InMemorySnapshotRepository();
        $touchpointRepository = new InMemoryTouchpointRepository();
        $auditRepository = new NullAuditRepository();
        $this->exportRepository = $this->makeExportRepository();

        $this->service = new AttributionService(
            $modelRepository,
            $snapshotRepository,
            $touchpointRepository,
            $auditRepository,
            $this->exportRepository
        );
    }

    public function testScheduleSnapshotExportPersistsPendingJob(): void
    {
        $now = (int) floor(time() / 3600) * 3600;

        $result = $this->service->scheduleSnapshotExport(1, 1, [
            'scope' => ScopeType::GLOBAL->value,
            'start_hour' => $now - 7200,
            'end_hour' => $now,
            'format' => ExportFormat::CSV->value,
            'webhook' => [
                'url' => 'https://example.com/hook',
                'headers' => ['X-Test' => '  value '],
            ],
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('csv', $result['format']);
        $this->assertSame('global', $result['scope_type']);
        $this->assertNotNull($result['export_id']);

        $jobs = $this->exportRepository->listRecentForModel(1, 1);
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame(ExportStatus::PENDING, $job->status);
        $this->assertNotNull($job->webhook);
        $this->assertSame('https://example.com/hook', $job->webhook->url);
        // ExportWebhook stores header values verbatim (no trimming).
        $this->assertSame(['X-Test' => '  value '], $job->webhook->headers);
        $this->assertSame($now - 7200, $job->startHour);
        $this->assertSame($now, $job->endHour);
    }

    public function testScheduleSnapshotExportRejectsInvalidWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->scheduleSnapshotExport(1, 1, [
            'scope' => ScopeType::GLOBAL->value,
            'start_hour' => 2000,
            'end_hour' => 1000,
            'format' => ExportFormat::CSV->value,
        ]);
    }

    public function testListSnapshotExportsReturnsFormattedJobs(): void
    {
        $now = (int) floor(time() / 3600) * 3600;

        $this->service->scheduleSnapshotExport(1, 1, [
            'scope' => ScopeType::GLOBAL->value,
            'start_hour' => $now - 7200,
            'end_hour' => $now - 3600,
            'format' => ExportFormat::CSV->value,
        ]);

        $jobs = $this->service->listSnapshotExports(1, 1, 10);

        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame('pending', $job['status']);
        $this->assertSame(1, $job['model_id']);
        $this->assertSame('csv', $job['format']);
        $this->assertNotNull($job['export_id']);
        $this->assertNull($job['completed_at']);
    }

    /**
     * Builds an in-memory export job repository backed by the authoritative
     * Prosper202\Attribution\ExportJob value object. Defined as an anonymous
     * class so it never collides with the like-named fakes declared in sibling
     * test files that share the Tests\Attribution namespace.
     */
    private function makeExportRepository(): ExportJobRepositoryInterface
    {
        return new class implements ExportJobRepositoryInterface {
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
                    array_values(array_filter(
                        $this->jobs,
                        static fn (ExportJob $job): bool => $job->status === ExportStatus::PENDING
                    )),
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
        };
    }
}
