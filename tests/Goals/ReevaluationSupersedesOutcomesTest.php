<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalEngineException;

/**
 * Re-evaluation under a new version replaces the previous version's results
 * for the subject, in both tables (plan §5.5). The funnel reads
 * 202_goal_outcomes through liveOutcomes(), so a re-evaluation that left the
 * old version's outcome live would count the subject twice; one that
 * superseded only the ledger would still inflate the funnel. The count is
 * one, one and zero — never two.
 *
 * @group integration
 */
final class ReevaluationSupersedesOutcomesTest extends TestCase
{
    use GoalDatabase;

    private const T = 1_650_000_000;

    private int $goal;

    private function funnel(): int
    {
        return $this->goals->countLiveOutcomes(1, ['goal_id' => $this->goal, 'subject_type' => 'click', 'subject_id' => 100]);
    }

    /** @param array<string, mixed> $definition */
    private function edit(array $definition): int
    {
        $this->clock += 100;

        return $this->goals->addVersion(1, $this->goal, GoalDefinition::parse($definition, $this->goal), $this->clock)['version'];
    }

    private function given(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->goal = $this->goal(7, ['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'fixed', 'amount' => 5]]);
        $this->ingest(100, [
            $this->event('p1', 'purchase', self::T + 1, ['amount' => 10]),
            $this->event('p2', 'purchase', self::T + 2, ['amount' => 50]),
        ]);
        self::assertSame(1, $this->funnel());
        self::assertSame(['lead' => 1, 'payout' => '5.00000'], $this->clickState(100));
    }

    public function testTheOldConversionsKeepTheirVersionUntilAReevaluationIsApplied(): void
    {
        $this->given();
        $v2 = $this->edit(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'fixed', 'amount' => 7]]);
        self::assertSame(2, $v2);
        self::assertSame(['goal:' . $this->goal . ':1'], array_column($this->counted(100), 'source_ref'), 'an edit never rewrites history');

        $before = [$this->ledger(100), $this->liveOutcomes(100)];
        $preview = $this->engine->reevaluate(1, $this->goal, null, false);
        self::assertFalse($preview['applied']);
        self::assertSame(2, $preview['version']);
        self::assertSame(1, $preview['totals']['retire']);
        self::assertSame(1, $preview['totals']['write']);
        self::assertSame(1, $preview['totals']['ledger_superseded']);
        self::assertSame('supersede', $preview['subjects'][0]['retire'][0]['ledger']);
        self::assertSame([$this->ledger(100), $this->liveOutcomes(100)], $before, 'a preview changes nothing');
    }

    public function testAVersionThatReachesTheGoalReplacesTheOutcomeOneForOne(): void
    {
        $this->given();
        $this->edit(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'fixed', 'amount' => 7]]);

        $applied = $this->engine->reevaluate(1, $this->goal, null, true);
        self::assertTrue($applied['applied']);
        self::assertSame(1, $this->funnel(), 'one, not two');
        self::assertSame([$this->goal . ':2:1@p1=7.00000'], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '7.00000'], $this->clickState(100));

        $old = self::$db->query("SELECT o.superseded_reason, o.superseded_by, n.outcome_id AS new_id
            FROM 202_goal_outcomes o JOIN 202_goal_outcomes n ON n.goal_version = 2 WHERE o.goal_version = 1")->fetch_assoc();
        self::assertSame('reevaluation', $old['superseded_reason']);
        self::assertSame($old['new_id'], $old['superseded_by']);
        $rows = $this->ledger(100);
        self::assertSame('reevaluation', $rows[0]['superseded_reason']);
        self::assertSame($rows[1]['conv_id'], $rows[0]['superseded_by']);

        // Applying again finds nothing to do.
        $again = $this->engine->reevaluate(1, $this->goal, null, true);
        self::assertSame(0, $again['totals']['retire'] + $again['totals']['write']);
    }

    public function testAVersionThatReachesItAtAnotherEventStillCountsOnce(): void
    {
        $this->given();
        $this->edit(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'threshold' => ['count' => 2], 'value' => ['type' => 'fixed', 'amount' => 9]]);

        $this->engine->reevaluate(1, $this->goal, null, true);
        self::assertSame(1, $this->funnel());
        self::assertSame([$this->goal . ':2:1@p2=9.00000'], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '9.00000'], $this->clickState(100));
    }

    public function testAVersionThatNoLongerQualifiesRetiresTheOutcomeAndDeletesItsConversion(): void
    {
        $this->given();
        $this->edit(['name' => 'Purchase', 'trigger' => ['event' => 'purchase', 'where' => [['prop' => 'amount', 'op' => 'gte', 'value' => 100]]],
            'value' => ['type' => 'fixed', 'amount' => 5]]);

        $preview = $this->engine->reevaluate(1, $this->goal, null, false);
        self::assertSame('delete', $preview['subjects'][0]['retire'][0]['ledger']);
        self::assertNull($preview['subjects'][0]['retire'][0]['replaced_by']);

        $this->engine->reevaluate(1, $this->goal, null, true);
        self::assertSame(0, $this->funnel());
        $rows = $this->ledger(100);
        self::assertCount(1, $rows);
        self::assertSame('1', $rows[0]['deleted'], 'a row cannot be superseded by a row that does not exist: it is deleted');
        self::assertSame(0, $this->clickState(100)['lead']);
        $retired = self::$db->query('SELECT superseded_reason, superseded_by FROM 202_goal_outcomes')->fetch_assoc();
        self::assertSame(['reevaluation', null], [$retired['superseded_reason'], $retired['superseded_by']]);
    }

    public function testAfterARebaseLaterEventsAndReplaysKeepEvaluatingUnderTheAppliedVersion(): void
    {
        $this->given();
        $this->edit(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'threshold' => ['count' => 2], 'value' => ['type' => 'fixed', 'amount' => 9]]);
        $this->engine->reevaluate(1, $this->goal, null, true);

        // A late event that sorts before both replays the subject: the
        // rebased version holds, so the answer moves to the new 2nd purchase
        // (the late one is 1st), still one outcome, never a v1 one again.
        $this->ingest(100, [$this->event('p0', 'purchase', self::T, ['amount' => 1])]);
        self::assertSame([$this->goal . ':2:1@p1=9.00000'], $this->liveOutcomes(100));
        self::assertSame(1, $this->funnel());
        self::assertSame(['lead' => 1, 'payout' => '9.00000'], $this->clickState(100));

        // And an in-order event continues from the rebased progress.
        $this->ingest(100, [$this->event('p3', 'purchase', self::T + 3)]);
        self::assertSame(1, $this->funnel(), 'repeat once: the third purchase reaches nothing');
    }

    public function testAVersionTheGoalDoesNotHaveIsRefusedByName(): void
    {
        $this->given();
        try {
            $this->engine->reevaluate(1, $this->goal, 9, false);
            self::fail('re-evaluated under a version that does not exist');
        } catch (GoalEngineException $e) {
            self::assertSame(GoalEngineException::INVALID, $e->reason);
            self::assertArrayHasKey('version', $e->fieldErrors);
        }
    }
}
