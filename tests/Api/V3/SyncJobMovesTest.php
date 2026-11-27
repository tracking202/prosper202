<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\SyncController;
use Api\V3\Exception\ConflictException;
use Api\V3\RequestContext;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;
use Api\V3\Support\SyncProfileKeys;
use Prosper202\Lifecycle\UnknownState;
use Tests\Support\CallbackSyncEngine;
use Tests\Support\HookedStateStore;
use Tests\Support\KeyCapturingSyncEngine;
use Tests\TestCase;

/**
 * A sync job's moves when two requests meet on it: each reads the job as it
 * is stored and moves it only as SyncJobState allows, so neither writes the
 * copy it read earlier over what the other did.
 */
final class SyncJobMovesTest extends TestCase
{
    private string $dir;
    private HookedStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/p202-sync-moves-' . bin2hex(random_bytes(4));
        $this->store = new HookedStateStore($this->dir);
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function controller(?SyncEngine $engine = null, ?ServerStateStore $store = null): SyncController
    {
        $store ??= $this->store;

        return new SyncController(
            $this->createMysqliMock(),
            42,
            $store,
            $engine ?? new KeyCapturingSyncEngine($store),
            SyncProfileKeys::withKey(str_repeat('k', 32))
        );
    }

    /** @return array<string, mixed> */
    private static function request(): array
    {
        return [
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => 'source-key-0123456789'],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'target-key-0123456789'],
            'entity' => 'campaigns',
        ];
    }

    private function queue(): string
    {
        return (string) $this->controller()->createJob(self::request())['data']['job_id'];
    }

    /** @return array<string, mixed> */
    private function jobFile(string $id): array
    {
        return (array) json_decode((string) file_get_contents($this->dir . '/jobs/' . $id . '.json'), true);
    }

    /** @return list<array<string, mixed>> */
    private function auditOf(string $id): array
    {
        return array_values(array_filter(
            $this->store->listAudit([]),
            static fn (array $record): bool => ($record['job_id'] ?? '') === $id
        ));
    }

    private function counter(string $name): int
    {
        return (int) ($this->store->metrics()['counters'][$name] ?? 0);
    }

    /**
     * A cancel that read the job queued, and a whole run of it before the
     * cancel wrote.
     *
     * @return array<string, mixed>
     */
    private function cancelAroundAWholeRun(string $id): array
    {
        $this->store->afterJobRead = function () use ($id): void {
            $this->controller(null, new ServerStateStore($this->dir))->runJob($id);
        };

        return $this->controller()->cancelJob($id);
    }

    public function testACancelThatReadTheJobBeforeItsRunEndedLeavesTheRunsEnd(): void
    {
        $id = $this->queue();
        $answer = $this->cancelAroundAWholeRun($id);

        $job = $this->jobFile($id);
        self::assertSame('succeeded', $job['status'], 'the run ended it; the cancel came after');
        self::assertIsArray($job['results'], 'what the run did is kept');
        self::assertSame('succeeded', $answer['data']['status'], 'the cancel answers with the job as it ended');
        self::assertArrayNotHasKey(SyncProfileKeys::SEALED, $job['request']['source'], 'a finished job keeps no key');
    }

    public function testAJobThatAnEarlierReadWouldCancelIsCountedAndAuditedOnce(): void
    {
        $id = $this->queue();
        $this->cancelAroundAWholeRun($id);

        $audit = $this->auditOf($id);
        self::assertCount(1, $audit, 'one end, one audit record');
        self::assertSame('succeeded', $audit[0]['status']);
        self::assertSame(1, $this->counter('jobs_succeeded'));
        self::assertSame(0, $this->counter('jobs_cancelled'), 'it was not cancelled');
    }

    public function testACancelOfAQueuedJobEndsItAndTheWorkerLeavesItAlone(): void
    {
        $id = $this->queue();
        $answer = $this->controller()->cancelJob($id);
        self::assertSame('cancelled', $answer['data']['status']);

        $engine = new KeyCapturingSyncEngine($this->store);
        $this->controller($engine)->runWorker(['limit' => 5]);
        $again = $this->controller($engine)->runJob($id);
        self::assertSame([], $engine->keys, 'a cancelled job does not run');
        self::assertSame('cancelled', $again['data']['status']);
        self::assertCount(1, $this->auditOf($id));
        self::assertSame(1, $this->counter('jobs_cancelled'));
    }

    public function testAJobReadRunningIsNotStartedASecondTime(): void
    {
        $id = $this->queue();
        $job = $this->jobFile($id);
        $job['status'] = 'running';
        $job['attempts'] = 1;
        $job['running_since'] = time();
        file_put_contents($this->dir . '/jobs/' . $id . '.json', json_encode($job));

        // Nothing holds its lock here: a run on another host sharing the
        // state directory holds one this host's flock() may not see.
        $engine = new KeyCapturingSyncEngine($this->store);
        $answer = $this->controller($engine)->runJob($id);

        self::assertSame([], $engine->keys, 'a job that is running is not run again beside its run');
        self::assertSame('running', $answer['data']['status']);
        self::assertSame(1, $this->jobFile($id)['attempts']);
    }

    public function testAFailureAfterTheEndWasSavedLeavesTheEndAndIsNotARetry(): void
    {
        $id = $this->queue();
        $this->store->failNextAudit = new \RuntimeException('the audit file could not be written');
        $engine = new CallbackSyncEngine($this->store, static function (): void {
        });
        $this->controller($engine)->runJob($id);

        self::assertSame('succeeded', $this->jobFile($id)['status'], 'the sync ran to its end and that was saved');
        $this->controller($engine)->runWorker(['limit' => 5]);
        self::assertSame(1, $engine->runs, 'a sync that succeeded is not run again');
        self::assertSame(0, $this->counter('jobs_retried'));
    }

    public function testAJobEndedWhileItsRunWentOnKeepsThatEnd(): void
    {
        $id = $this->queue();
        // Another host took the run as lost: it cannot see this one's lock.
        $engine = new CallbackSyncEngine($this->store, function () use ($id): void {
            $job = $this->jobFile($id);
            self::assertSame('running', $job['status']);
            $job['status'] = 'failed';
            $job['error'] = 'taken as lost';
            file_put_contents($this->dir . '/jobs/' . $id . '.json', json_encode($job));
        });
        $answer = $this->controller($engine)->runJob($id);

        self::assertSame('failed', $answer['data']['status'], 'a job that ended does not end a second time');
        self::assertSame('taken as lost', $this->jobFile($id)['error']);
        self::assertSame([], $this->auditOf($id), 'the end that stands was audited by whoever made it');
        self::assertSame(0, $this->counter('jobs_succeeded'));
        $events = array_column($this->store->listJobEvents($id, 0, 100)['data'], 'message');
        self::assertContains('Job run finished after the job had ended', $events, 'what the run did is in its log');
    }

    public function testATryThatCouldNotStartLeavesARunAnotherHostStarted(): void
    {
        $id = $this->queue();
        // Another sync of the pair holds its lock, so this try stops before it starts.
        $pair = (new ServerStateStore($this->dir))->acquirePairLock('https://prod.example.com', 'https://stage.example.com');
        // After this try's second read (under its lock), a host whose lock
        // this one cannot see starts the job.
        $this->store->afterJobRead = function () use ($id): void {
            $this->store->afterJobRead = function () use ($id): void {
                $job = $this->jobFile($id);
                self::assertSame('queued', $job['status']);
                $job['status'] = 'running';
                $job['attempts'] = 1;
                $job['running_since'] = time();
                file_put_contents($this->dir . '/jobs/' . $id . '.json', json_encode($job));
            };
        };
        try {
            $answer = $this->controller()->runJob($id);
        } finally {
            $pair();
        }

        $job = $this->jobFile($id);
        self::assertSame('running', $job['status'], 'the run this try never started is not queued again under it');
        self::assertNull($job['error'], 'nor given this try\'s error');
        self::assertSame('running', $answer['data']['status']);
        self::assertSame(0, $this->counter('jobs_retried'));
    }

    public function testACancelAskedDuringTheRunEndsTheJobCancelledOnce(): void
    {
        $id = $this->queue();
        $engine = new CallbackSyncEngine($this->store, function () use ($id): void {
            $asked = $this->controller(null, new ServerStateStore($this->dir))->cancelJob($id);
            self::assertSame('running', $asked['data']['status'], 'the run is asked to stop; it ends the job');
            self::assertTrue($asked['data']['cancel_requested']);
        });
        $this->controller($engine)->runJob($id);

        $job = $this->jobFile($id);
        self::assertSame('cancelled', $job['status']);
        self::assertSame(1, $job['attempts'], 'the cancel did not write its earlier copy over the run\'s');
        self::assertCount(1, $this->auditOf($id));
        self::assertSame(1, $this->counter('jobs_cancelled'));
    }

    public function testARunThatFailsAfterACancelWasAskedEndsCancelledWithItsAuditRecord(): void
    {
        $id = $this->queue();
        $engine = new CallbackSyncEngine($this->store, function () use ($id): void {
            $this->controller(null, new ServerStateStore($this->dir))->cancelJob($id);
            throw new \RuntimeException('Transient');
        });
        $this->controller($engine)->runJob($id);

        self::assertSame('cancelled', $this->jobFile($id)['status'], 'not queued for another try');
        $audit = $this->auditOf($id);
        self::assertCount(1, $audit, 'every end has its audit record');
        self::assertSame('cancelled', $audit[0]['status']);
    }

    public function testAJobWhoseStoredStatusNoStateNamesIsLeftAloneByTheWorkerAndRefusedByACancel(): void
    {
        $id = $this->queue();
        $other = $this->queue();
        $job = $this->jobFile($id);
        $job['status'] = 'paused';
        file_put_contents($this->dir . '/jobs/' . $id . '.json', json_encode($job));

        $engine = new KeyCapturingSyncEngine($this->store);
        $worked = $this->controller($engine)->runWorker(['limit' => 5]);
        self::assertSame(1, $worked['data']['succeeded'], 'the other job runs');
        self::assertSame('succeeded', $this->jobFile($other)['status']);
        self::assertSame('paused', $this->jobFile($id)['status'], 'left as it is');

        try {
            $this->controller()->cancelJob($id);
            self::fail('a cancel of a job in no known state wrote it');
        } catch (UnknownState $e) {
            self::assertStringContainsString('"paused"', $e->getMessage());
            self::assertStringContainsString($id, $e->getMessage());
        }
        self::assertSame('paused', $this->jobFile($id)['status']);
    }

    public function testTwoRequestsUnderOneIdempotencyKeyQueueOneJob(): void
    {
        RequestContext::setHeaders(['Idempotency-Key' => 'sync-race-1']);
        $second = null;
        $this->store->beforeJobCreated = function () use (&$second): void {
            // The retry arrives while the first request is still queueing.
            try {
                $this->controller(null, new ServerStateStore($this->dir))->createJob(self::request());
            } catch (\Throwable $e) {
                $second = $e;
            }
        };
        $first = $this->controller()->createJob(self::request());

        self::assertCount(1, glob($this->dir . '/jobs/*.events.json') ?: [], 'one key, one job');
        self::assertInstanceOf(ConflictException::class, $second, 'the retry is told the first is still in flight');

        $replay = $this->controller()->createJob(self::request());
        self::assertSame($first['data']['job_id'], $replay['data']['job_id']);
        self::assertTrue($replay['idempotent_replay']);
    }
}
