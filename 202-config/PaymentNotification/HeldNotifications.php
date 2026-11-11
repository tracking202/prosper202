<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\Click\ClickRef;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Notifications refused because they state another currency than the
 * account counts in, kept as the processor read them (never the body, a
 * secret or the buyer) so that they can be recorded once the account counts
 * in that currency (HeldReplay).
 *
 * A provider's retry is the row already held: the row is keyed by what the
 * notification states (CLAUDE.md #15, #17), so one stating another amount
 * is another row, and the processor decides between them when they are
 * recorded.
 */
final class HeldNotifications
{
    /** An account's rows kept; a notification past this is refused as before, and says so. */
    public const KEEP_PER_ACCOUNT = 5000;
    /** How long a row taken for recording stays taken when its request died. */
    public const CLAIM_SECONDS = 300;

    private const TABLE = TableRegistry::HELD_PAYMENT_NOTIFICATIONS;

    public function __construct(private readonly \mysqli $db)
    {
    }

    /**
     * Keeps the notification for the account; false when the account holds
     * KEEP_PER_ACCOUNT already. A row that is held already stays as it is.
     */
    public function hold(
        int $userId,
        ProviderAdapter $provider,
        Notification $n,
        InboundRequest $request,
        int $now
    ): bool {
        if ($n->clickId === null || $n->currency === null) {
            throw new \LogicException('a notification is held for its currency once its click is known');
        }
        $conn = new Connection($this->db);
        $source = $provider->source()->value;
        $currency = strtoupper($n->currency);
        $dedupe = self::dedupe([
            $source, $n->kind->value, $n->transactionId, $n->originalTransactionId, $n->amount, $currency,
            (string) $n->clickId,
        ]);
        $held = $conn->prepareWrite('SELECT held_id FROM ' . self::TABLE . ' WHERE user_id = ? AND dedupe = ? LIMIT 1');
        $conn->bind($held, 'is', [$userId, $dedupe]);
        if ($conn->fetchOne($held) !== null) {
            return true;
        }
        $count = $conn->prepareWrite('SELECT COUNT(*) AS n FROM ' . self::TABLE . ' WHERE user_id = ?');
        $conn->bind($count, 'i', [$userId]);
        if ((int) ($conn->fetchOne($count)['n'] ?? 0) >= self::KEEP_PER_ACCOUNT) {
            return false;
        }
        $key = $provider instanceof FingerprintsNotifications ? $provider->fingerprint($request) : null;
        $stmt = $conn->prepareWrite(
            'INSERT INTO ' . self::TABLE . ' (user_id, source, kind, event, transaction_id, original_transaction_id,'
            . ' amount, currency, click_id, purlink_subid, occurred_at, received_at, notification_key, body_key,'
            . ' dedupe)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE held_id = held_id'
        );
        $conn->bind($stmt, 'isssssssisiisss', [
            $userId, $source, $n->kind->value, mb_substr($n->event, 0, 64, 'UTF-8'), $n->transactionId,
            $n->originalTransactionId, $n->amount, $currency, $n->clickId, $n->purLinkSubid, $n->occurredAt, $now,
            $key, Processor::bodyFingerprint($request->fields), $dedupe,
        ]);
        $conn->executeInsert($stmt);

        return true;
    }

    /**
     * The account's held notifications by source and currency, oldest first.
     *
     * @return array<string, array<string, array{count: int, latest: int}>> source => currency => counts
     */
    public function counts(int $userId): array
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT source, currency, COUNT(*) AS n, MAX(received_at) AS latest FROM ' . self::TABLE
            . ' WHERE user_id = ? GROUP BY source, currency ORDER BY source, currency'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $counts = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $counts[(string) $row['source']][(string) $row['currency']]
                = ['count' => (int) $row['n'], 'latest' => (int) $row['latest']];
        }

        return $counts;
    }

    /**
     * The account's next rows of one source in one currency that nobody is
     * recording: those never tried first, in the order they arrived, then
     * the ones refused longest ago, so a row that stays refused does not
     * keep the rows behind it waiting (CLAUDE.md #37).
     *
     * @return list<array<string, mixed>>
     */
    public function next(int $userId, string $source, string $currency, int $limit, int $now): array
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT held_id, kind, event, transaction_id, original_transaction_id, amount, currency, click_id,'
            . ' purlink_subid, occurred_at, notification_key, body_key FROM ' . self::TABLE
            . ' WHERE user_id = ? AND source = ? AND currency = ?'
            . ' AND (claimed_at IS NULL OR claimed_at < ?) ORDER BY COALESCE(tried_at, 0), held_id LIMIT ?'
        );
        $conn->bind($stmt, 'issii', [$userId, $source, strtoupper($currency), $now - self::CLAIM_SECONDS, $limit]);

        return $conn->fetchAll($stmt);
    }

    /** Takes the row for recording; false when another request has it, or it is gone. */
    public function claim(int $userId, int $heldId, int $now): bool
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareWrite(
            'UPDATE ' . self::TABLE . ' SET claimed_at = ? WHERE held_id = ? AND user_id = ?'
            . ' AND (claimed_at IS NULL OR claimed_at < ?)'
        );
        $conn->bind($stmt, 'iiii', [$now, $heldId, $userId, $now - self::CLAIM_SECONDS]);

        return $conn->executeUpdate($stmt) === 1;
    }

    /** The row is on file as a conversion: it is held no longer. */
    public function done(int $userId, int $heldId): void
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareWrite('DELETE FROM ' . self::TABLE . ' WHERE held_id = ? AND user_id = ?');
        $conn->bind($stmt, 'ii', [$heldId, $userId]);
        $conn->executeUpdate($stmt);
    }

    /** Gives the row back with what the processor said of it. */
    public function release(int $userId, int $heldId, string $answer, int $now): void
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareWrite(
            'UPDATE ' . self::TABLE . ' SET claimed_at = NULL, tried_at = ?, last_answer = ?'
            . ' WHERE held_id = ? AND user_id = ?'
        );
        $conn->bind($stmt, 'isii', [$now, mb_substr($answer, 0, 500, 'UTF-8'), $heldId, $userId]);
        $conn->executeUpdate($stmt);
    }

    /**
     * The notification a row holds.
     *
     * @param array<string, mixed> $row
     */
    public static function notification(array $row): Notification
    {
        $click = $row['purlink_subid'] !== null
            ? ClickRef::parse((string) $row['purlink_subid'])
            : (int) $row['click_id'];
        if ($click === null) {
            throw new \RuntimeException('held notification ' . $row['held_id'] . ' names a click that cannot be read');
        }

        return new Notification(
            NotificationKind::from((string) $row['kind']),
            (string) $row['event'],
            $row['transaction_id'] === null ? null : (string) $row['transaction_id'],
            $row['original_transaction_id'] === null ? null : (string) $row['original_transaction_id'],
            $row['amount'] === null ? null : (string) $row['amount'],
            (string) $row['currency'],
            $click,
            $row['occurred_at'] === null ? null : (int) $row['occurred_at']
        );
    }

    /**
     * Every part length-prefixed, so no value can stand for two (CLAUDE.md #17).
     *
     * @param list<string|null> $parts
     */
    private static function dedupe(array $parts): string
    {
        $joined = '';
        foreach ($parts as $part) {
            $joined .= $part === null ? '-;' : strlen($part) . ':' . $part . ';';
        }

        return hash('sha256', $joined);
    }
}
