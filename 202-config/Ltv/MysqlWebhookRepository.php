<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * Outbound LTV webhooks: endpoint CRUD, SSRF validation, the delivery queue,
 * and the dispatch-side helpers used by 202-cronjobs/ltv_webhooks.php.
 *
 * Enqueueing is a plain DB insert (safe inside request handling); actual HTTP
 * delivery happens ONLY in the cron dispatcher — never inline in ingest or
 * API writes.
 */
final class MysqlWebhookRepository
{
    public const EVENTS = ['customer.updated', 'revenue.recorded', 'subscription.changed'];

    /** Max delivery attempts before a delivery is failed and the hook may die. */
    public const MAX_ATTEMPTS = 6;

    public function __construct(private Connection $conn)
    {
    }

    /**
     * SSRF guard, applied at registration AND again at dispatch (DNS can
     * change between the two): https only, resolvable host, and no
     * private/loopback/link-local/reserved addresses.
     *
     * @throws RuntimeException with the reason when the URL is not allowed
     */
    public static function assertUrlAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('webhook_url must be a valid https:// URL');
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [443, 8443], true)) {
            throw new RuntimeException('webhook_url port must be 443 or 8443');
        }

        $host = (string) $parts['host'];
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = (string) $record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = (string) $record['ipv6'];
                    }
                }
            }
        }
        if ($ips === []) {
            throw new RuntimeException('webhook_url host does not resolve');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('webhook_url resolves to a private or reserved address');
            }
        }
    }

    /**
     * Compute the signature header value for a payload body.
     */
    public static function signature(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT webhook_id, webhook_url, subscribed_events, status, created_at, updated_at
             FROM 202_ltv_webhooks WHERE user_id = ? ORDER BY webhook_id ASC'
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        return $this->conn->fetchAll($stmt);
    }

    /**
     * Register a webhook endpoint. Returns [webhook_id, secret] — the secret
     * is generated server-side and shown once.
     *
     * @param list<string> $events subset of self::EVENTS
     * @return array{webhookId: int, secret: string}
     */
    public function create(int $userId, string $url, array $events): array
    {
        self::assertUrlAllowed($url);

        $events = array_values(array_unique(array_map(strval(...), $events)));
        if ($events === []) {
            $events = self::EVENTS;
        }
        foreach ($events as $event) {
            if (!in_array($event, self::EVENTS, true)) {
                throw new RuntimeException(
                    'Unknown webhook event "' . $event . '"; expected any of: ' . implode(', ', self::EVENTS)
                );
            }
        }

        $secret = bin2hex(random_bytes(24));
        $now = time();

        $stmt = $this->conn->prepareWrite(
            "INSERT INTO 202_ltv_webhooks
                (user_id, webhook_url, webhook_secret, webhook_headers, subscribed_events, status, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, 'active', ?, ?)"
        );
        $this->conn->bind($stmt, 'isssii', [$userId, $url, $secret, implode(',', $events), $now, $now]);
        $webhookId = $this->conn->executeInsert($stmt);

        return ['webhookId' => $webhookId, 'secret' => $secret];
    }

    public function delete(int $userId, int $webhookId): void
    {
        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_ltv_webhook_deliveries WHERE webhook_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$webhookId, $userId]);
        $this->conn->executeUpdate($stmt);

        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_ltv_webhooks WHERE webhook_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$webhookId, $userId]);
        if ($this->conn->executeUpdate($stmt) === 0) {
            throw new RuntimeException('Webhook not found');
        }
    }

    /**
     * Queue one event for every active webhook subscribed to it. Plain DB
     * inserts — the cron does the HTTP. A json_encode failure throws (never
     * silently queue a broken payload).
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(int $userId, string $eventName, array $payload): void
    {
        if (!in_array($eventName, self::EVENTS, true)) {
            throw new RuntimeException('Unknown webhook event: ' . $eventName);
        }

        $stmt = $this->conn->prepareRead(
            "SELECT webhook_id FROM 202_ltv_webhooks
             WHERE user_id = ? AND status = 'active'
               AND (subscribed_events = '' OR FIND_IN_SET(?, subscribed_events) > 0)"
        );
        $this->conn->bind($stmt, 'is', [$userId, $eventName]);
        $webhooks = $this->conn->fetchAll($stmt);
        if ($webhooks === []) {
            return;
        }

        $body = json_encode(['event' => $eventName, 'occurred_at' => time(), 'data' => $payload]);
        if ($body === false) {
            throw new RuntimeException('Failed to encode webhook payload for ' . $eventName);
        }

        $now = time();
        foreach ($webhooks as $webhook) {
            $ins = $this->conn->prepareWrite(
                "INSERT INTO 202_ltv_webhook_deliveries
                    (webhook_id, user_id, event_name, payload, status, attempts, next_attempt_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'pending', 0, ?, ?, ?)"
            );
            $this->conn->bind($ins, 'iissiii', [
                (int) $webhook['webhook_id'],
                $userId,
                $eventName,
                $body,
                $now,
                $now,
                $now,
            ]);
            $this->conn->execute($ins);
            $ins->close();
        }
    }

    /**
     * Dispatch-side: claim due pending deliveries (joined to their endpoint).
     *
     * @return list<array<string, mixed>>
     */
    public function duePending(int $limit): array
    {
        $stmt = $this->conn->prepareRead(
            "SELECT d.delivery_id, d.webhook_id, d.user_id, d.event_name, d.payload, d.attempts,
                    w.webhook_url, w.webhook_secret, w.status AS webhook_status
             FROM 202_ltv_webhook_deliveries d
             JOIN 202_ltv_webhooks w ON w.webhook_id = d.webhook_id
             WHERE d.status = 'pending' AND d.next_attempt_at <= ?
             ORDER BY d.next_attempt_at ASC
             LIMIT ?"
        );
        $this->conn->bind($stmt, 'ii', [time(), $limit]);

        return $this->conn->fetchAll($stmt);
    }

    /**
     * Dispatch-side: record one attempt's outcome. On success the delivery is
     * delivered; on failure it backs off exponentially (2^attempts minutes)
     * until MAX_ATTEMPTS, then is marked failed and the endpoint is marked
     * dead (visible state, no silent infinite retry).
     */
    public function recordAttempt(int $deliveryId, int $webhookId, bool $success, ?int $statusCode, string $responseBody): void
    {
        $now = time();
        $truncated = substr($responseBody, 0, 1000);

        if ($success) {
            $stmt = $this->conn->prepareWrite(
                "UPDATE 202_ltv_webhook_deliveries
                 SET status = 'delivered', attempts = attempts + 1,
                     last_status_code = ?, last_response_body = ?, updated_at = ?
                 WHERE delivery_id = ?"
            );
            $this->conn->bind($stmt, 'isii', [$statusCode, $truncated, $now, $deliveryId]);
            $this->conn->executeUpdate($stmt);
            return;
        }

        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_ltv_webhook_deliveries
             SET attempts = attempts + 1,
                 status = IF(attempts + 1 >= ?, 'failed', 'pending'),
                 next_attempt_at = ? + (POW(2, LEAST(attempts + 1, 10)) * 60),
                 last_status_code = ?, last_response_body = ?, updated_at = ?
             WHERE delivery_id = ?"
        );
        $this->conn->bind($stmt, 'iiisii', [
            self::MAX_ATTEMPTS,
            $now,
            $statusCode,
            $truncated,
            $now,
            $deliveryId,
        ]);
        $this->conn->executeUpdate($stmt);

        // If this delivery just exhausted its attempts, kill the endpoint so
        // the operator sees it (they can re-activate after fixing).
        $check = $this->conn->prepareRead(
            "SELECT status FROM 202_ltv_webhook_deliveries WHERE delivery_id = ? LIMIT 1"
        );
        $this->conn->bind($check, 'i', [$deliveryId]);
        $row = $this->conn->fetchOne($check);
        if ($row !== null && (string) $row['status'] === 'failed') {
            $kill = $this->conn->prepareWrite(
                "UPDATE 202_ltv_webhooks SET status = 'dead', updated_at = ? WHERE webhook_id = ? AND status = 'active'"
            );
            $this->conn->bind($kill, 'ii', [$now, $webhookId]);
            $this->conn->executeUpdate($kill);
        }
    }
}
