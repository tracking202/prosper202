<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\DataEngine\DirtyHours;
use Prosper202\DataEngine\DirtyHourState;
use Prosper202\DataEngine\QueueLock;

/**
 * The dirty hours (202_dirty_hours) through the cron job's pass,
 * DataEngine::processDirtyHours(), in a process of its own
 * (fixtures/rollup-runner.php), against writers that queue while it runs.
 *
 * @group integration
 */
final class DirtyHoursQueueIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990121;
    private const CLICK = 99012101;
    private const HOLD = 'p202_smde_hold_rollup';

    private static int $hour = 0;

    public static function setUpBeforeClass(): void
    {
        if (self::connectScratch()) {
            self::cleanUp();
        }
        self::$hour = (intdiv(time(), 3600) - 130) * 3600;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        self::row('202_clicks', [
            'click_id' => self::CLICK, 'user_id' => self::USER, 'aff_campaign_id' => 0, 'ppc_account_id' => 0,
            'landing_page_id' => 0, 'click_cpc' => 0, 'click_payout' => 0, 'click_time' => self::$hour + 600,
        ]);
        self::row('202_clicks_advance', [
            'click_id' => self::CLICK, 'text_ad_id' => 0, 'ip_id' => 0, 'country_id' => 0, 'region_id' => 0,
            'city_id' => 0, 'platform_id' => 0, 'browser_id' => 0, 'device_id' => 0,
        ]);
        self::row('202_clicks_record', ['click_id' => self::CLICK, 'click_id_public' => self::CLICK]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    private static function cleanUp(): void
    {
        self::q('DROP TRIGGER IF EXISTS ' . self::HOLD);
        self::q('DELETE FROM 202_dirty_hours WHERE user_id = ' . self::USER);
        foreach (['202_clicks', '202_clicks_advance', '202_clicks_record', '202_dataengine'] as $table) {
            self::q("DELETE FROM $table WHERE click_id = " . self::CLICK);
        }
    }

    /** @return list<string> the account's queue rows as "from|processed|deleted" */
    private static function queued(): array
    {
        $result = self::$db->query('SELECT CONCAT(click_time_from, "|", processed + 0, "|", deleted + 0) AS s'
            . ' FROM 202_dirty_hours WHERE user_id = ' . self::USER . ' ORDER BY click_time_from, click_time_to');
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 's');
    }

    private static function rolledUp(): bool
    {
        $result = self::$db->query('SELECT COUNT(*) FROM 202_dataengine WHERE click_id = ' . self::CLICK);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return (int) $result->fetch_row()[0] === 1;
    }

    private static function other(): \mysqli
    {
        $db = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) getenv('P202_TEST_DB_USER'),
            (string) getenv('P202_TEST_DB_PASS'),
            (string) getenv('P202_TEST_DB_NAME'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        $db->query("SET SESSION sql_mode = ''");

        return $db;
    }

    /** @return array{resource, array<int, resource>} the pass, started and not waited for */
    private static function startPass(): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=',
                __DIR__ . '/fixtures/rollup-runner.php', 'full', 'dirty-hours'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        self::assertIsResource($proc);

        return [$proc, $pipes];
    }

    /**
     * @param array{resource, array<int, resource>} $pass
     * @return array{string, string} what it printed and what it logged
     */
    private static function finish(array $pass): array
    {
        [$proc, $pipes] = $pass;
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        return [$out, $err];
    }

    private static function pass(): string
    {
        [$out, $err] = self::finish(self::startPass());
        self::assertSame('RETURNED true', $out, $err);

        return $err;
    }

    /**
     * A roll-up of the account's hour waits, on its first row, for a lock
     * the caller holds: the window between a pass reading an hour's clicks
     * and marking the hour, held open.
     */
    private static function holdRollups(\mysqli $holder): void
    {
        $lock = self::HOLD . '_' . getmypid();
        self::q('CREATE TRIGGER ' . self::HOLD . ' BEFORE INSERT ON 202_dataengine FOR EACH ROW'
            . " BEGIN DO GET_LOCK('$lock', 30); DO RELEASE_LOCK('$lock'); END");
        self::assertSame('1', (string) $holder->query("SELECT GET_LOCK('$lock', 0)")->fetch_row()[0]);
    }

    private static function awaitHeldRollup(): void
    {
        $deadline = microtime(true) + 20;
        do {
            $found = self::$db->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = DATABASE()"
                . " AND STATE = 'User lock' AND INFO LIKE '%" . self::HOLD . "%'");
            if ($found instanceof \mysqli_result && (int) $found->fetch_row()[0] === 1) {
                return;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        self::fail('the pass did not reach the roll-up the test holds');
    }

    private static function queue(): void
    {
        $line = ClickRollupSql::queueRetry(new Connection(self::requireScratch()), self::CLICK);
        self::assertSame('its latest visit\'s hour is queued for the cron job to roll up again', $line);
    }

    /**
     * An hour queued again while the pass was rolling it up was marked and
     * deleted with the rest: the change that queued it stayed out of the
     * reports until something else queued the hour.
     */
    public function testAnHourQueuedAgainWhileItIsRolledUpStaysQueued(): void
    {
        self::queue();
        self::assertSame([self::$hour . '|0|0'], self::queued());
        $holder = self::other();
        self::holdRollups($holder);

        $pass = self::startPass();
        try {
            self::awaitHeldRollup();
            self::queue();
        } finally {
            $holder->close();
        }
        [$out, $err] = self::finish($pass);

        self::assertSame('RETURNED true', $out, $err);
        self::assertTrue(self::rolledUp(), 'the pass rolled the hour up');
        self::assertSame([self::$hour . '|0|0'], self::queued(), 'and the hour is still queued for the next pass');

        self::q('DROP TRIGGER ' . self::HOLD);
        self::pass();
        self::assertSame([], self::queued(), 'which rolls it up and removes it');
    }

    private static function range(int $processed, int $deleted, int $from = 0): void
    {
        self::row('202_dirty_hours', [
            'ppc_account_id' => 0, 'aff_campaign_id' => 0, 'user_id' => self::USER,
            'click_time_from' => $from === 0 ? self::$hour : $from, 'click_time_to' => self::$hour + 3599,
            'landing_page_id' => 0, 'processed' => $processed, 'deleted' => $deleted,
        ]);
    }

    private static function waiting(): int
    {
        $result = self::$db->query(DirtyHours::waitingSql() . ' WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return (int) $result->fetch_assoc()['waiting'];
    }

    /**
     * A pass that was killed between taking a range and finishing it left
     * the range taken.
     *
     * @dataProvider takenFlags
     */
    public function testARangeLeftTakenByAPassThatEndedIsRolledUpByTheNext(int $processed, int $deleted): void
    {
        self::range($processed, $deleted);
        self::assertSame(1, self::waiting(), 'a taken range is not yet rolled up');

        $logged = self::pass();

        self::assertStringContainsString('1 taken by a pass that ended without finishing them; queued again.', $logged);
        self::assertTrue(self::rolledUp());
        self::assertSame([], self::queued());
    }

    /** @return iterable<string, array{int, int}> */
    public static function takenFlags(): iterable
    {
        yield 'as a pass writes it' => [1, 1];
        yield 'processed alone' => [1, 0];
    }

    public function testARollUpThatFailsLeavesItsRangeForTheNextPass(): void
    {
        self::range(0, 0);
        self::q('CREATE TRIGGER ' . self::HOLD . ' BEFORE INSERT ON 202_dataengine FOR EACH ROW'
            . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'");

        [$out] = self::finish(self::startPass());

        self::assertSame('THREW RuntimeException: dataengine query failed', $out);
        self::assertSame([self::$hour . '|0|0'], self::queued(), 'queued again by the pass that failed');
        self::assertSame(1, self::waiting());

        self::q('DROP TRIGGER ' . self::HOLD);
        self::pass();
        self::assertTrue(self::rolledUp());
        self::assertSame([], self::queued());
    }

    /** A second pass leaves the queue to the one running. */
    public function testOnePassRunsAtATime(): void
    {
        self::range(0, 0);
        $running = new Connection(self::other());
        self::assertTrue(QueueLock::tryTake($running, QueueLock::name($running, 'dirty hours pass')));

        self::pass();
        self::assertFalse(self::rolledUp());
        self::assertSame([self::$hour . '|0|0'], self::queued());
        $called = 0;
        $answer = (new DirtyHours(new Connection(self::requireScratch())))->pass(static function () use (&$called): void {
            $called++;
        });
        self::assertNull($answer);
        self::assertSame(0, $called);
    }

    public function testAPassAnswersWhatItDidWithEachRange(): void
    {
        self::range(0, 0);
        self::range(0, 0, self::$hour - 3600);
        self::range(1, 1, self::$hour - 7200);
        $queue = new DirtyHours(new Connection(self::requireScratch()));
        $seen = [];

        $answer = $queue->pass(static function (array $hour) use (&$seen, $queue): void {
            $seen[] = $hour;
            self::assertContains($hour['click_time_from'] . '|1|1', self::queued(), 'taken while it is rolled up');
            if ($hour['click_time_from'] === self::$hour) {
                $queue->queue(self::USER, self::$hour, self::$hour + 3599);
                self::assertNull($queue->pass(static fn () => self::fail('a pass inside a pass')));
            }
        });

        self::assertSame(['rolled_up' => 2, 'queued_again' => 1], $answer);
        self::assertSame(
            [self::$hour, self::$hour - 3600, self::$hour - 7200],
            array_column($seen, 'click_time_from'),
            'in the order queued, the one a pass had left taken included'
        );
        self::assertSame(
            ['id', 'user_id', 'ppc_account_id', 'aff_campaign_id', 'click_time_from', 'click_time_to'],
            array_keys($seen[0])
        );
        self::assertSame(self::USER, $seen[0]['user_id']);
        self::assertSame([self::$hour . '|0|0'], self::queued(), 'the ranges rolled up are removed, the one queued again kept');
    }

    /**
     * @dataProvider statesARangeIsQueuedFrom
     */
    public function testQueuingARangePutsItBackToQueuedFrom(int $processed, int $deleted): void
    {
        self::range($processed, $deleted);

        (new DirtyHours(new Connection(self::requireScratch())))->queue(self::USER, self::$hour, self::$hour + 3599);

        self::assertSame([self::$hour . '|0|0'], self::queued());
    }

    /** @return iterable<string, array{int, int}> */
    public static function statesARangeIsQueuedFrom(): iterable
    {
        yield 'queued' => [0, 0];
        yield 'taken' => [1, 1];
        yield 'processed alone' => [1, 0];
    }

    public function testAQueuedRangeKeepsTheIdsItsWriterGave(): void
    {
        $queue = new DirtyHours(new Connection(self::requireScratch()));
        $queue->queue(self::USER, 100, 200, 3, 4, 5, 6, 7, 8);
        $ranges = [];
        for ($i = 0; $i < 501; $i++) {
            $ranges[] = [9, 1000 + $i * 3600, 1000 + $i * 3600 + 3599];
        }
        $queue->queueRanges(self::USER, $ranges);
        $queue->queueRanges(self::USER, $ranges);
        $queue->queueRanges(self::USER, []);

        $row = self::$db->query('SELECT ppc_account_id, aff_campaign_id, click_time_from, click_time_to, landing_page_id,'
            . ' aff_network_id, text_ad_id, ppc_network_id FROM 202_dirty_hours WHERE click_time_from = 100 AND user_id = '
            . self::USER)->fetch_row();
        self::assertSame(['3', '4', '100', '200', '5', '6', '7', '8'], $row);
        $count = self::$db->query('SELECT COUNT(*), MIN(aff_campaign_id), MAX(landing_page_id) FROM 202_dirty_hours'
            . ' WHERE ppc_account_id = 9 AND user_id = ' . self::USER)->fetch_row();
        self::assertSame(['501', '0', '0'], $count, 'each range once, however often it is queued');
    }

    /** DirtyHourState::condition() and ofRow() are one reading, and assignment() writes it. */
    public function testEachStatesConditionSelectsTheRowsItsFlagsReadAs(): void
    {
        $from = 1;
        foreach ([0, 1] as $processed) {
            foreach ([0, 1] as $deleted) {
                self::range($processed, $deleted, $from++);
            }
        }
        $mine = ' AND user_id = ' . self::USER;
        $seen = 0;
        foreach (DirtyHourState::cases() as $state) {
            $result = self::$db->query('SELECT processed, deleted FROM 202_dirty_hours WHERE (' . $state->condition() . ')' . $mine);
            self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                self::assertSame($state, DirtyHourState::ofRow($row['processed']), json_encode($row));
                $seen++;
            }
        }
        self::assertSame(4, $seen, 'every row is in exactly one state');

        foreach (DirtyHourState::cases() as $state) {
            self::q('UPDATE 202_dirty_hours SET ' . $state->assignment() . ' WHERE user_id = ' . self::USER);
            $row = self::$db->query('SELECT DISTINCT processed, deleted FROM 202_dirty_hours WHERE user_id = ' . self::USER)
                ->fetch_all(MYSQLI_ASSOC);
            self::assertCount(1, $row);
            self::assertSame($state, DirtyHourState::ofRow($row[0]['processed']));
        }
    }
}
