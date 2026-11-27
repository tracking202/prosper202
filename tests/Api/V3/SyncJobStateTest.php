<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\SyncController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncJobState;
use Prosper202\Lifecycle\IllegalTransition;
use Prosper202\Lifecycle\UnknownState;
use Tests\Support\LifecycleContract;
use Tests\TestCase;

/**
 * The sync job lifecycle's table, and the store's changeJob(), the one place
 * a job's status changes: it reads the record under its lock, hands it to
 * the caller's decision and makes only a move the table holds.
 */
final class SyncJobStateTest extends TestCase
{
    private string $dir;
    private ServerStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/p202-sync-state-' . bin2hex(random_bytes(4));
        $this->store = new ServerStateStore($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function testTheTableIsTheWholeLifecycle(): void
    {
        self::assertSame([], LifecycleContract::violations(SyncJobState::class));
        self::assertSame([SyncJobState::Queued], SyncJobState::initial());
        $table = [];
        foreach (SyncJobState::cases() as $state) {
            $table[$state->value] = array_map(static fn (SyncJobState $to): string => $to->value, $state->next());
        }
        self::assertSame([
            'queued' => ['running', 'cancelled', 'failed'],
            'running' => ['succeeded', 'partial', 'failed', 'cancelled', 'queued'],
            'succeeded' => [],
            'partial' => [],
            'failed' => [],
            'cancelled' => [],
        ], $table);
        self::assertSame(
            ['succeeded', 'partial', 'failed', 'cancelled'],
            array_map(static fn (SyncJobState $state): string => $state->value, SyncJobState::finished())
        );
    }

    /** @return iterable<string, array{SyncJobState, SyncJobState}> */
    public static function refusedMoves(): iterable
    {
        // Named here, not asked of the enum: a state given a way out must fail this.
        foreach ([SyncJobState::Succeeded, SyncJobState::Partial, SyncJobState::Failed, SyncJobState::Cancelled] as $ended) {
            foreach (SyncJobState::cases() as $to) {
                if ($to !== $ended) {
                    yield $ended->value . ' to ' . $to->value => [$ended, $to];
                }
            }
        }
        // A job ends only by running, by a cancel or by a run that cannot start.
        yield 'queued to succeeded' => [SyncJobState::Queued, SyncJobState::Succeeded];
        yield 'queued to partial' => [SyncJobState::Queued, SyncJobState::Partial];
    }

    /** @dataProvider refusedMoves */
    public function testAMoveTheTableDoesNotHoldIsRefusedAndNothingIsWritten(SyncJobState $from, SyncJobState $to): void
    {
        $id = $this->jobIn($from);
        $before = file_get_contents($this->path($id));
        try {
            $this->store->changeJob($id, static fn (): array => ['status' => $to, 'error' => 'written']);
            self::fail($from->value . ' became ' . $to->value);
        } catch (IllegalTransition $e) {
            self::assertStringContainsString($from->value . ' may not become ' . $to->value, $e->getMessage());
        }
        self::assertSame($before, file_get_contents($this->path($id)));
    }

    public function testARunningJobIsNotStartedAgain(): void
    {
        self::assertFalse(SyncJobState::Running->canMoveTo(SyncJobState::Running));
    }

    public function testAChangeIsDecidedOnTheRecordAsStored(): void
    {
        $id = $this->jobIn(SyncJobState::Queued);
        $this->store->changeJob($id, static fn (): array => ['status' => SyncJobState::Running, 'attempts' => 1]);
        $this->store->changeJob($id, static fn (): array => ['cancel_requested' => true]);

        $seen = null;
        $result = $this->store->changeJob($id, static function (array $stored, SyncJobState $state) use (&$seen): array {
            $seen = [$state, $stored['attempts'], $stored['cancel_requested']];

            return ['status' => SyncJobState::Cancelled];
        });
        self::assertSame([SyncJobState::Running, 1, true], $seen);
        self::assertSame(SyncJobState::Running, $result['from']);
        self::assertTrue($result['changed']);
        self::assertSame('cancelled', $result['job']['status']);
        $stored = $this->read($id);
        self::assertSame('cancelled', $stored['status']);
        self::assertSame(1, $stored['attempts'], 'a field the change does not name is kept');
        self::assertTrue($stored['cancel_requested']);
        self::assertSame($result['job'], $stored);
    }

    public function testAChangeThatDecidesAgainstLeavesTheRecord(): void
    {
        $id = $this->jobIn(SyncJobState::Succeeded);
        $before = $this->read($id);
        $result = $this->store->changeJob($id, static fn (): ?array => null);
        self::assertFalse($result['changed']);
        self::assertSame(SyncJobState::Succeeded, $result['from']);
        self::assertSame($before, $result['job']);
        self::assertSame($before, $this->read($id));
    }

    public function testAStatusIsSetAsAStateNeverAsText(): void
    {
        $id = $this->jobIn(SyncJobState::Queued);
        try {
            $this->store->changeJob($id, static fn (): array => ['status' => 'running']);
            self::fail('a status given as text was written');
        } catch (\LogicException $e) {
            self::assertStringContainsString('SyncJobState', $e->getMessage());
        }
        self::assertSame('queued', $this->read($id)['status']);
    }

    public function testFieldsSetWithoutAStatusKeepTheState(): void
    {
        $id = $this->jobIn(SyncJobState::Failed);
        $result = $this->store->changeJob($id, static fn (): array => ['request' => ['entity' => 'campaigns']]);
        self::assertTrue($result['changed']);
        self::assertSame('failed', $this->read($id)['status'], 'a finished job may still drop its keys');
        self::assertSame(['entity' => 'campaigns'], $this->read($id)['request']);
    }

    public function testARecordInNoKnownStateIsRefusedNamingIt(): void
    {
        $id = $this->jobIn(SyncJobState::Queued);
        $job = $this->read($id);
        $job['status'] = 'paused';
        file_put_contents($this->path($id), json_encode($job));
        self::assertNull(SyncJobState::ofRecord($job));
        self::assertNull(SyncJobState::ofRecord(['status' => 3]));
        self::assertNull(SyncJobState::ofRecord([]));

        $this->expectException(UnknownState::class);
        $this->expectExceptionMessage('Sync job ' . $id . ' holds the state "paused"');
        $this->store->changeJob($id, static fn (): array => ['status' => SyncJobState::Cancelled]);
    }

    public function testThereIsNoChangingAJobThatIsNotThere(): void
    {
        $called = false;
        self::assertNull($this->store->changeJob('0123456789abcdef', static function () use (&$called): array {
            $called = true;

            return [];
        }));
        self::assertFalse($called);
        self::assertSame([], glob($this->dir . '/jobs/0123456789abcdef*') ?: [], 'and nothing is created for it');
    }

    public function testAJobIsCreatedQueuedAndNoOtherWay(): void
    {
        $job = $this->store->createJob(['entity' => 'campaigns'], 7, ['max_attempts' => 2]);
        self::assertSame('queued', $job['status']);
        self::assertSame(2, $this->read((string) $job['job_id'])['max_attempts']);

        $this->expectException(\LogicException::class);
        $this->store->createJob(['entity' => 'campaigns'], 7, ['status' => 'succeeded']);
    }

    public function testJobsAreListedByState(): void
    {
        $queued = $this->jobIn(SyncJobState::Queued);
        $failed = $this->jobIn(SyncJobState::Failed);
        self::assertSame([$queued], array_column($this->store->listJobs([SyncJobState::Queued], 0), 'job_id'));
        self::assertSame([$failed], array_column($this->store->listJobs([SyncJobState::Failed], 0), 'job_id'));
        self::assertCount(2, $this->store->listJobs([], 0));

        $this->expectException(\TypeError::class);
        $this->store->listJobs(['queued'], 0);
    }

    public function testTheAuditFilterTakesTheStatesAJobEndsInAndNoOther(): void
    {
        $controller = new SyncController($this->createMysqliMock(), 1, $this->store);
        foreach (SyncJobState::finished() as $state) {
            self::assertSame([], $controller->auditList(['status' => $state->value])['data']);
        }
        foreach (['queued', 'running', 'unknown'] as $never) {
            try {
                $controller->auditList(['status' => $never]);
                self::fail($never . ' is no state an audit record holds');
            } catch (ValidationException $e) {
                self::assertSame(['status' => 'Valid values: succeeded, partial, failed, cancelled'], $e->getFieldErrors());
            }
        }
    }

    public function testTheDocumentedStatusesAreTheLifecycles(): void
    {
        $root = dirname(__DIR__, 3);
        $all = array_map(static fn (SyncJobState $state): string => $state->value, SyncJobState::cases());
        $ends = array_map(static fn (SyncJobState $state): string => $state->value, SyncJobState::finished());

        $guide = (string) file_get_contents($root . '/documentation/api/16-sync.md');
        self::assertSame(1, preg_match('/^## Job Statuses\n(.*?)^#/ms', $guide, $section), 'the guide lists the statuses');
        preg_match_all('/^\| `([a-z_]+)` \|/m', $section[1], $rows);
        sort($all);
        $listed = $rows[1];
        sort($listed);
        self::assertSame($all, $listed, 'documentation/api/16-sync.md, Job Statuses');

        $spec = (string) file_get_contents($root . '/docs/openapi.yaml');
        self::assertSame(
            1,
            preg_match('/operationId: syncAuditList\n.*?- name: status\n.*?enum:\n((?:\s+- [a-z_]+\n)+)/s', $spec, $enum),
            'the audit list documents its status filter'
        );
        preg_match_all('/- ([a-z_]+)/', $enum[1], $values);
        self::assertSame($ends, $values[1], 'docs/openapi.yaml, GET /audit/sync-jobs status');
    }

    /** A job in $state, reached by the moves that lead there. */
    private function jobIn(SyncJobState $state): string
    {
        $id = (string) $this->store->createJob(['entity' => 'campaigns'], 7)['job_id'];
        if ($state === SyncJobState::Queued) {
            return $id;
        }
        $this->store->changeJob($id, static fn (): array => ['status' => SyncJobState::Running]);
        if ($state !== SyncJobState::Running) {
            $this->store->changeJob($id, static fn (): array => ['status' => $state]);
        }
        self::assertSame($state->value, $this->read($id)['status']);

        return $id;
    }

    private function path(string $id): string
    {
        return $this->dir . '/jobs/' . $id . '.json';
    }

    /** @return array<string, mixed> */
    private function read(string $id): array
    {
        return (array) json_decode((string) file_get_contents($this->path($id)), true);
    }
}
