<?php

declare(strict_types=1);

namespace Prosper202\Lifecycle;

/**
 * What every lifecycle enum answers from its transition table (State::next()).
 *
 * @phpstan-require-implements State
 */
trait Moves
{
    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** A state nothing leaves. */
    public function isTerminal(): bool
    {
        return $this->next() === [];
    }

    /**
     * The move, for a state held in memory: $to, or a refusal naming both.
     *
     * @throws IllegalTransition
     */
    public function moveTo(self $to): static
    {
        if (!$this->canMoveTo($to)) {
            throw IllegalTransition::between($this, $to);
        }

        return $to;
    }

    /**
     * Every state that may move to $to: the from-states an UPDATE to $to
     * names in its own WHERE, so the statement is the check.
     *
     * @return list<static>
     */
    public static function sources(self $to): array
    {
        $sources = [];
        foreach (self::cases() as $case) {
            if ($case->canMoveTo($to)) {
                $sources[] = $case;
            }
        }

        return $sources;
    }

    /**
     * `<column> IN (?, …)` for the states that may move to $to, with their
     * values to bind (all strings). A state nothing moves to has no sources:
     * that is a mistake in the caller, never an UPDATE that matches no row.
     *
     * @return array{sql: string, binds: list<string>, types: string}
     */
    public static function whereMayMoveTo(self $to, string $column): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1) {
            throw new \InvalidArgumentException('whereMayMoveTo() takes a column name, not "' . $column . '".');
        }
        $binds = [];
        foreach (self::sources($to) as $source) {
            $binds[] = (string) $source->value;
        }
        if ($binds === []) {
            throw new \LogicException('No state of ' . self::class . ' may move to ' . $to->value . '.');
        }

        return [
            'sql' => $column . ' IN (' . implode(', ', array_fill(0, count($binds), '?')) . ')',
            'binds' => $binds,
            'types' => str_repeat('s', count($binds)),
        ];
    }

    /**
     * A stored value as its state. A value no case names is refused with the
     * record it was read from, never read as some default state.
     *
     * @throws UnknownState
     */
    public static function ofStored(mixed $value, string $where): static
    {
        $state = is_string($value) ? self::tryFrom($value) : null;
        if ($state === null) {
            throw UnknownState::of(self::class, $value, $where);
        }

        return $state;
    }
}
