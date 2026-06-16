<?php

declare(strict_types=1);

include_once(__DIR__ . '/MessagingClient.class.php');

/**
 * MessagingService
 *
 * Per-user orchestration of the Intercom-style messenger on a self-hosted
 * Prosper202 install. Owns the local cache (the 202_messaging_* tables) and
 * mediates between the browser widget and the central messaging API.
 *
 * Design notes:
 *  - All network calls go through MessagingClient and happen OUTSIDE database
 *    transactions; only the resulting DB writes are transactional.
 *  - sync() is throttled (MESSAGING_SYNC_THROTTLE) so the widget can call it on
 *    every poll without hammering the central server.
 *  - Outbound messages are queued locally first (optimistic UI) and pushed; if a
 *    push fails the row stays 'pending' and a later sync()/cron retries it.
 *  - Every $stmt->execute() return value is checked (CLAUDE.md #1): inside a
 *    transaction a silent false would otherwise commit a partial write.
 */
class MessagingService
{
    private const MAX_PUSH_ATTEMPTS = 5;

    private mysqli $db;
    private int $userId;
    /** @var array<string,mixed> */
    private array $identity;
    private ?MessagingClient $client = null;

    /**
     * @param array<string,mixed> $identity Identity payload for the central API.
     */
    public function __construct(mysqli $db, int $userId, array $identity)
    {
        $this->db       = $db;
        $this->userId   = $userId;
        $this->identity = $identity;
    }

    /**
     * Build a service for a given local user, assembling the central identity
     * payload from the user record and the locally-stored attribute snapshot.
     *
     * @return self|null Null if the user cannot be resolved.
     */
    public static function forUser(int $userId): ?self
    {
        if ($userId <= 0) {
            return null;
        }

        $db = DB::getInstance()->getConnection();

        $feedback = get_user_data_feedback($userId);
        if ($feedback['install_hash'] === null && $feedback['user_email'] === null) {
            // Unknown user — nothing to identify to the central server.
            return null;
        }

        $service = new self($db, $userId, []);
        $service->identity = [
            'install_hash'  => $feedback['install_hash'],
            'api_key'       => $feedback['api_key'],
            'user_id'       => $userId,
            'user_email'    => $feedback['user_email'],
            'registered_at' => $feedback['time_stamp'],
            'attributes'    => $service->getAttributes(),
        ];

        return $service;
    }

    private function client(): MessagingClient
    {
        return $this->client ??= new MessagingClient();
    }

    // ---------------------------------------------------------------------
    // Sync orchestration
    // ---------------------------------------------------------------------

    /**
     * Flush pending outbound data and pull fresh state from the central server.
     *
     * Throttled: unless $force is true, a sync that ran within
     * MESSAGING_SYNC_THROTTLE seconds is skipped (no network), so the widget can
     * call this on every poll cheaply.
     *
     * @return bool True if a successful pull occurred this call.
     */
    public function sync(bool $force = false): bool
    {
        if (!$force && !$this->isSyncDue()) {
            return false;
        }

        $this->markSyncStart();

        // Push first so the user's own messages/events reach the server before we
        // pull state back (network happens here, never inside a transaction).
        $this->flushEvents();
        $this->pushPending();
        $this->reportReadReceipts();

        $cursor   = $this->getCursor();
        $response = $this->client()->pull($this->identity, $cursor);

        if ($response === null) {
            $this->recordSyncError('pull failed');
            return false;
        }

        $this->applyPull($response);
        return true;
    }

    /**
     * Apply a /pull response to the local cache inside a single transaction.
     *
     * @param array<string,mixed> $response
     */
    private function applyPull(array $response): void
    {
        $conversations = $response['conversations'] ?? null;
        if (!is_array($conversations)) {
            // A 200 with a malformed body is a server bug; do not silently treat
            // it as "no conversations" and wipe nothing — just record the error.
            $this->recordSyncError('malformed pull response');
            return;
        }

        $this->db->begin_transaction();
        try {
            foreach ($conversations as $conversation) {
                if (!is_array($conversation) || empty($conversation['external_id'])) {
                    continue;
                }

                $conversationId = $this->upsertConversation($conversation);

                $messages = $conversation['messages'] ?? [];
                if (is_array($messages)) {
                    foreach ($messages as $message) {
                        if (is_array($message)) {
                            $this->upsertMessage($conversationId, $message);
                        }
                    }
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('MessagingService: applyPull failed: ' . $e->getMessage());
            $this->recordSyncError('apply failed: ' . $e->getMessage());
            return;
        }

        $newCursor = isset($response['cursor']) ? (string) $response['cursor'] : null;
        $this->recordSyncSuccess($newCursor);
    }

    // ---------------------------------------------------------------------
    // Conversations / messages cache writes
    // ---------------------------------------------------------------------

    /**
     * Insert or update a conversation; returns its local primary key.
     *
     * @param array<string,mixed> $c
     */
    private function upsertConversation(array $c): int
    {
        $externalId = (string) $c['external_id'];
        $type       = in_array($c['type'] ?? '', ['conversation', 'broadcast'], true) ? $c['type'] : 'conversation';
        $status     = in_array($c['status'] ?? '', ['open', 'closed'], true) ? $c['status'] : 'open';
        $subject    = isset($c['subject']) ? (string) $c['subject'] : null;
        $lastAt     = $this->normalizeDate($c['last_message_at'] ?? null);

        $existingId = $this->findConversationId($externalId);

        if ($existingId !== null) {
            $sql  = "UPDATE 202_messaging_conversations
                        SET type = ?, subject = ?, status = ?, last_message_at = COALESCE(?, last_message_at)
                      WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('prepare update conversation failed');
            }
            $stmt->bind_param('ssssi', $type, $subject, $status, $lastAt, $existingId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('update conversation failed');
            }
            $stmt->close();
            return $existingId;
        }

        $sql  = "INSERT INTO 202_messaging_conversations
                    (user_id, external_id, type, subject, status, last_message_at)
                 VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('prepare insert conversation failed');
        }
        $stmt->bind_param('isssss', $this->userId, $externalId, $type, $subject, $status, $lastAt);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('insert conversation failed');
        }
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        return $newId;
    }

    /**
     * Insert or reconcile a message within a conversation.
     *
     * Reconciliation: an outbound message we queued locally has a client_token
     * but no external_id yet. When the server echoes it back (in /pull or /send),
     * we match on client_token and stamp the canonical external_id rather than
     * inserting a duplicate.
     *
     * @param array<string,mixed> $m
     */
    private function upsertMessage(int $conversationId, array $m): void
    {
        $externalId  = isset($m['external_id']) ? (string) $m['external_id'] : null;
        $clientToken = isset($m['client_token']) ? (string) $m['client_token'] : null;
        $direction   = ($m['direction'] ?? '') === 'outbound' ? 'outbound' : 'inbound';
        $author      = in_array($m['author'] ?? '', ['team', 'system', 'user'], true) ? $m['author'] : 'team';
        $body        = isset($m['body']) ? (string) $m['body'] : '';
        $createdAt   = $this->normalizeDate($m['created_at'] ?? null) ?? date('Y-m-d H:i:s');

        // Reconcile a locally-queued outbound message by its client token.
        if ($clientToken !== null) {
            $localId = $this->findMessageIdByClientToken($conversationId, $clientToken);
            if ($localId !== null) {
                $sql  = "UPDATE 202_messaging_messages
                            SET external_id = ?, delivery_status = 'sent', created_at = ?
                          WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException('prepare reconcile message failed');
                }
                $stmt->bind_param('ssi', $externalId, $createdAt, $localId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('reconcile message failed');
                }
                $stmt->close();
                $this->touchConversation($conversationId, $createdAt, $body);
                return;
            }
        }

        // Skip if we already have this server message.
        if ($externalId !== null && $this->messageExists($conversationId, $externalId)) {
            return;
        }

        $deliveryStatus = $direction === 'outbound' ? 'sent' : 'delivered';

        $sql  = "INSERT INTO 202_messaging_messages
                    (conversation_id, external_id, client_token, direction, author, body, created_at, delivery_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('prepare insert message failed');
        }
        $stmt->bind_param('isssssss', $conversationId, $externalId, $clientToken, $direction, $author, $body, $createdAt, $deliveryStatus);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('insert message failed');
        }
        $stmt->close();

        $this->touchConversation($conversationId, $createdAt, $body);
    }

    private function touchConversation(int $conversationId, string $createdAt, string $body): void
    {
        $preview = mb_substr(trim($body), 0, 200);
        $sql  = "UPDATE 202_messaging_conversations
                    SET last_message_at = GREATEST(COALESCE(last_message_at, ?), ?),
                        last_message_preview = ?
                  WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('prepare touch conversation failed');
        }
        $stmt->bind_param('sssi', $createdAt, $createdAt, $preview, $conversationId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('touch conversation failed');
        }
        $stmt->close();
    }

    // ---------------------------------------------------------------------
    // Outbound messages
    // ---------------------------------------------------------------------

    /**
     * Queue a user-composed message and attempt an immediate push.
     *
     * @param string|null $conversationExternalId Existing thread, or null/empty for a new one.
     * @return array{conversation_external_id:string,message:array}|null
     *         The optimistic message + its conversation external id, or null on a hard failure.
     */
    public function sendMessage(?string $conversationExternalId, string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        $clientToken = $this->generateToken();

        // Resolve (or create) the local conversation so the UI has something to show.
        if ($conversationExternalId !== null && $conversationExternalId !== '') {
            $conversationId = $this->findConversationId($conversationExternalId);
            if ($conversationId === null) {
                // Unknown thread — fall back to starting a new one.
                $conversationExternalId = null;
            }
        }

        if ($conversationExternalId === null || $conversationExternalId === '') {
            $conversationExternalId = 'pending_' . $clientToken;
            $conversationId = $this->upsertConversation([
                'external_id'     => $conversationExternalId,
                'type'            => 'conversation',
                'status'          => 'open',
                'last_message_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $now  = date('Y-m-d H:i:s');
        $sql  = "INSERT INTO 202_messaging_messages
                    (conversation_id, client_token, direction, author, body, created_at, delivery_status)
                 VALUES (?, ?, 'outbound', 'user', ?, ?, 'pending')";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log('MessagingService: prepare queue outbound failed');
            return null;
        }
        $stmt->bind_param('isss', $conversationId, $clientToken, $body, $now);
        if (!$stmt->execute()) {
            $stmt->close();
            error_log('MessagingService: queue outbound failed');
            return null;
        }
        $messageId = (int) $stmt->insert_id;
        $stmt->close();

        $this->touchConversation($conversationId, $now, $body);

        // Best-effort immediate delivery; on failure the row stays 'pending'.
        $this->pushMessage($messageId);

        // Re-read so the caller gets the (possibly reconciled) canonical row.
        $message = $this->getMessageById($messageId);
        $convExt = $this->getConversationExternalId($conversationId) ?? $conversationExternalId;

        return [
            'conversation_external_id' => $convExt,
            'message'                  => $message ?? [],
        ];
    }

    /**
     * Push all pending outbound messages that have not exhausted their retries.
     */
    private function pushPending(): void
    {
        $sql = "SELECT id FROM 202_messaging_messages
                 WHERE direction = 'outbound' AND delivery_status = 'pending'
                   AND sync_attempts < ?
                   AND conversation_id IN (SELECT id FROM 202_messaging_conversations WHERE user_id = ?)
                 ORDER BY id ASC";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $max = self::MAX_PUSH_ATTEMPTS;
        $stmt->bind_param('ii', $max, $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return;
        }
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $stmt->close();

        foreach ($ids as $id) {
            $this->pushMessage($id);
        }
    }

    /**
     * Push a single queued message to the central server and reconcile the result.
     */
    private function pushMessage(int $messageId): bool
    {
        $message = $this->getMessageById($messageId);
        if ($message === null || $message['delivery_status'] !== 'pending') {
            return false;
        }

        $conversationId = (int) $message['conversation_id'];
        $convExternalId = $this->getConversationExternalId($conversationId);
        // A 'pending_' external id is local-only; tell the server this is a new thread.
        $sendConvId = ($convExternalId !== null && !str_starts_with($convExternalId, 'pending_'))
            ? $convExternalId
            : null;

        $response = $this->client()->send(
            $this->identity,
            $sendConvId,
            (string) $message['body'],
            (string) $message['client_token']
        );

        if ($response === null) {
            $this->incrementPushAttempts($messageId);
            return false;
        }

        $this->db->begin_transaction();
        try {
            // Adopt the server's canonical conversation identifiers.
            if (isset($response['conversation']) && is_array($response['conversation'])
                && !empty($response['conversation']['external_id'])) {
                $this->reconcileConversation($conversationId, $response['conversation']);
            }

            $serverMessage = $response['message'] ?? null;
            $externalId = is_array($serverMessage) && isset($serverMessage['external_id'])
                ? (string) $serverMessage['external_id']
                : null;
            $createdAt = is_array($serverMessage)
                ? ($this->normalizeDate($serverMessage['created_at'] ?? null) ?? (string) $message['created_at'])
                : (string) $message['created_at'];

            $sql  = "UPDATE 202_messaging_messages
                        SET external_id = ?, delivery_status = 'sent', created_at = ?
                      WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('prepare push reconcile failed');
            }
            $stmt->bind_param('ssi', $externalId, $createdAt, $messageId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('push reconcile failed');
            }
            $stmt->close();

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('MessagingService: pushMessage reconcile failed: ' . $e->getMessage());
            $this->incrementPushAttempts($messageId);
            return false;
        }

        return true;
    }

    /**
     * Replace a local conversation's identifiers with the server's canonical ones
     * (used when a 'pending_' placeholder gets a real external id).
     *
     * @param array<string,mixed> $c
     */
    private function reconcileConversation(int $conversationId, array $c): void
    {
        $externalId = (string) $c['external_id'];
        $type       = in_array($c['type'] ?? '', ['conversation', 'broadcast'], true) ? $c['type'] : 'conversation';
        $status     = in_array($c['status'] ?? '', ['open', 'closed'], true) ? $c['status'] : 'open';
        $subject    = isset($c['subject']) ? (string) $c['subject'] : null;

        $sql  = "UPDATE 202_messaging_conversations
                    SET external_id = ?, type = ?, status = ?, subject = ?
                  WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('prepare reconcile conversation failed');
        }
        $stmt->bind_param('ssssi', $externalId, $type, $status, $subject, $conversationId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('reconcile conversation failed');
        }
        $stmt->close();
    }

    private function incrementPushAttempts(int $messageId): void
    {
        $sql  = "UPDATE 202_messaging_messages
                    SET sync_attempts = sync_attempts + 1,
                        delivery_status = IF(sync_attempts + 1 >= ?, 'failed', 'pending')
                  WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $max = self::MAX_PUSH_ATTEMPTS;
        $stmt->bind_param('ii', $max, $messageId);
        if (!$stmt->execute()) {
            error_log('MessagingService: incrementPushAttempts failed for message ' . $messageId);
        }
        $stmt->close();
    }

    // ---------------------------------------------------------------------
    // Read state
    // ---------------------------------------------------------------------

    /**
     * Mark all inbound messages in a conversation as read.
     *
     * This only touches local state (the source of truth for the unread badge),
     * so it stays off the network and keeps the request fast. Read receipts are
     * reported to the central server separately by reportReadReceipts(), which
     * runs during sync()/cron rather than in the page request.
     */
    public function markConversationRead(string $conversationExternalId): void
    {
        $conversationId = $this->findConversationId($conversationExternalId);
        if ($conversationId === null) {
            return;
        }

        $now  = date('Y-m-d H:i:s');
        $sql  = "UPDATE 202_messaging_messages
                    SET read_at = ?
                  WHERE conversation_id = ? AND direction = 'inbound' AND read_at IS NULL";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('si', $now, $conversationId);
        if (!$stmt->execute()) {
            error_log('MessagingService: markConversationRead update failed');
        }
        $stmt->close();
    }

    /**
     * Best-effort report of read inbound messages whose receipt has not yet been
     * sent to the central server. Runs during sync()/cron, never in a page request.
     */
    private function reportReadReceipts(): void
    {
        $externalIds = [];
        $sql = "SELECT external_id FROM 202_messaging_messages
                 WHERE read_at IS NOT NULL AND receipt_sent = 0
                   AND direction = 'inbound' AND external_id IS NOT NULL
                   AND conversation_id IN (SELECT id FROM 202_messaging_conversations WHERE user_id = ?)
                 LIMIT 200";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $this->userId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $externalIds[] = (string) $row['external_id'];
            }
        }
        $stmt->close();

        if ($externalIds === []) {
            return;
        }

        $response = $this->client()->markRead($this->identity, $externalIds);
        if ($response === null) {
            return; // try again next sync
        }

        // Flag the reported receipts so we don't resend them.
        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
        $types = str_repeat('s', count($externalIds));
        $sql  = "UPDATE 202_messaging_messages SET receipt_sent = 1 WHERE external_id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param($types, ...$externalIds);
        if (!$stmt->execute()) {
            error_log('MessagingService: reportReadReceipts flag update failed');
        }
        $stmt->close();
    }

    // ---------------------------------------------------------------------
    // Events & attributes (segmentation)
    // ---------------------------------------------------------------------

    /**
     * Merge custom attributes into the stored snapshot and mark it for delivery.
     *
     * @param array<string,mixed> $attributes
     */
    public function updateAttributes(array $attributes): void
    {
        if ($attributes === []) {
            return;
        }

        $current = $this->getAttributes();
        // Scalars only — nested structures are not part of the contract.
        foreach ($attributes as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $current[(string) $key] = $value;
            }
        }

        $json = json_encode($current);
        if ($json === false) {
            error_log('MessagingService: failed to encode attributes: ' . json_last_error_msg());
            return;
        }

        $now  = date('Y-m-d H:i:s');
        $sql  = "INSERT INTO 202_messaging_attributes (user_id, data, dirty, updated_at)
                 VALUES (?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), dirty = 1, updated_at = VALUES(updated_at)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log('MessagingService: prepare updateAttributes failed');
            return;
        }
        $stmt->bind_param('iss', $this->userId, $json, $now);
        if (!$stmt->execute()) {
            error_log('MessagingService: updateAttributes failed');
        }
        $stmt->close();

        // Keep the in-memory identity fresh for any send/pull later this request.
        $this->identity['attributes'] = $current;
    }

    /**
     * Record a behavioural event for later delivery to the central server.
     *
     * @param array<string,mixed>|null $metadata
     */
    public function recordEvent(string $name, ?array $metadata = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $metaJson = null;
        if ($metadata !== null && $metadata !== []) {
            $encoded = json_encode($metadata);
            if ($encoded === false) {
                error_log('MessagingService: failed to encode event metadata: ' . json_last_error_msg());
                return;
            }
            $metaJson = $encoded;
        }

        $token = $this->generateToken();
        $now   = date('Y-m-d H:i:s');
        $sql   = "INSERT INTO 202_messaging_events
                    (user_id, event_name, metadata, occurred_at, client_token, delivery_status)
                  VALUES (?, ?, ?, ?, ?, 'pending')";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log('MessagingService: prepare recordEvent failed');
            return;
        }
        $stmt->bind_param('issss', $this->userId, $name, $metaJson, $now, $token);
        if (!$stmt->execute()) {
            error_log('MessagingService: recordEvent failed');
        }
        $stmt->close();
    }

    /**
     * Deliver the attribute snapshot (if changed) plus any pending events.
     */
    private function flushEvents(): void
    {
        $events = [];
        $sql = "SELECT id, event_name, metadata, occurred_at, client_token
                  FROM 202_messaging_events
                 WHERE user_id = ? AND delivery_status = 'pending'
                   AND sync_attempts < ?
                 ORDER BY id ASC
                 LIMIT 100";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $max = self::MAX_PUSH_ATTEMPTS;
            $stmt->bind_param('ii', $this->userId, $max);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $metadata = null;
                    if ($row['metadata'] !== null) {
                        $decoded = json_decode((string) $row['metadata'], true);
                        $metadata = is_array($decoded) ? $decoded : null;
                    }
                    $events[] = [
                        '_local_id'    => (int) $row['id'],
                        'name'         => $row['event_name'],
                        'metadata'     => $metadata,
                        'occurred_at'  => $row['occurred_at'],
                        'client_token' => $row['client_token'],
                    ];
                }
            }
            $stmt->close();
        }

        $attributesDirty = $this->areAttributesDirty();

        if ($events === [] && !$attributesDirty) {
            return; // nothing to flush
        }

        // Strip internal keys before sending.
        $payloadEvents = array_map(static function (array $e): array {
            unset($e['_local_id']);
            return $e;
        }, $events);

        $response = $this->client()->track($this->identity, $this->getAttributes(), $payloadEvents);

        if ($response === null) {
            // Bump attempts so poison events eventually stop being retried.
            foreach ($events as $e) {
                $this->incrementEventAttempts($e['_local_id']);
            }
            return;
        }

        // Mark delivered.
        foreach ($events as $e) {
            $this->markEventSent($e['_local_id']);
        }
        if ($attributesDirty) {
            $this->clearAttributesDirty();
        }
    }

    private function incrementEventAttempts(int $eventId): void
    {
        $sql  = "UPDATE 202_messaging_events
                    SET sync_attempts = sync_attempts + 1,
                        delivery_status = IF(sync_attempts + 1 >= ?, 'failed', 'pending')
                  WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $max = self::MAX_PUSH_ATTEMPTS;
        $stmt->bind_param('ii', $max, $eventId);
        if (!$stmt->execute()) {
            error_log('MessagingService: incrementEventAttempts failed for event ' . $eventId);
        }
        $stmt->close();
    }

    private function markEventSent(int $eventId): void
    {
        $sql  = "UPDATE 202_messaging_events SET delivery_status = 'sent' WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $eventId);
        if (!$stmt->execute()) {
            error_log('MessagingService: markEventSent failed for event ' . $eventId);
        }
        $stmt->close();
    }

    // ---------------------------------------------------------------------
    // Read models for the widget
    // ---------------------------------------------------------------------

    /**
     * @return array{unread_count:int,conversations:array<int,array<string,mixed>>}
     */
    public function getInbox(): array
    {
        $conversations = [];
        $sql = "SELECT c.external_id, c.type, c.subject, c.status, c.last_message_at, c.last_message_preview,
                       (SELECT COUNT(*) FROM 202_messaging_messages m
                          WHERE m.conversation_id = c.id AND m.direction = 'inbound' AND m.read_at IS NULL) AS unread
                  FROM 202_messaging_conversations c
                 WHERE c.user_id = ?
                 ORDER BY c.last_message_at DESC, c.id DESC
                 LIMIT 50";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $this->userId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $row['unread'] = (int) $row['unread'];
                    $conversations[] = $row;
                }
            }
            $stmt->close();
        }

        $unreadTotal = 0;
        foreach ($conversations as $c) {
            $unreadTotal += $c['unread'];
        }

        return [
            'unread_count'  => $unreadTotal,
            'conversations' => $conversations,
        ];
    }

    /**
     * @return array{conversation:array<string,mixed>|null,messages:array<int,array<string,mixed>>}
     */
    public function getConversation(string $conversationExternalId): array
    {
        $conversationId = $this->findConversationId($conversationExternalId);
        if ($conversationId === null) {
            return ['conversation' => null, 'messages' => []];
        }

        $conversation = null;
        $sql = "SELECT external_id, type, subject, status, last_message_at
                  FROM 202_messaging_conversations WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $conversationId);
            if ($stmt->execute()) {
                $conversation = $stmt->get_result()->fetch_assoc() ?: null;
            }
            $stmt->close();
        }

        $messages = [];
        $sql = "SELECT external_id, direction, author, body, created_at, read_at, delivery_status
                  FROM 202_messaging_messages
                 WHERE conversation_id = ?
                 ORDER BY created_at ASC, id ASC
                 LIMIT 500";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $conversationId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $messages[] = $row;
                }
            }
            $stmt->close();
        }

        return ['conversation' => $conversation, 'messages' => $messages];
    }

    // ---------------------------------------------------------------------
    // Small DB helpers
    // ---------------------------------------------------------------------

    private function findConversationId(string $externalId): ?int
    {
        $sql  = "SELECT id FROM 202_messaging_conversations WHERE user_id = ? AND external_id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $this->userId, $externalId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['id'] : null;
    }

    private function getConversationExternalId(int $conversationId): ?string
    {
        $sql  = "SELECT external_id FROM 202_messaging_conversations WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $conversationId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string) $row['external_id'] : null;
    }

    private function findMessageIdByClientToken(int $conversationId, string $clientToken): ?int
    {
        $sql  = "SELECT id FROM 202_messaging_messages WHERE conversation_id = ? AND client_token = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $conversationId, $clientToken);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['id'] : null;
    }

    private function messageExists(int $conversationId, string $externalId): bool
    {
        $sql  = "SELECT id FROM 202_messaging_messages WHERE conversation_id = ? AND external_id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $conversationId, $externalId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getMessageById(int $messageId): ?array
    {
        $sql  = "SELECT id, conversation_id, external_id, client_token, direction, author, body,
                        created_at, read_at, delivery_status
                   FROM 202_messaging_messages WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $messageId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * @return array<string,mixed>
     */
    private function getAttributes(): array
    {
        $sql  = "SELECT data FROM 202_messaging_attributes WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || $row['data'] === null) {
            return [];
        }

        $decoded = json_decode((string) $row['data'], true);
        if (!is_array($decoded)) {
            // Our own snapshot should always decode; log corruption rather than
            // silently dropping it.
            error_log('MessagingService: stored attributes for user ' . $this->userId . ' are corrupt');
            return [];
        }
        return $decoded;
    }

    private function areAttributesDirty(): bool
    {
        $sql  = "SELECT dirty FROM 202_messaging_attributes WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? ((int) $row['dirty'] === 1) : false;
    }

    private function clearAttributesDirty(): void
    {
        $sql  = "UPDATE 202_messaging_attributes SET dirty = 0 WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $this->userId);
        if (!$stmt->execute()) {
            error_log('MessagingService: clearAttributesDirty failed');
        }
        $stmt->close();
    }

    // ---------------------------------------------------------------------
    // Sync-state helpers (202_messaging_sync)
    // ---------------------------------------------------------------------

    private function isSyncDue(): bool
    {
        $throttle = defined('MESSAGING_SYNC_THROTTLE') ? (int) MESSAGING_SYNC_THROTTLE : 20;

        $sql  = "SELECT last_sync FROM 202_messaging_sync WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return true;
        }
        $stmt->bind_param('i', $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return true;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || $row['last_sync'] === null) {
            return true;
        }
        return (time() - strtotime((string) $row['last_sync'])) >= $throttle;
    }

    private function getCursor(): ?string
    {
        $sql  = "SELECT sync_cursor FROM 202_messaging_sync WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row && $row['sync_cursor'] !== null ? (string) $row['sync_cursor'] : null;
    }

    private function markSyncStart(): void
    {
        $now  = date('Y-m-d H:i:s');
        $sql  = "INSERT INTO 202_messaging_sync (user_id, last_sync) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE last_sync = VALUES(last_sync)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('is', $this->userId, $now);
        if (!$stmt->execute()) {
            error_log('MessagingService: markSyncStart failed');
        }
        $stmt->close();
    }

    private function recordSyncSuccess(?string $cursor): void
    {
        $now  = date('Y-m-d H:i:s');
        $sql  = "INSERT INTO 202_messaging_sync (user_id, last_sync, last_success, sync_cursor, error_count, last_error)
                 VALUES (?, ?, ?, ?, 0, NULL)
                 ON DUPLICATE KEY UPDATE last_success = VALUES(last_success),
                                         sync_cursor = VALUES(sync_cursor),
                                         error_count = 0,
                                         last_error = NULL";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isss', $this->userId, $now, $now, $cursor);
        if (!$stmt->execute()) {
            error_log('MessagingService: recordSyncSuccess failed');
        }
        $stmt->close();
    }

    private function recordSyncError(string $error): void
    {
        $sql  = "INSERT INTO 202_messaging_sync (user_id, error_count, last_error)
                 VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE error_count = error_count + 1, last_error = VALUES(last_error)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('is', $this->userId, $error);
        if (!$stmt->execute()) {
            error_log('MessagingService: recordSyncError failed');
        }
        $stmt->close();
    }

    // ---------------------------------------------------------------------
    // Misc
    // ---------------------------------------------------------------------

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
