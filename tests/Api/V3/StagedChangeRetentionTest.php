<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Exception\ConflictException;
use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

/**
 * Retention pruning on the staged-change ledger. Pruning runs inside
 * stageWriteChange(), so an unrelated request staging a change is what
 * triggers it — which makes it a hazard for any record that is still being
 * worked on.
 */
final class StagedChangeRetentionTest extends TestCase
{
    private string $tmpDir;
    private ServerStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/p202-retention-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        $this->store = new ServerStateStore($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function change(string $id, string $status, int $epoch): array
    {
        return [
            'change_id' => $id,
            'status' => $status,
            'method' => 'POST',
            'path' => '/campaigns',
            'payload' => [],
            'created_at_epoch' => $epoch,
            'created_by' => 5,
            'expires_at_epoch' => time() + 86400,
        ];
    }

    /**
     * Seed the ledger past the retention cap in one write. Going through
     * stageWriteChange() 5000 times would be a file write per record.
     */
    private function seedOverCap(array $extra): void
    {
        $this->store->stageWriteChange(5, $this->change('chg_' . str_repeat('0', 24), 'staged', time()));
        $path = $this->tmpDir . '/staged_changes/user-5.json';
        $this->assertFileExists($path, 'expected the ledger file the store just wrote');

        $items = [];
        for ($i = 0; $i < 5200; $i++) {
            $id = sprintf('chg_%024x', $i + 1);
            $items[$id] = $this->change($id, 'applied', 1_000_000 + $i);
        }
        foreach ($extra as $id => $item) {
            $items[$id] = $item;
        }
        file_put_contents($path, json_encode(['items' => $items], JSON_THROW_ON_ERROR));
    }

    public function testAnInFlightApplyingChangeSurvivesPruning(): void
    {
        // Its handler is mid-dispatch and about to write the outcome back to
        // this record; pruning it loses the audit entry and leaves the apply
        // response falling back to the stale pre-apply change.
        $inFlight = 'chg_' . str_repeat('a', 24);
        $this->seedOverCap([$inFlight => [
            'change_id' => $inFlight,
            'status' => 'applying',
            'applying_since' => time(),
            'method' => 'POST',
            'path' => '/campaigns',
            'payload' => [],
            'created_at_epoch' => 1, // oldest, so it prunes first if eligible
            'created_by' => 5,
            'expires_at_epoch' => time() + 86400,
        ]]);

        // Any later staging triggers the prune.
        $this->store->stageWriteChange(5, $this->change('chg_' . str_repeat('b', 24), 'staged', time()));

        $this->assertNotNull(
            $this->store->getStagedChangeForUser(5, $inFlight),
            'a change still applying must not be pruned as if it were resolved'
        );
    }

    public function testExpiredProposalsArePrunedSoTheLedgerCannotGrowWithoutBound(): void
    {
        // An expired proposal keeps the status `staged` but can never be
        // applied. Counting only terminal records would let a propose-only
        // key grow this file forever, and every later stage and list has to
        // read and rewrite it.
        $expired = 'chg_' . str_repeat('d', 24);
        $this->seedOverCap([$expired => [
            'change_id' => $expired,
            'status' => 'staged',
            'method' => 'POST',
            'path' => '/campaigns',
            'payload' => [],
            'created_at_epoch' => 1, // oldest, so it prunes first
            'created_by' => 5,
            'expires_at_epoch' => time() - 60,
        ]]);

        $this->store->stageWriteChange(5, $this->change('chg_' . str_repeat('e', 24), 'staged', time()));

        $this->assertNull($this->store->getStagedChangeForUser(5, $expired));
    }

    public function testLiveProposalsAreNeverPruned(): void
    {
        // The other direction: a proposal still awaiting a decision must
        // survive the cap, however full the ledger is.
        $live = 'chg_' . str_repeat('f', 24);
        $this->seedOverCap([$live => [
            'change_id' => $live,
            'status' => 'staged',
            'method' => 'POST',
            'path' => '/campaigns',
            'payload' => [],
            'created_at_epoch' => 1,
            'created_by' => 5,
            'expires_at_epoch' => time() + 86400,
        ]]);

        $this->store->stageWriteChange(5, $this->change('chg_' . str_repeat('0', 23) . '1', 'staged', time()));

        $this->assertNotNull($this->store->getStagedChangeForUser(5, $live));
    }

    public function testThePendingQueueIsCappedSoAProposeOnlyKeyCannotGrowItForever(): void
    {
        // Pruning only reclaims resolved and expired records, so without a
        // bound on the live queue a stage-scoped key can grow this file for a
        // whole TTL -- and every later stage and list reads and rewrites it.
        putenv('P202_STAGED_CHANGE_MAX_PENDING=5');
        try {
            for ($i = 0; $i < 5; $i++) {
                $this->store->stageWriteChange(5, $this->change(sprintf('chg_%024x', $i + 1), 'staged', time()));
            }

            $this->expectException(ConflictException::class);
            $this->expectExceptionMessageMatches('/awaiting a decision/');
            $this->store->stageWriteChange(5, $this->change('chg_' . str_repeat('9', 24), 'staged', time()));
        } finally {
            putenv('P202_STAGED_CHANGE_MAX_PENDING');
        }
    }

    public function testResolvingAProposalFreesQueueSpace(): void
    {
        // The cap counts live proposals only: applying or discarding one must
        // let the next through, and so must letting one expire.
        putenv('P202_STAGED_CHANGE_MAX_PENDING=3');
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->store->stageWriteChange(5, $this->change(sprintf('chg_%024x', $i + 1), 'staged', time()));
            }
            $this->store->updateStagedChange(5, sprintf('chg_%024x', 1), function (array $c): array {
                $c['status'] = 'applied';
                return $c;
            });

            $this->store->stageWriteChange(5, $this->change('chg_' . str_repeat('8', 24), 'staged', time()));
            $this->assertNotNull($this->store->getStagedChangeForUser(5, 'chg_' . str_repeat('8', 24)));
        } finally {
            putenv('P202_STAGED_CHANGE_MAX_PENDING');
        }
    }

    public function testResolvedChangesAreStillPruned(): void
    {
        // The cap must still do its job: the oldest terminal records go.
        $this->seedOverCap([]);
        $this->store->stageWriteChange(5, $this->change('chg_' . str_repeat('c', 24), 'staged', time()));

        $this->assertNull(
            $this->store->getStagedChangeForUser(5, sprintf('chg_%024x', 1)),
            'the oldest applied record should have been pruned'
        );
        $this->assertNotNull(
            $this->store->getStagedChangeForUser(5, sprintf('chg_%024x', 5200)),
            'the newest applied record should have been kept'
        );
    }
}
