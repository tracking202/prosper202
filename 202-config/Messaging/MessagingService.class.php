<?php

declare(strict_types=1);

use Prosper202\Cron\CronLock;
use Prosper202\Database\Connection;

include_once(__DIR__ . '/MessagingClient.class.php');
include_once(__DIR__ . '/ConsentPolicy.class.php');
include_once(__DIR__ . '/OfferProfile.class.php');

/**
 * MessagingService
 *
 * Product analytics for one user of a self-hosted install: custom attributes
 * and behavioural events are recorded locally (202_messaging_attributes,
 * 202_messaging_events) and delivered to the portal's POST /track by sync(),
 * which 202-account/ajax/messaging/track.php runs after every record, and
 * deliverPendingExclusive() runs for every user with something pending from
 * the hourly tier of 202-cronjobs/index.php (and 202-cronjobs/sync-messaging.php,
 * for an operator who wants a tighter cadence), one run at a time.
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
 *    the next sync, and track.php tells the page when that is due. A sync
 *    sends at most $maxBatches batches of BATCH_SIZE events; SYNC_MORE says
 *    rows are still pending, and the page asks again like for a throttle.
 *  - The cron runs hourly, not every minute: each failed delivery counts an
 *    attempt on the events it carried, and MAX_PUSH_ATTEMPTS failures drop
 *    them, so a minutely retry would lose everything a five-minute portal
 *    outage caught.
 *  - Every statement goes through Prosper202\Database\Connection, which
 *    throws on a failed prepare, execute or result read (CLAUDE.md #1).
 */
class MessagingService
{
    /** sync() outcomes. */
    public const SYNC_IDLE = 'idle';           // nothing was pending
    public const SYNC_THROTTLED = 'throttled'; // something is pending; the last sync was too recent
    public const SYNC_SENT = 'sent';           // the pending rows were delivered
    public const SYNC_MORE = 'more';           // batches were delivered and rows are still pending
    public const SYNC_FAILED = 'failed';       // delivery (or reading what to deliver) failed

    /** Events per /track call. */
    public const BATCH_SIZE = 100;

    private const MAX_PUSH_ATTEMPTS = 5;

    private mysqli $db;
    private Connection $conn;
    private int $userId;
    /** @var array<string,mixed> */
    private array $identity;
    private ?MessagingClient $client = null;
    /** Consent decision for this instance (one lookup per sync). */
    private ?bool $analyticsSyncAllowed = null;
    /** @var array<string,string|null>|null Consent export for this instance. */
    private ?array $consentExport = null;

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
     * @param mysqli|null $db The connection to use; null for the application's
     * @return self|null Null when no active user has this id.
     * @throws \Prosper202\Database\Exceptions\QueryException when the user cannot be read
     */
    public static function forUser(int $userId, ?mysqli $db = null): ?self
    {
        if ($userId <= 0) {
            return null;
        }

        $db ??= DB::getInstance()->getConnection();
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

    /**
     * Whether analytics-tier data may leave the install for this user,
     * checked at delivery so a revocation stops rows queued earlier. Fails
     * closed: any lookup failure is "not allowed".
     */
    private function analyticsSyncAllowed(): bool
    {
        if ($this->analyticsSyncAllowed === null) {
            try {
                $this->analyticsSyncAllowed = ConsentPolicy::analyticsAllowed($this->db, $this->userId);
            } catch (Throwable $e) {
                error_log('MessagingService: consent lookup failed, failing closed: ' . $e->getMessage());
                $this->analyticsSyncAllowed = false;
            }
        }
        return $this->analyticsSyncAllowed;
    }

    /**
     * The consent block sent with every delivery, for every user (a denied
     * user's denial must reach the portal). Never throws; all-unset on failure.
     *
     * @return array<string,string|null>
     */
    private function consentExport(): array
    {
        if ($this->consentExport === null) {
            $this->consentExport = ConsentPolicy::exportForSync($this->db, $this->userId);
        }
        return $this->consentExport;
    }

    /**
     * The identity as sent: the attribute snapshot is analytics-tier, so it
     * is left out for a user without analytics consent; consent always rides.
     *
     * @return array<string,mixed>
     */
    private function outboundIdentity(): array
    {
        $identity = $this->identity;
        if (!$this->analyticsSyncAllowed()) {
            unset($identity['attributes']);
        }
        $identity['consent'] = $this->consentExport();
        return $identity;
    }

    /** A changed attribute snapshot this user may deliver (analytics consent). */
    private function attributesPending(): bool
    {
        return $this->analyticsSyncAllowed() && $this->areAttributesDirty();
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
     * One call sends up to $maxBatches batches of BATCH_SIZE events, one
     * /track call each, and stops at the first failure. When the last batch
     * went out and rows are still pending, the answer is SYNC_MORE rather
     * than SYNC_SENT: a backlog left after an outage is not reported as
     * delivered, and the caller asks again (the page after the throttle, the
     * cron on its next run).
     *
     * @param int $maxBatches Batches this call may send (at least 1)
     * @return self::SYNC_* the outcome
     */
    public function sync(bool $force = false, int $maxBatches = 1): string
    {
        $maxBatches = max(1, $maxBatches);
        try {
            $sent = false;
            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $events = $this->pendingEvents();
                $attributesDirty = $this->attributesPending();
                if ($events === [] && !$attributesDirty) {
                    return $sent ? self::SYNC_SENT : self::SYNC_IDLE;
                }
                if (!$sent && !$force && $this->secondsUntilSyncDue() > 0) {
                    return self::SYNC_THROTTLED;
                }

                $this->markSyncStart();
                if (!$this->flushEvents($events, $attributesDirty)) {
                    $this->recordSyncError('track failed');
                    return self::SYNC_FAILED;
                }
                $this->recordSyncSuccess();
                $sent = true;
            }

            return $this->pendingEvents() === [] && !$this->attributesPending() ? self::SYNC_SENT : self::SYNC_MORE;
        } catch (Throwable $e) {
            error_log('MessagingService: sync for user ' . $this->userId . ' failed: ' . $e->getMessage());
        }

        return self::SYNC_FAILED;
    }

    /**
     * The active users with something to deliver: an event still pending
     * (and not out of attempts) or a changed attribute snapshot.
     *
     * @return list<int> user ids, ascending
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    public static function usersWithPendingDelivery(mysqli $db): array
    {
        $conn = new Connection($db);
        $stmt = $conn->prepareRead(
            "SELECT u.user_id
               FROM 202_users u
              WHERE u.user_active = 1 AND u.user_deleted = 0
                AND (EXISTS (SELECT 1 FROM 202_messaging_events e
                              WHERE e.user_id = u.user_id AND e.delivery_status = 'pending'
                                AND e.sync_attempts < ?)
                  OR EXISTS (SELECT 1 FROM 202_messaging_attributes a
                              WHERE a.user_id = u.user_id AND a.dirty = 1))
              ORDER BY u.user_id ASC"
        );
        $conn->bind($stmt, 'i', [self::MAX_PUSH_ATTEMPTS]);

        return array_map(static fn (array $row): int => (int) $row['user_id'], $conn->fetchAll($stmt));
    }

    /**
     * The cron's delivery, while holding the lock at $lockPath (a CronLock,
     * CronLock::path(CronLock::ANALYTICS_DELIVERY_FILE) outside tests), or
     * null while another run holds it. 202-cronjobs/index.php runs it hourly
     * on every default deployment; 202-cronjobs/sync-messaging.php is the
     * same run on its own. Two at once read the same pending rows: each
     * event went out twice, and while the portal was down each spent two of
     * its attempts on one outage.
     *
     * @return array{sent: int, more: int, idle: int, failed: int, stopped: int}|null
     * @throws \Prosper202\Cron\CronLockFailed when the lock cannot be taken for any other reason
     * @throws \Prosper202\Database\Exceptions\QueryException when the users cannot be listed
     */
    public static function deliverPendingExclusive(
        mysqli $db,
        string $lockPath,
        int $maxBatchesPerUser = 10,
        int $maxConsecutiveFailures = 3
    ): ?array {
        // Held until this returns.
        $lock = CronLock::take($lockPath);
        if ($lock === null) {
            return null;
        }

        self::refreshProfiles($db);

        return self::deliverPending($db, $maxBatchesPerUser, $maxConsecutiveFailures);
    }

    /**
     * Recompute the offer/volume profile (OfferProfile) of every active user
     * with analytics consent, and record first_click_received once. A user
     * without consent is skipped (nothing computed or written); one user's
     * failure never stops the run or the delivery after it.
     */
    public static function refreshProfiles(mysqli $db, ?int $now = null): void
    {
        $now ??= time();
        try {
            $conn = new Connection($db);
            $stmt = $conn->prepareRead('SELECT user_id FROM 202_users WHERE user_active = 1 AND user_deleted = 0 ORDER BY user_id ASC');
            $userIds = array_map(static fn (array $row): int => (int) $row['user_id'], $conn->fetchAll($stmt));
        } catch (Throwable $e) {
            error_log('Analytics profile refresh: users could not be listed: ' . $e->getMessage());
            return;
        }

        foreach ($userIds as $userId) {
            try {
                if (!ConsentPolicy::analyticsAllowed($db, $userId)) {
                    continue;
                }
                $service = self::forUser($userId, $db);
                if ($service === null) {
                    continue;
                }
                $profile = OfferProfile::compute($db, $userId, $now);
                $readFailed = OfferProfile::lastComputeFailed();
                if ($readFailed !== null) {
                    // Partial figures would overwrite a good profile with zeros.
                    error_log("Analytics profile refresh: user {$userId} kept the last profile: {$readFailed}");
                    continue;
                }
                // Unchanged since the last run: nothing to mark for delivery.
                if (array_intersect_key($service->getAttributes(), $profile) != $profile) {
                    $service->updateAttributes($profile);
                }
                if (($profile['first_click_at'] ?? null) !== null && !$service->hasEvent('first_click_received')) {
                    $service->recordEvent('first_click_received', ['first_click_at' => (int) $profile['first_click_at']], 'analytics');
                }
            } catch (Throwable $e) {
                error_log("Analytics profile refresh failed for user {$userId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Every user usersWithPendingDelivery() names, up to $maxBatchesPerUser
     * batches each, past the per-request throttle (the cron's cadence is the
     * throttle).
     *
     * A user who cannot be read is that user's failure, not the run's. After
     * $maxConsecutiveFailures failed deliveries in a row the run stops: the
     * portal is most likely down, every further user would wait out the
     * client's timeouts and spend one of their events' attempts, and what
     * is left is delivered next run ('stopped' counts the users not tried).
     *
     * @return array{sent: int, more: int, idle: int, failed: int, stopped: int}
     * @throws \Prosper202\Database\Exceptions\QueryException when the users cannot be listed
     */
    private static function deliverPending(mysqli $db, int $maxBatchesPerUser, int $maxConsecutiveFailures): array
    {
        $counts = ['sent' => 0, 'more' => 0, 'idle' => 0, 'failed' => 0, 'stopped' => 0];
        $users = self::usersWithPendingDelivery($db);
        $failedInARow = 0;
        foreach ($users as $i => $userId) {
            if ($failedInARow >= max(1, $maxConsecutiveFailures)) {
                $counts['stopped'] = count($users) - $i;
                error_log('Analytics delivery: ' . $failedInARow . ' deliveries failed in a row; '
                    . $counts['stopped'] . ' user(s) wait for the next run');
                break;
            }
            try {
                $service = self::forUser($userId, $db);
            } catch (Throwable $e) {
                error_log('Analytics delivery: user ' . $userId . ' could not be read: ' . $e->getMessage());
                $counts['failed']++;
                continue;
            }
            if ($service === null) {
                continue;
            }

            $outcome = $service->sync(true, $maxBatchesPerUser);
            if ($outcome === self::SYNC_FAILED) {
                $counts['failed']++;
                $failedInARow++;
                continue;
            }
            $failedInARow = 0;
            $counts[$outcome === self::SYNC_THROTTLED ? 'idle' : $outcome]++;
        }

        return $counts;
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
     * Can this value be stored as an attribute? Scalars, null and arrays (the
     * offer profile is nested); objects and resources are not.
     *
     * @param mixed $value
     */
    public static function isPersistableAttributeValue($value): bool
    {
        return is_scalar($value) || $value === null || is_array($value);
    }

    /**
     * Merge custom attributes into the stored snapshot and mark it for delivery.
     * Values may be nested arrays; the snapshot is one JSON document.
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
        foreach ($attributes as $key => $value) {
            if (self::isPersistableAttributeValue($value)) {
                $current[(string) $key] = $value;
            } else {
                error_log("MessagingService: updateAttributes rejected non-persistable value for key '{$key}' (" . gettype($value) . ')');
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
     * @param string $tier 'essential' (always delivered) or 'analytics' (consent-gated)
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    public function recordEvent(string $name, ?array $metadata = null, string $tier = 'analytics'): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        // An unknown tier is refused, never stored as either tier.
        if (!in_array($tier, ['essential', 'analytics'], true)) {
            error_log("MessagingService: recordEvent rejected unknown tier '{$tier}' for event '{$name}'");
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
                (user_id, event_name, metadata, occurred_at, client_token, delivery_status, tier)
             VALUES (?, ?, ?, ?, ?, 'pending', ?)"
        );
        $this->conn->bind($stmt, 'isssss', [$this->userId, $name, $metaJson, $now, $token, $tier]);
        $this->conn->executeInsert($stmt);
    }

    /**
     * The events waiting for delivery, oldest first, at most BATCH_SIZE a call.
     * Without analytics consent only essential-tier rows; analytics rows stay
     * local (and a denial purges them, ConsentPolicy::record()).
     *
     * @return list<array{_local_id: int, name: string, metadata: mixed, occurred_at: string, client_token: string, tier: string}>
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    private function pendingEvents(): array
    {
        $stmt = $this->conn->prepareRead(
            "SELECT id, event_name, metadata, occurred_at, client_token, tier
               FROM 202_messaging_events
              WHERE user_id = ? AND delivery_status = 'pending'
                AND sync_attempts < ?"
            . ($this->analyticsSyncAllowed() ? '' : " AND tier = 'essential'")
            . " ORDER BY id ASC
              LIMIT ?"
        );
        $this->conn->bind($stmt, 'iii', [$this->userId, self::MAX_PUSH_ATTEMPTS, self::BATCH_SIZE]);

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
                'tier'         => (string) $row['tier'],
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

        // Consent read again, uncached, right before sending: rows selected
        // before a denial that landed since are not sent (the denial purged them).
        // The consent block is read again too, so a denial or an unsubscribe
        // since this instance first read it travels with this delivery.
        $this->consentExport = null;
        if ($this->analyticsSyncAllowed()) {
            try {
                $stillAllowed = ConsentPolicy::analyticsAllowed($this->db, $this->userId);
            } catch (Throwable $e) {
                $stillAllowed = false;
            }
            if (!$stillAllowed) {
                $this->analyticsSyncAllowed = false;
                $events = array_values(array_filter($events, static fn (array $e): bool => ($e['tier'] ?? '') === 'essential'));
                $payloadEvents = array_map(static function (array $e): array {
                    unset($e['_local_id']);
                    return $e;
                }, $events);
                $attributesDirty = false;
                if ($events === []) {
                    return true;
                }
            }
        }

        [$attributes, $attributesVersion] = $this->attributeSnapshot();
        if (!$this->analyticsSyncAllowed()) {
            // Analytics-tier: never sent without consent; the dirty flag stays
            // so a later grant delivers a fresh snapshot.
            $attributes = [];
        }
        $response = $this->client()->track($this->outboundIdentity(), $attributes, $payloadEvents, $this->consentExport());

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
            $this->clearAttributesDirty($attributesVersion);
        }

        return true;
    }

    /**
     * Has an event with this name ever been recorded for this user? For
     * one-shot events; a failed read answers true so it never duplicates one.
     */
    public function hasEvent(string $name): bool
    {
        try {
            $stmt = $this->conn->prepareRead('SELECT id FROM 202_messaging_events WHERE user_id = ? AND event_name = ? LIMIT 1');
            $this->conn->bind($stmt, 'is', [$this->userId, $name]);
            return $this->conn->fetchOne($stmt) !== null;
        } catch (Throwable $e) {
            error_log('MessagingService: hasEvent failed: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Whether this event was recorded at or after $since (Y-m-d H:i:s), with
     * exactly this metadata when given.
     *
     * @param array<string,mixed>|null $metadata
     */
    public function hasEventSince(string $name, string $since, ?array $metadata = null): bool
    {
        // Metadata is compared in PHP, decoded, so no JSON cast is involved. A
        // failed read is not "already recorded": it is logged and answers false.
        try {
            $stmt = $this->conn->prepareRead(
                'SELECT metadata FROM 202_messaging_events WHERE user_id = ? AND event_name = ? AND occurred_at >= ?'
            );
            $this->conn->bind($stmt, 'iss', [$this->userId, $name, $since]);
            foreach ($this->conn->fetchAll($stmt) as $row) {
                if ($metadata === null) {
                    return true;
                }
                $stored = json_decode((string) ($row['metadata'] ?? ''), true);
                if (is_array($stored) && $stored == $metadata) {
                    return true;
                }
            }
            return false;
        } catch (Throwable $e) {
            error_log('MessagingService: hasEventSince failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove analytics-tier data that has not left the install: undelivered
     * analytics events and the whole attribute snapshot (all analytics-tier).
     * Called by ConsentPolicy::record() on a denial. False when a delete failed.
     */
    public static function purgeAnalyticsData(mysqli $db, int $userId): bool
    {
        $conn = new Connection($db);
        $ok = true;
        foreach ([
            "DELETE FROM 202_messaging_events WHERE user_id = ? AND tier = 'analytics' AND delivery_status != 'sent'",
            'DELETE FROM 202_messaging_attributes WHERE user_id = ?',
        ] as $sql) {
            try {
                $stmt = $conn->prepareWrite($sql);
                $conn->bind($stmt, 'i', [$userId]);
                $conn->executeUpdate($stmt);
            } catch (Throwable $e) {
                error_log('MessagingService: analytics purge failed: ' . $e->getMessage());
                $ok = false;
            }
        }
        return $ok;
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
        return $this->attributeSnapshot()[0];
    }

    /**
     * The stored attributes and the server's MD5 of them, which names the
     * version a delivery sent (null when nothing is stored).
     *
     * @return array{0: array<string,mixed>, 1: string|null}
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    private function attributeSnapshot(): array
    {
        $stmt = $this->conn->prepareRead('SELECT data, MD5(data) AS version FROM 202_messaging_attributes WHERE user_id = ?');
        $this->conn->bind($stmt, 'i', [$this->userId]);
        $row = $this->conn->fetchOne($stmt);

        if ($row === null || $row['data'] === null) {
            return [[], null];
        }
        $version = (string) $row['version'];

        $decoded = json_decode((string) $row['data'], true);
        if (!is_array($decoded)) {
            // Our own snapshot should always decode; log corruption rather than
            // silently dropping it.
            error_log('MessagingService: stored attributes for user ' . $this->userId . ' are corrupt');
            return [[], $version];
        }
        return [$decoded, $version];
    }

    private function areAttributesDirty(): bool
    {
        $stmt = $this->conn->prepareRead('SELECT dirty FROM 202_messaging_attributes WHERE user_id = ?');
        $this->conn->bind($stmt, 'i', [$this->userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null && (int) $row['dirty'] === 1;
    }

    /**
     * Only the version that was sent: attributes another request saved while
     * the delivery was in flight stay pending for the next sync.
     */
    private function clearAttributesDirty(?string $sentVersion): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE 202_messaging_attributes SET dirty = 0 WHERE user_id = ? AND MD5(data) <=> ?');
        $this->conn->bind($stmt, 'is', [$this->userId, $sentVersion]);
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
