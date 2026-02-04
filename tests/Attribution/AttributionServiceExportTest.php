<?php

declare(strict_types=1);

namespace Tests\Attribution;

require_once __DIR__ . '/Support/RepositoryFakes.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionService;
use Prosper202\Attribution\Export\ExportFormat;
use Prosper202\Attribution\Export\ExportStatus;
use Prosper202\Attribution\Repository\NullAuditRepository;
use Prosper202\Attribution\ScopeType;
use Tests\Attribution\Support\InMemoryExportRepository;
use Tests\Attribution\Support\InMemoryModelRepository;
use Tests\Attribution\Support\InMemorySnapshotRepository;
use Tests\Attribution\Support\InMemoryTouchpointRepository;

final class AttributionServiceExportTest extends TestCase
{
    private AttributionService $service;
    private InMemoryExportRepository $exportRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $modelRepository = new InMemoryModelRepository();
        $snapshotRepository = new InMemorySnapshotRepository();
        $touchpointRepository = new InMemoryTouchpointRepository();
        $auditRepository = new NullAuditRepository();
        $this->exportRepository = new InMemoryExportRepository();

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

        $result = $this->service->scheduleSnapshotExport(
            1,
            1,
            ScopeType::GLOBAL,
            null,
            $now - 7200,
            $now,
            ExportFormat::CSV,
            ['headers' => ['X-Test' => '  value ']]
        );

        $this->assertSame('pending', $result['status']);
        $this->assertSame('csv', $result['format']);
        $this->assertSame('global', $result['scope_type']);
        $this->assertNotNull($result['export_id']);

        $jobs = $this->exportRepository->findForUser(1);
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame(ExportStatus::PENDING, $job->status);
        $this->assertSame(['X-Test' => 'value'], $job->webhookHeaders);
        $this->assertNull($job->webhookUrl);
        $this->assertSame($now - 7200, $job->startHour);
        $this->assertSame($now, $job->endHour);
    }

    public function testScheduleSnapshotExportRejectsInvalidWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->scheduleSnapshotExport(
            1,
            1,
            ScopeType::GLOBAL,
            null,
            2000,
            1000,
            ExportFormat::CSV
        );
    }

    public function testListExportsReturnsFormattedJobs(): void
    {
        $now = (int) floor(time() / 3600) * 3600;

        $this->service->scheduleSnapshotExport(
            1,
            1,
            ScopeType::GLOBAL,
            null,
            $now - 7200,
            $now - 3600,
            ExportFormat::CSV
        );

        $jobs = $this->service->listExports(1, 1, 10);

        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame('pending', $job['status']);
        $this->assertSame(1, $job['model_id']);
        $this->assertSame('csv', $job['format']);
        $this->assertArrayHasKey('download_token', $job);
        $this->assertNull($job['completed_at']);
    }
}
