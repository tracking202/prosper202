<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\Notifications\NotificationOutbox;

/**
 * The one writer of 202_destination_deliveries (DeliveryRowsWrittenOnlyByTheOwnerTest).
 *
 * Every statement that changes a row's status names the states it may leave
 * in its own WHERE (DeliveryStatus::next()), so a row someone else moved
 * first is left as they left it and the caller is told (false, or 0 rows).
 *
 * A sender holds a row by its claim: the try it counted (attempts). What it
 * writes when the send did not deliver is refused once the claim is no
 * longer the row's (a later run claimed it, or it left pending). A send that
 * delivered is recorded from any state but sent: the platform has it.
 */
final class DeliveryRows
{
    public const DESTINATION_DELETED = 'the destination was deleted before this was sent';

    /** Back to the queue as a new row is: every try, due now, nothing said of the last send. */
    private const REQUEUE = ' SET status = ?, attempts = 0, next_attempt_at = ?, last_status_code = NULL, last_error = NULL, sent_at = NULL';

    public function __construct(private readonly Connection $conn)
    {
    }

    /** Queue a conversion for a destination, once: 1 when it was queued, 0 when it already was. */
    public function queue(int $userId, int $destinationId, int $convId, int $now): int
    {
        // ON DUPLICATE KEY, not IGNORE: a replayed conversion queues once, and nothing is truncated in silence.
        $stmt = $this->conn->prepareWrite('INSERT INTO `' . TableRegistry::DESTINATION_DELIVERIES
            . '` (user_id, destination_id, conv_id, kind, status, attempts, next_attempt_at, created_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE delivery_id = delivery_id');
        $this->conn->bind($stmt, 'iiissii', [$userId, $destinationId, $convId, DestinationQueue::KIND_CONVERSION, DeliveryStatus::Pending->value, $now, $now]);

        return $this->conn->executeUpdate($stmt);
    }

    /**
     * Log a test send as it ended. Returns its delivery id.
     *
     * @param array<string, mixed> $summary
     */
    public function logTest(int $userId, int $destinationId, SendResult $result, array $summary, int $now): int
    {
        $stmt = $this->conn->prepareWrite('INSERT INTO `' . TableRegistry::DESTINATION_DELIVERIES
            . '` (user_id, destination_id, conv_id, kind, status, attempts, next_attempt_at, last_status_code, last_error, summary, created_at, sent_at)
             VALUES (?, ?, NULL, ?, ?, 1, ?, ?, ?, ?, ?, ?)');
        $status = $result->delivered ? DeliveryStatus::Sent : DeliveryStatus::Failed;
        $this->conn->bind($stmt, 'iissiissii', [$userId, $destinationId, DestinationQueue::KIND_TEST, $status->value, $now,
            $result->status, self::clip($result->reason), self::summaryJson($summary), $now,
            $status === DeliveryStatus::Sent ? $now : null]);

        return $this->conn->executeInsert($stmt);
    }

    /**
     * Take a pending row for one try: its try counted, and not due again
     * before the try's backoff. $attempts is the count the caller read;
     * false when the row has moved on since.
     */
    public function claim(int $userId, int $deliveryId, int $attempts, int $now): bool
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . '` SET attempts = ?, next_attempt_at = ? WHERE delivery_id = ? AND user_id = ? AND status = ? AND attempts = ?');
        $this->conn->bind($stmt, 'iiiisi', [$attempts + 1, $now + NotificationOutbox::backoff($attempts + 1), $deliveryId, $userId, DeliveryStatus::Pending->value, $attempts]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    /**
     * A claimed row that waits: its try given back, due again at $until.
     * $claimed is the row's attempts as the claim left them.
     */
    public function wait(int $userId, int $deliveryId, int $claimed, SendResult $result, int $until): bool
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . '` SET attempts = ?, next_attempt_at = ?, last_status_code = ?, last_error = ?, summary = COALESCE(?, summary)
             WHERE delivery_id = ? AND user_id = ? AND status = ? AND attempts = ?');
        $this->conn->bind($stmt, 'iiissiisi', [$claimed - 1, $until, $result->status, $result->reason === '' ? null : self::clip($result->reason),
            self::summaryJson($result->summary), $deliveryId, $userId, DeliveryStatus::Pending->value, $claimed]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    /**
     * A claimed row whose try did not deliver and is tried again at the
     * claim's backoff: it stays pending and says why.
     *
     * @param array<string, mixed>|null $summary
     */
    public function retryLater(int $userId, int $deliveryId, int $claimed, ?int $code, string $reason, ?array $summary): bool
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . '` SET last_status_code = ?, last_error = ?, summary = COALESCE(?, summary), sent_at = NULL
             WHERE delivery_id = ? AND user_id = ? AND status = ? AND attempts = ?');
        $this->conn->bind($stmt, 'issiisi', [$code, $reason === '' ? null : self::clip($reason), self::summaryJson($summary ?? []),
            $deliveryId, $userId, DeliveryStatus::Pending->value, $claimed]);
        if ($this->conn->executeUpdate($stmt) === 1) {
            return true;
        }

        // No row changed: the claim is gone, or the same answer came twice.
        return $this->holds($userId, $deliveryId, $claimed);
    }

    /**
     * Move a row to $to: where a send ended, or a cancel. False when the row
     * may not make the move from where it is now, or (a send that did not
     * deliver) $claimed is no longer its claim; it is left as it is.
     *
     * @param int|null $claimed the row's attempts as the caller's claim left them; null when it claimed nothing
     * @param array<string, mixed>|null $summary
     */
    public function finish(int $userId, int $deliveryId, DeliveryStatus $to, ?int $claimed, ?int $code, string $reason, ?array $summary, int $now): bool
    {
        if ($to === DeliveryStatus::Pending) {
            throw new \LogicException('A delivery is put back in the queue by resend(), not finish().');
        }
        $from = DeliveryStatus::whereMayMoveTo($to, 'status');
        $byClaim = $claimed !== null && $to !== DeliveryStatus::Sent;
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . '` SET status = ?, last_status_code = ?, last_error = ?, summary = COALESCE(?, summary), sent_at = ?
             WHERE delivery_id = ? AND user_id = ? AND ' . $from['sql'] . ($byClaim ? ' AND attempts = ?' : ''));
        $this->conn->bind($stmt, 'sissiii' . $from['types'] . ($byClaim ? 'i' : ''), [$to->value, $code, $reason === '' ? null : self::clip($reason),
            self::summaryJson($summary ?? []), $to === DeliveryStatus::Sent ? $now : null, $deliveryId, $userId, ...$from['binds'], ...($byClaim ? [$claimed] : [])]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    /** Put one of a destination's conversions back in the queue with all its tries. Returns how many (0 or 1). */
    public function resendOne(int $userId, int $destinationId, int $deliveryId, int $now): int
    {
        $from = DeliveryStatus::whereMayMoveTo(DeliveryStatus::Pending, 'status');
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES . '`' . self::REQUEUE
            . ' WHERE user_id = ? AND destination_id = ? AND kind = ? AND ' . $from['sql'] . ' AND delivery_id = ?');
        $this->conn->bind($stmt, 'siiis' . $from['types'] . 'i', [DeliveryStatus::Pending->value, $now, $userId, $destinationId,
            DestinationQueue::KIND_CONVERSION, ...$from['binds'], $deliveryId]);

        return $this->conn->executeUpdate($stmt);
    }

    /** Put a destination's conversions in $from, made since $since, back in the queue: at most $limit, oldest first. */
    public function resendAll(int $userId, int $destinationId, DeliveryStatus $from, int $since, int $limit, int $now): int
    {
        $from->moveTo(DeliveryStatus::Pending);
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES . '`' . self::REQUEUE
            . ' WHERE user_id = ? AND destination_id = ? AND kind = ? AND status = ? AND created_at >= ? ORDER BY delivery_id LIMIT ' . max(1, $limit));
        $this->conn->bind($stmt, 'siiissi', [DeliveryStatus::Pending->value, $now, $userId, $destinationId, DestinationQueue::KIND_CONVERSION, $from->value, $since]);

        return $this->conn->executeUpdate($stmt);
    }

    /** Put the account's conversions withheld for $reason back in the queue. Returns how many. */
    public function release(int $userId, WithheldFor $reason, int $now): int
    {
        DeliveryStatus::Withheld->moveTo(DeliveryStatus::Pending);
        // The reason is the row's last_error, whole: the column that says why a row is where it is.
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES . '`' . self::REQUEUE
            . ' WHERE user_id = ? AND kind = ? AND status = ? AND last_error = ?');
        $this->conn->bind($stmt, 'siisss', [DeliveryStatus::Pending->value, $now, $userId, DestinationQueue::KIND_CONVERSION, DeliveryStatus::Withheld->value, $reason->value]);

        return $this->conn->executeUpdate($stmt);
    }

    /** Cancel what a deleted destination had queued and not sent, in the delete's transaction. Returns how many. */
    public function cancelForDeletedDestination(int $userId, int $destinationId): int
    {
        $from = DeliveryStatus::whereMayMoveTo(DeliveryStatus::Cancelled, 'status');
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . '` SET status = ?, last_error = ? WHERE destination_id = ? AND user_id = ? AND ' . $from['sql']);
        $this->conn->bind($stmt, 'ssii' . $from['types'], [DeliveryStatus::Cancelled->value, self::DESTINATION_DELETED, $destinationId, $userId, ...$from['binds']]);

        return $this->conn->executeUpdate($stmt);
    }

    /** Every pending conversion of a destination waits until at least $until. */
    public function holdUntil(int $userId, int $destinationId, int $until): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . '` SET next_attempt_at = GREATEST(next_attempt_at, ?) WHERE destination_id = ? AND user_id = ? AND status = ? AND kind = ?');
        $this->conn->bind($stmt, 'iiiss', [$until, $destinationId, $userId, DeliveryStatus::Pending->value, DestinationQueue::KIND_CONVERSION]);
        $this->conn->executeUpdate($stmt);
    }

    /** A destination's waiting conversions are due now. */
    public function makeDue(int $userId, int $destinationId, int $now): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . '` SET next_attempt_at = ? WHERE destination_id = ? AND user_id = ? AND status = ? AND kind = ? AND next_attempt_at > ?');
        $this->conn->bind($stmt, 'iiissi', [$now, $destinationId, $userId, DeliveryStatus::Pending->value, DestinationQueue::KIND_CONVERSION, $now]);
        $this->conn->executeUpdate($stmt);
    }

    /** Whether the row is still pending under the claim that left its attempts at $claimed. On the primary. */
    private function holds(int $userId, int $deliveryId, int $claimed): bool
    {
        $stmt = $this->conn->prepareWrite('SELECT 1 AS held FROM `' . TableRegistry::DESTINATION_DELIVERIES . '` WHERE delivery_id = ? AND user_id = ? AND status = ? AND attempts = ?');
        $this->conn->bind($stmt, 'iisi', [$deliveryId, $userId, DeliveryStatus::Pending->value, $claimed]);

        return $this->conn->fetchOne($stmt) !== null;
    }

    /** @param array<string, mixed> $summary */
    private static function summaryJson(array $summary): ?string
    {
        if ($summary === []) {
            return null;
        }
        $json = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        // A summary too long for its column is dropped whole, never cut into invalid JSON.
        return strlen($json) <= 1000 ? $json : null;
    }

    /** A reason cut to last_error's width (VARCHAR(255)). */
    public static function clip(string $text): string
    {
        return mb_strimwidth($text, 0, 255, '…', 'UTF-8');
    }
}
