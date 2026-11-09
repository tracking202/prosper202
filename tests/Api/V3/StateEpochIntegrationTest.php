<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\ServerStateStore;
use Api\V3\Support\StateEpoch;
use PHPUnit\Framework\TestCase;
use Tests\Report\ScratchReportDatabase;

/**
 * The database's API state epoch (StateEpoch) against a real
 * 202_deployment_secrets, and a restore told apart through it (P4QS): the
 * epoch row put back to the value a dump held, which is what restoring the
 * dump does to it.
 *
 * @group integration
 */
final class StateEpochIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::connectScratch();
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q("DELETE FROM 202_deployment_secrets WHERE secret_name = '" . StateEpoch::SECRET_NAME . "'");
        $this->dir = sys_get_temp_dir() . '/p202-epoch-it-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (self::$db !== null) {
            self::q("DELETE FROM 202_deployment_secrets WHERE secret_name = '" . StateEpoch::SECRET_NAME . "'");
        }
        if (is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testItCountsUpFromNoneAndStaysPutInsideATransaction(): void
    {
        $epoch = new StateEpoch(self::requireScratch());
        self::assertSame(0, $epoch->current(), 'no row: nothing written yet');
        self::assertSame(1, $epoch->advance());
        self::assertSame(2, $epoch->advance());
        self::assertSame(2, $epoch->current());

        self::assertTrue(self::requireScratch()->begin_transaction());
        try {
            self::assertNull($epoch->advance(), 'not moved inside a transaction a rollback could take back');
        } finally {
            self::requireScratch()->rollback();
        }
        self::assertSame(2, $epoch->current());

        (new \Prosper202\Database\Connection(self::requireScratch()))->transaction(static function () use ($epoch): void {
            self::assertNull($epoch->advance(), 'nor inside the served tree\'s own transaction()');
        });
        self::assertSame(2, $epoch->current());
    }

    public function testARetriedCreateIsNotReplayedAfterTheDatabaseIsRestored(): void
    {
        $db = self::requireScratch();
        $store = new ServerStateStore($this->dir, null, new StateEpoch($db));
        $store->putIdempotent('user:1', 'seed-key', ['status' => 201, 'body' => ['id' => 1]]);
        $dump = (new StateEpoch($db))->current(); // the backup is taken here

        $store->reserveIdempotent('user:1', 'create-after-dump');
        $store->putIdempotent('user:1', 'create-after-dump', ['status' => 201, 'body' => ['id' => 2]]);
        self::assertSame('replay', $store->lookupIdempotent('user:1', 'create-after-dump')['state'],
            'the positive control: before the restore, the retry replays');

        // The restore: the dump's epoch row comes back with it.
        self::q("UPDATE 202_deployment_secrets SET secret_value = '" . $dump . "' WHERE secret_name = '"
            . StateEpoch::SECRET_NAME . "'");

        $after = new ServerStateStore($this->dir, null, new StateEpoch($db));
        self::assertSame('miss', $after->lookupIdempotent('user:1', 'create-after-dump')['state'],
            'the create the restore took away is made again, not answered as done');
        self::assertSame('replay', $after->lookupIdempotent('user:1', 'seed-key')['state'],
            'a create from before the dump, which the restored database has, still replays');
        self::assertCount(1, glob($this->dir . '/restored-*') ?: [], 'what was written after the dump is set aside');
    }
}
