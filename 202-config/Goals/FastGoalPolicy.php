<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/**
 * When a goal an install reached came implausibly soon after the install
 * (plan §7.1), and what that does to its payout: an app registration's
 * `fast_goal_seconds` and `fast_goal_policy`.
 *
 * The app token ships inside the app, so anyone can post events for an
 * install and walk it to a payable goal. A person needs time to reach a
 * goal after installing — open the app, sign up, play a level — and a
 * script does not. An outcome of an install subject whose reaching event's
 * effective time is less than `fast_goal_seconds` after the install's time
 * (GoalSubject::$installAt, Google's install-begin) is `too_fast`. The
 * install itself (the `@install` event, which is the install) is never
 * flagged. 0 flags nothing.
 *
 * Whether a flagged outcome pays:
 *
 * - `count` (the default): it pays and notifies like any other, and the
 *   report counts it and filters by it. A threshold is a heuristic — an app
 *   whose first goal is "opened the app" reaches it within seconds — so the
 *   default leaves the money where it was and makes the pattern visible.
 * - `hold`: it is recorded, counted in the funnel and flagged, and neither
 *   paid nor sent to the traffic source; its ledger row (when the install
 *   has a click) is written unpayable, as a tracked outcome is, and the
 *   outcome's `value_note` says `held_too_fast` (HELD_NOTE).
 *
 * The decision is made once, under the policy the registration has when an
 * outcome of that (goal, reaching event) is first written, and it stands
 * (GoalEngine::valuation()'s $decided): there is no release. Restating an
 * outcome, giving it its ledger row when the install's credit changes,
 * reviving it, or replacing it in a re-evaluation (a new goal version, a
 * replay that shifts n) with the same reaching event all keep the stored
 * `too_fast` and held-or-not, whatever the policy says by then. So
 * switching `hold` to `count` pays only outcomes written afterwards, and
 * switching `count` to `hold` never un-pays an outcome already written.
 * Held is read from the note rather than from `too_fast` with
 * `payable = 0`: under `count` a flagged outcome is also unpaid while its
 * install has no credit, and it must pay once the credit arrives.
 *
 * Read strictly (CLAUDE.md #11): a value that is missing or not exactly
 * what a write stores is named in `unreadable` and resolves to the reading
 * that trusts least — the threshold at its ceiling and `hold`.
 */
final class FastGoalPolicy
{
    public const COUNT = 'count';
    public const HOLD = 'hold';
    /** The value_note of an outcome held under `hold` (≤ the column's 32 characters). */
    public const HELD_NOTE = 'held_too_fast';
    public const DEFAULT_SECONDS = 5;
    public const MAX_SECONDS = 3600;

    /** @param list<string> $unreadable */
    private function __construct(
        public readonly int $seconds,
        public readonly bool $hold,
        public readonly array $unreadable,
    ) {
    }

    /** @return list<string> */
    public static function policies(): array
    {
        return [self::COUNT, self::HOLD];
    }

    public static function unreadable(): self
    {
        return self::fromRow(null);
    }

    public static function fromRow(mixed $row): self
    {
        $unreadable = [];
        $seconds = is_array($row) ? self::readSeconds($row['fast_goal_seconds'] ?? null) : null;
        if ($seconds === null) {
            $unreadable[] = 'fast_goal_seconds';
        }
        $policy = is_array($row) ? ($row['fast_goal_policy'] ?? null) : null;
        if ($policy !== self::COUNT && $policy !== self::HOLD) {
            $unreadable[] = 'fast_goal_policy';
            $policy = self::HOLD;
        }

        return new self($seconds ?? self::MAX_SECONDS, $policy === self::HOLD, $unreadable);
    }

    /** An integer, or its canonical digits, from 0 to MAX_SECONDS; null for anything else. */
    public static function readSeconds(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,4})$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > self::MAX_SECONDS) {
            return null;
        }

        return $value;
    }

    /**
     * The fast-goal decision a stored outcome row carries: whether it was
     * too fast, and whether it was held. A held row is too fast whatever its
     * `too_fast` column says. A row read without the columns is a query that
     * cannot answer, and is refused rather than read as "not fast"
     * (CLAUDE.md #2, #11).
     *
     * @param array<string, mixed> $row a 202_goal_outcomes row
     * @return array{0: bool, 1: bool} too fast, held
     */
    public static function decidedOn(array $row): array
    {
        if (!array_key_exists('too_fast', $row) || !array_key_exists('value_note', $row)) {
            throw new \LogicException('an outcome row read without too_fast and value_note cannot carry its fast-goal decision');
        }
        $held = $row['value_note'] === self::HELD_NOTE;

        return [$held || (int) $row['too_fast'] === 1, $held];
    }

    /**
     * Whether an outcome of this install subject was reached too fast. The
     * install goal's own outcome (the `@install` event) never is.
     */
    public function tooFast(Outcome $outcome, int $installAt): bool
    {
        if ($outcome->eventId === GoalEvent::INSTALL_EVENT_ID || $this->seconds === 0) {
            return false;
        }

        return $outcome->reachedAt - $installAt < $this->seconds;
    }
}
