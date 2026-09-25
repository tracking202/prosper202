<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/**
 * Told, after the engine's transaction commits, about the outcomes it wrote
 * (plan §5.5, "notify traffic source"). Runs post-commit and must never
 * make the write look failed: the engine catches what it throws, logs it
 * and reports the notices as `failed`.
 *
 * A notice is one written outcome:
 *   goal_id, version, n, event_id, outcome_id, conversion_id (int|null),
 *   payable (bool), amount (decimal string|null), transaction_id
 *   (string|null), dedupe_key (string|null), and `kind`, the engine's
 *   decision:
 *     reached     the first row of an outcome, payable, newly recorded on
 *                 the ledger, on a campaign that notifies: send it;
 *     suppressed  a payable outcome that must not be sent, with `reason`:
 *                 `replacement` (a replay or re-evaluation wrote it in place
 *                 of an outcome the traffic source may already have heard
 *                 about, and a delivered postback cannot be recalled; or
 *                 its event already reached the goal in an outcome the same
 *                 replay retires, when a late event shifted n),
 *                 `no_click` (an install with no click has no traffic
 *                 source);
 *     off         payable, but the campaign does not notify for this goal;
 *     none        not payable: nothing is ever sent for it.
 */
interface OutcomeNotifier
{
    /**
     * @param list<array<string, mixed>> $notices
     * @return list<array<string, mixed>> one entry per notice: the notice's
     *         goal_id, n, outcome_id and kind, plus what was sent
     */
    public function notify(int $userId, GoalSubject $subject, array $notices): array;
}
