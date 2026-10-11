<?php

declare(strict_types=1);

use Prosper202\Database\Connection;

include_once(__DIR__ . '/MessagingClient.class.php');

/**
 * MessagingService
 *
 * Product analytics for one user of a self-hosted install: custom attributes
 * and behavioural events are recorded locally (202_messaging_attributes,
 * 202_messaging_events) and delivered to the portal's POST /track by sync(),
 * which 202-account/ajax/messaging/track.php runs after every record and
 * 202-cronjobs/sync-messaging.php runs for every active user.
 *
 * Chat is not here. The install no longer stores or syncs messages: the
 * browser talks to the chat service directly with a pass that
 * 202-account/ajax/chat-pass.php fetches from the portal, using the identity
 * identityFor() builds.
 *
 * Design notes:
 *  - All network calls go through MessagingClient and happen outside any
 *    database transaction.
 *  - sync() is throttled (MESSAGING_SYNC_THROTTLE) per user, so a burst of
 *    events is one /track call; what the throttle holds back is delivered by
 *    the next sync, and track.php tells the page when that is due.
 *  - Every statement goes through Prosper202\Database\Connection, which
 *    throws on a failed prepare, execute or result read (CLAUDE.md #1).
 */
class MessagingService
{
    /** sync() outcomes. */
    public const SYNC_IDLE = 'idle';           // nothing was pending
    public const SYNC_THROTTLED = 'throttled'; // something is pending; the last sync was too recent
    public const SYNC_SENT = 'sent';           // the pending rows were delivered
    public const SYNC_FAILED = 'failed';       // delivery (or reading what to deliver) failed

    private const MAX_PUSH_ATTEMPTS = 5;

    private mysqli $db;
    private Connection $conn;
    private int $userId;
    /** @var array<string,mixed> */
    private array $identity;
    private ?MessagingClient $client = null;

    /**
     * @param array<string,mixed> $identity Identity payload for the portal.
     */
    public function __construct(mysqli $db, int $userId, array $identity)
    {
        $this->db       = $db;
        $this->conn     = new Connection($db);
        $this->userId   = $userId;
        $this->identity = $identity;
    }

    /**
     * A service for a local user, with the identity /track authenticates
     * with and the stored attribute snapshot.
     *
     * @return self|null Null when no active user has this id.
     * @throws \Prosper202\Database\Exceptions\QueryException when the user cannot be read
     */
    public static function forUser(int $userId): ?self
    {
        if ($userId <= 0) {
            return null;
        }

        $db = DB::getInstance()->getConnection();
        $identity = self::identityFor($db, $userId);
        if ($identity === null) {
            return null;
        }

        $service = new self($db, $userId, []);
        $service->identity = [
            'install_hash'  => $identity['install_hash'],
            'api_key'       => $identity['api_key'],
            'user_id'       => $userId,
            'user_email'    => $identity['user_email'],
            'registered_at' => $identity['registered_at'],
            'attributes'    => $service->getAttributes(),
        ];

        return $service;
    }

    /**
     * Who this user is to the portal: the install hash, the customer API key,
     * and the user's id, email, display name and registration time. Both the
     * analytics identity (forUser()) and the chat pass request (chat-pass.php)
     * are built from this, so the two cannot resolve the key differently.
     *
     * The API key is the user's own, or else the install owner's: sub-users
     * share the owner's install_hash but often carry no
     * p202_customer_api_key, and the portal authenticates with the key and
     * the hash together (mirroring the license lookup in functions-auth.php).
     * '' when neither has one.
     *
     * The display name is first and last name, else the sign-in name, cut
     * to the chat pass's 100 UTF-16 code units (the protocol counts lengths
     * as JavaScript does, and the portal sends a longer name as none).
     *
     * Null when no active, undeleted user has this id: a deactivated user's
     * session keeps working until it expires, and it may not act through
     * either channel (CLAUDE.md #5). A failed read throws rather than
     * answering '' for the key: '' means "this install has no key", which
     * hides the chat box, and must never stand in for "could not read"
     * (CLAUDE.md #11).
     *
     * @return array{install_hash: string, api_key: string, user_id: int, user_email: string, user_name: string, registered_at: int}|null
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    public static function identityFor(mysqli $db, int $userId): ?array
    {
        $conn = new Connection($db);
        $stmt = $conn->prepareRead(
            'SELECT user_name, user_fname, user_lname, user_email, user_time_register,
                    p202_customer_api_key, install_hash
               FROM 202_users
              WHERE user_id = ? AND user_deleted = 0 AND user_active = 1'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        $installHash = trim((string) $row['install_hash']);
        $apiKey = trim((string) ($row['p202_customer_api_key'] ?? ''));
        if ($apiKey === '' && $installHash !== '') {
            $apiKey = self::installApiKey($conn, $installHash);
        }

        $name = trim(trim((string) ($row['user_fname'] ?? '')) . ' ' . trim((string) ($row['user_lname'] ?? '')));
        if ($name === '') {
            $name = trim((string) $row['user_name']);
        }

        return [
            'install_hash'  => $installHash,
            'api_key'       => $apiKey,
            'user_id'       => $userId,
            'user_email'    => trim((string) $row['user_email']),
            'user_name'     => self::cutToUtf16Units($name, 100),
            'registered_at' => (int) $row['user_time_register'],
        ];
    }

    /**
     * $text cut to at most $units UTF-16 code units, never inside a
     * character: a character outside the Basic Multilingual Plane (an
     * emoji) is two.
     */
    private static function cutToUtf16Units(string $text, int $units): string
    {
        $cut = '';
        $used = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            $cost = intdiv(strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')), 2);
            if ($used + $cost > $units) {
                break;
            }
            $cut .= $character;
            $used += $cost;
        }

        return rtrim($cut);
    }

    /**
     * The install owner's customer API key, for a user whose own row has
     * none: the first active user sharing the install hash that has one.
     * '' when none does.
     */
    private static function installApiKey(Connection $conn, string $installHash): string
    {
        $stmt = $conn->prepareRead(
            "SELECT p202_customer_api_key FROM 202_users
              WHERE install_hash = ? AND user_deleted != 1 AND user_active = 1
                AND p202_customer_api_key IS NOT NULL AND p202_customer_api_key != ''
              ORDER BY user_id ASC LIMIT 1"
        );
        $conn->bind($stmt, 's', [$installHash]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? '' : trim((string) $row['p202_customer_api_key']);
    }

    private function client(): MessagingClient
    {
        return $this->client ??= new MessagingClient();
    }

    // ---------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------

    /**
     * Deliver pending events and a changed attribute snapshot to POST /track.
     *
     * Nothing pending is SYNC_IDLE and touches neither the network nor the
     * throttle. Otherwise, unless $force, a sync that ran within
     * MESSAGING_SYNC_THROTTLE seconds holds the rows back (SYNC_THROTTLED;
     * secondsUntilSyncDue() says for how long). The attempt is stamped before
     * the network call, so a portal that is down is not asked again within
     * the window. A database failure is SYNC_FAILED, logged, never "idle".
     *
     * @return self::SYNC_* the outcome
     */
    public function sync(bool $force = false): string
    {
        try {
            $events = $this->pendingEvents();
            $attributesDirty = $this->areAttributesDirty();
            if ($events === [] && !$attributesDirty) {
                return self::SYNC_IDLE;
            }
            if (!$force && $this->secondsUntilSyncDue() > 0) {
                return self::SYNC_THROTTLED;
            }

            $this->markSyncStart();
            if ($this->flushEvents($events, $attributesDirty)) {
                $this->recordSyncSuccess();
                return self::SYNC_SENT;
            }
            $this->recordSyncError('track failed');
        } catch (Throwable $e) {
            error_log('MessagingService: sync for user ' . $this->userId . ' failed: ' . $e->getMessage());
        }

        return self::SYNC_FAILED;
    }

    /**
     * Seconds until sync() may call the portal again; 0 when it may now.
     *
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    public function secondsUntilSyncDue(): int
    {
        $throttle = defined('MESSAGING_SYNC_THROTTLE') ? (int) MESSAGING_SYNC_THROTTLE : 20;

        $stmt = $this->conn->prepareRead('SELECT last_sync FROM 202_messaging_sync WHERE user_id = ?');
        $this->conn->bind($stmt, 'i', [$this->userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null || $row['last_sync'] === null) {
            return 0;
        }
        $last = strtotime((string) $row['last_sync']);
        if ($last === false) {
            return 0;
        }

        return max(0, $throttle - (time() - $last));
    }

    // ---------------------------------------------------------------------
    // Events & attributes (segmentation)
    // ---------------------------------------------------------------------

    /**
     * Merge custom attributes into the stored snapshot and mark it for delivery.
     *
     * @param array<string,mixed> $attributes
     * @throws \Prosper202\Database\Exceptions\QueryException
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
            throw new \RuntimeException('MessagingService: failed to encode attributes: ' . json_last_error_msg());
        }

        $now  = date('Y-m-d H:i:s');
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_messaging_attributes (user_id, data, dirty, updated_at)
             VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), dirty = 1, updated_at = VALUES(updated_at)'
        );
        $this->conn->bind($stmt, 'iss', [$this->userId, $json, $now]);
        $this->conn->executeUpdate($stmt);

        // Keep the in-memory identity fresh for a sync later this request.
        $this->identity['attributes'] = $current;
    }

    /**
     * Record a behavioural event for delivery to the portal.
     *
     * @param array<string,mixed>|null $metadata
     * @throws \Prosper202\Database\Exceptions\QueryException
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
                throw new \RuntimeException('MessagingService: failed to encode event metadata: ' . json_last_error_msg());
            }
            $metaJson = $encoded;
        }

        $token = $this->generateToken();
        $now   = date('Y-m-d H:i:s');
        $stmt  = $this->conn->prepareWrite(
            "INSERT INTO 202_messaging_events
                (user_id, event_name, metadata, occurred_at, client_token, delivery_status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        );
        $this->conn->bind($stmt, 'issss', [$this->userId, $name, $metaJson, $now, $token]);
        $this->conn->executeInsert($stmt);
    }

    /**
     * The events waiting for delivery, oldest first, at most 100 a call.
     *
     * @return list<array{_local_id: int, name: string, metadata: mixed, occurred_at: string, client_token: string}>
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    private function pendingEvents(): array
    {
        $stmt = $this->conn->prepareRead(
            "SELECT id, event_name, metadata, occurred_at, client_token
               FROM 202_messaging_events
              WHERE user_id = ? AND delivery_status = 'pending'
                AND sync_attempts < ?
              ORDER BY id ASC
              LIMIT 100"
        );
        $this->conn->bind($stmt, 'ii', [$this->userId, self::MAX_PUSH_ATTEMPTS]);

        $events = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $metadata = null;
            if ($row['metadata'] !== null) {
                $decoded = json_decode((string) $row['metadata'], true);
                $metadata = is_array($decoded) ? $decoded : null;
            }
            $events[] = [
                '_local_id'    => (int) $row['id'],
                'name'         => (string) $row['event_name'],
                'metadata'     => $metadata,
                'occurred_at'  => (string) $row['occurred_at'],
                'client_token' => (string) $row['client_token'],
            ];
        }

        return $events;
    }

    /**
     * Deliver the attribute snapshot plus the given events; true when the
     * portal took them, and only then are they marked sent.
     *
     * @param list<array<string,mixed>> $events from pendingEvents()
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    private function flushEvents(array $events, bool $attributesDirty): bool
    {
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
            return false;
        }

        foreach ($events as $e) {
            $this->markEventSent($e['_local_id']);
        }
        if ($attributesDirty) {
            $this->clearAttributesDirty();
        }

        return true;
    }

    private function incrementEventAttempts(int $eventId): void
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_messaging_events
                SET sync_attempts = sync_attempts + 1,
                    delivery_status = IF(sync_attempts + 1 >= ?, 'failed', 'pending')
              WHERE id = ?"
        );
        $this->conn->bind($stmt, 'ii', [self::MAX_PUSH_ATTEMPTS, $eventId]);
        $this->conn->executeUpdate($stmt);
    }

    private function markEventSent(int $eventId): void
    {
        $stmt = $this->conn->prepareWrite("UPDATE 202_messaging_events SET delivery_status = 'sent' WHERE id = ?");
        $this->conn->bind($stmt, 'i', [$eventId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * @return array<string,mixed>
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    private function getAttributes(): array
    {
        $stmt = $this->conn->prepareRead('SELECT data FROM 202_messaging_attributes WHERE user_id = ?');
        $this->conn->bind($stmt, 'i', [$this->userId]);
        $row = $this->conn->fetchOne($stmt);

        if ($row === null || $row['data'] === null) {
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
        $stmt = $this->conn->prepareRead('SELECT dirty FROM 202_messaging_attributes WHERE user_id = ?');
        $this->conn->bind($stmt, 'i', [$this->userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null && (int) $row['dirty'] === 1;
    }

    private function clearAttributesDirty(): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE 202_messaging_attributes SET dirty = 0 WHERE user_id = ?');
        $this->conn->bind($stmt, 'i', [$this->userId]);
        $this->conn->executeUpdate($stmt);
    }

    // ---------------------------------------------------------------------
    // Sync state (202_messaging_sync)
    // ---------------------------------------------------------------------

    private function markSyncStart(): void
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_messaging_sync (user_id, last_sync) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_sync = VALUES(last_sync)'
        );
        $this->conn->bind($stmt, 'is', [$this->userId, $now]);
        $this->conn->executeUpdate($stmt);
    }

    private function recordSyncSuccess(): void
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_messaging_sync (user_id, last_sync, last_success, error_count, last_error)
             VALUES (?, ?, ?, 0, NULL)
             ON DUPLICATE KEY UPDATE last_success = VALUES(last_success), error_count = 0, last_error = NULL'
        );
        $this->conn->bind($stmt, 'iss', [$this->userId, $now, $now]);
        $this->conn->executeUpdate($stmt);
    }

    private function recordSyncError(string $error): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_messaging_sync (user_id, error_count, last_error)
             VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE error_count = error_count + 1, last_error = VALUES(last_error)'
        );
        $this->conn->bind($stmt, 'is', [$this->userId, $error]);
        $this->conn->executeUpdate($stmt);
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
