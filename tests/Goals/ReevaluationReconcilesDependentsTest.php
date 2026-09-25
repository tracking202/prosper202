<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Prosper202\Goals\GoalDefinition;

/**
 * Re-evaluating a goal re-decides every goal whose `after` chain leads to
 * it. A funnel A → B → C (B after A, C after B) is one reconciliation: when
 * A's new version stops matching, the B and C a subject reached behind it
 * are retired with it (outcome and conversion alike); when A's new version
 * starts matching, the B and C that were waiting on it are written; and the
 * progress the re-evaluation stores is the progress of exactly the outcomes
 * it leaves live, so the next in-order event continues from the same
 * answer a replay would reach. A goal off the chain is not touched.
 *
 * The oracle for "the same answer a replay would reach": an event that
 * sorts before everything stored replays the whole subject from its events
 * and reconciles; after a consistent re-evaluation that replay writes and
 * retires nothing.
 *
 * @group integration
 */
final class ReevaluationReconcilesDependentsTest extends TestCase
{
    use GoalDatabase;

    private const T = 1_650_000_000;

    private int $a;
    private int $b;
    private int $c;
    private int $other;

    /** @param array<string, mixed> $aDefinition */
    private function given(array $aDefinition): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->a = $this->goal(7, $aDefinition);
        $this->b = $this->goal(7, ['name' => 'Upsell', 'trigger' => ['event' => 'upsell'], 'after' => [$this->a],
            'value' => ['type' => 'fixed', 'amount' => 3]]);
        $this->c = $this->goal(7, ['name' => 'Renewal', 'trigger' => ['event' => 'renew'], 'after' => [$this->b],
            'value' => ['type' => 'fixed', 'amount' => 2]]);
        $this->other = $this->goal(7, ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 1]]);
        $this->ingest(100, [
            $this->event('s1', 'signup', self::T),
            $this->event('p1', 'purchase', self::T + 1, ['amount' => 10]),
            $this->event('u1', 'upsell', self::T + 2),
            $this->event('r1', 'renew', self::T + 3),
        ]);
    }

    /** @param array<string, mixed> $definition */
    private function editA(array $definition): void
    {
        $this->clock += 100;
        $this->goals->addVersion(1, $this->a, GoalDefinition::parse($definition, $this->a), $this->clock);
    }

    /** @return list<string> goal:version count/sum/times */
    private function progress(): array
    {
        $rows = self::$db->query('SELECT goal_id, goal_version, `count`, `sum`, times_reached FROM 202_goal_progress
            WHERE subject_type = \'click\' AND subject_id = 100 ORDER BY goal_id, goal_version')->fetch_all(MYSQLI_ASSOC);

        return array_map(static fn (array $r): string => $r['goal_id'] . ':' . $r['goal_version'] . ' ' . $r['count'] . '/' . $r['sum'] . '/' . $r['times_reached'], $rows);
    }

    /** A replay of the whole subject that must find nothing to change. */
    private function assertAReplayAgrees(string $when): void
    {
        $replayed = $this->ingest(100, [$this->event('early' . $this->clock, 'noop', self::T - 1000)]);
        self::assertTrue($replayed['replayed']);
        self::assertSame([0, 0], [$replayed['outcomes_written'], $replayed['outcomes_retired']], $when . ': a replay disagrees with the stored outcomes');
    }

    public function testAPrerequisiteThatStopsMatchingRetiresTheWholeChainBehindIt(): void
    {
        $this->given(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'fixed', 'amount' => 5]]);
        self::assertSame([
            $this->a . ':1:1@p1=5.00000', $this->b . ':1:1@u1=3.00000', $this->c . ':1:1@r1=2.00000', $this->other . ':1:1@s1=1.00000',
        ], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '11.00000'], $this->clickState(100));
        $otherProgress = array_values(array_filter($this->progress(), fn (string $p): bool => str_starts_with($p, $this->other . ':')));

        $this->editA(['name' => 'Purchase', 'trigger' => ['event' => 'purchase', 'where' => [['prop' => 'amount', 'op' => 'gte', 'value' => 100]]],
            'value' => ['type' => 'fixed', 'amount' => 5]]);

        $preview = $this->engine->reevaluate(1, $this->a, null, false);
        self::assertSame([$this->a, $this->b, $this->c], $preview['subjects'][0]['goals'], 'the preview names the chain it re-decides');
        self::assertSame([$this->a, $this->b, $this->c], $preview['goals']);
        $retire = array_map(static fn (array $r): string => $r['goal_id'] . ':' . $r['version'] . ':' . $r['n'] . '@' . $r['event_id'] . ' ' . $r['ledger'],
            $preview['subjects'][0]['retire']);
        sort($retire);
        $expected = [$this->a . ':1:1@p1 delete', $this->b . ':1:1@u1 delete', $this->c . ':1:1@r1 delete'];
        sort($expected);
        self::assertSame($expected, $retire);
        self::assertSame(3, $preview['totals']['retire']);
        self::assertSame(3, $preview['totals']['ledger_deleted']);
        self::assertSame(1, $preview['totals']['subjects'], 'dependents are re-decided per subject; they never add subjects');

        $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame([$this->other . ':1:1@s1=1.00000'], $this->liveOutcomes(100), 'B and C were reached behind an A that no longer is');
        self::assertSame(['lead' => 1, 'payout' => '1.00000'], $this->clickState(100));
        $live = array_filter($this->ledger(100), static fn (array $r): bool => (int) $r['deleted'] === 0 && $r['superseded_reason'] === null);
        self::assertSame(['goal:' . $this->other . ':1'], array_values(array_column($live, 'source_ref')));
        self::assertSame($otherProgress, array_values(array_filter($this->progress(), fn (string $p): bool => str_starts_with($p, $this->other . ':'))),
            'a goal off the chain keeps its progress');
        $this->assertAReplayAgrees('after the re-evaluation');

        // The next events continue from the re-decided progress: a purchase
        // of 200 reaches A's version 2, and only then do B and C count again
        // — once each, never a second live n=1 beside a retired one.
        $this->ingest(100, [$this->event('p2', 'purchase', self::T + 10, ['amount' => 200])]);
        $this->ingest(100, [$this->event('u2', 'upsell', self::T + 11)]);
        $this->ingest(100, [$this->event('r2', 'renew', self::T + 12)]);
        self::assertSame([
            $this->a . ':2:1@p2=5.00000', $this->b . ':1:1@u2=3.00000', $this->c . ':1:1@r2=2.00000', $this->other . ':1:1@s1=1.00000',
        ], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '11.00000'], $this->clickState(100));
        $this->assertAReplayAgrees('after later in-order events');
    }

    public function testAPrerequisiteThatStartsMatchingWritesTheChainThatWasWaitingOnIt(): void
    {
        $this->given(['name' => 'Purchase', 'trigger' => ['event' => 'purchase', 'where' => [['prop' => 'amount', 'op' => 'gte', 'value' => 100]]],
            'value' => ['type' => 'fixed', 'amount' => 5]]);
        self::assertSame([$this->other . ':1:1@s1=1.00000'], $this->liveOutcomes(100));

        $this->editA(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'fixed', 'amount' => 5]]);
        $preview = $this->engine->reevaluate(1, $this->a, null, false);
        self::assertSame(3, $preview['totals']['write']);
        self::assertSame([$this->a, $this->b, $this->c], array_column($preview['subjects'][0]['write'], 'goal_id'));

        $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame([
            $this->a . ':2:1@p1=5.00000', $this->b . ':1:1@u1=3.00000', $this->c . ':1:1@r1=2.00000', $this->other . ':1:1@s1=1.00000',
        ], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '11.00000'], $this->clickState(100));
        $this->assertAReplayAgrees('after the re-evaluation');

        // Repeat once: another upsell reaches nothing more.
        $this->ingest(100, [$this->event('u2', 'upsell', self::T + 11)]);
        self::assertCount(4, $this->liveOutcomes(100));
        $this->assertAReplayAgrees('after a later in-order event');

        $again = $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame(0, $again['totals']['retire'] + $again['totals']['write'], 'applying again finds nothing to do');
    }

    /**
     * A dependent's version the evaluation cannot use — here one stored
     * before a repeating sum needed a max, so it no longer parses — was not
     * recomputed, so its outcomes, their conversions and its progress are
     * left exactly as they were, while the dependent's usable version is
     * re-decided with the goal.
     */
    public function testADependentVersionTheEvaluationCannotUseIsLeftAsItWas(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->a = $this->goal(7, ['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'fixed', 'amount' => 5]]);
        $this->b = $this->goal(7, ['name' => 'Upsell', 'trigger' => ['event' => 'upsell'], 'after' => [$this->a],
            'threshold' => ['sum' => ['prop' => 'amount', 'gte' => 10]], 'repeat' => ['mode' => 'each', 'max' => 5],
            'value' => ['type' => 'fixed', 'amount' => 3]]);
        $this->ingest(100, [
            $this->event('p1', 'purchase', self::T + 1),
            $this->event('u1', 'upsell', self::T + 2, ['amount' => 25]),
        ]);
        self::assertSame([$this->a . ':1:1@p1=5.00000', $this->b . ':1:1@u1=3.00000', $this->b . ':1:2@u1=3.00000'], $this->liveOutcomes(100));

        // As stored before the rule: the same version without its max.
        $stmt = self::$db->prepare('UPDATE 202_goal_versions SET definition = JSON_REMOVE(definition, \'$.repeat.max\') WHERE goal_id = ? AND version = 1');
        $stmt->bind_param('i', $this->b);
        self::assertTrue($stmt->execute());
        self::assertSame(1, $stmt->affected_rows, 'the stored version was rewritten');
        $this->clock += 100;
        $this->goals->addVersion(1, $this->b, GoalDefinition::parse(['name' => 'Upsell', 'trigger' => ['event' => 'upsell'], 'after' => [$this->a],
            'value' => ['type' => 'fixed', 'amount' => 4]], $this->b), $this->clock);
        $this->ingest(100, [$this->event('u2', 'upsell', self::T + 3)]);
        $progressBefore = array_values(array_filter($this->progress(), fn (string $p): bool => str_starts_with($p, $this->b . ':1 ')));
        self::assertSame([$this->b . ':1 1/25.00000/2'], $progressBefore);

        $this->editA(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'fixed', 'amount' => 6]]);
        $preview = $this->engine->reevaluate(1, $this->a, null, false);
        self::assertSame([$this->a, $this->b], $preview['goals']);
        self::assertSame([$this->a . ':1:1'], array_map(static fn (array $r): string => $r['goal_id'] . ':' . $r['version'] . ':' . $r['n'], $preview['subjects'][0]['retire']));

        $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame([
            $this->a . ':2:1@p1=6.00000', $this->b . ':1:1@u1=3.00000', $this->b . ':1:2@u1=3.00000', $this->b . ':2:1@u2=4.00000',
        ], $this->liveOutcomes(100));
        self::assertSame($progressBefore, array_values(array_filter($this->progress(), fn (string $p): bool => str_starts_with($p, $this->b . ':1 '))),
            'the unusable version keeps its progress');
        self::assertContains($this->b . ':2 1/0.00000/1', $this->progress(), 'the usable version is re-decided');
    }
}
