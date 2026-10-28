<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookRefused;
use Prosper202\Database\Connection;
use RuntimeException;

/**
 * Outbound LTV webhooks: endpoint CRUD, SSRF validation, the delivery queue,
 * and the dispatch-side helpers WebhookDispatcher uses.
 *
 * Enqueueing is a plain DB insert (safe inside request handling); actual HTTP
 * delivery happens ONLY in WebhookDispatcher, which the cron runs — never
 * inline in ingest or API writes.
 */
final class MysqlWebhookRepository
{
    /**
     * The events this install sends: every name an emitter passes
     * (LtvController::enqueueEvent(), EventBridge::emit()/emitIfEnabled()),
     * which WebhookEventsAreKnownTest holds it to, and the names a webhook may
     * subscribe to (create() refuses any other). It was a listing only, with
     * well-formedness the only check, so `revenue.recoded` made a hook that
     * subscribed to nothing and answered 201 (CLAUDE.md #4). A subscriber
     * that wants the events later versions add registers '*', which is
     * stored as subscribe-all and needs no edit here; a new emitter adds its
     * name here.
     */
    public const KNOWN_EVENTS = [
        'customer.updated',
        'revenue.recorded',
        'subscription.changed',
        'conversion.recorded',
        'engagement.recorded',
    ];

    /** Max delivery attempts before a delivery is failed and the hook may die. */
    public const MAX_ATTEMPTS = 6;

    /** The ports a webhook URL may name, held at saving and at sending. */
    public const PORTS = [443, 8443];

    public function __construct(private Connection $conn)
    {
    }

    /**
     * Why a webhook URL is refused where one is saved (create(), the
     * Landing Page Optimizer's bridge config), or null: the export
     * webhooks' rules (WebhookGuard) on one of PORTS, all but the lookup,
     * so a resolver that stalls cannot hold a request. DeliverySender runs
     * the whole check, lookup included, when each delivery is sent. An
     * allowlist that does not parse admits nothing here.
     *
     * @param string|null $allowNetworks the operator's allowlist; null reads P202_WEBHOOK_ALLOW_NETWORKS
     */
    public static function urlRefusal(string $url, ?string $allowNetworks = null): ?string
    {
        try {
            $guard = new WebhookGuard(null, $allowNetworks);
        } catch (\UnexpectedValueException) {
            $guard = new WebhookGuard(null, '');
        }

        return $guard->webhookRefusal($url, self::PORTS);
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
     * One endpoint with the columns list() returns — never the secret, which
     * leaves the server once, at creation. Null when the account has none.
     *
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $webhookId): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT webhook_id, webhook_url, subscribed_events, status, created_at, updated_at
             FROM 202_ltv_webhooks WHERE webhook_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$webhookId, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    /**
     * The endpoint's queued and past deliveries by status — the rows
     * delete() removes with it, counted with the same predicate.
     *
     * @return array<string, int> status => count (only statuses present)
     */
    public function deliveryCounts(int $userId, int $webhookId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT status, COUNT(*) AS c FROM 202_ltv_webhook_deliveries
             WHERE webhook_id = ? AND user_id = ? GROUP BY status ORDER BY status'
        );
        $this->conn->bind($stmt, 'ii', [$webhookId, $userId]);
        $counts = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * Well-formedness rule for event names: a namespaced lowercase slug such
     * as "conversion.recorded". What enqueue() checks of an emitter's name
     * (which emitters may send is KNOWN_EVENTS, held by a test); create()
     * also requires the name to be one of KNOWN_EVENTS.
     *
     * @throws RuntimeException when the name is not a namespaced slug
     */
    private static function assertEventName(string $event): void
    {
        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_-]+)+$/', $event) !== 1) {
            throw new LtvInputException(
                'events',
                'Event name must be namespaced lowercase slug, e.g. conversion.recorded'
            );
        }
    }

    /**
     * Why a subscription name is refused, or null when it is one this install
     * sends: a name it never sends would make a hook that receives nothing.
     */
    public static function unknownEventReason(string $event): ?string
    {
        if (in_array($event, self::KNOWN_EVENTS, true)) {
            return null;
        }

        return '"' . $event . '" is not an event this install sends (' . implode(', ', self::KNOWN_EVENTS)
            . '); "*" subscribes to every event, including ones later versions add';
    }

    /**
     * Register a webhook endpoint. Returns [webhook_id, secret] — the secret
     * is generated server-side and shown once.
     *
     * @param list<string> $events names from KNOWN_EVENTS, or ['*'] to
     *        subscribe to every current AND future event — stored as '',
     *        which enqueue() already treats as subscribe-all. [] defaults to
     *        KNOWN_EVENTS. Any other name is refused, nothing written.
     * @return array{webhookId: int, secret: string}
     * @throws WebhookRefused naming why the URL is refused (urlRefusal())
     */
    public function create(int $userId, string $url, array $events): array
    {
        $refused = self::urlRefusal($url);
        if ($refused !== null) {
            throw new WebhookRefused($refused);
        }

        $events = array_values(array_unique(array_map(strval(...), $events)));
        if ($events === []) {
            $events = self::KNOWN_EVENTS;
        }
        if (in_array('*', $events, true)) {
            if ($events !== ['*']) {
                throw new LtvInputException(
                    'events',
                    "The '*' wildcard cannot be combined with individual event names"
                );
            }
            $subscribedEvents = '';
        } else {
            foreach ($events as $event) {
                self::assertEventName($event);
                $unknown = self::unknownEventReason($event);
                if ($unknown !== null) {
                    throw new LtvInputException('events', $unknown);
                }
            }
            $subscribedEvents = implode(',', $events);
        }

        $secret = bin2hex(random_bytes(24));
        $now = time();

        $stmt = $this->conn->prepareWrite(
            "INSERT INTO 202_ltv_webhooks
                (user_id, webhook_url, webhook_secret, webhook_headers, subscribed_events, status, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, 'active', ?, ?)"
        );
        $this->conn->bind($stmt, 'isssii', [$userId, $url, $secret, $subscribedEvents, $now, $now]);
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
            // Typed so callers map exactly this to 404; DB failures above
            // throw plain RuntimeException and must not read as "gone".
            throw new RecordNotFoundException('Webhook not found');
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
        self::assertEventName($eventName);

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
     * Delivery history for one endpoint, newest first — the operator-facing
     * log behind the settings UI (attempt counts, last status codes, backoff
     * schedule). Response bodies are already stored truncated.
     *
     * @return list<array<string, mixed>>
     */
    public function recentDeliveries(int $userId, int $webhookId, int $limit = 25): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT delivery_id, event_name, status, attempts, last_status_code,
                    next_attempt_at, created_at, updated_at
             FROM 202_ltv_webhook_deliveries
             WHERE webhook_id = ? AND user_id = ?
             ORDER BY delivery_id DESC
             LIMIT ?'
        );
        $this->conn->bind($stmt, 'iii', [$webhookId, $userId, max(1, $limit)]);

        return $this->conn->fetchAll($stmt);
    }

    /** The states a delivery is in (the column's enum). */
    public const DELIVERY_STATUSES = ['pending', 'delivered', 'failed'];

    /**
     * The delivery log the API serves: recentDeliveries()'s columns plus
     * the last attempt's response body or error ("curl: …", "blocked: …",
     * stored truncated to 1,000 bytes), optionally one status only, newest
     * first. Never the payload, never the endpoint's secret.
     *
     * @return list<array<string, mixed>>
     */
    public function deliveries(int $userId, int $webhookId, int $limit, ?string $status = null): array
    {
        if ($status !== null && !in_array($status, self::DELIVERY_STATUSES, true)) {
            throw new LtvInputException(
                'status',
                'Delivery status must be one of: ' . implode(', ', self::DELIVERY_STATUSES)
            );
        }
        $sql = 'SELECT delivery_id, event_name, status, attempts, last_status_code, last_response_body,
                       next_attempt_at, created_at, updated_at
                FROM 202_ltv_webhook_deliveries
                WHERE webhook_id = ? AND user_id = ?' . ($status !== null ? ' AND status = ?' : '') . '
                ORDER BY delivery_id DESC
                LIMIT ?';
        $stmt = $this->conn->prepareRead($sql);
        if ($status !== null) {
            $this->conn->bind($stmt, 'iisi', [$webhookId, $userId, $status, max(1, $limit)]);
        } else {
            $this->conn->bind($stmt, 'iii', [$webhookId, $userId, max(1, $limit)]);
        }

        return $this->conn->fetchAll($stmt);
    }

    /**
     * Dispatch-side: atomically claim ONE due delivery before posting it.
     * The single-row conditional UPDATE (bump next_attempt_at past now) is
     * the arbiter under overlapping cron runs: exactly one worker's UPDATE
     * matches, so a delivery can never be POSTed twice. A worker that
     * crashes mid-delivery leaves the row pending and it retries after the
     * claim window lapses.
     */
    public function claimDelivery(int $deliveryId, int $now, int $claimSeconds = 300): bool
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_ltv_webhook_deliveries
             SET next_attempt_at = ?
             WHERE delivery_id = ? AND status = 'pending' AND next_attempt_at <= ?"
        );
        $this->conn->bind($stmt, 'iii', [$now + $claimSeconds, $deliveryId, $now]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    /**
     * Dispatch-side: the pending deliveries due at $now (joined to their
     * endpoint), oldest due first, after the cursor (next_attempt_at,
     * delivery_id) of the last row a run read. A plain read: claimDelivery()
     * decides who sends each one. The cursor lets a run step past rows it
     * leaves due (WebhookDispatcher defers a slow webhook's) without reading
     * them again.
     *
     * @return list<array<string, mixed>>
     */
    public function duePending(int $now, int $limit, int $afterDueAt = 0, int $afterId = 0): array
    {
        $stmt = $this->conn->prepareRead(
            "SELECT d.delivery_id, d.webhook_id, d.user_id, d.event_name, d.payload, d.attempts, d.next_attempt_at,
                    w.webhook_url, w.webhook_secret, w.status AS webhook_status
             FROM 202_ltv_webhook_deliveries d
             JOIN 202_ltv_webhooks w ON w.webhook_id = d.webhook_id
             WHERE d.status = 'pending' AND d.next_attempt_at <= ?
               AND (d.next_attempt_at > ? OR (d.next_attempt_at = ? AND d.delivery_id > ?))
             ORDER BY d.next_attempt_at ASC, d.delivery_id ASC
             LIMIT ?"
        );
        $this->conn->bind($stmt, 'iiiii', [$now, $afterDueAt, $afterDueAt, $afterId, $limit]);

        return $this->conn->fetchAll($stmt);
    }

    /**
     * Dispatch-side: mark up to $limit deliveries queued before $queuedBefore
     * and still waiting as failed, with $reason as their last response, and
     * never send them. A delivery claimed by a run in flight (next_attempt_at
     * past $now) is left to that run. The endpoint is not marked dead: it
     * did nothing wrong. Answers how many were marked.
     */
    public function expireUnsent(int $now, int $queuedBefore, string $reason, int $limit): int
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_ltv_webhook_deliveries
             SET status = 'failed', last_status_code = NULL, last_response_body = ?, updated_at = ?
             WHERE status = 'pending' AND next_attempt_at <= ? AND created_at < ?
             LIMIT ?"
        );
        $this->conn->bind($stmt, 'siiii', [substr($reason, 0, 1000), $now, $now, $queuedBefore, $limit]);

        return $this->conn->executeUpdate($stmt);
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
        // A receiver's body is any bytes. Cut mid-character, or not UTF-8 at
        // all, it is refused under a strict sql_mode: the outcome would go
        // unrecorded and the delivery be sent again once its claim lapsed.
        $truncated = mb_strcut(mb_scrub($responseBody, 'UTF-8'), 0, 1000, 'UTF-8');

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
            // `attempts = attempts + 1` MUST come last: MySQL evaluates
            // single-table UPDATE assignments left to right and later
            // expressions see already-updated columns. With the increment
            // first, `attempts + 1` below read old+1, so the status test was
            // really old+2 — abandoning delivery one attempt early (5 of the
            // 6 MAX_ATTEMPTS) and marking the endpoint dead prematurely.
            "UPDATE 202_ltv_webhook_deliveries
             SET status = IF(attempts + 1 >= ?, 'failed', 'pending'),
                 next_attempt_at = ? + (POW(2, LEAST(attempts + 1, 10)) * 60),
                 last_status_code = ?, last_response_body = ?, updated_at = ?,
                 attempts = attempts + 1
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
