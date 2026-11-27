<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\QueueLock;
use Prosper202\DataEngine\RebuildWindows;
use Prosper202\DataEngine\RebuildWindowState;

/**
 * The rebuild windows (202_dataengine_job): RebuildWindows on a real
 * server, and through the cron job's DataEngine::processClickUpgrade()
 * without curl, and dej.php, each in a process that boots the app
 * (tests/Report/fixtures/account-scope-runner.php).
 *
 * Needs a 202-config.php and P202_TEST_DB_*. It empties 202_dataengine_job.
 *
 * @group integration
 */
final class RebuildWindowsIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990111;
    private const CLICKS = [99011101, 99011102];

    private static int $hour = 0;

    public static function setUpBeforeClass(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before the cron job ran.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once dirname(__DIR__, 2) . '/202-config/version.php';
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::$hour = (intdiv(time(), 3600) - 120) * 3600;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    private static function cleanUp(): void
    {
        self::q('DROP TRIGGER IF EXISTS p202_smde_refuse_rollup');
        self::q('DELETE FROM 202_dataengine_job');
        $clicks = implode(', ', self::CLICKS);
        foreach (['202_clicks', '202_clicks_advance', '202_clicks_record', '202_dataengine'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
    }

    private static function click(int $id, int $time): void
    {
        self::row('202_clicks', [
            'click_id' => $id, 'user_id' => self::USER, 'aff_campaign_id' => 0, 'ppc_account_id' => 0,
            'landing_page_id' => 0, 'click_cpc' => 0, 'click_payout' => 0, 'click_time' => $time,
        ]);
        self::row('202_clicks_advance', [
            'click_id' => $id, 'text_ad_id' => 0, 'ip_id' => 0, 'country_id' => 0, 'region_id' => 0,
            'city_id' => 0, 'platform_id' => 0, 'browser_id' => 0, 'device_id' => 0,
        ]);
        self::row('202_clicks_record', ['click_id' => $id, 'click_id_public' => $id]);
    }

    private static function window(int $from, int $to, int $processing = 0, int $processed = 0): void
    {
        self::row('202_dataengine_job', [
            'time_from' => $from, 'time_to' => $to, 'processing' => $processing, 'processed' => $processed,
        ]);
    }

    /** @return list<string> each window as "processing|processed" */
    private static function windows(): array
    {
        $result = self::$db->query(
            'SELECT CONCAT(processing, "|", processed) AS state FROM 202_dataengine_job ORDER BY time_from'
        );
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'state');
    }

    /** @return list<int> */
    private static function rolledUp(): array
    {
        $result = self::$db->query('SELECT click_id FROM 202_dataengine WHERE click_id IN ('
            . implode(', ', self::CLICKS) . ') ORDER BY click_id');
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'click_id'));
    }

    /** @return array<string, mixed> what the runner returned */
    private static function runApp(string $reader, bool $withCurl): array
    {
        $out = self::runAppRaw($reader, $withCurl);
        self::assertStringStartsWith('RETURNED ', $out, $out);

        return (array) json_decode(substr($out, 9), true, 16, JSON_THROW_ON_ERROR);
    }

    /** What the runner printed. */
    private static function runAppRaw(string $reader, bool $withCurl): string
    {
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_FROM' => (string) self::$hour,
            'P202_TEST_TO' => (string) (self::$hour + 3600),
        ] + getenv();
        $scan = null;
        if (!$withCurl) {
            $scan = sys_get_temp_dir() . '/p202-smde-nocurl-' . getmypid();
            if (!is_dir($scan)) {
                mkdir($scan);
            }
            foreach (array_filter(array_map('trim', explode(',', (string) php_ini_scanned_files()))) as $ini) {
                if (!str_contains(basename($ini), 'curl')) {
                    copy($ini, $scan . '/' . basename($ini));
                }
            }
            $env['PHP_INI_SCAN_DIR'] = $scan;
        }
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=',
                dirname(__DIR__) . '/Report/fixtures/account-scope-runner.php', $reader],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if ($scan !== null) {
            array_map('unlink', glob($scan . '/*') ?: []);
            rmdir($scan);
        }
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        return $out;
    }

    private static function other(): \mysqli
    {
        return new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) getenv('P202_TEST_DB_USER'),
            (string) getenv('P202_TEST_DB_PASS'),
            (string) getenv('P202_TEST_DB_NAME'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
    }

    private static function rebuildWithoutCurl(): void
    {
        self::assertSame(['curl' => false], self::runApp('click-upgrade', false));
    }

    /**
     * A run that was killed after its claim, or whose release failed, left
     * the window `processing`; nothing took it again, and without curl no
     * window after it either.
     */
    public function testAWindowLeftClaimedByARunThatEndedIsTakenAgain(): void
    {
        $day = self::$hour;
        self::click(self::CLICKS[0], $day + 600);
        self::click(self::CLICKS[1], $day + 86400 + 600);
        self::window($day, $day + 86399, 1, 0);
        self::window($day + 86400, $day + 2 * 86400 - 1);

        self::rebuildWithoutCurl();
        self::assertSame([self::CLICKS[0]], self::rolledUp(), 'the window nobody holds is rebuilt');
        self::assertSame(['0|1', '0|0'], self::windows());

        self::rebuildWithoutCurl();
        self::assertSame(self::CLICKS, self::rolledUp(), 'and the window after it on the next run');
        self::assertSame(['0|1', '0|1'], self::windows());
    }

    /**
     * dej.php rolls one hour up for whoever holds the window. It claimed,
     * marked and released any window whose bounds were that hour's: a
     * failed hour released a window another run held.
     *
     * @dataProvider hourOutcomes
     */
    public function testAnHoursRollupLeavesTheWindowToItsHolder(bool $fails): void
    {
        self::click(self::CLICKS[0], self::$hour + 600);
        self::window(self::$hour, self::$hour + 3599, 1, 0);
        if ($fails) {
            self::q("CREATE TRIGGER p202_smde_refuse_rollup BEFORE INSERT ON 202_dataengine FOR EACH ROW"
                . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'");
        }

        $printed = self::runApp('hour', true)['printed'];

        self::assertSame($fails ? 'Error: dataengine query failed' : '', $printed);
        self::assertSame($fails ? [] : [self::CLICKS[0]], self::rolledUp());
        self::assertSame(['1|0'], self::windows(), 'the window is still its holder\'s');
    }

    /** @return iterable<string, array{bool}> */
    public static function hourOutcomes(): iterable
    {
        yield 'the hour is rolled up' => [false];
        yield 'the roll-up fails' => [true];
    }

    /** A window a run is rebuilding now is left to it, and the next one taken. */
    public function testAWindowALiveRunHoldsIsLeftAndTheNextIsTaken(): void
    {
        $day = self::$hour;
        self::click(self::CLICKS[0], $day + 600);
        self::click(self::CLICKS[1], $day + 86400 + 600);
        self::window($day, $day + 86399);
        self::window($day + 86400, $day + 2 * 86400 - 1);
        $running = new RebuildWindows(new Connection(self::other()));
        self::assertSame(['from' => $day, 'to' => $day + 86399], $running->take());

        self::rebuildWithoutCurl();

        self::assertSame([self::CLICKS[1]], self::rolledUp(), 'the second window\'s click only');
        self::assertSame(['1|0', '0|1'], self::windows());
    }

    /** A rebuild that fails releases its window, and the next run rebuilds it. */
    public function testAFailedRebuildReleasesItsWindow(): void
    {
        self::click(self::CLICKS[0], self::$hour + 600);
        self::window(self::$hour, self::$hour + 86399);
        self::q("CREATE TRIGGER p202_smde_refuse_rollup BEFORE INSERT ON 202_dataengine FOR EACH ROW"
            . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'");

        self::assertSame('THREW RuntimeException: dataengine query failed', self::runAppRaw('click-upgrade', false));
        self::assertSame(['0|0'], self::windows());

        self::q('DROP TRIGGER p202_smde_refuse_rollup');
        self::rebuildWithoutCurl();
        self::assertSame([self::CLICKS[0]], self::rolledUp());
        self::assertSame(['0|1'], self::windows());
    }

    public function testEachMoveAnswersWhetherItMovedTheWindow(): void
    {
        $queue = new RebuildWindows(new Connection(self::requireScratch()));
        $elsewhere = new RebuildWindows(new Connection(self::other()));
        self::assertFalse($queue->anyUnfinished());
        self::assertNull($queue->take());
        $queue->queue(200, 299);
        $queue->queue(100, 199);
        // The table has no key: the same bounds twice are one window.
        $queue->queue(100, 199);
        self::assertTrue($queue->anyUnfinished());

        $first = $queue->take();
        self::assertSame(['from' => 100, 'to' => 199], $first, 'the earliest');
        self::assertSame(['1|0', '1|0', '0|0'], self::windows());
        $second = $elsewhere->take();
        self::assertSame(['from' => 200, 'to' => 299], $second, 'another run takes the next');
        self::assertNull($elsewhere->take(), 'and nothing a live run holds, itself included');

        self::assertFalse($queue->complete($second), 'a window another run holds is not this run\'s to mark');
        self::assertFalse($queue->release($second), 'nor to release');
        self::assertSame(['1|0', '1|0', '1|0'], self::windows());
        self::assertTrue($queue->complete($first));
        self::assertSame(['0|1', '0|1', '1|0'], self::windows());
        self::assertFalse($queue->complete($first), 'a window already marked is not marked again');
        self::assertFalse($queue->release($first), 'nor released');
        self::assertSame(['0|1', '0|1', '1|0'], self::windows());

        self::assertTrue($elsewhere->release($second));
        self::assertSame(['0|1', '0|1', '0|0'], self::windows());
        self::assertFalse($elsewhere->complete($second), 'a released window is not this run\'s to mark');
        self::assertSame($second, $queue->take(), 'released, it is taken again');
        self::assertTrue($queue->complete($second));
        self::assertFalse($queue->anyUnfinished());
        self::assertNull($queue->take());
    }

    /** The lock is released with the window: the same connection's next take is not its own holder. */
    public function testAMarkedOrReleasedWindowsLockIsFree(): void
    {
        $conn = new Connection(self::requireScratch());
        $queue = new RebuildWindows($conn);
        $queue->queue(100, 199);
        $window = $queue->take();
        self::assertNotNull($window);
        $lock = QueueLock::name($conn, 'rebuild window 100-199');
        $free = static fn (): string => (string) self::$db->query("SELECT IS_FREE_LOCK('$lock')")->fetch_row()[0];
        self::assertSame('0', $free());
        self::assertTrue($queue->release($window));
        self::assertSame('1', $free());
    }

    /** RebuildWindowState::condition() and ofRow() are one reading, and assignment() writes it. */
    public function testEachStatesConditionSelectsTheRowsItsFlagsReadAs(): void
    {
        foreach ([0, 1, 2] as $processing) {
            foreach ([0, 1, 2] as $processed) {
                self::window($processing * 10 + $processed, 99, $processing, $processed);
            }
        }
        $seen = 0;
        foreach (RebuildWindowState::cases() as $state) {
            $result = self::$db->query('SELECT processing, processed FROM 202_dataengine_job WHERE ' . $state->condition());
            self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                self::assertSame($state, RebuildWindowState::ofRow($row['processing'], $row['processed']), json_encode($row));
                $seen++;
            }
        }
        self::assertSame(9, $seen, 'every row is in exactly one state');

        foreach (RebuildWindowState::cases() as $state) {
            self::q('UPDATE 202_dataengine_job SET ' . $state->assignment());
            $row = self::$db->query('SELECT DISTINCT processing, processed FROM 202_dataengine_job')->fetch_all(MYSQLI_ASSOC);
            self::assertCount(1, $row);
            self::assertSame($state, RebuildWindowState::ofRow($row[0]['processing'], $row[0]['processed']));
        }
    }

    public function testTheReadersStatementsCountAndListTheWindows(): void
    {
        self::window(100, 199, 0, 1);
        self::window(200, 299, 1, 0);
        self::window(300, 399);
        $progress = self::$db->query(RebuildWindows::progressSql())->fetch_assoc();
        self::assertSame(['total' => '3', 'done' => '1'], $progress);
        $recent = self::$db->query(RebuildWindows::recentSql(2))->fetch_all(MYSQLI_ASSOC);
        self::assertSame([
            ['time_from' => '300', 'time_to' => '399', 'processing' => '0', 'processed' => '0'],
            ['time_from' => '200', 'time_to' => '299', 'processing' => '1', 'processed' => '0'],
        ], $recent);
        self::q('DELETE FROM 202_dataengine_job');
        self::assertSame(['total' => '0', 'done' => '0'], self::$db->query(RebuildWindows::progressSql())->fetch_assoc());
    }
}
