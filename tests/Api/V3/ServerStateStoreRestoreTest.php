<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Exception\DatabaseException;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\StateEpoch;
use PHPUnit\Framework\TestCase;

/**
 * The epoch the database's 202_deployment_secrets holds, without a database:
 * $value is what a dump would carry, and setting it back is the restore.
 */
final class FakeStateEpoch extends StateEpoch
{
    public bool $inTransaction = false;
    public bool $unreadable = false;
    public $onCurrent = null;

    public function __construct(public int $value = 0)
    {
    }

    public function current(): int
    {
        if ($this->unreadable) {
            throw new \RuntimeException('the database is gone');
        }

        $value = $this->value;
        if ($this->onCurrent !== null) {
            ($this->onCurrent)();
        }

        return $value;
    }

    public function advance(): ?int
    {
        return $this->inTransaction ? null : ++$this->value;
    }
}

/**
 * A database restored from an older dump keeps its install id, and so the
 * API state directory, while the records written after the dump stayed in
 * it (P4QS): a create retried under its Idempotency-Key was answered as done
 * with nothing created, and a staged change proposed after the dump was
 * still listed to apply to the older data. The store counts its records
 * against the database's epoch and sets aside what the database is behind.
 */
final class ServerStateStoreRestoreTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/p202-state-restore-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    private function store(FakeStateEpoch $epoch): ServerStateStore
    {
        return new ServerStateStore($this->dir, null, $epoch);
    }

    /** Records of each kind a restore makes wrong, written through $store. */
    private static function writeEachKind(ServerStateStore $store): void
    {
        $store->reserveIdempotent('user:1', 'create-1');
        $store->putIdempotent('user:1', 'create-1', ['status' => 201, 'body' => ['id' => 9]]);
        $store->stageWriteChange(1, ['change_id' => 'chg-1', 'status' => 'pending', 'operation' => 'delete']);
        $store->recordChange('campaigns', 'create', ['id' => 9], 1);
        $store->createJob(['pair' => 'a-b'], 1);
    }

    public function testRecordsWrittenAfterTheDumpAreSetAsideWhenTheDatabaseGoesBack(): void
    {
        $epoch = new FakeStateEpoch(10);
        $dump = $epoch->value;
        self::writeEachKind($this->store($epoch));
        self::assertGreaterThan($dump, $epoch->value, 'each write moved the database\'s epoch on');

        $epoch->value = $dump; // the restore
        $after = $this->store($epoch);

        self::assertSame('miss', $after->lookupIdempotent('user:1', 'create-1')['state'],
            'the retried create is not answered from a record the restore made wrong');
        self::assertNull($after->getStagedChangeForUser(1, 'chg-1'), 'nor is the staged change listed to apply');
        self::assertSame([], $after->listJobs(), 'nor the sync job');
        self::assertSame([], $after->listChanges('campaigns', null, 10, 60)['data'], 'nor the change-feed entry');
        $aside = glob($this->dir . '/restored-*') ?: [];
        self::assertCount(1, $aside, 'they are set aside, not deleted');
        self::assertFileExists($aside[0] . '/staged_changes/' . basename(glob($aside[0] . '/staged_changes/*')[0] ?? 'x'));
    }

    public function testRecordsWrittenBeforeTheDumpStay(): void
    {
        $epoch = new FakeStateEpoch(10);
        $store = $this->store($epoch);
        $store->putIdempotent('user:1', 'before-dump', ['status' => 201, 'body' => ['id' => 1]]);
        $store->stageWriteChange(1, ['change_id' => 'chg-before', 'status' => 'staged']);
        $store->recordChange('campaigns', 'create', ['id' => 1], 1);
        $dump = $epoch->value;
        $store->putIdempotent('user:1', 'after-dump', ['status' => 201, 'body' => ['id' => 2]]);
        $store->stageWriteChange(1, ['change_id' => 'chg-after', 'status' => 'staged']);
        $store->updateStagedChange(1, 'chg-before', static fn (array $c): array => ['status' => 'applied'] + $c);

        $epoch->value = $dump;
        $after = $this->store($epoch);
        self::assertSame('replay', $after->lookupIdempotent('user:1', 'before-dump')['state'],
            'a create the restored database has is still answered as done: retried, it is not made twice');
        self::assertSame('miss', $after->lookupIdempotent('user:1', 'after-dump')['state']);
        self::assertNull($after->getStagedChangeForUser(1, 'chg-after'));
        self::assertNull($after->getStagedChangeForUser(1, 'chg-before'),
            'a change applied after the dump is set aside: what it applied the restore took away');
        self::assertSame([1], array_column($after->listChanges('campaigns', null, 10, 60)['data'], 'seq'),
            'the change-feed entry from before the dump stays');
    }

    public function testTheChangeFeedSequenceContinuesSoACursorOnlyMovesForward(): void
    {
        $epoch = new FakeStateEpoch(3);
        $store = $this->store($epoch);
        $store->recordChange('campaigns', 'create', ['id' => 1], 1);
        $store->recordChange('campaigns', 'create', ['id' => 2], 1);
        $epoch->value = 3;

        $after = $this->store($epoch);
        $after->recordChange('campaigns', 'update', ['id' => 1], 1);
        $items = $after->listChanges('campaigns', null, 10, 60)['data'];
        self::assertSame([3], array_column($items, 'seq'), 'the next entry after the restore is 3, not 1 again');
    }

    public function testConcurrentWriteDuringRestoreCheckIsNotSetAside(): void
    {
        $epoch = new FakeStateEpoch(10);
        $writer = $this->store($epoch);
        $writer->stageWriteChange(1, ['change_id' => 'before', 'status' => 'pending']);
        $reads = 0;
        $epoch->onCurrent = function () use (&$reads, $epoch, $writer): void {
            ++$reads;
            if ($reads === 2) {
                $epoch->onCurrent = null;
            }
            $writer->stageWriteChange(1, ['change_id' => 'concurrent-' . $reads, 'status' => 'pending']);
        };
        $checker = $this->store($epoch);
        self::assertNotNull($checker->getStagedChangeForUser(1, 'concurrent-1'));
        self::assertSame([], glob($this->dir . '/restored-*') ?: [], 'concurrent writes are not a restore');
    }

    public function testADatabaseAtOrAheadOfTheRecordsKeepsThem(): void
    {
        $epoch = new FakeStateEpoch(5);
        self::writeEachKind($this->store($epoch));

        $same = $this->store($epoch);
        self::assertSame('replay', $same->lookupIdempotent('user:1', 'create-1')['state'] ?? null,
            'the positive control: no restore, the record replays');
        self::assertNotNull($same->getStagedChangeForUser(1, 'chg-1'));

        $epoch->value += 100; // writes elsewhere moved it on further
        self::assertNotNull($this->store($epoch)->getStagedChangeForUser(1, 'chg-1'));
        self::assertSame([], glob($this->dir . '/restored-*') ?: []);
    }

    public function testInsideATransactionTheEpochStaysAndNothingReadsAsARestore(): void
    {
        $epoch = new FakeStateEpoch(7);
        $epoch->inTransaction = true;
        $this->store($epoch)->stageWriteChange(1, ['change_id' => 'chg-tx', 'status' => 'pending']);
        self::assertSame(7, $epoch->value, 'a rollback cannot take back what was never moved');
        self::assertNotNull($this->store($epoch)->getStagedChangeForUser(1, 'chg-tx'));
    }

    public function testAStoreThatCannotReadTheEpochRefuses(): void
    {
        $epoch = new FakeStateEpoch(1);
        $epoch->unreadable = true;
        $store = $this->store($epoch);
        $store->incrementMetric('requests');
        self::assertSame(1, $store->metrics()['counters']['requests'] ?? null, 'a metric never asks the epoch');
        $this->expectException(DatabaseException::class);
        $store->lookupIdempotent('user:1', 'any');
    }

    public function testAStoreGivenADirectoryWithoutAnEpochIsUnchanged(): void
    {
        $store = new ServerStateStore($this->dir);
        $store->stageWriteChange(1, ['change_id' => 'chg-plain', 'status' => 'pending']);
        self::assertFileDoesNotExist($this->dir . '/epoch.json');
        self::assertNotNull((new ServerStateStore($this->dir))->getStagedChangeForUser(1, 'chg-plain'));
    }

    public function testARestoreLeavesANoticeOfWhatWasSetAsideAndWhere(): void
    {
        $epoch = new FakeStateEpoch(10);
        self::writeEachKind($this->store($epoch));
        self::assertSame([], $this->store($epoch)->restoreNotices(), 'no restore, no notice');

        $epoch->value = 10; // the restore
        $notices = $this->store($epoch)->restoreNotices();

        self::assertCount(1, $notices, 'reading the notices is itself the check, so Settings says so first');
        $aside = glob($this->dir . '/restored-*') ?: [];
        self::assertSame($aside[0] ?? null, $notices[0]['dir']);
        self::assertSame(
            ['changes' => 1, 'idempotency' => 1, 'jobs' => 1, 'staged_changes' => 1],
            (static function (array $c): array {
                ksort($c);

                return $c;
            })($notices[0]['counts'])
        );
        self::assertEqualsWithDelta(time(), $notices[0]['at'], 5);
        self::assertSame($notices[0]['at'] + ServerStateStore::SET_ASIDE_KEPT_SECONDS, $notices[0]['kept_until']);
        self::assertCount(1, $this->store($epoch)->restoreNotices(), 'it stays until it is dismissed');

        $this->store($epoch)->dismissRestoreNotices();
        self::assertSame([], $this->store($epoch)->restoreNotices());
        self::assertDirectoryExists($aside[0], 'dismissing removes nothing');
    }

    public function testWhatWasSetAsideIsRemovedAfterThirtyDaysAndNotBefore(): void
    {
        $epoch = new FakeStateEpoch(10);
        self::writeEachKind($this->store($epoch));
        $epoch->value = 10;
        $store = $this->store($epoch);
        $notice = $store->restoreNotices()[0];

        $kept = ServerStateStore::SET_ASIDE_KEPT_SECONDS;
        self::assertSame(0, $store->pruneSetAside($kept, $notice['at'] + $kept - 1));
        self::assertDirectoryExists($notice['dir']);
        // Not this store's: another name, and a link under a name it would take.
        mkdir($this->dir . '/restored-by-hand');
        mkdir($this->dir . '/elsewhere');
        touch($this->dir . '/elsewhere/keep.json');
        symlink($this->dir . '/elsewhere', $this->dir . '/restored-20200101T000000Z-abcdef');

        self::assertSame(1, $store->pruneSetAside($kept, $notice['at'] + $kept));
        self::assertDirectoryDoesNotExist($notice['dir']);
        self::assertSame([], $store->restoreNotices(), 'a notice goes with its directory');
        self::assertDirectoryExists($this->dir . '/restored-by-hand');
        self::assertFileExists($this->dir . '/elsewhere/keep.json', 'a link is not followed');
        unlink($this->dir . '/restored-20200101T000000Z-abcdef');
    }

    public function testAnOldSetAsideDirectoryIsRemovedOnTheNextUseOfTheStore(): void
    {
        $epoch = new FakeStateEpoch(10);
        $this->store($epoch);
        $old = $this->dir . '/restored-' . gmdate('Ymd\THis\Z', time() - ServerStateStore::SET_ASIDE_KEPT_SECONDS - 60)
            . '-0a1b2c';
        mkdir($old . '/staged_changes', 0700, true);
        file_put_contents($old . '/staged_changes/1.json', '{"items":{}}');
        $recent = $this->dir . '/restored-' . gmdate('Ymd\THis\Z', time() - 3600) . '-0a1b2c';
        mkdir($recent);

        $this->store($epoch)->lookupIdempotent('user:1', 'any');

        self::assertDirectoryDoesNotExist($old);
        self::assertDirectoryExists($recent);
    }
}
