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
    public const SYNC_UNACKED = 'unacknowledged'; // delivered, but the portal did not acknowledge a consent change

    /** Events per /track call. */
    public const BATCH_SIZE = 100;

    private const MAX_PUSH_ATTEMPTS = 5;

    private mysqli $db;
    private Connection $conn;
    private int $userId;
    /** @var array<string,mixed> */
    private array $identity;
    private ?MessagingClient $client = null;
    /**
     * Analytics consent and its version when this instance was made: what it
     * captures is stamped with that version, so work begun before a denial
     * is never delivered after a re-grant. Null when it could not be read.
     *
     * @var array{allowed:bool,version:int}|null
     */
    private ?array $captureFence;

    /**
     * @param array<string,mixed> $identity Identity payload for the portal.
     */
    public function __construct(mysqli $db, int $userId, array $identity)
    {
        $this->db       = $db;
        $this->conn     = new Connection($db);
        $this->userId   = $userId;
        $this->identity = $identity;
        $this->captureFence = ConsentPolicy::captureFence($db, $userId);
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
     * The identity as sent: the attribute snapshot is analytics-tier, so it
     * is left out for a user without analytics consent; consent always rides.
     *
     * @param array<string,mixed> $attributes the snapshot this delivery sends
     * @param array<string,string|null> $consent
     * @return array<string,mixed>
     */
    private function outboundIdentity(bool $analyticsAllowed, array $attributes, array $consent): array
    {
        $identity = $this->identity;
        $identity['attributes'] = $attributes;
        if (!$analyticsAllowed) {
            unset($identity['attributes']);
        }
        $identity['consent'] = $consent;
        return $identity;
    }

    // ---------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------

    /**
     * Deliver pending events, a changed attribute snapshot and an
     * unacknowledged consent change to POST /track.
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
     * A delivery the portal took without acknowledging the consent version it
     * carried is SYNC_UNACKED: its rows are marked sent and the consent
     * change stays pending for the cron, which retries it every run.
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
                if (!$this->somethingPending()) {
                    return $sent ? self::SYNC_SENT : self::SYNC_IDLE;
                }
                if (!$sent && !$force && $this->secondsUntilSyncDue() > 0) {
                    return self::SYNC_THROTTLED;
                }

                $this->markSyncStart();
                $outcome = $this->flushPending();
                if ($outcome === self::SYNC_FAILED) {
                    $this->recordSyncError('track failed');
                    return self::SYNC_FAILED;
                }
                $this->recordSyncSuccess();
                if ($outcome === self::SYNC_UNACKED) {
                    return self::SYNC_UNACKED;
                }
                $sent = true;
            }

            return $this->somethingPending() ? self::SYNC_MORE : self::SYNC_SENT;
        } catch (Throwable $e) {
            error_log('MessagingService: sync for user ' . $this->userId . ' failed: ' . $e->getMessage());
        }

        return self::SYNC_FAILED;
    }

    /** Whether an event, a changed snapshot or a consent change waits for delivery. */
    private function somethingPending(): bool
    {
        $state = ConsentPolicy::deliveryState($this->db, $this->userId);
        return $state['dirty']
            || $this->pendingEvents($state) !== []
            || ($state['analytics_allowed'] && $this->areAttributesDirty($state['revoked']));
    }

    /**
     * The active users with something to deliver: an event still pending
     * (and not out of attempts, nor captured before analytics was last
     * revoked), a changed attribute snapshot, or an unacknowledged consent
     * change.
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
               LEFT JOIN 202_users_pref p ON p.user_id = u.user_id
              WHERE u.user_active = 1 AND u.user_deleted = 0
                AND (EXISTS (SELECT 1 FROM 202_messaging_events e
                              WHERE e.user_id = u.user_id AND e.delivery_status = 'pending'
                                AND e.sync_attempts < ?
                                AND (e.tier = 'essential' OR e.consent_version >= COALESCE(p.analytics_revoked_version, 0)))
                  OR EXISTS (SELECT 1 FROM 202_messaging_attributes a
                              WHERE a.user_id = u.user_id AND a.dirty = 1 AND a.withheld = 0
                                AND a.consent_version >= COALESCE(p.analytics_revoked_version, 0))
                  OR p.consent_version > p.consent_acked_version)
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
            // The portal answered: an unacknowledged consent change is still
            // pending, and is not a sign the portal is down.
            $failedInARow = 0;
            $counts[match ($outcome) {
                self::SYNC_THROTTLED => 'idle',
                self::SYNC_UNACKED => 'more',
                default => $outcome,
            }]++;
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
     * The snapshot is analytics-tier: nothing is written unless this instance
     * was made with analytics allowed, and not once analytics has been
     * revoked since (the write checks the revocation mark itself).
     *
     * @param array<string,mixed> $attributes
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    public function updateAttributes(array $attributes): void
    {
        if ($attributes === [] || $this->captureFence === null || !$this->captureFence['allowed']) {
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
        $version = $this->captureFence['version'];
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_messaging_attributes (user_id, data, dirty, updated_at, consent_version)
             SELECT ?, ?, 1, ?, ? FROM DUAL WHERE ' . self::NOT_REVOKED_SINCE . '
             ON DUPLICATE KEY UPDATE data = VALUES(data), dirty = 1, updated_at = VALUES(updated_at),
                                     consent_version = VALUES(consent_version), withheld = 0'
        );
        $this->conn->bind($stmt, 'issiii', [$this->userId, $json, $now, $version, $version, $this->userId]);
        $this->conn->executeUpdate($stmt);

        // Keep the in-memory identity fresh for a sync later this request.
        $this->identity['attributes'] = $current;
    }

    /**
     * Record a behavioural event for delivery to the portal. An analytics-tier
     * event is held to the same consent check as updateAttributes().
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
        if ($tier === 'analytics' && ($this->captureFence === null || !$this->captureFence['allowed'])) {
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
        $version = $this->captureFence['version'] ?? 0;
        $stmt  = $this->conn->prepareWrite(
            "INSERT INTO 202_messaging_events
                (user_id, event_name, metadata, occurred_at, client_token, delivery_status, tier, consent_version)
             SELECT ?, ?, ?, ?, ?, 'pending', ?, ? FROM DUAL
              WHERE ? = 'essential' OR " . self::NOT_REVOKED_SINCE
        );
        $this->conn->bind($stmt, 'isssssisii', [$this->userId, $name, $metaJson, $now, $token, $tier, $version, $tier, $version, $this->userId]);
        $this->conn->executeUpdate($stmt);
    }

    /** SQL true when analytics was not revoked after the version bound first (user id bound second). */
    private const NOT_REVOKED_SINCE = '? >= COALESCE((SELECT p.analytics_revoked_version FROM 202_users_pref p WHERE p.user_id = ?), 0)';

    /**
     * The events waiting for delivery, oldest first, at most BATCH_SIZE a call.
     * Without analytics consent only essential-tier rows; an analytics row
     * captured before analytics was last revoked is never one of them.
     *
     * @param array{analytics_allowed:bool,revoked:int} $state from ConsentPolicy::deliveryState()
     * @return list<array{_local_id: int, _consent_version: int, name: string, metadata: mixed, occurred_at: string, client_token: string, tier: string}>
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    private function pendingEvents(array $state): array
    {
        $stmt = $this->conn->prepareRead(
            "SELECT id, event_name, metadata, occurred_at, client_token, tier, consent_version
               FROM 202_messaging_events
              WHERE user_id = ? AND delivery_status = 'pending'
                AND sync_attempts < ?"
            . ($state['analytics_allowed'] ? " AND (tier = 'essential' OR consent_version >= ?)" : " AND tier = 'essential'")
            . " ORDER BY id ASC
              LIMIT ?"
        );
        if ($state['analytics_allowed']) {
            $this->conn->bind($stmt, 'iiii', [$this->userId, self::MAX_PUSH_ATTEMPTS, $state['revoked'], self::BATCH_SIZE]);
        } else {
            $this->conn->bind($stmt, 'iii', [$this->userId, self::MAX_PUSH_ATTEMPTS, self::BATCH_SIZE]);
        }

        $events = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $metadata = null;
            if ($row['metadata'] !== null) {
                $decoded = json_decode((string) $row['metadata'], true);
                $metadata = is_array($decoded) ? $decoded : null;
            }
            $events[] = [
                '_local_id'        => (int) $row['id'],
                '_consent_version' => (int) $row['consent_version'],
                'name'             => (string) $row['event_name'],
                'metadata'         => $metadata,
                'occurred_at'      => (string) $row['occurred_at'],
                'client_token'     => (string) $row['client_token'],
                'tier'             => (string) $row['tier'],
            ];
        }

        return $events;
    }

    /**
     * Deliver one batch: the pending events, the attribute snapshot when it
     * changed, and the consent block with its version. Consent is read again
     * right before sending and decides what goes: analytics rows and the
     * snapshot only while analytics is allowed and only when captured since
     * it was last revoked.
     *
     * Events are marked sent, and the snapshot clean, only when the portal
     * took them; a consent change only when the portal acknowledged its
     * version (`consent_ack`), since a portal can answer ok without having
     * stored it.
     *
     * @return self::SYNC_SENT|self::SYNC_UNACKED|self::SYNC_FAILED
     * @throws \Throwable when what to deliver cannot be read
     */
    private function flushPending(): string
    {
        $events = $this->pendingEvents(ConsentPolicy::deliveryState($this->db, $this->userId));
        [$attributes, $attributesHash, $attributesVersion, $attributesDirty] = $this->attributeSnapshot();

        $state = ConsentPolicy::deliveryState($this->db, $this->userId);
        $fresh = static fn (int $version): bool => $state['analytics_allowed'] && $version >= $state['revoked'];
        $events = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['tier'] === 'essential' || $fresh($e['_consent_version'])
        ));
        if ($attributesHash === null || !$fresh($attributesVersion)) {
            // Analytics-tier: never sent without consent; the dirty flag stays
            // so a later grant delivers a fresh snapshot.
            $attributes = [];
            $attributesDirty = false;
        }
        if ($events === [] && !$attributesDirty && !$state['dirty']) {
            return self::SYNC_SENT;
        }

        $payloadEvents = array_map(static function (array $e): array {
            unset($e['_local_id'], $e['_consent_version']);
            return $e;
        }, $events);
        $response = $this->client()->track(
            $this->outboundIdentity($state['analytics_allowed'], $attributes, $state['consent']),
            $attributes,
            $payloadEvents,
            $state['consent'] + ['version' => $state['version']]
        );

        if ($response === null) {
            // Bump attempts so poison events eventually stop being retried.
            foreach ($events as $e) {
                $this->incrementEventAttempts($e['_local_id']);
            }
            return self::SYNC_FAILED;
        }

        foreach ($events as $e) {
            $this->markEventSent($e['_local_id']);
        }
        if ($attributesDirty) {
            $this->clearAttributesDirty($attributesHash);
        }
        if (!$state['dirty']) {
            return self::SYNC_SENT;
        }
        $ack = $response['consent_ack'] ?? null;
        if (!is_int($ack) || $ack !== $state['version']) {
            error_log('MessagingService: the portal did not acknowledge consent version ' . $state['version']
                . ' for user ' . $this->userId . '; it stays pending');
            return self::SYNC_UNACKED;
        }
        ConsentPolicy::acknowledgeDelivery($this->db, $this->userId, $state['version']);

        return self::SYNC_SENT;
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
        // MySQL assigns SET left to right: the status must read the old count.
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_messaging_events
                SET delivery_status = IF(sync_attempts + 1 >= ?, 'failed', 'pending'),
                    sync_attempts = sync_attempts + 1
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
     * The stored attributes, or none when they were captured before
     * analytics was last revoked.
     *
     * @return array<string,mixed>
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    private function getAttributes(): array
    {
        $snapshot = $this->attributeSnapshot();
        $state = ConsentPolicy::deliveryState($this->db, $this->userId);

        return $snapshot[2] >= $state['revoked'] ? $snapshot[0] : [];
    }

    /**
     * The stored attributes, the server's MD5 of them (which names the version
     * a delivery sent; null when nothing is stored), the consent version they
     * were captured under, and whether they changed since the last delivery.
     *
     * @return array{0: array<string,mixed>, 1: string|null, 2: int, 3: bool}
     * @throws \Prosper202\Database\Exceptions\QueryException
     */
    private function attributeSnapshot(): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT data, MD5(data) AS version, consent_version, dirty, withheld FROM 202_messaging_attributes WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$this->userId]);
        $row = $this->conn->fetchOne($stmt);

        // A withheld snapshot (recorded before consent existed) reads as none.
        if ($row === null || $row['data'] === null || (int) $row['withheld'] === 1) {
            return [[], null, 0, false];
        }
        $version = (string) $row['version'];
        $captured = (int) $row['consent_version'];
        $dirty = (int) $row['dirty'] === 1;

        $decoded = json_decode((string) $row['data'], true);
        if (!is_array($decoded)) {
            // Our own snapshot should always decode; log corruption rather than
            // silently dropping it.
            error_log('MessagingService: stored attributes for user ' . $this->userId . ' are corrupt');
            return [[], $version, $captured, $dirty];
        }
        return [$decoded, $version, $captured, $dirty];
    }

    /** A changed snapshot captured since analytics was last revoked at $revoked. */
    private function areAttributesDirty(int $revoked): bool
    {
        $stmt = $this->conn->prepareRead('SELECT dirty, consent_version, withheld FROM 202_messaging_attributes WHERE user_id = ?');
        $this->conn->bind($stmt, 'i', [$this->userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null && (int) $row['dirty'] === 1 && (int) $row['withheld'] === 0
            && (int) $row['consent_version'] >= $revoked;
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
