<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\SyncController;
use Api\V3\Exception\RemoteApiException;
use Api\V3\Support\ServerStateStore;
use Tests\Support\ScriptedRemoteApiClient;
use Tests\Support\ScriptedTargetSyncEngine;
use Tests\TestCase;

/**
 * A force-update whose target record changed after the sync read it is
 * answered 409 Version mismatch (the If-Match the sync sends is stale).
 * RemoteApiClient wrapped every remote error status in a DatabaseException,
 * whose message is "Internal server error", so the job recorded that
 * sentence for the record - and, without skip_errors, threw it to the job
 * runner, which queued the job to run again: a retry that re-reads the
 * target and force-writes over the change the 409 had protected.
 *
 * Now the conflict is a named outcome: the record, the target id, the
 * remote's reason, "nothing was written", and no automatic retry. The
 * remote is scripted at the HTTP exchange only (ScriptedRemoteApiClient),
 * so the status-to-exception mapping under test is the real client's.
 */
final class SyncConflictTest extends TestCase
{
    private const MISMATCH = '{"error":true,"message":"Version mismatch","status":409,'
        . '"details":{"expected_version":"read","current_version":"now"}}';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/p202-sync-conflict-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    /**
     * Two campaigns that differ from the target's, the first of which the
     * target answers with $status.
     *
     * @return array{SyncController, ServerStateStore, ScriptedRemoteApiClient}
     */
    private function sync(int $status, string $body): array
    {
        $store = new ServerStateStore($this->tmpDir);
        $engine = new ScriptedTargetSyncEngine($store);
        $campaign = static fn (int $id, string $name, int $network, string $payout): array => [
            'aff_campaign_id' => $id,
            'aff_campaign_name' => $name,
            'aff_network_id' => $network,
            'aff_campaign_payout' => $payout,
        ];
        $engine->sourceData = [
            'aff-networks' => [['aff_network_id' => 1, 'aff_network_name' => 'Net']],
            'campaigns' => [$campaign(10, 'A', 1, '9.00'), $campaign(11, 'B', 1, '9.00')],
        ];
        $engine->targetData = [
            'aff-networks' => [['aff_network_id' => 7, 'aff_network_name' => 'Net']],
            'campaigns' => [
                $campaign(20, 'A', 7, '1.00') + ['etag' => '"read"'],
                $campaign(21, 'B', 7, '1.00') + ['etag' => '"read"'],
            ],
        ];
        $engine->target = new ScriptedRemoteApiClient(['PUT campaigns/20' => [$status, $body]]);

        return [new SyncController($this->createMysqliMock(), 42, $store, $engine), $store, $engine->target];
    }

    /** @return array<string, mixed> the job as GET /sync/jobs/{id} answers it */
    private function runSync(SyncController $controller, bool $skipErrors): array
    {
        $created = $controller->createJob([
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'k'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'k'],
            'entity' => 'campaigns',
            'force_update' => true,
            'skip_errors' => $skipErrors,
        ]);

        return $controller->runJob((string) $created['data']['job_id'])['data'];
    }

    public function testWithSkipErrorsTheConflictIsANamedOutcomeAndTheRestIsSynced(): void
    {
        [$controller, $store] = $this->sync(409, self::MISMATCH);
        $job = $this->runSync($controller, true);

        $campaigns = $job['results']['results']['campaigns'];
        self::assertSame('partial', $job['status']);
        self::assertSame(1, $campaigns['updated'], 'the other record was written');
        self::assertSame(1, $campaigns['failed']);
        self::assertSame(1, $campaigns['conflicted']);
        self::assertSame(['campaigns[A]: update: 409 from PUT campaigns/20: Version mismatch'], $campaigns['errors']);
        $conflict = $campaigns['conflicts'][0];
        self::assertSame(
            [
                'entity' => 'campaigns',
                'key' => 'A',
                'operation' => 'update',
                'target_id' => '20',
                'status' => 409,
                'reason' => 'Version mismatch',
                'written' => false,
            ],
            array_diff_key($conflict, ['next_step' => true])
        );
        self::assertStringContainsString('not retried', $conflict['next_step']);
        self::assertSame(1, $store->metrics()['counters']['conflicts'] ?? null, 'the conflict metric counts it');
    }

    public function testWithoutSkipErrorsTheJobStopsNamingTheConflictAndIsNotRetried(): void
    {
        [$controller, , $target] = $this->sync(409, self::MISMATCH);
        $job = $this->runSync($controller, false);

        self::assertSame('failed', $job['status']);
        self::assertNull($job['next_run_at'], 'not queued to run again');
        self::assertSame('campaigns[A] update: 409 from PUT campaigns/20: Version mismatch', $job['error']);
        self::assertSame('20', $job['conflict']['target_id']);
        self::assertFalse($job['conflict']['written']);
        $puts = array_values(array_filter($target->requests, static fn (array $r): bool => $r[0] === 'PUT'));
        self::assertSame(
            [['PUT', 'http://target.example/api/v3/campaigns/20']],
            $puts,
            'one PUT, the refused one: nothing after it, and nothing again'
        );
    }

    public function testAnOutageIsNamedByItsStatusAndStillRetried(): void
    {
        [$controller] = $this->sync(503, '{"error":true,"message":"Service unavailable","status":503}');
        $job = $this->runSync($controller, false);

        self::assertSame('queued', $job['status'], 'a 5xx is worth another attempt');
        self::assertNotNull($job['next_run_at']);
        self::assertSame('campaigns[A] update: 503 from PUT campaigns/20: Service unavailable', $job['error']);
        self::assertNull($job['conflict']);
    }

    public function testTheClientKeepsTheRemoteStatusMessageAndFieldErrors(): void
    {
        $client = new ScriptedRemoteApiClient([
            'PUT campaigns/1' => [
                422,
                '{"error":true,"message":"Validation failed","status":422,'
                    . '"field_errors":{"aff_campaign_name":"is required"}}',
            ],
            'GET campaigns' => [502, '<html>Bad gateway</html>'],
        ]);
        try {
            $client->put('campaigns/1', ['x' => 1]);
            self::fail('a 422 was taken as success');
        } catch (RemoteApiException $e) {
            self::assertSame(422, $e->remoteStatus);
            self::assertSame(['aff_campaign_name' => 'is required'], $e->fieldErrors);
            $described = '422 from PUT campaigns/1: Validation failed (aff_campaign_name: is required)';
            self::assertSame($described, $e->describe());
            self::assertFalse($e->isConflict());
            self::assertSame('Internal server error', $e->getMessage(), 'as a response it is still the generic 500');
        }
        try {
            $client->get('campaigns');
            self::fail('a 502 was taken as success');
        } catch (RemoteApiException $e) {
            self::assertSame('502 from GET campaigns: Remote API error 502', $e->describe());
        }
    }
}
