<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Conversion\Ledger\Amount;

/**
 * What the evaluator carries from one event to the next for a subject: per
 * (goal, version) the matching-event count, the running sum, how many
 * times it has been reached and when first; and which goals the subject
 * has reached (eligibly), for `after`.
 *
 * The same state is what 202_goal_progress stores, so evaluating one more
 * event from the stored state and re-evaluating every event from nothing
 * run the same fold (GoalEvaluator::fold()) and cannot disagree.
 */
final class EvaluationState
{
    /** @var array<string, array{goal_id: int, version: int, count: int, sum: int, times: int, reached_at: int|null}> */
    public array $progress = [];

    /** @var array<int, true> goal id => reached (eligibly) */
    public array $reached = [];

    /** @return array{goal_id: int, version: int, count: int, sum: int, times: int, reached_at: int|null} */
    public function &entry(int $goalId, int $version): array
    {
        $key = $goalId . ':' . $version;
        if (!isset($this->progress[$key])) {
            $this->progress[$key] = ['goal_id' => $goalId, 'version' => $version, 'count' => 0, 'sum' => 0, 'times' => 0, 'reached_at' => null];
        }

        return $this->progress[$key];
    }

    /** @return list<array<string, mixed>> the vector format, ordered by goal then version */
    public function progressArray(): array
    {
        $rows = array_values($this->progress);
        usort($rows, static fn (array $a, array $b): int => [$a['goal_id'], $a['version']] <=> [$b['goal_id'], $b['version']]);

        return array_map(static fn (array $p): array => [
            'goal_id' => $p['goal_id'],
            'version' => $p['version'],
            'count' => $p['count'],
            'sum' => Amount::fromUnits($p['sum']),
            'times_reached' => $p['times'],
            'reached_at' => $p['reached_at'],
        ], $rows);
    }
}
