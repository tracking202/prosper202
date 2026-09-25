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
}
