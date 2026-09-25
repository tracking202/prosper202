<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

/**
 * Where the goal engine tells traffic sources about outcomes (plan §5.8,
 * §5.10): every server-to-server postback for a goal outcome, web (PR 4b)
 * and app (PR 5) alike, is written here and sent by the worker.
 *
 * The engine calls these methods inside the transaction that writes the
 * ledger row, so an implementation must write, not send: what it records
 * commits or rolls back with the row it announces. NotificationOutbox is
 * the durable implementation (queued, retried, corrected).
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
        ?int $goalId = null,
    ): int;

    /**
     * Ledger row $oldConvId was replaced by $newConvId, or retired with no
     * replacement ($newConvId null).
     */
    public function onReplaced(int $userId, int $oldConvId, ?int $newConvId): void;

    /**
     * The event that reached $newConvId's outcome had reached the same goal
     * in the retired outcomes whose rows are $priorConvIds. Returns whether
     * $newConvId's announcement was withheld because one of those was
     * already announced.
     *
     * @param list<int> $priorConvIds
     */
    public function onEventMoved(int $userId, int $newConvId, array $priorConvIds): bool;

    /**
     * @return array{total: int, live: int, pending: int} the `reached` rows
     *         for the conversion: all, not cancelled, still to send
     */
    public function reachedState(int $convId): array;
}
