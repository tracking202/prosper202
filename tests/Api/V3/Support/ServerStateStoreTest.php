<?php

declare(strict_types=1);

namespace Tests\Api\V3\Support;

use Api\V3\Exception\DatabaseException;
use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

class ServerStateStoreTest extends TestCase
{
    private string $tempDir;
    private ServerStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/p202-test-' . uniqid();
        $this->store = new ServerStateStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->recursiveRmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function recursiveRmdir(string $dir): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->recursiveRmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function testIdempotentStoreAndRetrieve(): void
    {
        $response = ['status' => 200, 'body' => ['id' => 42]];
        $this->store->putIdempotent('scope-a', 'key-1', $response);

        $result = $this->store->getIdempotent('scope-a', 'key-1');
        $this->assertSame($response, $result);
    }

    public function testIdempotentReturnsNullForUnknownKey(): void
    {
        $result = $this->store->getIdempotent('scope-a', 'nonexistent');
        $this->assertNull($result);
    }

    public function testIdempotentScopesAreIsolated(): void
    {
        $responseA = ['scope' => 'A'];
        $responseB = ['scope' => 'B'];

        $this->store->putIdempotent('scope-a', 'key-1', $responseA);
        $this->store->putIdempotent('scope-b', 'key-1', $responseB);

        $this->assertSame($responseA, $this->store->getIdempotent('scope-a', 'key-1'));
        $this->assertSame($responseB, $this->store->getIdempotent('scope-b', 'key-1'));
    }

    public function testIdempotentSameKeySameScopeReturnsSameResponse(): void
    {
        $response = ['status' => 200];
        $this->store->putIdempotent('scope-a', 'key-1', $response);

        $first = $this->store->getIdempotent('scope-a', 'key-1');
        $second = $this->store->getIdempotent('scope-a', 'key-1');

        $this->assertSame($first, $second);
        $this->assertSame($response, $first);
    }

    // ---------------------------------------------------------------
    // Change Tracking
    // ---------------------------------------------------------------

    public function testRecordAndListChanges(): void
    {
        $this->store->recordChange('campaigns', 'create', ['id' => 1, 'name' => 'Camp1'], 10);

        $result = $this->store->listChanges('campaigns', null, 100, 3600);
        $this->assertCount(1, $result['data']);
        $this->assertSame('campaigns', $result['data'][0]['entity']);
        $this->assertSame('create', $result['data'][0]['operation']);
        $this->assertSame(10, $result['data'][0]['actor_user_id']);
    }

    public function testChangesHaveIncrementingSeqNumbers(): void
    {
        $this->store->recordChange('offers', 'create', ['id' => 1], 1);
        $this->store->recordChange('offers', 'update', ['id' => 1], 1);
        $this->store->recordChange('offers', 'delete', ['id' => 1], 1);

        $result = $this->store->listChanges('offers', null, 100, 3600);
        $this->assertCount(3, $result['data']);
        $this->assertSame(1, $result['data'][0]['seq']);
        $this->assertSame(2, $result['data'][1]['seq']);
        $this->assertSame(3, $result['data'][2]['seq']);
    }

    public function testChangesCursorPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->store->recordChange('items', 'create', ['id' => $i], 1);
        }

        $page1 = $this->store->listChanges('items', null, 2, 3600);
        $this->assertCount(2, $page1['data']);
        $this->assertNotNull($page1['cursor']);
        $this->assertNotNull($page1['cursor_expires_at']);

        $page2 = $this->store->listChanges('items', $page1['cursor'], 2, 3600);
        $this->assertCount(2, $page2['data']);
        $this->assertNotNull($page2['cursor']);

        $page3 = $this->store->listChanges('items', $page2['cursor'], 2, 3600);
        $this->assertCount(1, $page3['data']);
        $this->assertNull($page3['cursor']);
    }

    public function testChangesUpdatedSinceFilter(): void
    {
        $before = time();
        $this->store->recordChange('widgets', 'create', ['id' => 1], 1);
        $this->store->recordChange('widgets', 'update', ['id' => 2], 1);

        $result = $this->store->listChanges('widgets', null, 100, 3600, $before);
        $this->assertCount(2, $result['data']);

        $futureTime = time() + 1000;
        $result = $this->store->listChanges('widgets', null, 100, 3600, $futureTime);
        $this->assertCount(0, $result['data']);
    }

    public function testChangesDeletedSinceFilter(): void
    {
        $before = time();
        $this->store->recordChange('widgets', 'create', ['id' => 1], 1);
        $this->store->recordChange('widgets', 'delete', ['id' => 2], 1);

        $result = $this->store->listChanges('widgets', null, 100, 3600, null, $before);
        $this->assertCount(1, $result['data']);
        $this->assertSame('delete', $result['data'][0]['operation']);

        $futureTime = time() + 1000;
        $result = $this->store->listChanges('widgets', null, 100, 3600, null, $futureTime);
        $this->assertCount(0, $result['data']);
    }

    // ---------------------------------------------------------------
    // Jobs
    // ---------------------------------------------------------------

    public function testCreateAndRetrieveJob(): void
    {
        $job = $this->store->createJob(['action' => 'sync'], 5);

        $this->assertArrayHasKey('job_id', $job);
        $this->assertSame('queued', $job['status']);
        $this->assertSame(5, $job['actor_user_id']);

        $retrieved = $this->store->getJob($job['job_id']);
        $this->assertNotNull($retrieved);
        $this->assertSame($job['job_id'], $retrieved['job_id']);
        $this->assertSame('queued', $retrieved['status']);
    }

    public function testGetJobReturnsNullForNonexistent(): void
    {
        $result = $this->store->getJob('nonexistent-job-id');
        $this->assertNull($result);
    }

    public function testSaveJobUpdatesJob(): void
    {
        $job = $this->store->createJob(['action' => 'export'], 1);
        $job['status'] = 'running';
        $job['results'] = ['exported' => 42];
        $this->store->saveJob($job);

        $updated = $this->store->getJob($job['job_id']);
        $this->assertNotNull($updated);
        $this->assertSame('running', $updated['status']);
        $this->assertSame(['exported' => 42], $updated['results']);
    }

    public function testSaveJobWithoutJobIdThrowsDatabaseException(): void
    {
        $this->expectException(DatabaseException::class);
        $this->store->saveJob(['status' => 'running']);
    }

    // ---------------------------------------------------------------
    // Job Events
    // ---------------------------------------------------------------

    public function testAppendAndListJobEvents(): void
    {
        $job = $this->store->createJob(['action' => 'import'], 1);
        $jobId = $job['job_id'];

        $this->store->appendJobEvent($jobId, 'info', 'Started import', ['file' => 'data.csv']);
        $this->store->appendJobEvent($jobId, 'warning', 'Skipped row', ['row' => 3]);

        $events = $this->store->listJobEvents($jobId, 0, 100);
        $this->assertCount(2, $events['data']);
        $this->assertSame('info', $events['data'][0]['level']);
        $this->assertSame('Started import', $events['data'][0]['message']);
        $this->assertSame('warning', $events['data'][1]['level']);
    }

    public function testJobEventsPaginationOffsetLimit(): void
    {
        $job = $this->store->createJob(['action' => 'test'], 1);
        $jobId = $job['job_id'];

        for ($i = 0; $i < 5; $i++) {
            $this->store->appendJobEvent($jobId, 'info', "Event $i");
        }

        $page = $this->store->listJobEvents($jobId, 2, 2);
        $this->assertCount(2, $page['data']);
        $this->assertSame('Event 2', $page['data'][0]['message']);
        $this->assertSame('Event 3', $page['data'][1]['message']);
        $this->assertSame(5, $page['pagination']['total']);
        $this->assertSame(2, $page['pagination']['offset']);
        $this->assertSame(2, $page['pagination']['limit']);
    }

    // ---------------------------------------------------------------
    // Audit
    // ---------------------------------------------------------------

    public function testAppendAndListAuditEntries(): void
    {
        $this->store->appendAudit([
            'actor_user_id' => '1',
            'source' => 'system',
            'target' => 'campaigns',
            'status' => 'success',
            'created_at_epoch' => time(),
        ]);

        $entries = $this->store->listAudit([]);
        $this->assertCount(1, $entries);
        $this->assertSame('system', $entries[0]['source']);
    }

    public function testAuditFilterByActor(): void
    {
        $this->store->appendAudit([
            'actor_user_id' => '10',
            'source' => 'api',
            'created_at_epoch' => time(),
        ]);
        $this->store->appendAudit([
            'actor_user_id' => '20',
            'source' => 'api',
            'created_at_epoch' => time(),
        ]);

        $filtered = $this->store->listAudit(['actor' => '10']);
        $this->assertCount(1, $filtered);
        $this->assertSame('10', $filtered[0]['actor_user_id']);
    }

    public function testGetAuditByJobId(): void
    {
        $this->store->appendAudit([
            'job_id' => 'job-abc-123',
            'actor_user_id' => '1',
            'created_at_epoch' => time(),
        ]);
        $this->store->appendAudit([
            'job_id' => 'job-def-456',
            'actor_user_id' => '2',
            'created_at_epoch' => time(),
        ]);

        $entry = $this->store->getAudit('job-abc-123');
        $this->assertNotNull($entry);
        $this->assertSame('job-abc-123', $entry['job_id']);

        $missing = $this->store->getAudit('nonexistent');
        $this->assertNull($missing);
    }

    // ---------------------------------------------------------------
    // Metrics
    // ---------------------------------------------------------------

    public function testIncrementAndReadMetric(): void
    {
        $this->store->incrementMetric('api.requests');

        $metrics = $this->store->metrics();
        $this->assertArrayHasKey('counters', $metrics);
        $this->assertSame(1, $metrics['counters']['api.requests']);
    }

    public function testMultipleIncrementsAccumulate(): void
    {
        $this->store->incrementMetric('api.requests', 3);
        $this->store->incrementMetric('api.requests', 7);

        $metrics = $this->store->metrics();
        $this->assertSame(10, $metrics['counters']['api.requests']);
    }

    public function testDifferentMetricsAreIndependent(): void
    {
        $this->store->incrementMetric('requests', 5);
        $this->store->incrementMetric('errors', 2);

        $metrics = $this->store->metrics();
        $this->assertSame(5, $metrics['counters']['requests']);
        $this->assertSame(2, $metrics['counters']['errors']);
    }

    // ---------------------------------------------------------------
    // Tracing
    // ---------------------------------------------------------------

    public function testStartAndListSpan(): void
    {
        $spanId = $this->store->startSpan('db.query', ['sql' => 'SELECT 1']);

        $this->assertIsString($spanId);
        $this->assertNotEmpty($spanId);

        $spans = $this->store->listSpans();
        $this->assertCount(1, $spans);
        $this->assertSame($spanId, $spans[0]['span_id']);
        $this->assertSame('db.query', $spans[0]['name']);
        $this->assertSame('running', $spans[0]['status']);
    }

    public function testEndSpanUpdatesStatus(): void
    {
        $spanId = $this->store->startSpan('http.request');
        $this->store->endSpan($spanId, 'ok', ['status_code' => 200]);

        $spans = $this->store->listSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('ok', $spans[0]['status']);
        $this->assertNotNull($spans[0]['ended_at']);
        $this->assertNotNull($spans[0]['ended_at_epoch']);
    }

    public function testFilterSpansByName(): void
    {
        $this->store->startSpan('db.query');
        $this->store->startSpan('http.request');
        $this->store->startSpan('db.query');

        $dbSpans = $this->store->listSpans('db.query');
        $this->assertCount(2, $dbSpans);

        $httpSpans = $this->store->listSpans('http.request');
        $this->assertCount(1, $httpSpans);
    }

    // ---------------------------------------------------------------
    // Rate Limiting
    // ---------------------------------------------------------------

    public function testFirstRateLimitRequestIsAllowed(): void
    {
        $result = $this->store->consumeRateLimit('api-v3', 10, 60);

        $this->assertTrue($result['allowed']);
        $this->assertSame(9, $result['remaining']);
        $this->assertArrayHasKey('reset_at', $result);
    }

    public function testExceedingRateLimitReturnsNotAllowed(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->store->consumeRateLimit('api-v3', 3, 60);
        }

        $result = $this->store->consumeRateLimit('api-v3', 3, 60);
        $this->assertFalse($result['allowed']);
        $this->assertSame(0, $result['remaining']);
    }

    public function testRateLimitWindowResetRestartsCount(): void
    {
        // Exhaust the limit with a very short window
        for ($i = 0; $i < 5; $i++) {
            $this->store->consumeRateLimit('short-window', 5, 1);
        }

        $exhausted = $this->store->consumeRateLimit('short-window', 5, 1);
        $this->assertFalse($exhausted['allowed']);

        // Wait for window to expire
        sleep(2);

        $renewed = $this->store->consumeRateLimit('short-window', 5, 1);
        $this->assertTrue($renewed['allowed']);
        $this->assertSame(4, $renewed['remaining']);
    }

    // ---------------------------------------------------------------
    // Sync Manifests
    // ---------------------------------------------------------------

    public function testSaveAndLoadManifest(): void
    {
        $manifest = [
            'last_sync_epoch' => time(),
            'mappings' => ['a' => 'b'],
        ];
        $this->store->saveSyncManifest('pair-key-1', $manifest);

        $loaded = $this->store->loadSyncManifest('pair-key-1');
        $this->assertSame('pair-key-1', $loaded['pair_key']);
        $this->assertSame($manifest['last_sync_epoch'], $loaded['last_sync_epoch']);
        $this->assertSame(['a' => 'b'], $loaded['mappings']);
    }

    public function testDefaultManifestForNewPair(): void
    {
        $loaded = $this->store->loadSyncManifest('never-saved');
        $this->assertSame('never-saved', $loaded['pair_key']);
        $this->assertSame(0, $loaded['last_sync_epoch']);
        $this->assertSame([], $loaded['mappings']);
    }

    // ---------------------------------------------------------------
    // Locks
    // ---------------------------------------------------------------

    public function testAcquireAndReleaseLock(): void
    {
        $release = $this->store->acquirePairLock('source-a', 'target-b');
        $this->assertIsCallable($release);

        // Release so we can acquire again
        $release();

        // Should be able to acquire again after release
        $release2 = $this->store->acquirePairLock('source-a', 'target-b');
        $this->assertIsCallable($release2);
        $release2();
    }

    public function testDoubleAcquireThrows(): void
    {
        $release = $this->store->acquirePairLock('source-x', 'target-y');

        $this->expectException(DatabaseException::class);
        try {
            $this->store->acquirePairLock('source-x', 'target-y');
        } finally {
            $release();
        }
    }

    // ---------------------------------------------------------------
    // Prune Tokens
    // ---------------------------------------------------------------

    public function testIssueAndValidatePruneToken(): void
    {
        $token = $this->store->issuePruneToken('pair-1', 600);

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $valid = $this->store->validatePruneToken($token, 'pair-1');
        $this->assertTrue($valid);
    }

    public function testInvalidPruneTokenReturnsFalse(): void
    {
        $result = $this->store->validatePruneToken('bogus-token', 'pair-1');
        $this->assertFalse($result);
    }

    public function testPruneTokenWrongPairKeyReturnsFalse(): void
    {
        $token = $this->store->issuePruneToken('pair-1', 600);

        $result = $this->store->validatePruneToken($token, 'wrong-pair');
        $this->assertFalse($result);
    }

    // ---------------------------------------------------------------
    // Canonical Hash
    // ---------------------------------------------------------------

    public function testCanonicalHashSamePayloadGivesSameHash(): void
    {
        $payload = ['name' => 'test', 'value' => 42];

        $hash1 = ServerStateStore::canonicalHash($payload);
        $hash2 = ServerStateStore::canonicalHash($payload);

        $this->assertSame($hash1, $hash2);
    }

    public function testCanonicalHashKeyOrderDoesNotMatter(): void
    {
        $payload1 = ['b' => 2, 'a' => 1, 'c' => 3];
        $payload2 = ['a' => 1, 'c' => 3, 'b' => 2];

        $hash1 = ServerStateStore::canonicalHash($payload1);
        $hash2 = ServerStateStore::canonicalHash($payload2);

        $this->assertSame($hash1, $hash2);
    }

    // ---------------------------------------------------------------
    // Sanitize
    // ---------------------------------------------------------------

    public function testSanitizeRedactsApiKey(): void
    {
        $payload = ['api_key' => 'secret123', 'name' => 'visible'];
        $sanitized = $this->store->sanitize($payload);

        $this->assertSame('***REDACTED***', $sanitized['api_key']);
        $this->assertSame('visible', $sanitized['name']);
    }

    public function testSanitizeRedactsTokenValues(): void
    {
        $payload = ['access_token' => 'abc123', 'authorization' => 'Bearer xyz'];
        $sanitized = $this->store->sanitize($payload);

        $this->assertSame('***REDACTED***', $sanitized['access_token']);
        $this->assertSame('***REDACTED***', $sanitized['authorization']);
    }
}
