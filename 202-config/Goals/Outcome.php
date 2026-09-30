<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Conversion\Ledger\Amount;

/**
 * The n-th time a goal version was reached by a subject, and by which
 * event. The evaluator's output: what the goal says it is worth, before any
 * campaign decides whether and how much to pay for it.
 */
final class Outcome
{
    public const SOURCE_FIXED = 'fixed';
    public const SOURCE_PROPERTY = 'property';
    public const SOURCE_NONE = 'none';

    public function __construct(
        public readonly int $goalId,
        public readonly int $version,
        public readonly int $n,
        public readonly string $eventId,
        public readonly int $reachedAt,
        /** The goal's value in units of 0.00001; null for `none` or an unusable property. */
        public readonly ?int $valueUnits,
        public readonly string $valueSource,
        /** Why a from_property value could not be read: missing, not_a_number, negative, out_of_range. */
        public readonly ?string $valueNote,
        /** no_click / no_install: the goal's window names an anchor this subject does not have. */
        public readonly ?string $ineligibleReason,
    ) {
    }

    public function isEligible(): bool
    {
        return $this->ineligibleReason === null;
    }

    /** The same outcome: the same event reached the same n, at the same time, for the same value. */
    public function sameAs(self $other): bool
    {
        return $this->goalId === $other->goalId
            && $this->version === $other->version
            && $this->n === $other->n
            && $this->eventId === $other->eventId
            && $this->reachedAt === $other->reachedAt
            && $this->valueUnits === $other->valueUnits
            && $this->valueSource === $other->valueSource
            && $this->valueNote === $other->valueNote
            && $this->ineligibleReason === $other->ineligibleReason;
    }

    /** @return array<string, mixed> the vector format */
    public function toArray(): array
    {
        return [
            'goal_id' => $this->goalId,
            'version' => $this->version,
            'n' => $this->n,
            'event_id' => $this->eventId,
            'reached_at' => $this->reachedAt,
            'value' => $this->valueUnits === null ? null : Amount::fromUnits($this->valueUnits),
            'value_source' => $this->valueSource,
            'value_note' => $this->valueNote,
            'ineligible_reason' => $this->ineligibleReason,
        ];
    }
}
