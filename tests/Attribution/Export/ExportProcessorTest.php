<?php

declare(strict_types=1);

namespace Tests\Attribution\Export;

require_once __DIR__ . '/../Support/RepositoryFakes.php';

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionService;
use Prosper202\Attribution\Export\ExportProcessor;
use Prosper202\Attribution\Export\SnapshotExporter;
use Prosper202\Attribution\Export\WebhookDispatcher;
use Prosper202\Attribution\Repository\NullAuditRepository;
use Prosper202\Attribution\ScopeType;
use Tests\Attribution\Support\InMemoryExportRepository;
use Tests\Attribution\Support\InMemoryModelRepository;
use Tests\Attribution\Support\InMemorySnapshotRepository;
use Tests\Attribution\Support\InMemoryTouchpointRepository;

final class ExportProcessorTest extends TestCase
{
    private InMemoryExportRepository $exportRepository;
    private InMemoryModelRepository $modelRepository;
    private InMemorySnapshotRepository $snapshotRepository;
    private InMemoryTouchpointRepository $touchpointRepository;
    private SnapshotExporter $snapshotExporter;
    private WebhookDispatcher $webhookDispatcher;
    private ExportProcessor $processor;
    private AttributionService $service;
    private string $exportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exportRepository = new InMemoryExportRepository(fn (): int => 1_700_000_100);
        $this->modelRepository = new InMemoryModelRepository();
        $this->snapshotRepository = new InMemorySnapshotRepository();
        $this->touchpointRepository = new InMemoryTouchpointRepository();
        $auditRepository = new NullAuditRepository();

        $this->exportPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prosper202-export-tests';
        if (is_dir($this->exportPath)) {
            $this->recursiveDelete($this->exportPath);
        }

        $this->snapshotExporter = new SnapshotExporter($this->exportPath);
        $this->webhookDispatcher = new WebhookDispatcher();

        $this->service = new AttributionService(
            $this->modelRepository,
            $this->snapshotRepository,
            $this->touchpointRepository,
            $auditRepository,
            $this->exportRepository
        );

        $this->processor = new ExportProcessor(
            $this->exportRepository,
            $this->snapshotRepository,
            $this->modelRepository,
            $this->snapshotExporter,
            $this->webhookDispatcher
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
        $this->service->scheduleSnapshotExport(
            1,
            1,
            ScopeType::GLOBAL,
            null,
            $now - 7200,
            $now,
            \Prosper202\Attribution\Export\ExportFormat::CSV
        );

        $results = $this->processor->processPending(5);

        $this->assertCount(1, $results);
        $this->assertSame('completed', $results[0]['status']);
        $this->assertArrayHasKey('export_id', $results[0]);

        $jobs = $this->exportRepository->findForUser(1);
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame('completed', $job->status->value);
        $this->assertNotNull($job->filePath);
        $this->assertFileExists($job->filePath);
    }

    public function testProcessPendingMarksJobsFailedWhenModelMissing(): void
    {
        $now = (int) floor(time() / 3600) * 3600;
        $this->service->scheduleSnapshotExport(
            1,
            1,
            ScopeType::GLOBAL,
            null,
            $now - 3600,
            $now,
            \Prosper202\Attribution\Export\ExportFormat::CSV
        );

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
