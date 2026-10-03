<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/**
 * What one evaluation produced: the outcomes in the order they were reached,
 * the goal versions that could not be evaluated and why, and the state to
 * continue from.
 */
final class EvaluationResult
{
    /**
     * @param list<Outcome> $outcomes In evaluation order: event order, then
     *        the goals' `after` order within one event.
     * @param list<array{goal_id: int, version: int, reason: string}> $disabled
     */
    public function __construct(
        public readonly array $outcomes,
        public readonly array $disabled,
        public readonly EvaluationState $state,
    ) {
    }

    /** @return array<string, mixed> the vector format */
    public function toArray(): array
    {
        return [
            'outcomes' => array_map(static fn (Outcome $o): array => $o->toArray(), $this->outcomes),
            'disabled' => $this->disabled,
            'progress' => $this->state->progressArray(),
        ];
    }
}
