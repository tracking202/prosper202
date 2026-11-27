<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use Prosper202\Lifecycle\UnknownState;

/**
 * Whether a redirector's rule takes visitors: `202_rotator_rules.status`,
 * an int that is 1, 0 or NULL. The redirects try a rule only when it is 1,
 * so a rule with no status (one stored before 1.9, which nothing writes any
 * more) is passed by like one switched off; every reader asks
 * matchesTraffic() rather than deciding what NULL means for itself
 * (CLAUDE.md #25).
 *
 * Not one of the string-backed lifecycles of Prosper202\Lifecycle: no backed
 * enum has a NULL case. next() is the same kind of table.
 */
enum RuleStatus
{
    case Active;
    case Inactive;
    /** NULL: found on old rows, never written to a rule that has a status. */
    case Unset;

    /**
     * The state a stored value names, or null for one that names none (the
     * column takes any int). A hydration path decides per record on that
     * null (CLAUDE.md #24); storedMatchesTraffic() is its answer where the
     * question is the redirects'.
     */
    public static function tryOfStored(mixed $value): ?self
    {
        return match (true) {
            $value === 1, $value === '1' => self::Active,
            $value === 0, $value === '0' => self::Inactive,
            $value === null => self::Unset,
            default => null,
        };
    }

    /** As tryOfStored(), refusing a value that names no state; $where names the record in the refusal. */
    public static function ofStored(mixed $value, string $where): self
    {
        return self::tryOfStored($value) ?? throw UnknownState::of(self::class, $value, $where);
    }

    /**
     * The state a request body asks for: 0 or 1, as a number or its digit.
     * null and '' send none, which is null here; a new rule is then
     * created Active and a kept one keeps its own.
     *
     * @throws \InvalidArgumentException for any other value
     */
    public static function ofSent(mixed $value): ?self
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value === 1, $value === '1' => self::Active,
            $value === 0, $value === '0' => self::Inactive,
            default => throw new \InvalidArgumentException('A rule\'s status is 0 or 1.'),
        };
    }

    /** The column's value. */
    public function stored(): ?int
    {
        return match ($this) {
            self::Active => 1,
            self::Inactive => 0,
            self::Unset => null,
        };
    }

    /** Whether the redirects try the rule. */
    public function matchesTraffic(): bool
    {
        return $this === self::Active;
    }

    /** matchesTraffic() of a stored value; one that names no state takes no visitors (CLAUDE.md #11). */
    public static function storedMatchesTraffic(mixed $value): bool
    {
        return self::tryOfStored($value)?->matchesTraffic() === true;
    }

    /** matchesTraffic() as a condition on the status column, for the redirects' read. */
    public static function matchesTrafficSql(string $column): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $column) !== 1) {
            throw new \InvalidArgumentException('matchesTrafficSql() takes a column name, not "' . $column . '".');
        }

        return $column . ' = ' . self::Active->stored();
    }

    /** The word the pages, the notices and Slack use. */
    public function word(): string
    {
        return $this->matchesTraffic() ? 'active' : 'inactive';
    }

    /**
     * The states this one may be switched to. Nothing becomes Unset.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Active => [self::Inactive],
            self::Inactive => [self::Active],
            self::Unset => [self::Active, self::Inactive],
        };
    }

    /**
     * The states a rule is created in.
     *
     * @return list<self>
     */
    public static function initial(): array
    {
        return [self::Active, self::Inactive];
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** @throws \DomainException for a move next() does not list */
    public function moveTo(self $to): self
    {
        if (!$this->canMoveTo($to)) {
            throw new \DomainException('A redirector rule\'s status ' . $this->name . ' may not become ' . $to->name . '.');
        }

        return $to;
    }
}
