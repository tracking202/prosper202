<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Goals\EvaluationState;
use Prosper202\Goals\EvaluationTooLarge;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalEvaluator;
use Prosper202\Goals\GoalEvent;
use Prosper202\Goals\GoalSpec;
use Prosper202\Goals\GoalSubject;
use Prosper202\Goals\InvalidGoalDefinition;

/**
 * What one event, and one subject, can cost the evaluator. The shapes that
 * the cross-language vectors cannot hold — they need 10,000 events, or a
 * state carried in from storage — are pinned here; the rest are in
 * tests/fixtures/app-sdk-contract/goals/.
 *
 * - A sum that repeats must have a max, so one event reaches at most max
 *   outcomes, and how many is computed, not searched for.
 * - A summand is at most 999999.99999 either way, and the running sum is
 *   held between SUM_FLOOR_UNITS and cap × gte, so every term of the
 *   arithmetic is an int and persisting the sum never meets a float.
 * - An evaluation given an outcome budget stops as soon as it passes it.
 */
final class SumBoundsTest extends TestCase
{
    private const MAX = 99999999999; // 999999.99999 in units

    /** @param array<string, mixed> $definition */
    private static function spec(array $definition, int $goalId = 1): GoalSpec
    {
        return new GoalSpec($goalId, [['version' => 1, 'effective_at' => 0, 'definition' => $definition]]);
    }

    private static function subject(): GoalSubject
    {
        return new GoalSubject(GoalSubject::CLICK, 1, 0, null, []);
    }

    /** @param array<string, mixed> $props */
    private static function event(string $id, int $at, array $props, int|float|null $revenue = null): GoalEvent
    {
        return new GoalEvent($id, 'buy', $at, $at, $props, $revenue, false, null);
    }

    public function testASumThatRepeatsWithoutMaxIsRefusedByNameAndACountIsNot(): void
    {
        try {
            GoalDefinition::parse(['name' => 'A', 'trigger' => ['event' => 'buy'], 'threshold' => ['sum' => ['prop' => 'v', 'gte' => 1]], 'repeat' => ['mode' => 'each']]);
            self::fail('accepted a repeating sum with no bound');
        } catch (InvalidGoalDefinition $e) {
            self::assertSame(['repeat.max'], array_keys($e->errors()));
            self::assertStringContainsString('required for a sum threshold', $e->errors()['repeat.max']);
        }
        $count = GoalDefinition::parse(['name' => 'A', 'trigger' => ['event' => 'buy'], 'repeat' => ['mode' => 'each']]);
        self::assertNull($count->repeatMax, 'a count grows by one per event, so its each may stay unbounded');
    }

    public function testOneEventReachesAtMostMaxAndCostsOnlyWhatItReaches(): void
    {
        $spec = self::spec(['name' => 'Every unit', 'trigger' => ['event' => 'buy'],
            'threshold' => ['sum' => ['prop' => 'v', 'gte' => '0.00001']], 'repeat' => ['mode' => 'each', 'max' => GoalDefinition::MAX_REPEAT]]);
        // 999999.99999 against 0.00001 crosses 99,999,999,999 multiples; a
        // search would take hours. Computed, it is the 10,000 outcomes.
        $started = hrtime(true);
        $result = GoalEvaluator::evaluateAll([$spec], self::subject(), [self::event('a', 10, ['v' => 999999.99999])]);
        self::assertCount(GoalDefinition::MAX_REPEAT, $result->outcomes);
        self::assertSame(GoalDefinition::MAX_REPEAT, $result->outcomes[GoalDefinition::MAX_REPEAT - 1]->n);
        self::assertLessThan(5_000_000_000, hrtime(true) - $started, 'ten thousand outcomes, not a search over every multiple');
        $p = $result->state->progress['1:1'];
        self::assertSame(GoalDefinition::MAX_REPEAT, $p['sum'], 'held at max × gte');

        // Nothing later reaches anything more.
        $later = GoalEvaluator::continueFrom([$spec], self::subject(), $result->state, [self::event('b', 20, ['v' => 999999.99999])]);
        self::assertSame([], $later->outcomes);
    }

    public function testTenHugeSummandsNeitherOverflowNorCount(): void
    {
        $spec = self::spec(['name' => 'Spent', 'trigger' => ['event' => 'buy'], 'threshold' => ['sum' => ['prop' => 'v', 'gte' => '999999']]]);
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = self::event('e' . $i, 10 + $i, ['v' => 9999999999999]);
        }
        $result = GoalEvaluator::evaluateAll([$spec], self::subject(), $events);
        self::assertSame([], $result->outcomes);
        self::assertSame([], $result->toArray()['progress'], 'an out-of-range summand does not count the event at all');
    }

    public function testTheRunningSumIsHeldAtTheFloorAndNeverLeavesIntRange(): void
    {
        $spec = self::spec(['name' => 'Spent', 'trigger' => ['event' => 'buy'],
            'threshold' => ['sum' => ['prop' => 'v', 'gte' => '999999.99999']], 'repeat' => ['mode' => 'each', 'max' => GoalDefinition::MAX_REPEAT]]);
        // 10,001 of the most negative summand pass the floor (10^15 units).
        $events = [];
        for ($i = 0; $i <= 10000; $i++) {
            $events[] = self::event('n' . $i, 10 + $i, ['v' => -999999.99999]);
        }
        $result = GoalEvaluator::evaluateAll([$spec], self::subject(), $events);
        $p = $result->state->progress['1:1'];
        self::assertSame(GoalEvaluator::SUM_FLOOR_UNITS, $p['sum']);
        self::assertSame('-10000000000.00000', $result->toArray()['progress'][0]['sum']);

        // Upward, from the largest sum storage can hand back (13 whole
        // digits), the sum is an int held at the ceiling.
        $state = new EvaluationState();
        $entry = &$state->entry(1, 1);
        $entry['sum'] = Amount::toUnits('9999999999999.99999');
        unset($entry);
        $up = GoalEvaluator::continueFrom([$spec], self::subject(), $state, [self::event('u', 100000, ['v' => 999999.99999])]);
        $p = $up->state->progress['1:1'];
        self::assertIsInt($p['sum']);
        self::assertSame(GoalDefinition::MAX_REPEAT * self::MAX, $p['sum']);
        self::assertCount(GoalDefinition::MAX_REPEAT, $up->outcomes);
        self::assertSame('9999999999.90000', $up->toArray()['progress'][0]['sum'], 'persistable: Amount::fromUnits() takes it');
    }

    public function testAnOutcomeBudgetStopsTheEvaluationAsSoonAsItIsPassed(): void
    {
        $spec = self::spec(['name' => 'Every buy', 'trigger' => ['event' => 'buy'], 'repeat' => ['mode' => 'each']]);
        $events = [];
        for ($i = 0; $i < 6; $i++) {
            $events[] = self::event('e' . $i, 10 + $i, []);
        }
        self::assertCount(6, GoalEvaluator::evaluateAll([$spec], self::subject(), $events, 6)->outcomes);
        try {
            GoalEvaluator::evaluateAll([$spec], self::subject(), $events, 5);
            self::fail('passed its outcome budget');
        } catch (EvaluationTooLarge $e) {
            self::assertSame(5, $e->limit);
        }
    }

    public function testRevenueBeyondWhatAConversionHoldsIsRefusedAtIntake(): void
    {
        foreach ([999999.99999, -999999.99999, 0, 12.5] as $ok) {
            self::assertSame($ok, GoalEvent::fromArray(['event_id' => 'e', 'name' => 'buy', 'occurred_at' => 1, 'received_at' => 1, 'revenue' => $ok])->revenue);
        }
        foreach ([1000000, -1000000, 1000000.5, 9999999999999, 1.0e300] as $bad) {
            try {
                GoalEvent::fromArray(['event_id' => 'e', 'name' => 'buy', 'occurred_at' => 1, 'received_at' => 1, 'revenue' => $bad]);
                self::fail(var_export($bad, true) . ' accepted');
            } catch (InvalidGoalDefinition $e) {
                self::assertSame(['event.revenue'], array_keys($e->errors()));
            }
        }
    }
}
