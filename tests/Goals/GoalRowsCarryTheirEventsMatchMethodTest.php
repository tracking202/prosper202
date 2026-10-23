<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Goals\GoalEngineException;
use Prosper202\Goals\GoalEvent;

/**
 * A goal's conversion carries how the event that reached it found its
 * click (match_method), whenever the row is written: GoalEngine::ingest()
 * stores each new event with the method its request found the click by,
 * and a replay or a re-evaluation writes rows for events stored earlier,
 * by other requests, so the row takes the stored event's method, not the
 * method of the request that happens to write it.
 *
 * @group integration
 */
final class GoalRowsCarryTheirEventsMatchMethodTest extends TestCase
{
    use GoalDatabase;

    private const T = 1_650_000_000;

    /** @param list<GoalEvent> $events */
    private function ingestBy(int $clickId, array $events, MatchMethod $method): array
    {
        $this->clock += 10;
        $stamped = array_map(fn (GoalEvent $e): GoalEvent => new GoalEvent(
            $e->eventId, $e->name, $e->occurredAt, $this->clock, $e->properties, $e->revenue, $e->revenueTrusted, $e->transactionId
        ), $events);

        return $this->engine->ingest(1, $this->engine->clickSubject(1, $clickId), $stamped, $method);
    }

    /** @return array<string, string> event id => the stored event's method */
    private static function storedEvents(int $clickId): array
    {
        return array_column(self::$db->query("SELECT event_id, match_method FROM 202_goal_events WHERE subject_id = $clickId ORDER BY event_id")
            ->fetch_all(MYSQLI_ASSOC), 'match_method', 'event_id');
    }

    /** @return array<string, string> event name => its row's method */
    private static function rows(int $clickId): array
    {
        return array_column(self::$db->query("SELECT event_name, match_method FROM 202_conversion_logs WHERE click_id = $clickId AND deleted = 0
            AND (superseded_reason IS NULL OR superseded_reason <> 'replay') ORDER BY conv_id")->fetch_all(MYSQLI_ASSOC), 'match_method', 'event_name');
    }

    public function testAGoalsRowCarriesTheMethodItsEventFoundTheClickBy(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->goal(7, ['name' => 'Sale', 'trigger' => ['event' => 'sale'], 'value' => ['type' => 'fixed', 'amount' => 20]]);

        $this->ingestBy(100, [$this->event('s1', 'sale', self::T + 1)], MatchMethod::SAME_IP);

        self::assertSame(['s1' => 'same_ip'], self::storedEvents(100));
        self::assertSame(['sale' => 'same_ip'], self::rows(100));
    }

    /**
     * B waits for A. B arrives first through a cookie and reaches nothing;
     * A arrives later, earlier in time, through a request that named the
     * click: the replay writes both outcomes in that request, and B's row
     * is B's event's, a cookie.
     */
    public function testAReplayWritesEachRowWithTheMethodOfTheEventThatReachedIt(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $a = $this->goal(7, ['name' => 'A', 'trigger' => ['event' => 'a'], 'value' => ['type' => 'fixed', 'amount' => 1]]);
        $this->goal(7, ['name' => 'B', 'trigger' => ['event' => 'b'], 'after' => [$a], 'value' => ['type' => 'fixed', 'amount' => 2]]);

        self::assertSame(0, $this->ingestBy(100, [$this->event('b', 'b', self::T + 20)], MatchMethod::COOKIE)['outcomes_written']);
        $second = $this->ingestBy(100, [$this->event('a', 'a', self::T + 10)], MatchMethod::CLICK_ID);
        self::assertTrue($second['replayed']);

        self::assertSame(['a' => 'click_id', 'b' => 'cookie'], self::rows(100));
        self::assertSame(['a' => 'click_id', 'b' => 'cookie'], self::storedEvents(100));
    }

    /** An event stored before 1.9.79 has the column's default: the rows it reaches later say unknown. */
    public function testAnEventStoredBeforeTheColumnReachesAnUnknownRow(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $a = $this->goal(7, ['name' => 'A', 'trigger' => ['event' => 'a'], 'value' => ['type' => 'fixed', 'amount' => 1]]);
        $this->goal(7, ['name' => 'B', 'trigger' => ['event' => 'b'], 'after' => [$a], 'value' => ['type' => 'fixed', 'amount' => 2]]);
        $this->ingestBy(100, [$this->event('b', 'b', self::T + 20)], MatchMethod::COOKIE);
        self::$db->query("UPDATE 202_goal_events SET match_method = DEFAULT WHERE event_id = 'b'");

        $this->ingestBy(100, [$this->event('a', 'a', self::T + 10)], MatchMethod::CLICK_ID);

        self::assertSame(['a' => 'click_id', 'b' => 'unknown'], self::rows(100));
    }

    /** The method is how the request found the click, not what the event says: a retry by another path is the same event. */
    public function testARetryThroughAnotherPathIsTheSameEventAndKeepsItsMethod(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->goal(7, ['name' => 'Sale', 'trigger' => ['event' => 'sale'], 'value' => ['type' => 'fixed', 'amount' => 20]]);
        $this->ingestBy(100, [$this->event('s1', 'sale', self::T + 1)], MatchMethod::CLICK_ID);

        $retry = $this->ingestBy(100, [$this->event('s1', 'sale', self::T + 1)], MatchMethod::COOKIE);

        self::assertSame(['s1'], $retry['duplicates']);
        self::assertSame(['s1' => 'click_id'], self::storedEvents(100));
        self::assertSame(['sale' => 'click_id'], self::rows(100));
    }

    public function testAStoredMethodNothingWritesIsRefusedNamingTheEvent(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $a = $this->goal(7, ['name' => 'A', 'trigger' => ['event' => 'a'], 'value' => ['type' => 'fixed', 'amount' => 1]]);
        $this->goal(7, ['name' => 'B', 'trigger' => ['event' => 'b'], 'after' => [$a], 'value' => ['type' => 'fixed', 'amount' => 2]]);
        $this->ingestBy(100, [$this->event('b', 'b', self::T + 20)], MatchMethod::COOKIE);
        self::$db->query("UPDATE 202_goal_events SET match_method = 'guess' WHERE event_id = 'b'");

        try {
            $this->ingestBy(100, [$this->event('a', 'a', self::T + 10)], MatchMethod::CLICK_ID);
            self::fail('a method nothing writes was read as one');
        } catch (GoalEngineException $e) {
            self::assertSame(GoalEngineException::INTEGRITY, $e->reason);
            self::assertMatchesRegularExpression("/goal event row \\d+ has match_method 'guess'/", $e->getMessage());
        }
        self::assertSame([], self::rows(100), 'the replay rolled back');
    }

    /** Code deployed before the 1.9.79 upgrade stores the event without the column, and reads it back as unknown. */
    public function testAnEventStoredBeforeTheUpgradeAddedTheColumnIsKept(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->goal(7, ['name' => 'Sale', 'trigger' => ['event' => 'sale'], 'value' => ['type' => 'fixed', 'amount' => 20]]);
        $log = tempnam(sys_get_temp_dir(), 'p202mm');
        $was = ini_set('error_log', (string) $log);
        self::$db->query('ALTER TABLE 202_goal_events DROP COLUMN match_method');
        try {
            $this->ingestBy(100, [$this->event('s1', 'sale', self::T + 1)], MatchMethod::SAME_IP);
            $rows = self::$db->query('SELECT * FROM 202_goal_events')->fetch_all(MYSQLI_ASSOC);
            self::assertSame(MatchMethod::UNKNOWN, \Prosper202\Goals\GoalEngine::eventFromRow($rows[0])->matchMethod);
        } finally {
            ini_set('error_log', (string) $was);
            self::$db->query("ALTER TABLE 202_goal_events ADD COLUMN `match_method` varchar(16) NOT NULL DEFAULT 'unknown' AFTER `fingerprint`");
        }
        self::assertSame(['sale' => 'same_ip'], self::rows(100), 'the conversion itself has the column and keeps the method');
        self::assertStringContainsString('INSERT INTO 202_goal_events', (string) file_get_contents((string) $log));
        unlink((string) $log);
    }
}
