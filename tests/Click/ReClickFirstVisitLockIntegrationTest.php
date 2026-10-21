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
 * the one it replaced; two re-clicks of one click, or a re-click and an
 * exit (recordExit(), which takes the lock first too), queue on the lock
 * rather than deadlock; and neither the lock, the click's first row, nor a
 * re-click's rewrite of a visit in the same second holds back the next
 * click's rows.
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
                '202_click_latest_visits',
            ] as $table
        ) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        $user = self::USER;
        self::q("DELETE rr FROM 202_rotator_rules_redirects rr JOIN 202_rotator_rules ru ON ru.id = rr.rule_id"
            . " JOIN 202_rotators r ON r.id = ru.rotator_id WHERE r.user_id = $user");
        self::q("DELETE ru FROM 202_rotator_rules ru JOIN 202_rotators r ON r.id = ru.rotator_id"
            . " WHERE r.user_id = $user");
        self::q("DELETE FROM 202_rotators WHERE user_id = $user");
        self::q('DROP TRIGGER IF EXISTS p202_test_exit_waits');
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

    /**
     * A child sending the click on through the account's rotator, once its
     * connection is held by the trigger after writing the route.
     *
     * @param array{int, int, int} $route
     * @return array{resource, array<int, resource>}
     */
    private static function exitThrough(int $clickId, array $route): array
    {
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/exit-runner.php',
                (string) $clickId, (string) self::USER, ...array_map('strval', $route),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $thread = trim((string) fgets($pipes[1]));
        self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $thread, 'the runner names its connection');
        $deadline = microtime(true) + 15;
        do {
            $state = self::$db->query("SELECT STATE FROM information_schema.PROCESSLIST WHERE ID = $thread");
            self::assertInstanceOf(\mysqli_result::class, $state, self::$db->error);
            if (($state->fetch_row()[0] ?? null) === 'User lock') {
                return [$proc, $pipes];
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        self::fail("The exit on connection $thread never reached the trigger: "
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
     * An exit through a rotator and a re-click of one click at once queue on
     * the click's lock: the exit takes it before it writes the route, as the
     * re-click does. An exit that wrote the route first held it while the
     * re-click, holding the click's lock, waited for it, and then waited for
     * the re-click to update the click's rows: the server ended one of them
     * as a deadlock.
     *
     * The exit is held once it has written its route (a trigger waits on a
     * named lock this connection holds) until the re-click waits on a lock.
     */
    public function testAnExitAndAReClickOfOneClickQueueRatherThanDeadlock(): void
    {
        $clickId = self::FIRST_CLICK + 4;
        self::firstVisit($clickId);
        $user = self::USER;
        $rotator = self::insert("INSERT INTO 202_rotators SET public_id = 1, user_id = $user");
        $rule = self::insert("INSERT INTO 202_rotator_rules SET rotator_id = $rotator, status = 1");
        $redirect = self::insert("INSERT INTO 202_rotator_rules_redirects SET rule_id = $rule, name = 'r'");
        $lock = 'p202_test_exit_' . $clickId;
        self::q("CREATE TRIGGER p202_test_exit_waits AFTER UPDATE ON 202_clicks_rotator FOR EACH ROW BEGIN"
            . " IF @p202_test_exit_waits = 1 THEN DO GET_LOCK('$lock', 20); DO RELEASE_LOCK('$lock'); END IF; END");
        $holder = self::connect();
        try {
            self::assertSame('1', (string) $holder->query("SELECT GET_LOCK('$lock', 0)")->fetch_row()[0]);
            $exit = self::exitThrough($clickId, [$rotator, $rule, $redirect]);
            $at = time() + 60;
            $reClick = self::reClick($clickId, $at);
            $holder->query("SELECT RELEASE_LOCK('$lock')");

            self::assertSame(
                ['ok', 'ok'],
                [self::outcome($exit), self::outcome($reClick)],
                'neither was ended as a deadlock'
            );
        } finally {
            $holder->close();
            self::q('DROP TRIGGER IF EXISTS p202_test_exit_waits');
        }
        self::assertCount(2, self::visits($clickId));
        $route = self::$db->query(
            "SELECT rotator_id, rule_id, rule_redirect_id FROM 202_clicks_rotator WHERE click_id = $clickId"
        );
        self::assertInstanceOf(\mysqli_result::class, $route, self::$db->error);
        self::assertSame(
            [['17', '18', '19']],
            $route->fetch_all(MYSQLI_NUM),
            'the re-click, which committed last, holds the route'
        );
    }

    /**
     * A re-click in the second the click was first visited is that visit:
     * its row is rewritten, with the first visit's source and cost and the
     * re-click's campaign and route, and no second row is added (two rows in
     * one second would both be the latest). The rewrite holds back no new
     * click either: it stops at the rows it found, short of the gap after
     * the newest click's, where the next click's rows go.
     */
    public function testAReClickInTheSecondOfItsVisitRewritesThatVisitAndHoldsBackNoNewClick(): void
    {
        $clickId = self::FIRST_CLICK + 5;
        $first = self::firstVisit($clickId, 0);
        $next = self::connect();
        self::assertTrue($next->query('SET SESSION innodb_lock_wait_timeout = 1'));
        $outcome = null;
        $resolve = static function () use ($next, $clickId, &$outcome): int {
            try {
                (new MysqlClickRepository(new Connection($next)))->recordClick(
                    new ClickRecord(
                        clickId: $clickId + 1,
                        userId: self::USER,
                        clickTime: time() + 1,
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
                    clickTime: $first,
                    affCampaignId: 14,
                    ppcAccountId: 13,
                    clickCpc: '0.75',
                    clickPayout: '1.25',
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
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            $rows = self::$db->query("SELECT click_time, ppc_account_id, aff_campaign_id, ROUND(click_payout, 2)"
                . " FROM $table WHERE click_id = $clickId");
            self::assertInstanceOf(\mysqli_result::class, $rows, self::$db->error);
            self::assertSame(
                [[(string) $first, '3', '14', '1.25']],
                $rows->fetch_all(MYSQLI_NUM),
                "$table: one row, the first visit's source and the re-click's campaign and payout"
            );
        }
        $route = self::$db->query("SELECT rotator_id, rule_id FROM 202_clicks WHERE click_id = $clickId");
        self::assertSame([['17', '19']], $route->fetch_all(MYSQLI_NUM));
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
