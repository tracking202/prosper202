<?php

declare(strict_types=1);

namespace {
    $_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

    require_once __DIR__ . '/../../../api/v2/app.php';
    require_once __DIR__ . '/../Support/RepositoryFakes.php';
}

namespace Tests\Attribution\Api {

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionService;
use Prosper202\Attribution\Repository\NullAuditRepository;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Export\ExportFormat;
use Slim\Environment;
use Tests\Attribution\Support\InMemoryExportRepository;
use Tests\Attribution\Support\InMemoryModelRepository;
use Tests\Attribution\Support\InMemorySnapshotRepository;
use Tests\Attribution\Support\InMemoryTouchpointRepository;

final class AttributionApiTest extends TestCase
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
        $this->exportRepository = new InMemoryExportRepository(fn (): int => 1_700_000_000);

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

    public function testScheduleExportEndpointCreatesJob(): void
    {
        $now = (int) floor(time() / 3600) * 3600;

        [$status, $payload] = $this->performPost('/attribution/models/1/exports', [], [
            'scope' => ScopeType::GLOBAL->value,
            'start_hour' => $now - 7200,
            'end_hour' => $now,
            'format' => ExportFormat::CSV->value,
        ]);

        $this->assertSame(202, $status);

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($decoded['error']);
        $this->assertSame('pending', $decoded['data']['status']);
        $this->assertSame(1, $decoded['data']['user_id']);
        $this->assertSame(1, $decoded['data']['model_id']);
        $this->assertNotEmpty($decoded['data']['download_token']);

        $jobs = $this->exportRepository->findForUser(1);
        $this->assertCount(1, $jobs);
        $this->assertSame('pending', $jobs[0]->status->value);
        $this->assertNull($jobs[0]->lastAttemptedAt);
    }

    public function testListExportsEndpointReturnsScheduledJobs(): void
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

        [$status, $payload] = $this->performGet('/attribution/exports');

        $this->assertSame(200, $status);

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($decoded['error']);
        $this->assertCount(1, $decoded['data']);
        $this->assertSame('pending', $decoded['data'][0]['status']);
        $this->assertSame(1, $decoded['data'][0]['model_id']);
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
     * @param array<string, scalar> $query
     * @param array<string, mixed> $body
     * @return array{int,string}
     */
    private function performPost(string $path, array $query = [], array $body = []): array
    {
        $queryString = http_build_query($query);
        Environment::mock([
            'REQUEST_METHOD' => 'POST',
            'SCRIPT_NAME' => '/api/v2/index.php',
            'PATH_INFO' => $path,
            'QUERY_STRING' => $queryString,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $app = create_attribution_app($this->service);
        $request = $app->request();
        $request->setBody(json_encode($body, JSON_THROW_ON_ERROR));

        ob_start();
        $app->run();
        $responseBody = (string) ob_get_clean();

        return [$app->response()->getStatus(), $responseBody];
    }
}
