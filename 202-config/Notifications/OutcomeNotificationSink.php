<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

/**
 * Where the goal engine tells traffic sources about outcomes (plan §5.10).
 *
 * The engine calls both methods inside the transaction that writes the
 * ledger row, so an implementation must write, not send: what it records
 * commits or rolls back with the row it announces. NotificationOutbox is
 * the durable implementation (queued, retried, corrected). The seam exists
 * so the web-events path's notifier can be reconciled onto the same outbox
 * rather than a second, parallel way to tell a network about an outcome.
 */
interface OutcomeNotificationSink
{
    /**
     * A new ledger row reached a notifying goal. Returns how many
     * notifications were recorded.
     */
    public function queueReached(
        int $userId,
        int $convId,
        int $clickId,
        string $goalName,
        string $amount,
        string $dedupeKey,
        ?string $transactionId,
    ): int;

    /**
     * Ledger row $oldConvId was replaced by $newConvId, or retired with no
     * replacement ($newConvId null).
     */
    public function onReplaced(int $userId, int $oldConvId, ?int $newConvId): void;

    /**
     * Ledger row $convId, which the engine had retired, counts again: its
     * outcome was revived (plan §5.7 (1)). Never a second `reached`.
     */
    public function onRevived(int $userId, int $convId): void;

    /**
     * Ledger row $newConvId is new, and $priorConvIds are the other rows
     * ever written for its (subject, goal, n), retired ones included (plan
     * §5.7 (2)). Returns whether it was withheld anywhere because one of
     * them had been announced there.
     *
     * @param list<int> $priorConvIds
     */
    public function onAnnouncedBefore(int $userId, int $newConvId, array $priorConvIds): bool;
}
