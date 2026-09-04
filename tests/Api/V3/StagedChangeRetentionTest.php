<?php

declare(strict_types=1);

namespace Tests\Api\V3;

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
