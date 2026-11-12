<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Click\FirstVisit;
use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\Ledger\RowCurrency;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\DataEngine\IpAddressSql;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\Identity\RequestSignals;
use Prosper202\Identity\SignalType;
use Prosper202\Http\EuropeanVisitor;
use Prosper202\Http\PrivacySetting;
use Prosper202\Http\StoredVisitorIp;
use Prosper202\Notifications\NotificationOutbox;
use Prosper202\Report\AccountZone;

/**
 * The destinations' queue and delivery log (202_destination_deliveries).
 *
 * Queued as the traffic-source outbox is (NotificationOutbox): inside the
 * conversion's transaction, by its one writer, so a conversion and the
 * promise to send it commit together. Sent by the worker under the outbox's
 * rules: claimed by a compare-and-set on attempts, NotificationOutbox::
 * backoff() between tries and MAX_ATTEMPTS in all, a refusal failed at once,
 * no row claimed after the run's seconds, and a host that gave no answer
 * skipped for the rest of the run. The body is built at send time from the
 * ledger, so no secret and no identifier is ever stored in a row.
 *
 * Before a send (owner, 2026-11-04): a conversion whose click was guessed
 * (not named: MatchMethod::namesTheClick()) is withheld; so is every visitor under
 * privacy 'all', and a visitor who may be in the EEA or UK (EuropeanVisitor)
 * unless the setting is 'disabled' and the destination states both consent
 * signals granted. Withheld rows are never retried by the worker; the
 * operator can send one again (resend()), and it is checked again.
 *
 * A send that says to wait (SendResult::$waitUntil: a click too recent for
 * the platform, a destination not yet authorised) is not a try: the row
 * stays pending with its attempts unchanged until then.
 */
final class DestinationQueue
{
    public const KIND_CONVERSION = 'conversion';
    public const KIND_TEST = 'test';
    public const STATUSES = ['pending', 'sent', 'failed', 'withheld', 'cancelled'];
    /** The statuses a row can be sent again from. */
    public const RESENDABLE = ['failed', 'withheld'];
    /** Rows a bulk send-again takes are at most this old: no platform takes an older click. */
    public const RESEND_DAYS = 90;
    public const RESEND_BATCH = 5000;
    public const BACKFILL_BATCH = 5000;
    /** About how many rows the cron job sends a minute, for every account together (202-cronjobs/index.php). */
    public const SENT_A_MINUTE = 200;

    /** Why a conversion with no currency recorded is held back; releaseHeldForCurrency() finds its rows by it. */
    public const NO_CURRENCY = 'withheld: nothing recorded which currency this conversion\'s amount is in';
    /** The minutely cron's budget. */
    public const RUN_SECONDS = 15;
    /** The longest page address a destination is given; a longer one is none, never cut into another page. */
    public const PAGE_URL_CHARS = 2048;
    /** Ranges filter_var() passes that hold no visitor a platform could match: shared, IETF-protocol, benchmarking. */
    private const NOT_A_VISITOR = [
        ['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 4],
        // IPv6 forms that carry an IPv4 address (NAT64, 6to4, Teredo), and multicast.
        ['64:ff9b::', 96], ['64:ff9b:1::', 48], ['2002::', 16], ['2001::', 32], ['ff00::', 8],
    ];

    private readonly DestinationStore $store;
    /** @var \Closure(string): bool */
    private \Closure $mayBeEuropean;
    /** @var array<int, string> */
    private array $zones = [];

    /** @param (callable(string): bool)|null $mayBeEuropean EuropeanVisitor::mayBe() by default */
    public function __construct(private readonly Connection $conn, ?DestinationStore $store = null, ?callable $mayBeEuropean = null)
    {
        $this->store = $store ?? new DestinationStore($conn);
        $this->mayBeEuropean = \Closure::fromCallable($mayBeEuropean ?? static fn (string $ip): bool => EuropeanVisitor::mayBe($ip));
    }

    /**
     * Queue a new conversion for each of the account's enabled destinations
     * that takes its campaign, in the caller's transaction. Before the
     * upgrade has made the table (code first, upgrade after) nothing is
     * queued; any other failure throws. record() runs this in a savepoint and
     * rethrows only a lock error or a rollback-only transaction: anything else
     * is logged and the conversion commits unqueued.
     */
    public function queueConversion(int $userId, int $convId, int $campaignId, int $now): int
    {
        try {
            $stmt = $this->conn->prepareWrite('SELECT destination_id, settings FROM `' . TableRegistry::DESTINATIONS . '` WHERE user_id = ? AND enabled = 1 AND deleted_at IS NULL');
        } catch (\Throwable $e) {
            if (Connection::isMysqlError($e, 1146, "doesn't exist")) {
                return 0;
            }
            throw $e;
        }
        $this->conn->bind($stmt, 'i', [$userId]);
        $queued = 0;
        foreach ($this->conn->fetchAll($stmt) as $row) {
            // Unreadable settings queue the row rather than fail the conversion; the worker says why it cannot send.
            $campaigns = json_decode((string) $row['settings'], true)['campaign_ids'] ?? [];
            if (is_array($campaigns) && $campaigns !== [] && !in_array($campaignId, $campaigns, true)) {
                continue;
            }
            $queued += $this->insert($userId, (int) $row['destination_id'], $convId, self::KIND_CONVERSION, 'pending', $now);
        }

        return $queued;
    }

    /**
     * Queue again the account's conversions held back for having no
     * currency recorded, once the owner has recorded one for them (Personal
     * settings): each is sent at the worker's next run under every rule a
     * new one is. One whose conversion still has none is held back again.
     * Returns how many were released.
     */
    public function releaseHeldForCurrency(int $userId, int $now): int
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . "` SET status = 'pending', attempts = 0, next_attempt_at = ?, last_error = NULL"
            . " WHERE user_id = ? AND kind = 'conversion' AND status = 'withheld' AND last_error = ?");
        $this->conn->bind($stmt, 'iis', [$now, $userId, self::NO_CURRENCY]);

        return $this->conn->executeUpdate($stmt);
    }

    /**
     * Send what is due, oldest first: at most $limit rows, none claimed after $seconds.
     *
     * @return array{sent: int, retrying: int, failed: int, withheld: int, cancelled: int}
     */
    public function sendDue(int $limit, int $seconds = self::RUN_SECONDS, ?int $now = null): array
    {
        $stopAt = microtime(true) + max(1, $seconds);
        $now ??= time();
        $out = ['sent' => 0, 'retrying' => 0, 'failed' => 0, 'withheld' => 0, 'cancelled' => 0];
        $unanswered = [];
        $waiting = [];
        $destinations = [];
        $after = 0;
        $tried = 0;
        while ($tried < $limit && microtime(true) < $stopAt) {
            // Paged by id, so rows passed over this run do not fill the next page (CLAUDE.md #37).
            try {
                // A switched-off destination's rows are not read at all: they wait, however many there are.
                $stmt = $this->conn->prepareWrite("SELECT q.delivery_id, q.user_id, q.destination_id, q.conv_id, q.attempts
                    FROM 202_destination_deliveries q
                    JOIN 202_destinations d ON d.destination_id = q.destination_id AND d.user_id = q.user_id AND d.enabled = 1 AND d.deleted_at IS NULL
                    JOIN 202_users u ON u.user_id = q.user_id AND u.user_active = 1 AND u.user_deleted = 0
                    WHERE q.status = 'pending' AND q.kind = 'conversion' AND q.next_attempt_at <= ? AND q.delivery_id > ? ORDER BY q.delivery_id LIMIT ?");
            } catch (\Throwable $e) {
                // Code deployed before the upgrade made the table: nothing is queued yet.
                if ($after === 0 && Connection::isMysqlError($e, 1146, "doesn't exist")) {
                    return $out;
                }
                throw $e;
            }
            $this->conn->bind($stmt, 'iii', [$now, $after, max(1, $limit)]);
            $rows = $this->conn->fetchAll($stmt);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $after = (int) $row['delivery_id'];
                if ($tried >= $limit || microtime(true) >= $stopAt) {
                    break 2;
                }
                $key = (int) $row['user_id'] . ':' . (int) $row['destination_id'];
                $destinations[$key] ??= $this->destination((int) $row['user_id'], (int) $row['destination_id']);
                $destination = $destinations[$key];
                if ($destination === null) {
                    $this->finish($after, 'cancelled', null, 'the destination was deleted before this was sent', null, $now);
                    $out['cancelled']++;
                    continue;
                }
                if (is_array($destination) && (!$destination['enabled'] || isset($unanswered[$destination['host']]) || isset($waiting[$key]))) {
                    // Switched off, a host that did not answer, or a destination that waits for its operator: left for a later run.
                    continue;
                }
                $attempts = (int) $row['attempts'] + 1;
                $claim = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
                    . "` SET attempts = ?, next_attempt_at = ? WHERE delivery_id = ? AND status = 'pending' AND attempts = ?");
                $this->conn->bind($claim, 'iiii', [$attempts, $now + NotificationOutbox::backoff($attempts), $after, $attempts - 1]);
                if ($this->conn->executeUpdate($claim) !== 1) {
                    continue;
                }
                $tried++;
                $threw = is_string($destination);
                try {
                    // A destination that cannot be read says why on each of its rows, which end failed after their tries.
                    [$status, $result] = is_string($destination)
                        ? ['pending', new SendResult(false, true, null, 'not sent: ' . $destination)]
                        : $this->deliver($destination, (int) $row['conv_id'], $now);
                } catch (\JsonException | DestinationInputException $e) {
                    // This row's data, not the run's: it fails alone (CLAUDE.md #24).
                    [$status, $result] = ['failed', new SendResult(false, false, null, 'not sent: ' . $e->getMessage())];
                } catch (\Throwable $e) {
                    if (Connection::isRetryableLockError($e)) {
                        throw $e;
                    }
                    // Counted as a try, so a row that always throws ends failed rather than blocking the queue.
                    error_log('destinations: delivery ' . $after . ' could not be sent: ' . $e->getMessage());
                    $threw = true;
                    [$status, $result] = ['pending', new SendResult(false, true, null, 'not sent: ' . get_class($e) . ' while reading the conversion; tried again later')];
                }
                if (!$threw && $result->unanswered()) {
                    $unanswered[$destination['host']] = true;
                }
                if (!$threw && $status === 'pending' && $result->waitUntil !== null) {
                    $this->wait($after, $attempts - 1, $result, $result->waitUntil);
                    if ($result->destinationWaits) {
                        $waiting[$key] = true;
                        $this->waitDestination((int) $row['user_id'], (int) $row['destination_id'], $result->waitUntil);
                    }
                    $out['retrying']++;
                    continue;
                }
                if ($status === 'pending' && $attempts >= NotificationOutbox::MAX_ATTEMPTS) {
                    $status = 'failed';
                }
                $this->finish($after, $status, $result?->status, $result?->reason ?? '', $result?->summary, $now);
                $out[$status === 'pending' ? 'retrying' : $status]++;
            }
        }
        foreach ($destinations as $destination) {
            if (is_string($destination)) {
                error_log('destinations: ' . $destination);
            }
        }

        return $out;
    }

    /**
     * Send a made-up conversion to the destination's validation endpoint now,
     * and log it. Null when the account has no such destination.
     */
    public function testFire(int $userId, int $destinationId, int $now): ?array
    {
        $destination = $this->destination($userId, $destinationId);
        if ($destination === null) {
            return null;
        }
        if (is_string($destination)) {
            throw new DestinationInputException('credentials', $destination . '; set the credentials again');
        }
        try {
            $result = $destination['adapter']->send(
                $destination['settings'],
                $destination['credentials'],
                ConversionFacts::sample($now, $this->currency($userId), $this->zone($userId)),
                true,
                $now
            );
        } catch (\JsonException $e) {
            $result = new SendResult(false, false, null, 'not sent: ' . $e->getMessage());
        }
        $id = $this->insertTest($userId, $destinationId, $result, $now);

        return [
            'delivery_id' => $id,
            'valid' => $result->delivered,
            'status_code' => $result->status,
            'messages' => $result->messages,
            'reason' => $result->reason !== '' ? $result->reason : null,
            'sent' => $result->summary,
        ];
    }

    /**
     * Put rows that ended failed or withheld back in the queue with all their
     * tries: one ($deliveryId), or the destination's of $status made in the
     * last RESEND_DAYS, at most RESEND_BATCH a call. The send's checks run
     * again, so a row still held back is withheld again. Null when the
     * account has no such destination.
     *
     * @return array{queued: int, more: bool}|null
     */
    public function resend(int $userId, int $destinationId, ?int $deliveryId, string $status, int $now): ?array
    {
        if ($deliveryId === null && !in_array($status, self::RESENDABLE, true)) {
            throw new DestinationInputException('status', 'status must be one of: ' . implode(', ', self::RESENDABLE) . '.');
        }
        if ($this->store->get($userId, $destinationId) === null) {
            return null;
        }
        $set = "` SET status = 'pending', attempts = 0, next_attempt_at = ?, last_status_code = NULL, last_error = NULL, sent_at = NULL
             WHERE user_id = ? AND destination_id = ? AND kind = 'conversion'";
        if ($deliveryId !== null) {
            $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES . $set . " AND status IN ('failed', 'withheld') AND delivery_id = ?");
            $this->conn->bind($stmt, 'iiii', [$now, $userId, $destinationId, $deliveryId]);

            return ['queued' => $this->conn->executeUpdate($stmt), 'more' => false];
        }
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES . $set . ' AND status = ? AND created_at >= ? ORDER BY delivery_id LIMIT ' . self::RESEND_BATCH);
        $this->conn->bind($stmt, 'iiisi', [$now, $userId, $destinationId, $status, $now - self::RESEND_DAYS * 86400]);
        $queued = $this->conn->executeUpdate($stmt);

        return ['queued' => $queued, 'more' => $queued === self::RESEND_BATCH];
    }

    /**
     * Queue the paid conversions of the last $days that the destination has
     * no row for (recorded before it existed, or while it was off), oldest
     * first, at most BACKFILL_BATCH a call. Null when the account has no such
     * destination.
     *
     * @return array{queued: int, more: bool}|null
     */
    public function backfill(int $userId, int $destinationId, int $days, int $now): ?array
    {
        $destination = $this->store->get($userId, $destinationId);
        if ($destination === null) {
            return null;
        }
        $most = self::backfillDays((string) $destination['type']);
        if ($days < 1 || $days > $most) {
            throw new DestinationInputException('days', 'days must be 1 to ' . $most . ' for this destination: its platform takes nothing older.');
        }
        $campaigns = ((array) $destination['settings'])['campaign_ids'] ?? [];
        if (!is_array($campaigns)) {
            throw new DestinationInputException('settings.campaign_ids', 'This destination\'s campaign filter cannot be read; save its campaigns again.');
        }
        $campaigns = array_values(array_filter($campaigns, 'is_int'));
        $queued = 0;
        $after = 0;
        while ($queued < self::BACKFILL_BATCH) {
            // Read, then written by key: an INSERT ... SELECT would hold the ledger's rows while it ran (CLAUDE.md #36).
            $stmt = $this->conn->prepareWrite('SELECT cl.conv_id FROM 202_conversion_logs cl
                WHERE cl.user_id = ? AND cl.conv_time >= ? AND cl.conv_id > ? AND cl.deleted = 0 AND cl.superseded_by IS NULL
                  AND cl.reverses_conv_id IS NULL AND cl.payable = 1 AND cl.click_payout >= 0'
                . ($campaigns === [] ? '' : ' AND cl.campaign_id IN (' . implode(',', array_fill(0, count($campaigns), '?')) . ')') . '
                  AND NOT EXISTS (SELECT 1 FROM 202_destination_deliveries q
                      WHERE q.destination_id = ? AND q.conv_id = cl.conv_id AND q.kind = \'conversion\' AND q.user_id = cl.user_id)
                ORDER BY cl.conv_id LIMIT 500');
            $this->conn->bind($stmt, 'iii' . str_repeat('i', count($campaigns)) . 'i', [$userId, $now - $days * 86400, $after, ...$campaigns, $destinationId]);
            $rows = $this->conn->fetchAll($stmt);
            foreach ($rows as $row) {
                $after = (int) $row['conv_id'];
                $queued += $this->insert($userId, $destinationId, $after, self::KIND_CONVERSION, 'pending', $now);
            }
            if (count($rows) < 500) {
                return ['queued' => $queued, 'more' => false];
            }
        }

        return ['queued' => $queued, 'more' => true];
    }

    /** How far back a type's platform takes a conversion, in days. */
    public static function backfillDays(string $type): int
    {
        return $type === Ga4Destination::TYPE ? intdiv(Ga4Destination::MAX_AGE_SECONDS, 86400) : intdiv(GoogleAdsDestination::CLICK_MAX_AGE, 86400);
    }

    /**
     * What each destination's conversions came to since $since, and why the
     * failed and withheld ones were (the commonest reasons first), with how
     * many could be sent again (those of the last RESEND_DAYS).
     *
     * @param list<int> $destinationIds
     * `waiting` is every conversion still to be sent, whatever its age.
     *
     * @return array<int, array{counts: array<string, int>, reasons: list<array{status: string, reason: string, n: int}>, resendable: array<string, int>, waiting: int}>
     */
    public function health(int $userId, array $destinationIds, int $since, int $now): array
    {
        $out = [];
        foreach ($destinationIds as $id) {
            $out[$id] = ['counts' => array_fill_keys(self::STATUSES, 0), 'reasons' => [], 'resendable' => array_fill_keys(self::RESENDABLE, 0), 'waiting' => 0];
        }
        if ($out === []) {
            return [];
        }
        // Every key part named, so the index is read by range and the log's length does not matter.
        $in = implode(',', array_fill(0, count($out), '?'));
        $ids = array_keys($out);
        $where = ' FROM `' . TableRegistry::DESTINATION_DELIVERIES . "` WHERE user_id = ? AND destination_id IN ($in) AND kind = 'conversion' AND status IN (";
        $types = 'i' . str_repeat('i', count($ids)) . 'i';
        $all = "'" . implode("','", self::STATUSES) . "'";
        $stmt = $this->conn->prepareRead('SELECT destination_id, status, COUNT(*) AS n' . $where . $all . ') AND created_at >= ? GROUP BY destination_id, status');
        $this->conn->bind($stmt, $types, [$userId, ...$ids, $since]);
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[(int) $row['destination_id']]['counts'][(string) $row['status']] = (int) $row['n'];
        }
        $stmt = $this->conn->prepareRead('SELECT destination_id, status, COUNT(*) AS n' . $where . "'failed','withheld') AND created_at >= ? GROUP BY destination_id, status");
        $this->conn->bind($stmt, $types, [$userId, ...$ids, $now - self::RESEND_DAYS * 86400]);
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[(int) $row['destination_id']]['resendable'][(string) $row['status']] = (int) $row['n'];
        }
        $stmt = $this->conn->prepareRead('SELECT destination_id, COUNT(*) AS n' . $where . "'pending') GROUP BY destination_id");
        $this->conn->bind($stmt, substr($types, 0, -1), [$userId, ...$ids]);
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[(int) $row['destination_id']]['waiting'] = (int) $row['n'];
        }
        $stmt = $this->conn->prepareRead("SELECT destination_id, status, COALESCE(last_error, '') AS reason, COUNT(*) AS n" . $where
            . "'failed','withheld') AND created_at >= ? GROUP BY destination_id, status, reason ORDER BY n DESC, reason LIMIT 500");
        $this->conn->bind($stmt, $types, [$userId, ...$ids, $since]);
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $id = (int) $row['destination_id'];
            if (count($out[$id]['reasons']) < 5) {
                $out[$id]['reasons'][] = ['status' => (string) $row['status'], 'reason' => (string) $row['reason'], 'n' => (int) $row['n']];
            }
        }

        return $out;
    }

    /**
     * A page of a destination's log, newest first, each conversion with its
     * click; $limit + 1 rows when there are more. With a status, conversions
     * only (a test is listed in the unfiltered log).
     *
     * @return list<array<string, mixed>>
     */
    public function log(int $userId, int $destinationId, ?string $status, int $limit, int $offset): array
    {
        $select = 'SELECT q.delivery_id, q.conv_id, q.kind, q.status, q.attempts, q.last_status_code, q.last_error, q.created_at, cl.click_id
            FROM `' . TableRegistry::DESTINATION_DELIVERIES . '` q LEFT JOIN 202_conversion_logs cl ON cl.conv_id = q.conv_id AND cl.user_id = q.user_id
            WHERE q.user_id = ? AND q.destination_id = ?';
        if ($status === null) {
            $stmt = $this->conn->prepareRead($select . ' ORDER BY q.delivery_id DESC LIMIT ? OFFSET ?');
            $this->conn->bind($stmt, 'iiii', [$userId, $destinationId, $limit + 1, $offset]);
        } else {
            // In the order of user_destination_status, so a long log is not sorted to show a page.
            $stmt = $this->conn->prepareRead($select . " AND q.kind = 'conversion' AND q.status = ? ORDER BY q.created_at DESC, q.delivery_id DESC LIMIT ? OFFSET ?");
            $this->conn->bind($stmt, 'iisii', [$userId, $destinationId, $status, $limit + 1, $offset]);
        }

        return $this->conn->fetchAll($stmt);
    }

    /**
     * One conversion's send. Returns the row's next status and the send's
     * answer (null when nothing was sent).
     *
     * @param array<string, mixed> $destination
     * @return array{0: string, 1: SendResult|null}
     */
    private function deliver(array $destination, int $convId, int $now): array
    {
        $userId = $destination['user_id'];
        // The primary, not a replica: a row a lagging replica lacks is not a deleted one.
        // 202_clicks_advance and 202_clicks_site are one row per click, written by its latest visit.
        $stmt = $this->conn->prepareWrite('SELECT cl.click_id, cl.campaign_id, cl.conv_time, cl.click_payout, cl.transaction_id, cl.deleted, cl.superseded_by, cl.customer_id, cl.match_method, cl.currency, '
            . IpAddressSql::address('ip', 'ip6') . ' AS ip_address, landing.site_url_address AS landing_url, outbound.site_url_address AS outbound_url,
                   ac.aff_campaign_id AS campaign_found, ac.identity_signals
            FROM 202_conversion_logs cl LEFT JOIN 202_clicks_advance ca ON ca.click_id = cl.click_id LEFT JOIN 202_ips ip ON ip.ip_id = ca.ip_id'
            . IpAddressSql::join('ip', 'ip6') . '
            LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = cl.campaign_id AND ac.user_id = cl.user_id
            LEFT JOIN 202_clicks_site cs ON cs.click_id = cl.click_id
            LEFT JOIN 202_site_urls landing ON landing.site_url_id = cs.click_landing_site_url_id AND cs.click_landing_site_url_id > 0
            LEFT JOIN 202_site_urls outbound ON outbound.site_url_id = cs.click_outbound_site_url_id AND cs.click_outbound_site_url_id > 0
            WHERE cl.conv_id = ? AND cl.user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$convId, $userId]);
        $conv = $this->conn->fetchOne($stmt);
        if ($conv === null || (int) $conv['deleted'] === 1) {
            return ['cancelled', new SendResult(false, false, null, 'the conversion was deleted before this was sent')];
        }
        if ($conv['superseded_by'] !== null) {
            return ['cancelled', new SendResult(false, false, null, 'the conversion was replaced by conversion ' . (int) $conv['superseded_by'] . ' before this was sent')];
        }
        $campaigns = $destination['settings']['campaign_ids'] ?? [];
        if (!is_array($campaigns)) {
            // A filter that is not a list is not read as "every campaign".
            return ['failed', new SendResult(false, false, null, 'not sent: destination ' . (int) $destination['destination_id'] . '\'s campaign filter cannot be read; save its campaigns again')];
        }
        if ($campaigns !== [] && !in_array((int) $conv['campaign_id'], $campaigns, true)) {
            return ['cancelled', new SendResult(false, false, null, 'the conversion\'s campaign is not one this destination sends')];
        }
        $ip = (string) ($conv['ip_address'] ?? '');
        // Only a click the conversion named: a guess would teach the platform the wrong clicks.
        $method = MatchMethod::tryFrom((string) ($conv['match_method'] ?? '')) ?? MatchMethod::UNKNOWN;
        if (!$method->namesTheClick()) {
            return ['withheld', new SendResult(false, false, null, 'withheld: the click was found by ' . $method->label() . ', not named by the conversion')];
        }
        $setting = PrivacySetting::forAccount($this->conn, $userId);
        if ($setting === 'all') {
            return ['withheld', new SendResult(false, false, null, 'withheld: your privacy setting holds back every visitor')];
        }
        if (($this->mayBeEuropean)($ip)) {
            if ($setting !== 'disabled') {
                return ['withheld', new SendResult(false, false, null, 'withheld: your privacy setting holds back visitors who may be in Europe, and this click\'s address is not placed outside it')];
            }
            if (!self::consentGranted($destination['settings'])) {
                return ['withheld', new SendResult(false, false, null, 'withheld: the visitor may be in the EEA or UK, and this destination does not state consent granted (ad_user_data and ad_personalization)')];
            }
        }
        // The platform is told the currency the row recorded, never the
        // account's now; an amount whose currency nothing recorded is not sent.
        $currency = RowCurrency::read($conv['currency'] ?? null);
        if ($currency === RowCurrency::UNKNOWN) {
            return ['withheld', new SendResult(false, false, null, self::NO_CURRENCY)];
        }
        $clickId = (int) $conv['click_id'];
        $click = TrafficSourcePixels::clickTokens($this->conn, $userId, $clickId, true);
        if ($click === null) {
            return ['failed', new SendResult(false, false, null, 'not sent: the conversion\'s click ' . $clickId . ' is not found')];
        }
        $tokens = $click['tokens'];
        // The first visit's, as the click's ids are: when the platform saw the click.
        $visit = $this->conn->prepareWrite('SELECT c.click_time FROM ' . FirstVisit::of('?') . ' AS c WHERE c.user_id = ?');
        $this->conn->bind($visit, 'ii', [$clickId, $userId]);
        $clickTime = $this->conn->fetchOne($visit)['click_time'] ?? null;
        [$customerId, $emails, $phones] = $this->customer($destination['settings'], (int) ($conv['customer_id'] ?? 0), $userId);
        $eventId = DestinationEventId::of($this->conn, $userId, $clickId, $convId, isset($conv['transaction_id']) ? (string) $conv['transaction_id'] : null);
        $facts = new ConversionFacts(
            $convId,
            $clickId,
            (int) $conv['campaign_id'],
            (int) $conv['conv_time'],
            (string) $conv['click_payout'],
            $currency,
            $eventId,
            $tokens,
            $customerId,
            $emails,
            $phones,
            $this->zone($userId),
            $clickTime === null ? null : (int) $clickTime,
            self::sendsUserData($destination['settings']) && self::visitorMayBeIdentified($conv) && $this->latestVisitSignalled($clickId, $userId)
                ? self::visitorAddress($ip) : null,
            // Not stored for a click yet: nothing is guessed in its place.
            null,
            self::pageUrl($conv['landing_url'] ?? null) ?? self::pageUrl($conv['outbound_url'] ?? null)
        );
        $result = $destination['adapter']->send($destination['settings'], $destination['credentials'], $facts, false, $now);

        return [match (true) {
            $result->delivered => 'sent',
            $result->withheld => 'withheld',
            $result->retry || $result->waitUntil !== null => 'pending',
            default => 'failed',
        }, $result];
    }

    /**
     * The customer's id and, when the destination sends user data and the
     * account has not stated ad_user_data denied, their hashed email and phone.
     *
     * @param array<string, mixed> $settings
     * @return array{0: int|null, 1: list<string>, 2: list<string>}
     */
    private function customer(array $settings, int $customerId, int $userId): array
    {
        if ($customerId <= 0) {
            return [null, [], []];
        }
        $stmt = $this->conn->prepareWrite('SELECT email, phone FROM `' . TableRegistry::CUSTOMERS . '` WHERE customer_id = ? AND user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return [null, [], []];
        }
        // The id goes only where the hashes may.
        if (!self::sendsUserData($settings)) {
            return [null, [], []];
        }
        $email = PiiHash::email($row['email'] ?? null);
        $phone = PiiHash::phone($row['phone'] ?? null);

        return [$customerId, $email === null ? [] : [$email], $phone === null ? [] : [$phone]];
    }

    /**
     * Whether the destination sends what identifies the visitor (hashes, the
     * customer's id, the address). An allowlist: unstated or granted
     * ad_user_data sends, anything else (denied, a corrupt value) does not.
     *
     * @param array<string, mixed> $settings
     */
    private static function sendsUserData(array $settings): bool
    {
        return ($settings['send_user_data'] ?? false) === true && in_array($settings['consent']['ad_user_data'] ?? null, [null, 'granted'], true);
    }

    /**
     * Whether the click's visitor may be identified to a platform, as a Meta
     * Purchase pixel asks it (FacebookPixelAssistantScript::mayTrack()): the
     * campaign's identity capture is on (RequestSignals::campaignAllows()),
     * and the click's latest visit did not refuse consent (p202_consent). A
     * refusal is not stored, but its mark is: a browser signal (the visitor
     * cookie, the landing page id; 202_identity_observations) is recorded
     * only from a request that did not refuse consent, under a campaign that
     * allowed capture (ClickIdentity::fromRequest()), with the time of the
     * visit it came with. So the address, which is the latest visit's, goes
     * only when a signal came with that visit (latestVisitSignalled()): a re-click that refused
     * leaves the earlier visits' marks behind it. A link a conversion's
     * customer id makes later (trustedCustomer(), customerOnly()) is no such
     * mark. The latest visit is the account's own (#27). A click with no
     * such signal, or whose campaign is not the account's or is gone, is
     * not; nor is one whose campaign has turned capture off since. Like the
     * Meta Purchase pixel's, the rule is per request: a later request of the
     * click that did not refuse (a landing page's, absent meaning given)
     * marks the visit it records.
     *
     * @param array<string, mixed> $conv
     */
    private static function visitorMayBeIdentified(array $conv): bool
    {
        return ($conv['campaign_found'] ?? null) !== null
            && RequestSignals::campaignAllows($conv['identity_signals'] ?? null);
    }

    /**
     * Whether a browser signal came with the click's latest visit (LatestVisit,
     * CLAUDE.md #26), read as the account's own: its time against the
     * observations' (visitorMayBeIdentified() says why). A latest visit of
     * another account under the id reads as none.
     */
    private function latestVisitSignalled(int $clickId, int $userId): bool
    {
        $stmt = $this->conn->prepareWrite('SELECT c.click_time FROM ' . LatestVisit::of('?') . ' AS c WHERE c.user_id = ?');
        $this->conn->bind($stmt, 'ii', [$clickId, $userId]);
        $latest = $this->conn->fetchOne($stmt)['click_time'] ?? null;
        if ($latest === null) {
            return false;
        }
        $stmt = $this->conn->prepareWrite('SELECT 1 AS signalled FROM 202_identity_observations WHERE click_id = ? AND signal_type IN (?, ?) AND observed_at >= ? LIMIT 1');
        $this->conn->bind($stmt, 'issi', [$clickId, SignalType::VISITOR_COOKIE->value, SignalType::LANDING_PAGE->value, (int) $latest]);

        return $this->conn->fetchOne($stmt) !== null;
    }

    /**
     * The click's stored address as the visitor's, or null: an address
     * stored masked (StoredVisitorIp::mask(), the privacy setting at the
     * click) names a /24 or /48, not the visitor (CLAUDE.md #30), and a
     * private, reserved, shared (carrier-grade NAT), benchmarking or
     * IETF-protocol one names no one a platform could match.
     */
    private static function visitorAddress(string $ip): ?string
    {
        if ($ip === '' || StoredVisitorIp::mask($ip) === $ip) {
            return null;
        }
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!is_string($public)) {
            return null;
        }
        $packed = (string) inet_pton($public);
        foreach (self::NOT_A_VISITOR as [$network, $bits]) {
            $base = (string) inet_pton($network);
            if (strlen($base) === strlen($packed) && self::inPrefix($packed, $base, $bits)) {
                return null;
            }
        }

        return $public;
    }

    private static function inPrefix(string $packed, string $base, int $bits): bool
    {
        $whole = intdiv($bits, 8);
        if (substr($packed, 0, $whole) !== substr($base, 0, $whole)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($base[$whole]) & $mask);
    }

    /**
     * A stored page address as the page, or null when it is not an http(s)
     * URL. Its query and fragment are cut off: they carry what the link put
     * there (subids, click ids, a landing page's ?email=), and the page is
     * sent whatever the user-data setting says. At most PAGE_URL_CHARS.
     */
    private static function pageUrl(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!is_array($parts) || ($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }
        // Rebuilt from its scheme, host, port and path: no user:password@, and no ;session= path parameters.
        $path = (string) preg_replace('/;[^\/]*/', '', (string) ($parts['path'] ?? ''));
        $page = $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . ($path === '' ? '/' : $path);

        return mb_strlen($page) <= self::PAGE_URL_CHARS ? $page : null;
    }

    /** @param array<string, mixed> $settings */
    private static function consentGranted(array $settings): bool
    {
        return ($settings['consent']['ad_user_data'] ?? null) === 'granted' && ($settings['consent']['ad_personalization'] ?? null) === 'granted';
    }

    /**
     * A destination ready to send with, null when it is gone, or why it
     * cannot be read (logged once per run, its rows left pending).
     *
     * @return array<string, mixed>|string|null
     */
    private function destination(int $userId, int $destinationId): array|string|null
    {
        try {
            $d = $this->store->forSending($userId, $destinationId);
            if ($d === null) {
                return null;
            }
            $d['adapter'] = $this->store->type($d['type']);
            $d['host'] = $d['adapter']->host($d['settings']);

            return $d;
        } catch (DestinationInputException $e) {
            return 'destination ' . $destinationId . ' has an unknown type: ' . $e->getMessage();
        } catch (QueryException | \mysqli_sql_exception $e) {
            // A database error is the run's, not the destination's: its rows keep their tries for the next run.
            throw $e;
        } catch (\RuntimeException | \JsonException $e) {
            return 'destination ' . $destinationId . ' cannot be read: ' . $e->getMessage();
        }
    }

    private function zone(int $userId): string
    {
        return $this->zones[$userId] ??= AccountZone::read($this->conn, $userId);
    }

    /** A row that waits: pending, its claim undone, due again at $until. */
    private function wait(int $deliveryId, int $attempts, SendResult $result, int $until): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . "` SET attempts = ?, next_attempt_at = ?, last_status_code = ?, last_error = ?, summary = COALESCE(?, summary) WHERE delivery_id = ? AND status = 'pending'");
        $this->conn->bind($stmt, 'iiissi', [$attempts, $until, $result->status, $result->reason === '' ? null : self::clip($result->reason, 255),
            $result->summary === [] ? null : self::summaryJson($result->summary), $deliveryId]);
        $this->conn->executeUpdate($stmt);
    }

    /** Every pending conversion of a destination that needs its operator waits until $until, so no run reads them before. */
    private function waitDestination(int $userId, int $destinationId, int $until): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . "` SET next_attempt_at = GREATEST(next_attempt_at, ?) WHERE destination_id = ? AND user_id = ? AND status = 'pending' AND kind = 'conversion'");
        $this->conn->bind($stmt, 'iii', [$until, $destinationId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    private function currency(int $userId): string
    {
        $stmt = $this->conn->prepareWrite('SELECT user_account_currency FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$userId]);

        return \Api\V3\Controllers\UsersController::normalizeCurrency($this->conn->fetchOne($stmt)['user_account_currency'] ?? null);
    }

    private function insert(int $userId, int $destinationId, ?int $convId, string $kind, string $status, int $now): int
    {
        // ON DUPLICATE KEY, not IGNORE: a replayed conversion queues once, and nothing is truncated in silence.
        $stmt = $this->conn->prepareWrite('INSERT INTO `' . TableRegistry::DESTINATION_DELIVERIES
            . '` (user_id, destination_id, conv_id, kind, status, attempts, next_attempt_at, created_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE delivery_id = delivery_id');
        $this->conn->bind($stmt, 'iiissii', [$userId, $destinationId, $convId, $kind, $status, $now, $now]);

        return $this->conn->executeUpdate($stmt);
    }

    private function insertTest(int $userId, int $destinationId, SendResult $result, int $now): int
    {
        $stmt = $this->conn->prepareWrite('INSERT INTO `' . TableRegistry::DESTINATION_DELIVERIES
            . '` (user_id, destination_id, conv_id, kind, status, attempts, next_attempt_at, last_status_code, last_error, summary, created_at, sent_at)
             VALUES (?, ?, NULL, ?, ?, 1, ?, ?, ?, ?, ?, ?)');
        $this->conn->bind($stmt, 'iissiissii', [$userId, $destinationId, self::KIND_TEST, $result->delivered ? 'sent' : 'failed', $now,
            $result->status, self::clip($result->reason, 255), self::summaryJson($result->summary), $now, $result->delivered ? $now : null]);

        return $this->conn->executeInsert($stmt);
    }

    /** @param array<string, mixed>|null $summary */
    private function finish(int $deliveryId, string $status, ?int $code, string $reason, ?array $summary, int $now): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE `' . TableRegistry::DESTINATION_DELIVERIES
            . '` SET status = ?, last_status_code = ?, last_error = ?, summary = COALESCE(?, summary), sent_at = ? WHERE delivery_id = ?');
        $this->conn->bind($stmt, 'sissii', [$status, $code, $reason === '' ? null : self::clip($reason, 255),
            $summary === null || $summary === [] ? null : self::summaryJson($summary), $status === 'sent' ? $now : null, $deliveryId]);
        $this->conn->executeUpdate($stmt);
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

    private static function clip(string $text, int $chars): string
    {
        return mb_strimwidth($text, 0, $chars, '…', 'UTF-8');
    }
}
