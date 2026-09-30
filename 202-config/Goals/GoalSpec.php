<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/**
 * One goal as the evaluator sees it: every version it has had, with the
 * time each started to apply, and the span of arrivals it evaluates.
 *
 * Definitions are held raw (decoded JSON) and parsed by the evaluator, so an
 * invalid stored definition disables that one version with its reason and
 * never throws through the other goals (plan §7.1, CLAUDE.md #11).
 */
final class GoalSpec
{
    /**
     * @param list<array{version: int, effective_at: int, definition: mixed}> $versions
     * @param int $startsAt Events received before this are not evaluated
     *        under the goal (unless a re-evaluation rebased the subject).
     * @param int|null $endsAt Events received at or after this are not
     *        evaluated under it: the goal was archived then.
     */
    public function __construct(
        public readonly int $goalId,
        public readonly array $versions,
        public readonly int $startsAt = 0,
        public readonly ?int $endsAt = null,
        /** What the system made this goal for ('install', the built-in install goal), or null for an operator's goal. */
        public readonly ?string $builtin = null,
    ) {
    }
}
