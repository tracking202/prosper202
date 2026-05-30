<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\CapabilitiesController;
use Api\V3\Controllers\SyncController;
use Api\V3\RequestContext;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\RemoteApiClient;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;
use Tests\TestCase;

class FakeSyncEngine extends SyncEngine
{
    /** @var array<string, mixed> */
    public array $planResponse = [];
    /** @var array<string, mixed> */
    public array $executeResponse = [];

    public function buildPlan(array $sourceProfile, array $targetProfile, string $entityArg = 'all', array $options = []): array
    {
        if ($this->planResponse !== []) {
            return $this->planResponse;
        }

        return [
            'source' => $sourceProfile['name'] ?? $sourceProfile['url'] ?? 'source',
            'target' => $targetProfile['name'] ?? $targetProfile['url'] ?? 'target',
            'entity' => $entityArg,
            'summary' => [
                'only_in_source' => 0,
                'only_in_target' => 0,
                'changed' => 0,
                'identical' => 0,
            ],
            'data' => [],
            'pair_key' => 'pair',
            'prune_confirmation_token' => null,
        ];
    }

    public function execute(array $sourceProfile, array $targetProfile, string $entityArg = 'all', array $options = [], ?callable $eventLogger = null): array
    {
        if ($eventLogger !== null) {
            $eventLogger('info', 'Fake run executed', ['entity' => $entityArg]);
        }

        if ($this->executeResponse !== []) {
            return $this->executeResponse;
        }

        return [
            'source' => $sourceProfile['name'] ?? $sourceProfile['url'] ?? 'source',
            'target' => $targetProfile['name'] ?? $targetProfile['url'] ?? 'target',
            'entity' => $entityArg,
            'dry_run' => (bool)($options['dry_run'] ?? false),
            'force_update' => false,
            'prune' => false,
            'prune_preview' => false,
            'results' => [
                'campaigns' => ['synced' => 1, 'skipped' => 0, 'failed' => 0, 'pruned' => 0, 'errors' => []],
            ],
            'mappings' => [],
            'delete_candidates' => [],
        ];
    }
}

final class FlakySyncEngine extends FakeSyncEngine
{
    public int $failuresRemaining = 1;

    public function execute(array $sourceProfile, array $targetProfile, string $entityArg = 'all', array $options = [], ?callable $eventLogger = null): array
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            if ($eventLogger !== null) {
                $eventLogger('error', 'Transient execution failure', ['entity' => $entityArg]);
            }
            throw new \RuntimeException('Transient execution failure');
        }

        return parent::execute($sourceProfile, $targetProfile, $entityArg, $options, $eventLogger);
    }
}

final class OptionCaptureSyncEngine extends FakeSyncEngine
{
    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public function execute(array $sourceProfile, array $targetProfile, string $entityArg = 'all', array $options = [], ?callable $eventLogger = null): array
    {
        $this->lastOptions = $options;
        return parent::execute($sourceProfile, $targetProfile, $entityArg, $options, $eventLogger);
    }
}

final class LoadSyncEngine extends SyncEngine
{
    public function execute(array $sourceProfile, array $targetProfile, string $entityArg = 'all', array $options = [], ?callable $eventLogger = null): array
    {
        if ($eventLogger !== null) {
            $eventLogger('info', 'Load test execution', ['entity' => $entityArg]);
        }

        return [
            'source' => $sourceProfile['name'] ?? 'source',
            'target' => $targetProfile['name'] ?? 'target',
            'entity' => $entityArg,
            'dry_run' => (bool)($options['dry_run'] ?? false),
            'force_update' => false,
            'prune' => false,
            'prune_preview' => false,
            'results' => [
                'campaigns' => ['synced' => 1, 'skipped' => 0, 'failed' => 0, 'pruned' => 0, 'errors' => []],
            ],
            'mappings' => [],
            'delete_candidates' => [],
        ];
    }
}

final class InMemoryPlanSyncEngine extends SyncEngine
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $sourceData = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $targetData = [];

    protected function loadDataSets(array $sourceProfile, array $targetProfile, array $query = []): array
    {
        return [$this->sourceData, $this->targetData];
    }
}

final class InMemoryExecuteSyncEngine extends SyncEngine
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $sourceData = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $targetData = [];
    private int $fetchCount = 0;

    protected function buildClients(array $sourceProfile, array $targetProfile): array
    {
        return [
            new RemoteApiClient('http://127.0.0.1', 'source-key'),
            new RemoteApiClient('http://127.0.0.1', 'target-key'),
        ];
    }

    protected function fetchPortableData(RemoteApiClient $client, array $query = []): array
    {
        $this->fetchCount++;
        return $this->fetchCount === 1 ? $this->sourceData : $this->targetData;
    }
}

final class SyncFeaturesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/p202-sync-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function testCapabilitiesEndpointExposesSyncFeatures(): void
    {
        $db = $this->createMysqliMock([
            'SELECT version FROM 202_version' => ['version' => '1.2.3'],
            'CONVERT_TZ' => ['tz' => '2000-01-01 00:00:00'],
        ]);

        $controller = new CapabilitiesController($db);
        $result = $controller->capabilities();

        $this->assertSame('v3', $result['data']['api_version']);
        $this->assertTrue($result['data']['sync_features']['sync_plan']);
        $this->assertTrue($result['data']['entity_support']['campaigns']['bulk_upsert']);
        $this->assertSame('named-timezone', $result['data']['server']['timezone_support']);
    }

    public function testCapabilitiesExposesConfiguredMaxBulkRows(): void
    {
        putenv('P202_MAX_BULK_ROWS=123');

        try {
            $db = $this->createMysqliMock([
                'SELECT version FROM 202_version' => ['version' => '1.2.3'],
                'CONVERT_TZ' => ['tz' => '2000-01-01 00:00:00'],
            ]);

            $controller = new CapabilitiesController($db);
            $result = $controller->capabilities();
            $this->assertSame(123, $result['data']['limits']['max_bulk_rows']);
        } finally {
            putenv('P202_MAX_BULK_ROWS');
        }
    }

    public function testVersionsEndpointContract(): void
    {
        $db = $this->createMysqliMock();
        $controller = new CapabilitiesController($db);
        $result = $controller->versions();

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('v3', $result['data']['preferred']);
        $this->assertContains('v3', $result['data']['supported']);
    }

    public function testCreateJobQueuesThenWorkerProcessesAndAudits(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);

        $controller = new SyncController($db, 42, $store, $engine);
        $response = $controller->createJob([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
        ]);

        $job = $response['data'];
        $this->assertSame('queued', $job['status']);
        $this->assertArrayNotHasKey('api_key', $job['request']['source']);

        $worker = $controller->runWorker(['limit' => 5]);
        $this->assertSame(1, $worker['data']['processed']);

        $updated = $controller->getJob((string)$job['job_id']);
        $this->assertSame('succeeded', $updated['data']['status']);
        $this->assertArrayHasKey('results', $updated['data']);
        $this->assertSame('campaigns', $updated['data']['results']['entity']);

        $history = $controller->history([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com'],
        ]);
        $this->assertNotEmpty($history['data']);
        $this->assertSame($updated['data']['job_id'], $history['data'][0]['job_id']);
    }

    public function testWorkerProgressUnderQueuedLoad(): void
    {
        putenv('P202_MAX_QUEUED_PER_PAIR=1000');
        try {
            $db = $this->createMysqliMock();
            $store = new ServerStateStore($this->tmpDir);
            $engine = new LoadSyncEngine($store);
            $controllerA = new SyncController($db, 7, $store, $engine);
            $controllerB = new SyncController($db, 7, $store, $engine);

            $jobCount = 200;
            for ($i = 0; $i < $jobCount; $i++) {
                $controllerA->createJob([
                    'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
                    'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
                    'entity' => 'campaigns',
                ]);
            }

            $totalProcessed = 0;
            $succeeded = 0;
            for ($i = 0; $i < 10; $i++) {
                $runA = $controllerA->runWorker(['limit' => 25]);
                $runB = $controllerB->runWorker(['limit' => 25]);
                $totalProcessed += (int)$runA['data']['processed'] + (int)$runB['data']['processed'];
                $succeeded += (int)$runA['data']['succeeded'] + (int)$runB['data']['succeeded'];
            }

            $this->assertGreaterThanOrEqual($jobCount, $totalProcessed);
            $this->assertSame($jobCount, $succeeded);
        } finally {
            putenv('P202_MAX_QUEUED_PER_PAIR');
        }
    }

    public function testCreateJobIdempotencyKeyIsRequestHashScoped(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);
        $controller = new SyncController($db, 42, $store, $engine);

        RequestContext::setHeaders(['Idempotency-Key' => 'sync-job-key-1']);
        try {
            $first = $controller->createJob([
                'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
                'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
                'entity' => 'campaigns',
            ]);

            $second = $controller->createJob([
                'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
                'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
                'entity' => 'campaigns',
            ]);

            $third = $controller->createJob([
                'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
                'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
                'entity' => 'landing-pages',
            ]);
        } finally {
            RequestContext::reset();
        }

        $this->assertSame($first['data']['job_id'], $second['data']['job_id']);
        $this->assertTrue((bool)$second['idempotent_replay']);

        $this->assertNotSame($first['data']['job_id'], $third['data']['job_id']);
        $this->assertArrayNotHasKey('idempotent_replay', $third);
    }

    public function testCreateJobRespectsPerPairQueueLimit(): void
    {
        putenv('P202_MAX_QUEUED_PER_PAIR=1');
        try {
            $db = $this->createMysqliMock();
            $store = new ServerStateStore($this->tmpDir);
            $engine = new FakeSyncEngine($store);
            $controller = new SyncController($db, 42, $store, $engine);

            $controller->createJob([
                'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
                'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
                'entity' => 'campaigns',
            ]);

            $this->expectException(ValidationException::class);
            $controller->createJob([
                'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
                'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
                'entity' => 'campaigns',
            ]);
        } finally {
            putenv('P202_MAX_QUEUED_PER_PAIR');
        }
    }

    public function testPlanRejectsInvalidCollisionMode(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);
        $controller = new SyncController($db, 42, $store, $engine);

        $this->expectException(ValidationException::class);
        $controller->plan([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
            'collision_mode' => 'invalid',
        ]);
    }

    public function testPlanDeterministicAndCollisionWarningsWithInMemoryEngine(): void
    {
        $engine = new InMemoryPlanSyncEngine(new ServerStateStore($this->tmpDir));
        $engine->sourceData = [
            'aff-networks' => [
                ['aff_network_id' => 1, 'aff_network_name' => 'Net A'],
            ],
            'campaigns' => [
                ['aff_campaign_id' => 2, 'aff_campaign_name' => 'Camp B', 'aff_network_id' => 1, 'aff_campaign_payout' => 10],
                ['aff_campaign_id' => 1, 'aff_campaign_name' => 'Camp A', 'aff_network_id' => 1, 'aff_campaign_payout' => 5],
                ['aff_campaign_id' => 3, 'aff_campaign_name' => 'Dup', 'aff_network_id' => 1, 'aff_campaign_payout' => 3],
                ['aff_campaign_id' => 4, 'aff_campaign_name' => 'Dup', 'aff_network_id' => 1, 'aff_campaign_payout' => 4],
            ],
        ];
        $engine->targetData = [
            'aff-networks' => [
                ['aff_network_id' => 9, 'aff_network_name' => 'Net A'],
            ],
            'campaigns' => [
                ['aff_campaign_id' => 10, 'aff_campaign_name' => 'Camp A', 'aff_network_id' => 9, 'aff_campaign_payout' => 5],
                ['aff_campaign_id' => 11, 'aff_campaign_name' => 'Camp B', 'aff_network_id' => 9, 'aff_campaign_payout' => 12],
                ['aff_campaign_id' => 12, 'aff_campaign_name' => 'Dup', 'aff_network_id' => 9, 'aff_campaign_payout' => 1],
                ['aff_campaign_id' => 13, 'aff_campaign_name' => 'Dup', 'aff_network_id' => 9, 'aff_campaign_payout' => 2],
            ],
        ];

        $source = ['name' => 'source', 'url' => 'https://source.example.com', 'api_key' => 'source-key'];
        $target = ['name' => 'target', 'url' => 'https://target.example.com', 'api_key' => 'target-key'];
        $first = $engine->buildPlan($source, $target, 'campaigns');
        $second = $engine->buildPlan($source, $target, 'campaigns');

        $this->assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $this->assertArrayHasKey('campaigns', $first['data']);
        $this->assertNotEmpty($first['data']['campaigns']['warnings']);
    }

    public function testPlanManualCollisionModeThrowsWithInMemoryEngine(): void
    {
        $engine = new InMemoryPlanSyncEngine(new ServerStateStore($this->tmpDir));
        $engine->sourceData = [
            'aff-networks' => [['aff_network_id' => 1, 'aff_network_name' => 'Net A']],
            'campaigns' => [
                ['aff_campaign_id' => 1, 'aff_campaign_name' => 'Dup', 'aff_network_id' => 1],
                ['aff_campaign_id' => 2, 'aff_campaign_name' => 'Dup', 'aff_network_id' => 1],
            ],
        ];
        $engine->targetData = [
            'aff-networks' => [['aff_network_id' => 9, 'aff_network_name' => 'Net A']],
            'campaigns' => [
                ['aff_campaign_id' => 10, 'aff_campaign_name' => 'Dup', 'aff_network_id' => 9],
                ['aff_campaign_id' => 11, 'aff_campaign_name' => 'Dup', 'aff_network_id' => 9],
            ],
        ];
        $source = ['name' => 'source', 'url' => 'https://source.example.com', 'api_key' => 'source-key'];
        $target = ['name' => 'target', 'url' => 'https://target.example.com', 'api_key' => 'target-key'];

        $this->expectException(ValidationException::class);
        $engine->buildPlan($source, $target, 'campaigns', ['fail_on_collision' => true]);
    }

    public function testExecuteRecordsTracingSpansForStages(): void
    {
        $store = new ServerStateStore($this->tmpDir);
        $engine = new InMemoryExecuteSyncEngine($store);
        $engine->sourceData = [
            'aff-networks' => [['aff_network_id' => 1, 'aff_network_name' => 'Net A']],
            'campaigns' => [['aff_campaign_id' => 10, 'aff_campaign_name' => 'Camp A', 'aff_network_id' => 1]],
            'trackers' => [],
            'ppc-networks' => [],
            'ppc-accounts' => [],
            'landing-pages' => [],
            'text-ads' => [],
            'rotators' => [],
        ];
        $engine->targetData = [
            'aff-networks' => [['aff_network_id' => 9, 'aff_network_name' => 'Net A']],
            'campaigns' => [['aff_campaign_id' => 20, 'aff_campaign_name' => 'Camp A', 'aff_network_id' => 9]],
            'trackers' => [],
            'ppc-networks' => [],
            'ppc-accounts' => [],
            'landing-pages' => [],
            'text-ads' => [],
            'rotators' => [],
        ];

        $source = ['name' => 'source', 'url' => 'https://source.example.com', 'api_key' => 'source-key'];
        $target = ['name' => 'target', 'url' => 'https://target.example.com', 'api_key' => 'target-key'];
        $engine->execute($source, $target, 'campaigns', ['dry_run' => true, 'prune_preview' => true, 'prune' => true]);

        $spans = $store->listSpans(null, 200);
        $names = array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $spans);
        $this->assertContains('sync.execute', $names);
        $this->assertContains('sync.execute.entity', $names);
        $this->assertContains('sync.execute.remap', $names);
        $this->assertContains('sync.execute.write', $names);
        $this->assertContains('sync.execute.prune', $names);
    }

    public function testCancelQueuedJobTransitionsToCancelledAndAudits(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);
        $controller = new SyncController($db, 42, $store, $engine);

        $created = $controller->createJob([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
        ]);

        $jobId = (string)$created['data']['job_id'];
        $cancelled = $controller->cancelJob($jobId);
        $this->assertSame('cancelled', $cancelled['data']['status']);
        $this->assertTrue((bool)$cancelled['data']['cancel_requested']);

        $history = $controller->history([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com'],
        ]);
        $this->assertNotEmpty($history['data']);
        $this->assertSame($jobId, $history['data'][0]['job_id']);
        $this->assertSame('cancelled', $history['data'][0]['status']);

        $metrics = $store->metrics();
        $this->assertSame(1, (int)($metrics['counters']['jobs_cancelled'] ?? 0));
    }

    public function testWorkerRetriesThenSucceedsWithinMaxAttempts(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FlakySyncEngine($store);
        $controller = new SyncController($db, 42, $store, $engine);

        $created = $controller->createJob([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
            'max_attempts' => 2,
        ]);
        $jobId = (string)$created['data']['job_id'];

        $firstWorker = $controller->runWorker(['limit' => 5]);
        $this->assertSame(1, $firstWorker['data']['processed']);
        $this->assertSame(1, $firstWorker['data']['requeued']);

        $firstState = $controller->getJob($jobId);
        $this->assertSame('queued', $firstState['data']['status']);
        $this->assertSame(1, (int)$firstState['data']['attempts']);

        $jobRaw = $store->getJob($jobId);
        $this->assertIsArray($jobRaw);
        $jobRaw['next_run_at'] = time() - 1;
        $store->saveJob($jobRaw);

        $secondWorker = $controller->runWorker(['limit' => 5]);
        $this->assertSame(1, $secondWorker['data']['processed']);
        $this->assertSame(1, $secondWorker['data']['succeeded']);

        $final = $controller->getJob($jobId);
        $this->assertSame('succeeded', $final['data']['status']);
        $this->assertSame(2, (int)$final['data']['attempts']);
    }

    public function testWorkerMarksPartialWhenSomeRowsFail(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);
        $engine->executeResponse = [
            'source' => 'prod',
            'target' => 'stage',
            'entity' => 'campaigns',
            'dry_run' => false,
            'force_update' => false,
            'prune' => false,
            'prune_preview' => false,
            'results' => [
                'campaigns' => ['synced' => 1, 'skipped' => 0, 'failed' => 1, 'pruned' => 0, 'errors' => ['bad row']],
            ],
            'mappings' => [],
            'delete_candidates' => [],
        ];
        $controller = new SyncController($db, 42, $store, $engine);

        $created = $controller->createJob([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
        ]);
        $jobId = (string)$created['data']['job_id'];

        $worker = $controller->runWorker(['limit' => 5]);
        $this->assertSame(1, $worker['data']['processed']);
        $this->assertSame(1, $worker['data']['partial']);

        $final = $controller->getJob($jobId);
        $this->assertSame('partial', $final['data']['status']);
    }

    public function testListChangesSupportsCursorPagination(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);

        $store->recordChange('campaigns', 'update', ['aff_campaign_id' => 1], 9);
        $store->recordChange('campaigns', 'delete', ['aff_campaign_id' => 2], 9);

        $controller = new SyncController($db, 9, $store, $engine);
        $first = $controller->listChanges('campaigns', ['limit' => 1, 'cursor_ttl' => 3600]);

        $this->assertCount(1, $first['data']);
        $this->assertNotEmpty($first['cursor']);

        $second = $controller->listChanges('campaigns', ['limit' => 5, 'cursor' => $first['cursor'], 'cursor_ttl' => 3600]);
        $this->assertCount(1, $second['data']);
        $this->assertSame('delete', $second['data'][0]['operation']);
    }

    public function testListChangesDeletedSinceReturnsOnlyDeleteOperations(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);

        $store->recordChange('campaigns', 'update', ['aff_campaign_id' => 1], 9);
        $store->recordChange('campaigns', 'delete', ['aff_campaign_id' => 2], 9);

        $controller = new SyncController($db, 9, $store, $engine);
        $result = $controller->listChanges('campaigns', ['limit' => 10, 'deleted_since' => 0, 'cursor_ttl' => 3600]);

        $this->assertCount(1, $result['data']);
        $this->assertSame('delete', $result['data'][0]['operation']);
    }

    public function testIncrementalSyncUsesManifestLastSyncEpoch(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new OptionCaptureSyncEngine($store);
        $controller = new SyncController($db, 42, $store, $engine);

        $source = ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'];
        $target = ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'];
        $pairKey = sha1(strtolower($source['url']) . '|' . strtolower($target['url']));
        $store->saveSyncManifest($pairKey, [
            'pair_key' => $pairKey,
            'last_sync_epoch' => 1700000000,
            'mappings' => [],
            'source_hashes' => [],
        ]);

        $controller->createJob([
            'source' => $source,
            'target' => $target,
            'entity' => 'campaigns',
            'incremental' => true,
        ]);
        $controller->runWorker(['limit' => 5]);

        $this->assertSame('1700000000', (string)($engine->lastOptions['updated_since'] ?? ''));
        $this->assertArrayHasKey('manifest', $engine->lastOptions);
    }

    public function testPruneRequiresConfirmationToken(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);
        $controller = new SyncController($db, 42, $store, $engine);

        $this->expectException(ValidationException::class);
        $controller->createJob([
            'source' => ['url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
            'prune' => true,
        ]);
    }

    public function testPrunePreviewDoesNotRequireConfirmationToken(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);
        $controller = new SyncController($db, 42, $store, $engine);

        $result = $controller->createJob([
            'source' => ['url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
            'prune' => true,
            'prune_preview' => true,
        ]);

        $this->assertSame('queued', $result['data']['status']);
    }

    public function testPruneRejectsInvalidConfirmationToken(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);
        $controller = new SyncController($db, 42, $store, $engine);

        $this->expectException(ValidationException::class);
        $controller->createJob([
            'source' => ['url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
            'prune' => true,
            'confirmation_token' => 'invalid-token',
        ]);
    }

    public function testAuditExportAndRedactionForSensitiveFields(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);
        $engine = new FakeSyncEngine($store);
        $engine->executeResponse = [
            'source' => 'prod',
            'target' => 'stage',
            'entity' => 'campaigns',
            'dry_run' => false,
            'force_update' => false,
            'prune' => false,
            'prune_preview' => false,
            'results' => [
                'campaigns' => [
                    'synced' => 1,
                    'skipped' => 0,
                    'failed' => 0,
                    'pruned' => 0,
                    'errors' => [],
                    'api_key' => 'internal-secret',
                    'auth_token' => 'internal-token',
                ],
            ],
            'mappings' => [],
            'delete_candidates' => [],
        ];
        $controller = new SyncController($db, 42, $store, $engine);

        $created = $controller->createJob([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
        ]);
        $jobId = (string)$created['data']['job_id'];

        $controller->runWorker(['limit' => 5]);

        $job = $controller->getJob($jobId);
        $this->assertArrayNotHasKey('api_key', $job['data']['request']['source']);
        $this->assertSame('***REDACTED***', $job['data']['results']['results']['campaigns']['api_key']);
        $this->assertSame('***REDACTED***', $job['data']['results']['results']['campaigns']['auth_token']);

        $auditList = $controller->auditList(['format' => 'csv']);
        $this->assertArrayHasKey('csv', $auditList);
        $this->assertStringContainsString('job_id', $auditList['csv']);

        $auditOne = $controller->auditGet($jobId, ['format' => 'csv']);
        $this->assertArrayHasKey('csv', $auditOne);
        $this->assertStringContainsString($jobId, $auditOne['csv']);
    }

    public function testAuditCapturesAllTerminalJobStatuses(): void
    {
        $db = $this->createMysqliMock();
        $store = new ServerStateStore($this->tmpDir);

        $successEngine = new FakeSyncEngine($store);
        $successController = new SyncController($db, 42, $store, $successEngine);
        $success = $successController->createJob([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
        ]);
        $successController->runWorker(['limit' => 5]);

        $partialEngine = new FakeSyncEngine($store);
        $partialEngine->executeResponse = [
            'source' => 'prod',
            'target' => 'stage',
            'entity' => 'campaigns',
            'dry_run' => false,
            'force_update' => false,
            'prune' => false,
            'prune_preview' => false,
            'results' => [
                'campaigns' => ['synced' => 1, 'skipped' => 0, 'failed' => 1, 'pruned' => 0, 'errors' => ['bad row']],
            ],
            'mappings' => [],
            'delete_candidates' => [],
        ];
        $partialController = new SyncController($db, 42, $store, $partialEngine);
        $partialController->createJob([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
        ]);
        $partialController->runWorker(['limit' => 5]);

        $failEngine = new FlakySyncEngine($store);
        $failEngine->failuresRemaining = 2;
        $failController = new SyncController($db, 42, $store, $failEngine);
        $failController->createJob([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
            'max_attempts' => 1,
        ]);
        $failController->runWorker(['limit' => 5]);

        $cancelController = new SyncController($db, 42, $store, new FakeSyncEngine($store));
        $cancel = $cancelController->createJob([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'prod-key'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key'],
            'entity' => 'campaigns',
        ]);
        $cancelController->cancelJob((string)$cancel['data']['job_id']);

        $history = $successController->history([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com'],
        ]);
        $statuses = array_map(static fn(array $row): string => (string)($row['status'] ?? ''), $history['data']);

        $this->assertContains('succeeded', $statuses);
        $this->assertContains('partial', $statuses);
        $this->assertContains('failed', $statuses);
        $this->assertContains('cancelled', $statuses);
        $this->assertNotEmpty((string)$success['data']['job_id']);
    }

    private function removeDir(string $path): void
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
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDir($full);
                continue;
            }
            @unlink($full);
        }
        @rmdir($path);
    }
}
