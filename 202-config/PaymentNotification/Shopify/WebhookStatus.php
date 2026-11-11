<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Whether an account's Shopify webhooks are arriving, read from the inbound
 * log: the last one its secret verified, the last order recorded, and a
 * later one no stored secret verified. That last is logged under no account
 * (nothing proves whose it is), so only its time is read, never its fields.
 */
final class WebhookStatus
{
    /** An unverified webhook older than this says nothing about the secret stored now. */
    public const UNVERIFIED_WINDOW = 7 * 86400;

    /**
     * @param array{at: int, outcome: string, reason: string, topic: string}|null $last the last verified webhook
     * @param int|null $lastOrderAt when the last order was recorded
     * @param int|null $unverifiedAt a webhook no secret verified, newer than $last
     */
    private function __construct(
        public readonly ?array $last,
        public readonly ?int $lastOrderAt,
        public readonly ?int $unverifiedAt,
    ) {
    }

    public static function of(\mysqli $db, int $account, ?int $now = null): self
    {
        $now ??= time();
        $conn = new Connection($db);
        $log = TableRegistry::INBOUND_LOG;
        $stmt = $conn->prepareRead(
            "SELECT inbound_id, received_at, outcome, reason, fields FROM $log"
            . " WHERE user_id = ? AND endpoint = 'shopify' AND method = 'POST' ORDER BY inbound_id DESC LIMIT 1"
        );
        $conn->bind($stmt, 'i', [$account]);
        $row = $conn->fetchOne($stmt);
        $last = null;
        if ($row !== null) {
            $fields = is_string($row['fields']) ? json_decode($row['fields'], true) : null;
            $last = [
                'at' => (int) $row['received_at'],
                'outcome' => (string) $row['outcome'],
                'reason' => (string) $row['reason'],
                'topic' => is_array($fields) && is_string($fields['topic'] ?? null) ? $fields['topic'] : '',
            ];
        }
        $stmt = $conn->prepareRead(
            "SELECT received_at FROM $log WHERE user_id = ? AND accepted = 1 AND endpoint = 'shopify'"
            . " AND method = 'POST' AND outcome = 'recorded' ORDER BY inbound_id DESC LIMIT 1"
        );
        $conn->bind($stmt, 'i', [$account]);
        $order = $conn->fetchOne($stmt);

        // Requests no account was found for are kept under user 0, at most InboundLog::KEEP_UNATTRIBUTED of them.
        $stmt = $conn->prepareRead(
            "SELECT received_at FROM $log WHERE user_id = 0 AND endpoint = 'shopify' AND outcome = 'unverified'"
            . ' AND inbound_id > ? AND received_at > ? ORDER BY inbound_id DESC LIMIT 1'
        );
        $conn->bind($stmt, 'ii', [$row === null ? 0 : (int) $row['inbound_id'], $now - self::UNVERIFIED_WINDOW]);
        $unverified = $conn->fetchOne($stmt);

        return new self(
            $last,
            $order === null ? null : (int) $order['received_at'],
            $unverified === null ? null : (int) $unverified['received_at'],
        );
    }

    /** "5 minutes ago", for a time in the past. */
    public static function ago(int $at, ?int $now = null): string
    {
        $seconds = max(0, ($now ?? time()) - $at);
        foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$size, $unit]) {
            if ($seconds >= $size) {
                $count = intdiv($seconds, $size);

                return $count . ' ' . $unit . ($count === 1 ? '' : 's') . ' ago';
            }
        }

        return 'just now';
    }

    /** Shopify's name in its admin for a webhook topic, or the topic itself. */
    public static function topicName(string $topic): string
    {
        return [
            'orders/paid' => 'Order payment',
            'orders/create' => 'Order creation',
            'refunds/create' => 'Refund create',
            'order_transactions/create' => 'Transaction creation',
        ][$topic] ?? $topic;
    }
}
