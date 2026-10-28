<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\SyncController;
use Api\V3\Exception\SyncException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\RemoteApiClient;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;
use Api\V3\Support\SyncProfileKeys;
use Tests\Support\KeyCapturingSyncEngine;
use Tests\Support\StoppingTargetSyncEngine;
use Tests\TestCase;

/**
 * A sync job and the two remotes it calls: a profile url this server will
 * not call is refused on its field before anything is queued; the keys are
 * sealed in the job file while it waits and gone once it ends (a job from
 * before reads as written and is sealed by its run); a refusal, a cap or a
 * key that does not open fails the job at once, with skip_errors too, and
 * the job says what the target had confirmed writing by then.
 */
final class SyncJobKeysAndStopsTest extends TestCase
{
    private const SOURCE_KEY = 'source-key-5f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const TARGET_KEY = 'target-key-0f1e2d3c4b5a69788796a5b4c3d2e1f9';

    private string $dir;
    private ServerStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/p202-sync-keys-' . bin2hex(random_bytes(4));
        $this->store = new ServerStateStore($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private static function keys(string $fill = 'k'): SyncProfileKeys
    {
        return SyncProfileKeys::withKey(str_repeat($fill, 32));
    }

    private function controller(SyncEngine $engine, ?SyncProfileKeys $keys = null): SyncController
    {
        return new SyncController($this->createMysqliMock(), 42, $this->store, $engine, $keys ?? self::keys());
    }

    /** @return array<string, mixed> */
    private static function job(array $more = []): array
    {
        return $more + [
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => self::SOURCE_KEY],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => self::TARGET_KEY],
            'entity' => 'campaigns',
        ];
    }

    /** Every byte the state store holds. */
    private function stored(): string
    {
        $all = '';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $all .= (string) file_get_contents((string) $file);
        }

        return $all;
    }

    private function assertNoKeyIsStored(string $why): void
    {
        $stored = $this->stored();
        self::assertStringNotContainsString(self::SOURCE_KEY, $stored, $why);
        self::assertStringNotContainsString(self::TARGET_KEY, $stored, $why);
    }

    /** @return array<string, mixed> */
    private function jobFile(string $id): array
    {
        return (array) json_decode((string) file_get_contents($this->dir . '/jobs/' . $id . '.json'), true);
    }

    private function queue(SyncController $controller, array $more = []): string
    {
        return (string) $controller->createJob(self::job($more))['data']['job_id'];
    }

    public function testAQueuedJobHoldsItsKeysSealedAndAFinishedOneHoldsNone(): void
    {
        $engine = new KeyCapturingSyncEngine($this->store);
        $controller = $this->controller($engine);
        $id = $this->queue($controller);

        $this->assertNoKeyIsStored('queued');
        $request = $this->jobFile($id)['request'];
        self::assertStringStartsWith('v1.', $request['source'][SyncProfileKeys::SEALED]);
        self::assertStringStartsWith('v1.', $request['target'][SyncProfileKeys::SEALED]);
        $answered = $controller->getJob($id)['data']['request']['target'];
        self::assertArrayNotHasKey(SyncProfileKeys::SEALED, $answered, 'never answered');

        $controller->runWorker(['limit' => 5]);
        self::assertSame([self::SOURCE_KEY, self::TARGET_KEY], $engine->keys, 'the run calls with the keys as sent');
        self::assertSame('succeeded', $this->jobFile($id)['status']);
        $this->assertNoKeyIsStored('finished');
        self::assertStringNotContainsString(SyncProfileKeys::SEALED, $this->stored(), 'a finished job keeps none');
    }

    public function testAJobToBeTriedAgainKeepsItsKeysSealed(): void
    {
        $engine = new KeyCapturingSyncEngine($this->store, new \RuntimeException('Transient'));
        $controller = $this->controller($engine);
        $id = $this->queue($controller);
        $controller->runWorker(['limit' => 5]);

        $job = $this->jobFile($id);
        self::assertSame('queued', $job['status']);
        self::assertArrayHasKey(SyncProfileKeys::SEALED, $job['request']['target']);
        $this->assertNoKeyIsStored('queued again');
    }

    public function testAJobQueuedBeforeKeysWereSealedRunsAndIsSealedByItsRun(): void
    {
        $old = $this->store->createJob(self::job(['options' => [], 'max_attempts' => 3]), 42);
        $old['next_run_at'] = 0;
        $this->store->saveJob($old);

        $engine = new KeyCapturingSyncEngine($this->store, new \RuntimeException('Transient'));
        $this->controller($engine)->runWorker(['limit' => 5]);
        self::assertSame([self::SOURCE_KEY, self::TARGET_KEY], $engine->keys, 'read as written');
        $job = $this->jobFile((string) $old['job_id']);
        self::assertSame('queued', $job['status']);
        self::assertArrayHasKey(SyncProfileKeys::SEALED, $job['request']['source']);
        $this->assertNoKeyIsStored('sealed by its run');
    }

    /** Sealed before anything else is tried: a run that cannot start (its pair is busy) still seals. */
    public function testAJobQueuedBeforeKeysWereSealedIsSealedEvenIfItsRunCannotStart(): void
    {
        $old = $this->store->createJob(self::job(['options' => [], 'max_attempts' => 3]), 42);
        $old['next_run_at'] = 0;
        $this->store->saveJob($old);
        $release = $this->store->acquirePairLock('https://prod.example.com', 'https://stage.example.com');
        try {
            $engine = new KeyCapturingSyncEngine($this->store);
            $this->controller($engine)->runWorker(['limit' => 5]);
        } finally {
            $release();
        }

        self::assertSame([], $engine->keys, 'the run did not start');
        self::assertSame('queued', $this->jobFile((string) $old['job_id'])['status']);
        $this->assertNoKeyIsStored('sealed all the same');
    }

    public function testAJobThatEndedBeforeKeysWereSealedKeepsThemNoLonger(): void
    {
        foreach (['succeeded', 'failed', 'cancelled', 'partial'] as $status) {
            $old = $this->store->createJob(self::job(), 42);
            $old['status'] = $status;
            $this->store->saveJob($old);
        }
        self::assertStringContainsString(self::TARGET_KEY, $this->stored(), 'planted');

        $this->controller(new KeyCapturingSyncEngine($this->store))->runWorker(['limit' => 5]);
        $this->assertNoKeyIsStored('the worker forgets them');
    }

    public function testAKeyThatDoesNotOpenFailsTheJobAtOnce(): void
    {
        $id = $this->queue($this->controller(new KeyCapturingSyncEngine($this->store)));
        $engine = new KeyCapturingSyncEngine($this->store);
        $this->controller($engine, self::keys('z'))->runWorker(['limit' => 5]);

        $job = $this->jobFile($id);
        self::assertSame([], $engine->keys, 'nothing was called');
        self::assertSame('failed', $job['status']);
        self::assertSame(1, $job['attempts'], 'not tried again: it would not open the next time either');
        self::assertSame(SyncException::KEY_UNREADABLE, $job['error_code']);
        self::assertStringContainsString('Queue the sync again', (string) $job['error']);
        self::assertStringNotContainsString(SyncProfileKeys::SEALED, $this->stored());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function refusedUrls(): iterable
    {
        $allowlist = 'add 127.0.0.1 (or its network) to P202_WEBHOOK_ALLOW_NETWORKS';
        yield 'metadata target' => ['target', 'http://169.254.169.254/latest/meta-data', 'link-local', 'metadata'];
        yield 'loopback source' => ['source', 'https://127.0.0.1:8443', 'loopback', $allowlist];
        yield 'localhost' => ['target', 'http://localhost:8001', 'names this server', 'P202_WEBHOOK_ALLOW_NETWORKS'];
        yield 'plain http' => ['target', 'http://stage.example.com', 'plain text', 'NETWORKS is not set'];
        yield 'user info' => ['source', 'https://admin:pw@prod.example.com', 'user name or password', 'api_key'];
    }

    /** @dataProvider refusedUrls */
    public function testAProfileUrlThisServerWillNotCallIsRefusedOnItsField(
        string $side,
        string $url,
        string $why,
        string $also
    ): void {
        $controller = $this->controller(new KeyCapturingSyncEngine($this->store));
        $payload = self::job();
        $payload[$side]['url'] = $url;
        $field = $side . '.url';
        foreach (['createJob', 'reSync', 'plan'] as $call) {
            $body = $call === 'plan' ? array_intersect_key($payload, ['source' => 1, 'target' => 1]) : $payload;
            try {
                $controller->$call($body);
                self::fail($call . ' accepted ' . $url);
            } catch (ValidationException $e) {
                $errors = $e->getFieldErrors();
                self::assertSame([$field], array_keys($errors), $call);
                self::assertStringContainsString($why, $errors[$field], $call);
                self::assertStringContainsString($also, $errors[$field], $call);
                self::assertStringNotContainsString(self::SOURCE_KEY, $e->getMessage() . implode(' ', $errors));
            }
        }
        self::assertSame([], $this->store->listJobs([], 0), 'nothing was queued');

        // A job's status and history are read by the label: no call is made.
        $read = ['source' => ['url' => $payload['source']['url']], 'target' => ['url' => $payload['target']['url']]];
        self::assertArrayHasKey('data', $controller->status($read));
        self::assertArrayHasKey('data', $controller->history($read));
    }

    /**
     * A cap reached on the second create stops the run with skip_errors,
     * fails the job without a retry, and says the first create landed.
     */
    public function testAStopFailsTheJobAtOnceAndSaysWhatTheTargetConfirmedWriting(): void
    {
        $stop = SyncException::limit('The sync ran out of time at POST campaigns from the sync target '
            . 'https://stage.example.com: a sync may take 900 seconds in all (RemoteApiClient::MAX_SECONDS).');
        $engine = new StoppingTargetSyncEngine($this->store, $stop);
        $controller = $this->controller($engine);
        $id = $this->queue($controller, ['skip_errors' => true]);
        $controller->runWorker(['limit' => 5]);

        $job = $this->jobFile($id);
        self::assertSame('failed', $job['status'], 'not partial: the sync stopped');
        self::assertNull($job['next_run_at']);
        self::assertSame(1, $job['attempts']);
        self::assertSame(SyncException::LIMIT, $job['error_code']);
        self::assertSame(['campaigns' => ['created' => 1]], $job['written']);
        self::assertStringContainsString('RemoteApiClient::MAX_SECONDS', (string) $job['error']);
        self::assertStringEndsWith(
            'Writes the target confirmed before it stopped: campaigns 1 created.',
            (string) $job['error']
        );
        self::assertSame(2, $engine->posts, 'nothing after the stop');
        $this->assertNoKeyIsStored('failed');
    }

    /**
     * A rotator's rules are read one request each; a cap met there is the
     * cap, not the generic "failed to fetch rotator" every other failure of
     * that read becomes.
     */
    public function testACapMetReadingARotatorsRulesIsStillTheCap(): void
    {
        $remote = new class ('https://prod.example.com', 'k') extends RemoteApiClient {
            protected function send(string $method, string $url, array $headers, ?string $body): array
            {
                if (str_contains($url, '/rotators/7')) {
                    throw SyncException::limit('GET rotators/7 from the sync source https://prod.example.com: '
                        . 'the answer is larger than 8388608 bytes.');
                }

                return [200, str_contains($url, '/rotators?') ? '{"data":[{"id":7,"public_id":"r7"}]}' : '{"data":[]}'];
            }
        };
        $engine = new class ($this->store, $remote) extends SyncEngine {
            public function __construct(ServerStateStore $store, private readonly RemoteApiClient $remote)
            {
                parent::__construct($store);
            }

            protected function buildClients(array $sourceProfile, array $targetProfile): array
            {
                return [$this->remote, $this->remote];
            }
        };

        try {
            $engine->execute(['url' => 'https://prod.example.com'], ['url' => 'https://stage.example.com']);
            self::fail('the sync went on');
        } catch (SyncException $e) {
            self::assertSame(SyncException::LIMIT, $e->errorCode);
            self::assertStringStartsWith('GET rotators/7 from the sync source', $e->getMessage());
            self::assertStringEndsWith('Nothing was written.', $e->getMessage());
        }
    }

    /** @return iterable<string, array{0: SyncException, 1: string, 2: string}> */
    public static function stopsBeforeAnyWrite(): iterable
    {
        $source = 'GET campaigns from the sync source https://prod.example.com';
        yield 'refused' => [
            SyncException::urlRefused('Not sent to the sync target http://10.0.0.5: private.'),
            'failed',
            SyncException::URL_REFUSED,
        ];
        yield 'a cap' => [SyncException::limit($source . ': more than 200 pages.'), 'failed', SyncException::LIMIT];
        yield 'unresolved' => [
            SyncException::urlUnresolved('Not sent to the sync target https://gone.example.com: does not resolve.'),
            'queued',
            SyncException::URL_UNRESOLVED,
        ];
        yield 'no answer' => [
            SyncException::remoteFailed($source . ': no answer (Connection refused).'),
            'queued',
            SyncException::REMOTE_FAILED,
        ];
    }

    /** @dataProvider stopsBeforeAnyWrite */
    public function testAStopWhileReadingSaysNothingWasWritten(SyncException $stop, string $status, string $code): void
    {
        $controller = $this->controller(new StoppingTargetSyncEngine($this->store, null, $stop));
        $id = $this->queue($controller);
        $controller->runWorker(['limit' => 5]);

        $job = $this->jobFile($id);
        self::assertSame($status, $job['status'], 'tried again only when another try could change it');
        self::assertSame($code, $job['error_code']);
        self::assertSame($stop->getMessage() . ' Nothing was written.', $job['error']);
        self::assertNull($job['written']);
    }
}
