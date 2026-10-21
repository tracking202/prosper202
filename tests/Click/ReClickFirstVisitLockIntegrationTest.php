<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\RotatorRoute;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Database\Connection;

/**
 * A re-click copies its click's first visit's cost, which a conversion
 * rewrites (a CPA tracker's cost, p202ApplyConversionClickSide()) under the
 * click's lock (MysqlConversionLedger::lockClick()): recordReClick() takes
 * the same lock first, so it copies the cost the conversion committed, not
 * the one it replaced; two re-clicks of one click queue on the lock rather
 * than deadlock; and the lock, the click's first row, does not hold back the
 * next click's rows.
 *
 * This connection holds the lock while each re-click runs in a child
 * process on its own (fixtures/re-click-runner.php), and lets go once the
 * server shows the child waiting.
 *
 * @group integration
 */
final class ReClickFirstVisitLockIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990304;
    private const FIRST_CLICK = 99030401;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 9);
        foreach (
            [
                '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_rotator',
                '202_clicks_record', '202_clicks_site', '202_attribution_rollup_dirty_clicks',
            ] as $table
        ) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
    }

    /** A redirector click from source 3 at 0.25, $ago seconds ago: its time. */
    private static function firstVisit(int $clickId, int $ago = 600): int
    {
        $time = time() - $ago;
        (new MysqlClickRepository(new Connection(self::requireScratch())))->recordClick(
            new ClickRecord(
                clickId: $clickId,
                userId: self::USER,
                clickTime: $time,
                ppcAccountId: 3,
                clickCpc: '0.25',
                keywordId: 5,
                ipId: 23,
                recordsCampaignRows: false,
                rotator: new RotatorRoute(7, 8, 9),
            ),
            static fn (): int => 51
        );

        return $time;
    }

    private static function connect(): \mysqli
    {
        return new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
    }

    /** A second connection holding the click's lock, as a conversion takes it. */
    private static function lockTheClick(int $clickId): \mysqli
    {
        $lock = self::connect();
        self::assertTrue($lock->begin_transaction());
        self::assertNotNull((new MysqlConversionLedger(new Connection($lock)))->lockClick($clickId, self::USER));

        return $lock;
    }

    /**
     * A child re-clicking the click at $at, once its connection is waiting
     * on a lock.
     *
     * @return array{resource, array<int, resource>}
     */
    private static function reClick(int $clickId, int $at): array
    {
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/re-click-runner.php',
                (string) $clickId, (string) self::USER, (string) $at,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $thread = trim((string) fgets($pipes[1]));
        self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $thread, 'the runner names its connection');
        $deadline = microtime(true) + 15;
        do {
            $waiting = self::$db->query('SELECT COUNT(*) FROM information_schema.INNODB_TRX'
                . " WHERE trx_mysql_thread_id = $thread AND trx_state = 'LOCK WAIT'");
            self::assertInstanceOf(\mysqli_result::class, $waiting, self::$db->error);
            if ((int) $waiting->fetch_row()[0] === 1) {
                return [$proc, $pipes];
            }
            // The server refreshes INNODB_TRX only once it has gone 0.1s unread.
            usleep(250000);
        } while (microtime(true) < $deadline);
        self::fail("The re-click on connection $thread never waited for a lock: "
            . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    }

    /** @param array{resource, array<int, resource>} $child */
    private static function outcome(array $child): string
    {
        [$proc, $pipes] = $child;
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(0, $exit, "exit $exit\n$out$err");

        return $out . $err;
    }

    /** @return list<array{string, string, string}> each row's time, source and cost, oldest first */
    private static function visits(int $clickId): array
    {
        $result = self::$db->query('SELECT click_time, ppc_account_id, click_cpc FROM 202_clicks'
            . " WHERE click_id = $clickId ORDER BY click_time, ppc_account_id");
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return $result->fetch_all(MYSQLI_NUM);
    }

    /**
     * The conversion has rewritten the cost on every row and not committed
     * when the re-click starts: the re-click waits, and copies 0.5. A
     * re-click that read the first visit without the lock read 0.25 before
     * the commit and wrote it after.
     */
    public function testAReClickCopiesTheCostAConversionCommittedWhileItWaited(): void
    {
        $clickId = self::FIRST_CLICK;
        $first = (string) self::firstVisit($clickId);
        $lock = self::lockTheClick($clickId);
        self::assertTrue($lock->query("UPDATE 202_clicks SET click_cpc = '0.50000' WHERE click_id = $clickId"));
        $at = time() + 60;

        $child = self::reClick($clickId, $at);
        self::assertTrue($lock->commit());
        $lock->close();

        self::assertSame('ok', self::outcome($child));
        self::assertSame(
            [[$first, '3', '0.50000'], [(string) $at, '3', '0.50000']],
            self::visits($clickId),
            'the re-click\'s row takes the first visit\'s source and the cost the conversion committed'
        );
    }

    /** Two re-clicks of one click, both waiting on the click's lock: each commits its row in turn. */
    public function testTwoReClicksOfOneClickQueueOnItsLock(): void
    {
        $clickId = self::FIRST_CLICK + 1;
        self::firstVisit($clickId);
        $lock = self::lockTheClick($clickId);
        $at = time() + 60;

        $children = [self::reClick($clickId, $at), self::reClick($clickId, $at + 1)];
        self::assertTrue($lock->commit());
        $lock->close();

        self::assertSame(['ok', 'ok'], array_map(self::outcome(...), $children), 'neither re-click deadlocked');
        $visits = self::visits($clickId);
        self::assertSame(
            [[(string) $at, '3', '0.25000'], [(string) ($at + 1), '3', '0.25000']],
            array_slice($visits, 1),
            'two rows more, each with the first visit\'s source and cost'
        );
        self::assertCount(3, $visits);
    }

    /**
     * The re-click holds its click's lock to its commit, here while it
     * resolves its redirect, and the next click's rows go in meanwhile: the
     * lock is the click's first row in the click_id index, not the gap after
     * its last, where a new click is inserted. Locking every row took that
     * gap too, and on the newest click held every new click's insert. The
     * click is a second old, in the partition the next click goes to: in an
     * earlier one, the lock's probe of the next click's partition, which
     * holds no click above it, takes that partition's last gap (measured on
     * a 202_clicks partitioned by week).
     */
    public function testANewClickIsNotHeldBackWhileTheNewestClickIsReClicked(): void
    {
        $clickId = self::FIRST_CLICK + 2;
        self::firstVisit($clickId, 1);
        $next = self::connect();
        self::assertTrue($next->query('SET SESSION innodb_lock_wait_timeout = 1'));
        $outcome = null;
        $resolve = static function () use ($next, $clickId, &$outcome): int {
            try {
                (new MysqlClickRepository(new Connection($next)))->recordClick(
                    new ClickRecord(
                        clickId: $clickId + 1,
                        userId: self::USER,
                        clickTime: time(),
                        ppcAccountId: 3,
                        clickCpc: '0.25',
                        recordsCampaignRows: false,
                        rotator: new RotatorRoute(7, 8, 9),
                    ),
                    static fn (): int => 51
                );
                $outcome = 'written';
            } catch (\Throwable $e) {
                $outcome = $e->getMessage();
            }

            return 52;
        };

        try {
            (new MysqlClickRepository(new Connection(self::requireScratch())))->recordReClick(
                new ClickRecord(
                    clickId: $clickId,
                    userId: self::USER,
                    clickTime: time() + 60,
                    ppcAccountId: 13,
                    clickCpc: '0.75',
                    recordsCampaignRows: false,
                    clickOut: 1,
                    rotator: new RotatorRoute(17, 18, 19),
                ),
                $resolve
            );
        } finally {
            $next->close();
        }

        self::assertSame('written', $outcome, 'the next click\'s rows went in while the re-click held its lock');
        self::assertSame([2, 1], array_map(
            static fn (int $id): int => count(self::visits($id)),
            [$clickId, $clickId + 1]
        ));
    }
}
