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
 *   has a click) is written unpayable, as a tracked outcome is. There is no
 *   release: an outcome held stays held, and the decision is the
 *   registration's when the outcome is written, like every snapshot the
 *   intake takes.
 *
 * Read strictly (CLAUDE.md #11): a value that is missing or not exactly
 * what a write stores is named in `unreadable` and resolves to the reading
 * that trusts least — the threshold at its ceiling and `hold`.
 */
final class FastGoalPolicy
{
    public const COUNT = 'count';
    public const HOLD = 'hold';
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
