<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;

/**
 * Events are evaluated in event-time order per subject, not in arrival
 * order (plan §5.5). A request whose event sorts before one already stored
 * is a replay: the subject is re-evaluated from its stored events, and the
 * outcomes and ledger rows that moved are superseded — never duplicated,
 * never left as a function of arrival order.
 *
 * @group integration
 */
final class EventOrderTest extends TestCase
{
    use GoalDatabase;

    private const T = 1_650_000_000;

    /** @return array<string, array{0: list<string>}> */
    public static function permutations(): array
    {
        $out = [];
        foreach ([['r', 't', 'p'], ['r', 'p', 't'], ['t', 'r', 'p'], ['t', 'p', 'r'], ['p', 'r', 't'], ['p', 't', 'r']] as $order) {
            $out[implode('', $order)] = [$order];
        }

        return $out;
    }

    /**
     * @dataProvider permutations
     * @param list<string> $arrival
     */
    public function testEveryArrivalOrderOfAThreeStepFunnelReachesTheSameOutcomes(array $arrival): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $register = $this->goal(7, ['name' => 'Registered', 'trigger' => ['event' => 'register'], 'value' => ['type' => 'fixed', 'amount' => 1]]);
        $tutorial = $this->goal(7, ['name' => 'Tutorial', 'trigger' => ['event' => 'tutorial'], 'after' => [$register], 'value' => ['type' => 'fixed', 'amount' => 2]]);
        $purchase = $this->goal(7, ['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'after' => [$tutorial], 'value' => ['type' => 'fixed', 'amount' => 3]]);

        $events = [
            'r' => $this->event('r', 'register', self::T + 1),
            't' => $this->event('t', 'tutorial', self::T + 2),
            'p' => $this->event('p', 'purchase', self::T + 3),
        ];
        foreach ($arrival as $key) {
            $this->ingest(100, [$events[$key]]);
        }

        self::assertSame([
            $register . ':1:1@r=1.00000',
            $tutorial . ':1:1@t=2.00000',
            $purchase . ':1:1@p=3.00000',
        ], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '6.00000'], $this->clickState(100));
        self::assertCount(3, $this->counted(100));
        self::assertSame(
            array_map(static fn (array $r): string => (string) $r['conv_id'], $this->counted(100)),
            array_map(static fn (array $r): string => (string) $r['conversion_id'],
                self::$db->query('SELECT conversion_id FROM 202_goal_outcomes WHERE superseded_at IS NULL ORDER BY conversion_id')->fetch_all(MYSQLI_ASSOC)),
            'every live outcome links a counted row'
        );
    }

    public function testThePrerequisiteArrivingSecondStillOpensTheGoalThatNeedsIt(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $a = $this->goal(7, ['name' => 'A', 'trigger' => ['event' => 'a']]);
        $b = $this->goal(7, ['name' => 'B', 'trigger' => ['event' => 'b'], 'after' => [$a], 'value' => ['type' => 'fixed', 'amount' => 2]]);

        $first = $this->ingest(100, [$this->event('b', 'b', self::T + 20)]);
        self::assertSame(0, $first['outcomes_written'], 'B waits for A');
        $second = $this->ingest(100, [$this->event('a', 'a', self::T + 10)]);
        self::assertTrue($second['replayed']);

        self::assertSame([$a . ':1:1@a=null unpaid', $b . ':1:1@b=2.00000'], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '2.00000'], $this->clickState(100));
    }

    public function testALateEarlierPurchaseRedecidesEveryOutcomeAndTheClickShowsSixteen(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $g = $this->goal(7, ['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'repeat' => ['mode' => 'each'], 'value' => ['type' => 'from_property']]);

        $this->ingest(100, [$this->event('p5', 'purchase', self::T + 20, [], 5, true)]);
        $this->ingest(100, [$this->event('p10', 'purchase', self::T + 30, [], 10, true)]);
        self::assertSame(['lead' => 1, 'payout' => '15.00000'], $this->clickState(100));

        $late = $this->ingest(100, [$this->event('p1', 'purchase', self::T + 15, [], 1, true)]);
        self::assertTrue($late['replayed']);
        self::assertSame(3, $late['outcomes_written']);
        self::assertSame(2, $late['outcomes_retired']);

        self::assertSame([$g . ':1:1@p1=1.00000', $g . ':1:2@p5=5.00000', $g . ':1:3@p10=10.00000'], $this->liveOutcomes(100));
        $rows = $this->ledger(100);
        self::assertCount(5, $rows);
        $superseded = array_values(array_filter($rows, static fn (array $r): bool => $r['superseded_reason'] !== null));
        self::assertCount(2, $superseded);
        self::assertSame(['replay', 'replay'], array_column($superseded, 'superseded_reason'));
        self::assertSame(['goal:' . $g . ':1:1:p5', 'goal:' . $g . ':1:2:p10'], array_column($superseded, 'dedupe_key'));
        foreach ($superseded as $old) {
            self::assertNotNull($old['superseded_by'], 'a replayed row names the row that replaced it');
        }
        self::assertCount(3, $this->counted(100));
        self::assertSame(['lead' => 1, 'payout' => '16.00000'], $this->clickState(100), '$1 + $5 + $10, not $31');

        // The retired outcomes are kept, marked, and never read as live.
        $retired = self::$db->query("SELECT superseded_reason, superseded_by, superseded_at FROM 202_goal_outcomes WHERE superseded_at IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
        self::assertCount(2, $retired);
        self::assertSame(['replay', 'replay'], array_column($retired, 'superseded_reason'));

        // A retry of the late event changes nothing.
        $retry = $this->ingest(100, [$this->event('p1', 'purchase', self::T + 15, [], 1, true)]);
        self::assertSame(['p1'], $retry['duplicates']);
        self::assertCount(5, $this->ledger(100));
    }

    public function testATieIsDecidedByEventIdEvenWhenTheTimesAgree(): void
    {
        // Two requests in the same second, for events that happened in the
        // same second: the order key falls through to the event id, so "a"
        // arriving second is still the first purchase. The outcome moves to
        // another event at the same reached_at — only the event says it moved.
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $g = $this->goal(7, ['name' => 'First', 'trigger' => ['event' => 'buy'], 'value' => ['type' => 'fixed', 'amount' => 1]]);
        $subject = $this->engine->clickSubject(1, 100);
        $at = $this->clock + 10;
        $this->engine->ingest(1, $subject, [$this->eventAt('b', self::T + 5, $at)]);
        $second = $this->engine->ingest(1, $subject, [$this->eventAt('a', self::T + 5, $at)]);

        self::assertTrue($second['replayed']);
        self::assertSame([$g . ':1:1@a=1.00000'], $this->liveOutcomes(100));
        self::assertSame(['goal:' . $g . ':1:1:b'], array_column(array_values(array_filter(
            $this->ledger(100),
            static fn (array $r): bool => $r['superseded_reason'] === 'replay'
        )), 'dedupe_key'));
        self::assertSame(['lead' => 1, 'payout' => '1.00000'], $this->clickState(100));
    }

    private function eventAt(string $id, int $occurred, int $received): \Prosper202\Goals\GoalEvent
    {
        return new \Prosper202\Goals\GoalEvent($id, 'buy', $occurred, $received, [], null, false, null);
    }

    public function testInOrderAndReplayedDeliveryLeaveTheSameProgress(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->click(101, 7);
        $this->goal(7, ['name' => 'Spend', 'trigger' => ['event' => 'buy'], 'threshold' => ['sum' => ['prop' => 'amount', 'gte' => 10]],
            'repeat' => ['mode' => 'each', 'max' => 10], 'value' => ['type' => 'fixed', 'amount' => 1]]);

        $events = [
            $this->event('a', 'buy', self::T + 1, ['amount' => 4]),
            $this->event('b', 'buy', self::T + 2, ['amount' => 7.5]),
            $this->event('c', 'buy', self::T + 3, ['amount' => 12]),
        ];
        foreach ($events as $e) {
            $this->ingest(100, [$e]);
        }
        foreach ([$events[2], $events[0], $events[1]] as $e) {
            $this->ingest(101, [$e]);
        }

        $progress = static fn (int $click): array => self::$db->query(
            "SELECT goal_id, goal_version, `count`, `sum`, times_reached, reached_at FROM 202_goal_progress WHERE subject_id = $click"
        )->fetch_all(MYSQLI_ASSOC);
        self::assertSame($progress(100), $progress(101));
        self::assertSame('23.50000', $progress(100)[0]['sum']);
        self::assertSame('2', $progress(100)[0]['times_reached']);
        self::assertSame(
            str_replace('@', '', implode(',', $this->liveOutcomes(100))),
            str_replace('@', '', implode(',', $this->liveOutcomes(101)))
        );
    }
}
