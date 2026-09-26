<?php

declare(strict_types=1);

namespace Api\V3\Apps\Apple;

use Api\V3\Support\MysqliStatements;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\InvalidGoalDefinition;

/**
 * Which goals an SKAN encoding may name, now that the iOS SDK evaluates
 * goals on the device (plan §5.5, PR 8).
 *
 * Any goal the evaluator can reach on a device will do: predicates,
 * counts, sums, `after` chains, install windows, repeats. What a device can
 * never reach is a goal windowed `from: click` — SKAdNetwork does not tell
 * an app which click it came from, so the device is an install subject with
 * no click and such an outcome is always ineligible (`no_click`) — and any
 * goal that waits, through `after`, for one. An encoding naming it would
 * reserve a conversion value nothing ever sets, so it is refused, and a goal
 * an encoding depends on cannot be edited into that shape
 * (GoalsController). A current definition that no longer parses is refused
 * too: the device would disable it.
 *
 * The same question from both sides, in one place: the encoding writer asks
 * about the goal it names, the goal editor about the definition it is about
 * to store.
 */
final class SkanEncodingRules
{
    use MysqliStatements;

    /** `after` chains are at most 5 wide per goal; this bounds a damaged graph. */
    private const MAX_GOALS_WALKED = 500;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * Why a device could never reach this goal, or null when it can.
     *
     * @param array<int, GoalDefinition> $replacing Definitions an edit is
     *        about to store, by goal id, read instead of the stored ones.
     */
    public function deviceUnreachable(int $goalId, array $replacing = []): ?string
    {
        $pending = [$goalId];
        $seen = [];
        while ($pending !== []) {
            $id = array_shift($pending);
            if (isset($seen[$id])) {
                continue; // a cycle is the evaluator's to disable; walking it once is enough
            }
            $seen[$id] = true;
            if (count($seen) > self::MAX_GOALS_WALKED) {
                return 'Goal ' . $goalId . '\'s `after` chain is too long to check.';
            }

            if (isset($replacing[$id])) {
                $definition = $replacing[$id];
            } else {
                $json = $this->currentDefinition($id);
                if ($json === null) {
                    return $id === $goalId
                        ? 'No live goal ' . $goalId . ' in this account.'
                        : 'Goal ' . $goalId . ' waits for goal ' . $id . ', which does not exist.';
                }
                try {
                    $definition = GoalDefinition::fromJson($json, $id);
                } catch (InvalidGoalDefinition) {
                    return 'Goal ' . $id . '\'s current definition is invalid; fix the goal first.';
                }
            }

            if ($definition->withinFrom === 'click') {
                $what = $id === $goalId ? 'Goal ' . $goalId . ' counts' : 'Goal ' . $goalId . ' waits for goal ' . $id . ', which counts';
                return $what . ' from the click (within.from = "click"). SKAdNetwork never tells an app which click it came from, '
                    . 'so the iOS SDK evaluates goals for an install with no click and could never reach this one. '
                    . 'Count from the install instead ("from": "install"), or encode another goal.';
            }
            foreach ($definition->after as $prerequisite) {
                $pending[] = $prerequisite;
            }
        }

        return null;
    }

    /**
     * Goal ids whose current definition waits, directly or through other
     * goals, for $goalId: the goals an edit to $goalId can make unreachable.
     *
     * @return list<int>
     */
    public function dependentsOf(int $goalId): array
    {
        $stmt = $this->prepare(
            'SELECT g.goal_id, v.definition FROM 202_goals g
             JOIN 202_goal_versions v ON v.goal_id = g.goal_id AND v.version = g.current_version
             WHERE g.user_id = ? AND g.archived_at IS NULL'
        );
        $this->bind($stmt, 'i', $this->userId);
        $this->execute($stmt, 'Goal lookup failed');
        $result = $this->result($stmt);
        $waitsOn = [];
        while ($row = $result->fetch_assoc()) {
            foreach (\Prosper202\Goals\MysqlGoalRepository::afterOf((string) $row['definition']) as $prerequisite) {
                $waitsOn[$prerequisite][] = (int) $row['goal_id'];
            }
        }
        $stmt->close();

        $out = [];
        $pending = $waitsOn[$goalId] ?? [];
        while ($pending !== []) {
            $id = array_shift($pending);
            if (isset($out[$id]) || $id === $goalId) {
                continue;
            }
            $out[$id] = true;
            foreach ($waitsOn[$id] ?? [] as $next) {
                $pending[] = $next;
            }
        }
        $ids = array_map('intval', array_keys($out));
        sort($ids);

        return $ids;
    }

    /** The JSON of a live goal's current version, or null. */
    private function currentDefinition(int $goalId): ?string
    {
        $stmt = $this->prepare(
            'SELECT v.definition FROM 202_goals g
             JOIN 202_goal_versions v ON v.goal_id = g.goal_id AND v.version = g.current_version
             WHERE g.goal_id = ? AND g.user_id = ? AND g.archived_at IS NULL LIMIT 1'
        );
        $this->bind($stmt, 'ii', $goalId, $this->userId);
        $this->execute($stmt, 'Goal lookup failed');
        $result = $this->result($stmt);
        $row = $result->fetch_assoc();
        $stmt->close();

        return is_array($row) ? (string) $row['definition'] : null;
    }
}
