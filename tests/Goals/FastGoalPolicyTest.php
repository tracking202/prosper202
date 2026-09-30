<?php

declare(strict_types=1);

namespace Tests\Goals;

use Prosper202\Goals\FastGoalPolicy;
use Prosper202\Goals\GoalEvent;
use Prosper202\Goals\Outcome;
use Tests\TestCase;

final class FastGoalPolicyTest extends TestCase
{
    private static function outcome(int $reachedAt, string $eventId = 'e1'): Outcome
    {
        return new Outcome(1, 1, 1, $eventId, $reachedAt, 100000, Outcome::SOURCE_FIXED, null, null);
    }

    public function testAnOutcomeSoonerThanTheThresholdAfterTheInstallIsTooFast(): void
    {
        $policy = FastGoalPolicy::fromRow(['fast_goal_seconds' => 5, 'fast_goal_policy' => 'count']);
        self::assertSame([[], false], [$policy->unreadable, $policy->hold]);
        self::assertSame(
            [true, true, true, false, false],
            array_map(static fn (int $t): bool => $policy->tooFast(self::outcome($t), 1000), [990, 1000, 1004, 1005, 2000])
        );
        self::assertFalse($policy->tooFast(self::outcome(1000, GoalEvent::INSTALL_EVENT_ID), 1000), 'the install is never too fast after itself');
        self::assertFalse(FastGoalPolicy::fromRow(['fast_goal_seconds' => '0', 'fast_goal_policy' => 'hold'])->tooFast(self::outcome(1000), 1000), '0 flags none');
        self::assertTrue(FastGoalPolicy::fromRow(['fast_goal_seconds' => '5', 'fast_goal_policy' => 'hold'])->hold);
    }

    /** @return iterable<string, array{array<string, mixed>|null, list<string>}> */
    public static function unreadable(): iterable
    {
        yield 'no row' => [null, ['fast_goal_seconds', 'fast_goal_policy']];
        yield 'policy case' => [['fast_goal_seconds' => 5, 'fast_goal_policy' => 'Count'], ['fast_goal_policy']];
        yield 'policy blank' => [['fast_goal_seconds' => 5, 'fast_goal_policy' => ''], ['fast_goal_policy']];
        yield 'seconds over' => [['fast_goal_seconds' => 3601, 'fast_goal_policy' => 'count'], ['fast_goal_seconds']];
        yield 'seconds padded' => [['fast_goal_seconds' => '05', 'fast_goal_policy' => 'count'], ['fast_goal_seconds']];
        yield 'seconds missing' => [['fast_goal_policy' => 'count'], ['fast_goal_seconds']];
    }

    /**
     * @dataProvider unreadable
     * @param list<string> $names
     */
    public function testAnUnreadableColumnIsNamedAndReadAsTheTrustingLeastValue(?array $row, array $names): void
    {
        $policy = FastGoalPolicy::fromRow($row);
        self::assertSame($names, $policy->unreadable);
        if (in_array('fast_goal_policy', $names, true)) {
            self::assertTrue($policy->hold, 'unreadable is hold, never count');
        }
        if (in_array('fast_goal_seconds', $names, true)) {
            self::assertSame(FastGoalPolicy::MAX_SECONDS, $policy->seconds);
        }
    }

    /**
     * The decision a stored outcome carries (GoalEngine::valuation()'s
     * $decided): held is the note, which makes it too fast whatever the
     * column says; a flagged row that is unpaid for another reason is not
     * held; and a row read without the columns cannot answer.
     */
    public function testAStoredOutcomeCarriesItsDecision(): void
    {
        self::assertSame([true, true], FastGoalPolicy::decidedOn(['too_fast' => '1', 'payable' => '0', 'value_note' => FastGoalPolicy::HELD_NOTE]));
        self::assertSame([true, true], FastGoalPolicy::decidedOn(['too_fast' => '0', 'payable' => '0', 'value_note' => 'held_too_fast']));
        self::assertSame([true, false], FastGoalPolicy::decidedOn(['too_fast' => '1', 'payable' => '0', 'value_note' => 'not_payable_on_campaign']));
        self::assertSame([true, false], FastGoalPolicy::decidedOn(['too_fast' => 1, 'payable' => 1, 'value_note' => null]));
        self::assertSame([false, false], FastGoalPolicy::decidedOn(['too_fast' => '0', 'payable' => '1', 'value_note' => null]));
        foreach ([['too_fast' => '1'], ['value_note' => null], []] as $partial) {
            try {
                FastGoalPolicy::decidedOn($partial);
                self::fail('a row without the columns cannot answer: ' . json_encode($partial));
            } catch (\LogicException $e) {
                self::assertStringContainsString('too_fast and value_note', $e->getMessage());
            }
        }
        self::assertLessThanOrEqual(32, strlen(FastGoalPolicy::HELD_NOTE), 'fits value_note varchar(32)');
    }
}
