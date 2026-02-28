<?php

declare(strict_types=1);

namespace Tests\Attribution\Export;

require_once __DIR__ . '/../Support/RepositoryFakes.php';

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\Export\ExportFormat;
use Prosper202\Attribution\Export\ExportJob;
use Prosper202\Attribution\Export\ExportProcessor;
use Prosper202\Attribution\Export\ExportStatus;
use Prosper202\Attribution\Export\SnapshotExporter;
use Prosper202\Attribution\Export\WebhookDispatcher;
use Prosper202\Attribution\Repository\ExportRepositoryInterface;
use Prosper202\Attribution\ScopeType;
use Tests\Attribution\Support\InMemoryModelRepository;
use Tests\Attribution\Support\InMemorySnapshotRepository;

final class ExportProcessorTest extends TestCase
{
    private InMemoryExportRepo $exportRepository;
    private InMemoryModelRepository $modelRepository;
    private InMemorySnapshotRepository $snapshotRepository;
    private ExportProcessor $processor;
    private string $exportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exportRepository = new InMemoryExportRepo();
        $this->modelRepository = new InMemoryModelRepository();
        $this->snapshotRepository = new InMemorySnapshotRepository();

        $this->exportPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prosper202-export-tests';
        if (is_dir($this->exportPath)) {
            $this->recursiveDelete($this->exportPath);
        }

        $snapshotExporter = new SnapshotExporter($this->exportPath);
        $webhookDispatcher = new WebhookDispatcher();

        $this->processor = new ExportProcessor(
            $this->exportRepository,
            $this->snapshotRepository,
            $this->modelRepository,
            $snapshotExporter,
            $webhookDispatcher
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->exportPath)) {
            $this->recursiveDelete($this->exportPath);
        }

        parent::tearDown();
    }

    public function testProcessPendingCompletesJob(): void
    {
        $now = (int) floor(time() / 3600) * 3600;
        $timestamp = time();

        $job = new ExportJob(
            exportId: null,
            userId: 1,
            modelId: 1,
            scopeType: ScopeType::GLOBAL,
            scopeId: null,
            startHour: $now - 7200,
            endHour: $now,
            format: ExportFormat::CSV,
            status: ExportStatus::PENDING,
            filePath: null,
            downloadToken: null,
            webhookUrl: null,
            webhookMethod: 'POST',
            webhookHeaders: [],
            webhookStatusCode: null,
            webhookResponseBody: null,
            lastAttemptedAt: null,
            completedAt: null,
            errorMessage: null,
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );

        $this->exportRepository->create($job);

        $results = $this->processor->processPending(5);

        $this->assertCount(1, $results);
        $this->assertSame('completed', $results[0]['status']);
        $this->assertArrayHasKey('export_id', $results[0]);

        $jobs = $this->exportRepository->findForUser(1);
        $this->assertCount(1, $jobs);
        $this->assertSame('completed', $jobs[0]->status->value);
        $this->assertNotNull($jobs[0]->filePath);
        $this->assertFileExists($jobs[0]->filePath);
    }

    public function testProcessPendingMarksJobsFailedWhenModelMissing(): void
    {
        $now = (int) floor(time() / 3600) * 3600;
        $timestamp = time();

        $job = new ExportJob(
            exportId: null,
            userId: 1,
            modelId: 1,
            scopeType: ScopeType::GLOBAL,
            scopeId: null,
            startHour: $now - 3600,
            endHour: $now,
            format: ExportFormat::CSV,
            status: ExportStatus::PENDING,
            filePath: null,
            downloadToken: null,
            webhookUrl: null,
            webhookMethod: 'POST',
            webhookHeaders: [],
            webhookStatusCode: null,
            webhookResponseBody: null,
            lastAttemptedAt: null,
            completedAt: null,
            errorMessage: null,
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );

        $this->exportRepository->create($job);
        $this->modelRepository->delete(1, 1);

        $results = $this->processor->processPending(5);

        $this->assertCount(1, $results);
        $this->assertSame('failed', $results[0]['status']);
        $this->assertStringContainsString('no longer available', (string) $results[0]['error']);

        $jobs = $this->exportRepository->findForUser(1);
        $this->assertSame('failed', $jobs[0]->status->value);
    }

    private function recursiveDelete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->recursiveDelete($full);
            } elseif (is_file($full)) {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}

/**
 * In-memory fake for ExportRepositoryInterface (Export subsystem).
 */
final class InMemoryExportRepo implements ExportRepositoryInterface
{
    /** @var array<int, ExportJob> */
    public array $jobs = [];

    private int $nextId = 1;

    public function create(ExportJob $job): ExportJob
    {
        $job->exportId = $this->nextId++;
        $this->jobs[$job->exportId] = $job;

        return $job;
    }

    public function update(ExportJob $job): ExportJob
    {
        if ($job->exportId !== null) {
            $this->jobs[$job->exportId] = $job;
        }

        return $job;
    }

    public function findById(int $exportId): ?ExportJob
    {
        return $this->jobs[$exportId] ?? null;
    }

    public function findForUser(int $userId, ?int $modelId = null, int $limit = 25): array
    {
        $filtered = array_filter(
            $this->jobs,
            static function (ExportJob $job) use ($userId, $modelId): bool {
                if ($job->userId !== $userId) {
                    return false;
                }
                if ($modelId !== null && $job->modelId !== $modelId) {
                    return false;
                }
                return true;
            }
        );

        usort($filtered, static fn (ExportJob $a, ExportJob $b): int => $b->createdAt <=> $a->createdAt);

        return array_slice(array_values($filtered), 0, $limit);
    }

    public function claimPending(int $limit = 10): array
    {
        $now = time();
        $claimed = [];

        foreach ($this->jobs as $job) {
            if ($job->status !== ExportStatus::PENDING) {
                continue;
            }
            $job->markProcessing($now);
            $claimed[] = $job;
            if (count($claimed) >= $limit) {
                break;
            }
        }

        return $claimed;
    }
}
