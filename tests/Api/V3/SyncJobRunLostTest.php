<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\SyncController;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\SyncException;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncProfileKeys;
use Tests\Support\KeyCapturingSyncEngine;
use Tests\Support\ScriptedRemoteApiClient;
use Tests\Support\ScriptedTargetSyncEngine;
use Tests\TestCase;

/**
 * A sync job whose run dies, and the stops that are not a remote's answer.
 *
 * The worker picks up only queued jobs, so a run whose process died left its
 * job 'running' for ever, counted against its pair's queue limit. A process
 * that ends by a fatal error, a limit or exit() fails its job as it goes; one
 * that is killed leaves it to the next read, which fails it once the run's
 * time has passed and nothing holds its lock, with what the run logged
 * writing. The runs here are real child processes, killed or made to die.
 */
final class SyncJobRunLostTest extends TestCase
{
    private const SOURCE_KEY = 'source-key-5f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const TARGET_KEY = 'target-key-0f1e2d3c4b5a69788796a5b4c3d2e1f9';

    private string $dir;
    private ServerStateStore $store;
    private string $log;
    private string|false $previousLog = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/p202-sync-lost-' . bin2hex(random_bytes(4));
        $this->store = new ServerStateStore($this->dir);
        $this->log = sys_get_temp_dir() . '/p202-sync-lost-' . bin2hex(random_bytes(4)) . '.log';
        $this->previousLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousLog);
        @unlink($this->log);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function controller(?\Api\V3\Support\SyncEngine $engine = null): SyncController
    {
        return new SyncController(
            $this->createMysqliMock(),
            42,
            $this->store,
            $engine ?? new KeyCapturingSyncEngine($this->store),
            SyncProfileKeys::withKey(str_repeat('k', 32))
        );
    }

    private function queue(array $more = []): string
    {
        return (string) $this->controller()->createJob($more + [
            'source' => ['name' => 'prod', 'url' => 'https://prod.example.com', 'api_key' => self::SOURCE_KEY],
            'target' => ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => self::TARGET_KEY],
            'entity' => 'campaigns',
        ])['data']['job_id'];
    }

    /** @return array<string, mixed> */
    private function jobFile(string $id): array
    {
        return (array) json_decode((string) file_get_contents($this->dir . '/jobs/' . $id . '.json'), true);
    }

    /** @param array<string, mixed> $job */
    private function writeJobFile(array $job): void
    {
        file_put_contents($this->dir . '/jobs/' . $job['job_id'] . '.json', json_encode($job));
    }

    /** Run the job in a child process that dies the way $how says; its exit status. */
    private function runAndDie(string $id, string $how): int
    {
        $command = [
            PHP_BINARY,
            '-d', 'memory_limit=64M',
            dirname(__DIR__, 2) . '/fixtures/sync-job/run-and-die.php',
            $this->dir,
            $id,
            $how,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertStringNotContainsString('the run finished', $stderr, 'the child died in its run');

        return $status;
    }

    /** Move the run's start back past the time a run may take. */
    private function backdate(string $id): void
    {
        $job = $this->jobFile($id);
        $job['running_since'] = time() - SyncController::ABANDONED_AFTER - 1;
        $this->writeJobFile($job);
    }

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

    /** @param array<string, mixed> $job */
    private function assertLost(array $job, string $status = 'failed'): void
    {
        self::assertSame($status, $job['status']);
        self::assertSame(SyncException::WORKER_LOST, $job['error_code']);
        self::assertNull($job['next_run_at']);
        self::assertStringNotContainsString(self::SOURCE_KEY, $this->stored(), 'a finished job holds no key');
        self::assertStringNotContainsString(self::TARGET_KEY, $this->stored(), 'a finished job holds no key');
    }

    public function testARunKilledMidJobIsFailedOnceItsTimeHasPassedWithWhatItWrote(): void
    {
        $id = $this->queue();
        $this->runAndDie($id, 'kill');
        self::assertSame('running', $this->jobFile($id)['status'], 'nothing in the killed process ran after it');

        $controller = $this->controller();
        $controller->runWorker(['limit' => 5]);
        self::assertSame('running', $controller->getJob($id)['data']['status'], 'within the time a run may take');

        $this->backdate($id);
        $result = $controller->runWorker(['limit' => 5]);
        self::assertSame(0, $result['data']['processed'], 'a lost run is not run again');

        $job = $this->jobFile($id);
        $this->assertLost($job);
        self::assertSame(['campaigns' => ['created' => 2, 'updated' => 1]], $job['written']);
        self::assertStringContainsString('stopped without finishing', (string) $job['error']);
        self::assertStringContainsString(
            'Writes it logged before it stopped: campaigns 2 created, 1 updated.',
            (string) $job['error']
        );
        self::assertStringContainsString('not tried again by itself', (string) $job['error']);
        $events = array_column($this->store->listJobEvents($id, 0, 100)['data'], 'message');
        self::assertSame('Job run lost', end($events));
        $audit = $this->store->getAudit($id);
        self::assertIsArray($audit);
        self::assertSame('failed', $audit['status']);
    }

    public function testAKilledRunIsFoundByReadingTheJob(): void
    {
        $id = $this->queue();
        $this->runAndDie($id, 'kill');
        $this->backdate($id);

        $this->assertLost($this->controller()->getJob($id)['data']);
    }

    public function testALostRunFreesItsPlaceInThePairsQueue(): void
    {
        putenv('P202_MAX_QUEUED_PER_PAIR=1');
        try {
            $id = $this->queue();
            $this->runAndDie($id, 'kill');
            $this->backdate($id);
            $second = $this->queue();
            self::assertNotSame($id, $second, 'the lost run no longer counts against the limit');
            $this->assertLost($this->jobFile($id));
        } finally {
            putenv('P202_MAX_QUEUED_PER_PAIR');
        }
    }

    public function testARunWhoseLockIsHeldIsLeftRunningHoweverLongItHasRun(): void
    {
        $id = $this->queue();
        $job = $this->jobFile($id);
        $job['status'] = 'running';
        $job['running_since'] = time() - 10 * SyncController::ABANDONED_AFTER;
        $this->writeJobFile($job);

        $release = $this->store->acquireJobLock($id);
        try {
            $this->controller()->runWorker(['limit' => 5]);
            self::assertSame('running', $this->controller()->getJob($id)['data']['status']);
        } finally {
            $release();
        }

        $this->controller()->runWorker(['limit' => 5]);
        $this->assertLost($this->jobFile($id));
    }

    public function testAJobThatStartedBeforeRunStartsWereRecordedIsTimedByItsLastSave(): void
    {
        $id = $this->queue();
        $job = $this->jobFile($id);
        $job['status'] = 'running';
        unset($job['running_since']);
        $job['updated_at'] = gmdate('c', time() - 60);
        $this->writeJobFile($job);
        $this->controller()->runWorker(['limit' => 5]);
        self::assertSame('running', $this->jobFile($id)['status']);

        $job['updated_at'] = gmdate('c', time() - SyncController::ABANDONED_AFTER - 1);
        $this->writeJobFile($job);
        $this->controller()->runWorker(['limit' => 5]);
        $this->assertLost($this->jobFile($id));
        $error = (string) $this->jobFile($id)['error'];
        self::assertStringContainsString('It logged no write before it stopped.', $error);
    }

    public function testACancelAskedOfALostRunCancelsIt(): void
    {
        $id = $this->queue();
        $this->runAndDie($id, 'kill');
        $this->controller()->cancelJob($id);
        self::assertSame('running', $this->jobFile($id)['status'], 'a cancel waits on the run');

        $this->backdate($id);
        $job = $this->controller()->getJob($id)['data'];
        $this->assertLost($job, 'cancelled');
    }

    public function testALostDryRunWroteNothing(): void
    {
        $id = $this->queue(['dry_run' => true]);
        $this->runAndDie($id, 'kill');
        $this->backdate($id);
        $this->controller()->runWorker(['limit' => 5]);

        $job = $this->jobFile($id);
        $this->assertLost($job);
        self::assertSame([], $job['written']);
        self::assertStringContainsString('It was a dry run, so it wrote nothing.', (string) $job['error']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function deaths(): iterable
    {
        $fatal = 'PHP stopped the run with a fatal error: ';
        yield 'out of time' => ['time', $fatal . 'Maximum execution time of 1 second exceeded.'];
        yield 'out of memory' => ['memory', $fatal . 'Allowed memory size of 67108864 bytes exhausted'];
        yield 'exit' => ['exit', 'The process running it ended before the run finished.'];
    }

    /** @dataProvider deaths */
    public function testAProcessThatEndsInItsRunFailsTheJobAtOnceSayingWhy(string $how, string $why): void
    {
        $id = $this->queue();
        $this->runAndDie($id, $how);

        $job = $this->jobFile($id);
        $this->assertLost($job);
        self::assertStringStartsWith($why, (string) $job['error']);
        self::assertSame(['campaigns' => ['created' => 2, 'updated' => 1]], $job['written']);
    }

    public function testAnErrorInsideThisServerIsNamedAndLoggedNotInternalServerError(): void
    {
        $engine = new KeyCapturingSyncEngine($this->store, new DatabaseException('Failed to prepare: secret detail'));
        $id = $this->queue(['max_attempts' => 1]);
        $this->controller($engine)->runWorker(['limit' => 5]);

        $job = $this->jobFile($id);
        self::assertSame('failed', $job['status']);
        self::assertSame(SyncException::INTERNAL, $job['error_code']);
        self::assertSame(
            'The sync stopped on an error inside this server (DatabaseException), not an answer from either '
                . 'instance; the server\'s error log has it under "Prosper202 sync: job ' . $id . '".',
            $job['error']
        );
        self::assertStringNotContainsString('secret detail', $this->stored(), 'the detail is logged, not kept');
        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('Prosper202 sync: job ' . $id, $log);
        self::assertStringContainsString('secret detail', $log);
    }

    /** The worker's own process: its run of jobs, with the error page's helpers loaded or not. */
    private function runWorkerOnDatabaseError(bool $withErrorArgs): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/sync-job/run-worker-engine-fails.php', $this->dir];
        if ($withErrorArgs) {
            $command[] = 'with-error-args';
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** @return iterable<string, array{bool}> */
    public static function errorHelperLoads(): iterable
    {
        yield 'with the error page helpers' => [true];
        yield 'without the error page helpers' => [false];
    }

    /** @dataProvider errorHelperLoads */
    public function testAJobThatStopsOnADatabaseErrorIsFailedWithItsOwnCauseAndTheWorkerGoesOn(bool $withErrorArgs): void
    {
        $first = $this->queue(['max_attempts' => 1]);
        $second = $this->queue([
            'max_attempts' => 1,
            'target' => ['name' => 'other', 'url' => 'https://other.example.com', 'api_key' => self::TARGET_KEY],
        ]);

        [$status, $stdout, $stderr] = $this->runWorkerOnDatabaseError($withErrorArgs);

        self::assertSame(0, $status, 'the worker is alive: ' . $stderr);
        self::assertSame(2, json_decode($stdout, true)['data']['processed'], 'the rest of the batch ran');
        foreach ([$first, $second] as $id) {
            $job = $this->jobFile($id);
            self::assertSame('failed', $job['status']);
            self::assertSame(SyncException::INTERNAL, $job['error_code']);
            self::assertStringContainsString('an error inside this server (mysqli_sql_exception)', (string) $job['error']);
            self::assertStringNotContainsString('Duplicate entry', $this->stored(), 'a quoted value is not kept');
        }
    }

    public function testTheWorkerLoadsTheHelpersThatItsFailureLogLineNeeds(): void
    {
        $worker = (string) file_get_contents(dirname(__DIR__, 3) . '/202-cronjobs/sync-worker.php');
        $args = strpos($worker, "/202-config/mysql-error-args.php'");
        $shared = strpos($worker, "/202-config/functions-shared.php'");
        self::assertIsInt($args);
        self::assertIsInt($shared);
        self::assertLessThan($shared, $args, 'functions-shared.php expects its loader to have loaded this');
    }

    public function testABusyPairIsSaidAndTheJobWaitsItsTurn(): void
    {
        $id = $this->queue();
        $release = $this->store->acquirePairLock('https://prod.example.com', 'https://stage.example.com');
        try {
            $this->controller()->runWorker(['limit' => 5]);
        } finally {
            $release();
        }

        $job = $this->jobFile($id);
        self::assertSame('queued', $job['status']);
        self::assertSame(SyncException::PAIR_BUSY, $job['error_code']);
        self::assertStringStartsWith(
            'Another sync between the same source and target is running',
            (string) $job['error']
        );
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $source
     * @param array<string, array<int, array<string, mixed>>> $target
     * @return array<string, mixed> the job's file after one run
     */
    private function syncOnce(array $source, array $target, bool $skipErrors, bool $forceUpdate = false): array
    {
        $engine = new ScriptedTargetSyncEngine($this->store);
        $engine->sourceData = $source;
        $engine->targetData = $target;
        $engine->target = new ScriptedRemoteApiClient([]);
        $id = $this->queue(['skip_errors' => $skipErrors, 'force_update' => $forceUpdate]);
        $this->controller($engine)->runWorker(['limit' => 5]);

        return $this->jobFile($id);
    }

    public function testARecordWithNothingToMatchItByNamesTheFieldAndIsNotTriedAgain(): void
    {
        $source = ['campaigns' => [['aff_campaign_id' => 10, 'aff_campaign_name' => '']]];
        $stopped = $this->syncOnce($source, [], false);
        self::assertSame('failed', $stopped['status'], 'the next run reads the same record');
        self::assertSame(SyncException::RECORD_UNUSABLE, $stopped['error_code']);
        self::assertSame(
            'campaigns[source id 10] match: its aff_campaign_name is empty on the source, and that is what the '
                . 'sync matches campaigns by, so it cannot be matched with a record on the target; give it a '
                . 'aff_campaign_name on the source. The target confirmed no write before it stopped.',
            $stopped['error']
        );

        $skipped = $this->syncOnce($source, [], true);
        $errors = $skipped['results']['results']['campaigns']['errors'];
        self::assertStringStartsWith('campaigns[source id 10]: match: its aff_campaign_name is empty', $errors[0]);
    }

    public function testAReferenceTheTargetLacksIsNamedOnAnUpdate(): void
    {
        $campaign = static fn (int $id, int $network, string $payout): array => [
            'aff_campaign_id' => $id,
            'aff_campaign_name' => 'A',
            'aff_network_id' => $network,
            'aff_campaign_payout' => $payout,
        ];
        $job = $this->syncOnce(
            [
                'aff-networks' => [['aff_network_id' => 1, 'aff_network_name' => 'Net']],
                'campaigns' => [$campaign(10, 1, '9.00')],
            ],
            ['campaigns' => [$campaign(20, 7, '1.00')]],
            false,
            true
        );

        self::assertSame('failed', $job['status']);
        self::assertSame(SyncException::RECORD_UNUSABLE, $job['error_code']);
        self::assertStringStartsWith(
            'campaigns[A] update: its aff_network_id names the aff-networks record Net, which the target does not have',
            (string) $job['error']
        );
        self::assertStringNotContainsString('Internal server error', (string) $job['error']);
    }

    public function testATargetRecordListedWithoutAnIdIsNamed(): void
    {
        $job = $this->syncOnce(
            ['campaigns' => [['aff_campaign_id' => 10, 'aff_campaign_name' => 'A', 'aff_campaign_payout' => '9.00']]],
            ['campaigns' => [['aff_campaign_name' => 'A', 'aff_campaign_payout' => '1.00']]],
            false,
            true
        );

        self::assertSame(SyncException::RECORD_UNUSABLE, $job['error_code']);
        self::assertStringStartsWith(
            'campaigns[A] update: the target listed its matching record without an id (aff_campaign_id or id)',
            (string) $job['error']
        );
    }
}
