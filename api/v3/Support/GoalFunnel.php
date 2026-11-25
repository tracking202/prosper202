<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Prosper202\Database\Connection;

/**
 * A report's `goals`: up to MAX_GOALS of the account's goals, in funnel
 * order, counted for the clicks each report row holds.
 *
 * A step's figures are over the row's clicks, whenever the click reached
 * the goal (a cohort, not the goals reached in the window):
 *  - reached: the clicks that reached this goal;
 *  - continued: the clicks that reached this goal and every step before it,
 *    in whatever order they reached them;
 *  - rate: reached per 100 of the row's clicks;
 *  - step_rate: continued per 100 of the step before's continued (of the
 *    row's clicks for the first step);
 *  - drop_off: the step before's continued less this one's (the row's
 *    clicks less the first step's).
 *
 * A click reaches a goal through a live outcome (superseded_at IS NULL,
 * as MysqlGoalRepository::outcomeFilter() reads them) whose subject is the
 * click or one of its PurLink subjects. An install's outcomes are the app
 * report's: an install is not a row of 202_dataengine.
 */
final class GoalFunnel
{
    public const MAX_GOALS = 10;

    /**
     * @param list<array{goal_id: int, name: string, scope: string, scope_id: int, archived: bool}> $steps
     */
    private function __construct(private readonly int $userId, private readonly array $steps)
    {
    }

    /**
     * The funnel a report's `goals` names, or null when it names none ('' or
     * absent). Anything but a list of distinct whole-number goal ids, or an
     * id that is not one of the account's goals, is a 422 naming `goals`.
     */
    public static function fromParam(Connection $conn, int $userId, mixed $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : null);
        if ($text === null) {
            self::refuse('Takes one value: goal ids, comma separated, in funnel order');
        }
        $ids = [];
        foreach (explode(',', $text) as $part) {
            $part = trim($part);
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $part) !== 1 || (int) $part > 4294967295) {
                self::refuse('Goal ids, comma separated, in funnel order: "' . $part
                    . '" is not a goal id (`p202 goal list` lists them)');
            }
            $id = (int) $part;
            if (isset($ids[$id])) {
                self::refuse('Goal ' . $id . ' is named twice; each step is one goal');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > self::MAX_GOALS) {
            self::refuse('At most ' . self::MAX_GOALS . ' goals, got ' . count($ids));
        }

        $list = array_values($ids);
        $stmt = $conn->prepareRead(
            'SELECT goal_id, name, scope, scope_id, archived_at FROM 202_goals WHERE user_id = ? AND goal_id IN ('
            . implode(',', array_fill(0, count($list), '?')) . ')'
        );
        $conn->bind($stmt, 'i' . str_repeat('i', count($list)), [$userId, ...$list]);
        $found = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $found[(int) $row['goal_id']] = $row;
        }
        $missing = array_values(array_diff($list, array_keys($found)));
        if ($missing !== []) {
            throw new ValidationException('Unknown goal', [
                'goals' => 'Not one of this account\'s goals: ' . implode(', ', $missing)
                    . ' (`p202 goal list` lists them)',
            ]);
        }

        $steps = [];
        foreach ($list as $id) {
            $row = $found[$id];
            $steps[] = [
                'goal_id' => $id,
                'name' => (string) $row['name'],
                'scope' => (string) $row['scope'],
                'scope_id' => (int) $row['scope_id'],
                'archived' => $row['archived_at'] !== null,
            ];
        }

        return new self($userId, $steps);
    }

    /**
     * The steps as an answer names them, in funnel order.
     *
     * @return list<array{goal_id: int, name: string, scope: string, scope_id: int, archived: bool}>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * The join onto 202_dataengine `de`: one row per click of the account
     * that reached any step, with a 0/1 flag per step (g0, g1, ...). Grouped
     * by the click, so it never repeats a `de` row; a click that reached no
     * step has no row, and its flags read NULL. A PurLink outcome whose
     * subject row is gone names no click (its click_id is NULL).
     */
    public function join(): string
    {
        $flags = [];
        foreach (array_keys($this->steps) as $i) {
            $flags[] = "MAX(o.goal_id = ?) AS g$i";
        }
        $ids = implode(',', array_fill(0, count($this->steps), '?'));

        return "LEFT JOIN (
                SELECT o.user_id,
                    CASE WHEN o.subject_type = 'click' THEN o.subject_id ELSE pls.click_id END AS click_id,
                    " . implode(",\n                    ", $flags) . "
                FROM 202_goal_outcomes o
                LEFT JOIN 202_purlink_goal_subjects pls ON o.subject_type = 'purlink'
                    AND pls.subject_id = o.subject_id AND pls.user_id = o.user_id
                WHERE o.user_id = ? AND o.superseded_at IS NULL
                    AND o.subject_type IN ('click', 'purlink') AND o.goal_id IN ($ids)
                GROUP BY o.user_id, CASE WHEN o.subject_type = 'click' THEN o.subject_id ELSE pls.click_id END
            ) gf ON gf.user_id = de.user_id AND gf.click_id = de.click_id";
    }

    /**
     * join()'s binds, in the order its placeholders appear.
     *
     * @return array{0: string, 1: list<int>}
     */
    public function joinBinds(): array
    {
        $ids = array_map(static fn (array $s): int => $s['goal_id'], $this->steps);
        $n = count($ids);

        return [str_repeat('i', $n) . 'i' . str_repeat('i', $n), [...$ids, $this->userId, ...$ids]];
    }

    /**
     * The aggregates a grouped query over join() selects: per step,
     * goal_reached_<i> and goal_continued_<i>.
     */
    public function select(): string
    {
        $out = [];
        $chain = [];
        foreach (array_keys($this->steps) as $i) {
            $chain[] = "gf.g$i";
            $out[] = "COALESCE(SUM(gf.g$i), 0) AS goal_reached_$i";
            $out[] = 'COALESCE(SUM(' . implode(' * ', $chain) . "), 0) AS goal_continued_$i";
        }

        return implode(",\n                ", $out);
    }

    /**
     * The steps of one row from select()'s columns and the row's clicks,
     * and the row without those columns.
     *
     * @param array<string, mixed> $row
     * @return array{0: array<string, mixed>, 1: list<array<string, int|float>>}
     */
    public function take(array $row, int $clicks): array
    {
        $out = [];
        $before = $clicks;
        foreach (array_keys($this->steps) as $i) {
            $reached = self::count($row, "goal_reached_$i");
            $continued = self::count($row, "goal_continued_$i");
            unset($row["goal_reached_$i"], $row["goal_continued_$i"]);
            $out[] = [
                'goal_id' => $this->steps[$i]['goal_id'],
                'reached' => $reached,
                'continued' => $continued,
                'rate' => self::percent($reached, $clicks),
                'step_rate' => self::percent($continued, $before),
                'drop_off' => $before - $continued,
            ];
            $before = $continued;
        }

        return [$row, $out];
    }

    private static function refuse(string $why): never
    {
        throw new ValidationException('Invalid goals', ['goals' => $why]);
    }

    private static function count(array $row, string $column): int
    {
        if (!array_key_exists($column, $row)) {
            throw new DatabaseException("Funnel row has no $column");
        }
        $value = $row[$column];
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new DatabaseException("Funnel column $column is not a count");
        }

        return (int) $value;
    }

    /** $part per 100 of $whole, to four places; 0 of nothing. */
    private static function percent(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 4) : 0.0;
    }
}
