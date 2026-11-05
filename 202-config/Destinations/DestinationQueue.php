<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Click\FirstVisit;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\DataEngine\IpAddressSql;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\Http\EuropeanVisitor;
use Prosper202\Http\PrivacySetting;
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
 * (not named by click id or cookie) is withheld; so is every visitor under
 * privacy 'all', and a visitor who may be in the EEA or UK (EuropeanVisitor)
 * unless the setting is 'disabled' and the destination states both consent
 * signals granted. Withheld rows are never retried.
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
    /** The minutely cron's budget. */
    public const RUN_SECONDS = 15;

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
     * queued; any other failure fails the transaction, as the outbox's does.
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
        $stmt = $this->conn->prepareWrite('SELECT cl.click_id, cl.campaign_id, cl.conv_time, cl.click_payout, cl.transaction_id, cl.deleted, cl.superseded_by, cl.customer_id, cl.match_method, '
            . IpAddressSql::address('ip', 'ip6') . ' AS ip_address
            FROM 202_conversion_logs cl LEFT JOIN 202_clicks_advance ca ON ca.click_id = cl.click_id LEFT JOIN 202_ips ip ON ip.ip_id = ca.ip_id'
            . IpAddressSql::join('ip', 'ip6') . '
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
        if (is_array($campaigns) && $campaigns !== [] && !in_array((int) $conv['campaign_id'], $campaigns, true)) {
            return ['cancelled', new SendResult(false, false, null, 'the conversion\'s campaign is not one this destination sends')];
        }
        $ip = (string) ($conv['ip_address'] ?? '');
        // Only a click the conversion named: a guess would teach the platform the wrong clicks.
        $method = MatchMethod::tryFrom((string) ($conv['match_method'] ?? '')) ?? MatchMethod::UNKNOWN;
        if ($method !== MatchMethod::CLICK_ID && $method !== MatchMethod::COOKIE) {
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
        [$customerRef, $emails, $phones] = $this->customer($destination['settings'], (int) ($conv['customer_id'] ?? 0), $userId);
        $transactionId = trim((string) ($conv['transaction_id'] ?? ''));
        $facts = new ConversionFacts(
            $convId,
            $clickId,
            (int) $conv['campaign_id'],
            (int) $conv['conv_time'],
            (string) $conv['click_payout'],
            $this->currency($userId),
            $transactionId !== '' ? $transactionId : 'P202-' . $convId,
            $tokens,
            $customerRef,
            $emails,
            $phones,
            $this->zone($userId),
            $clickTime === null ? null : (int) $clickTime
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
     * The customer's ref and, when the destination sends user data and the
     * account has not stated ad_user_data denied, their hashed email and phone.
     *
     * @param array<string, mixed> $settings
     * @return array{0: string|null, 1: list<string>, 2: list<string>}
     */
    private function customer(array $settings, int $customerId, int $userId): array
    {
        if ($customerId <= 0) {
            return [null, [], []];
        }
        $stmt = $this->conn->prepareWrite('SELECT primary_ref, email, phone FROM `' . TableRegistry::CUSTOMERS . '` WHERE customer_id = ? AND user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return [null, [], []];
        }
        // The customer's ref can be an email address: it goes only where the hashes may.
        // An allowlist: unstated or granted sends, anything else (denied, a corrupt value) does not.
        $send = ($settings['send_user_data'] ?? false) === true && in_array($settings['consent']['ad_user_data'] ?? null, [null, 'granted'], true);
        if (!$send) {
            return [null, [], []];
        }
        $email = PiiHash::email($row['email'] ?? null);
        $phone = PiiHash::phone($row['phone'] ?? null);

        return [(string) $row['primary_ref'], $email === null ? [] : [$email], $phone === null ? [] : [$phone]];
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
