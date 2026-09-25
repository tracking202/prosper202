<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;

/**
 * The traffic-source notification outbox (plan §5.2 step 6, §5.5, §5.10):
 * every server-to-server postback for a goal outcome, web clicks (PR 4b)
 * and app installs (PR 5) alike.
 *
 * Writes happen inside the caller's transaction, beside the ledger row they
 * announce, so a conversion and its queued postback commit or roll back
 * together; a process killed after the commit leaves the row for the worker
 * rather than nothing, and a replayed install finds the row already queued
 * (UNIQUE (conv_id, pixel_id, kind)) rather than queuing it twice.
 *
 * What is queued: one `reached` row per server-to-server pixel (type 4) of
 * the click's traffic-source account. The browser pixel types (image,
 * iframe, script, raw) are markup a page renders, so they are not queued (a
 * web intake a browser loads renders them itself, TrafficSourceNotifier).
 * The URL is resolved at queue time by TrafficSourcePixels (the tracker's
 * rules) with the click's tokens — [[subid]], [[c1]]–[[c4]], [[t202kw]],
 * [[gclid]], the utm_* tokens, [[cpc]], [[referer]], [[timestamp]],
 * [[random]] — and the row's own: [[sourceid]], [[payout]] (this row's
 * amount as a network reads money, `4.00`, not the click's total),
 * [[transactionid]] / [[t202txid]] (the network's id, else the ledger key),
 * [[p202_goal]], [[p202_goal_id]] and [[p202_goal_value]] — so what is sent
 * is what the transaction decided.
 *
 * A traffic source is told about an outcome once (onReplaced()): a pending
 * `reached` whose outcome is replaced is cancelled and the replacement's own
 * `reached` goes out instead; one that went out (or may have: an attempt was
 * made) cannot be recalled, so the replacement's `reached` is cancelled and
 * a `correction` — or, with no replacement, a `retraction` — is recorded as
 * `suppressed`, because no pixel has a correction URL to carry it yet. An
 * event a replay moved to another n is announced once too (onEventMoved()).
 *
 * Sending (sendDue()) is the worker's: never on the request path, which
 * makes no external calls (plan §7.3). Each row is claimed with a
 * compare-and-set on its attempt count before the send, so two workers
 * never send one row concurrently; a row whose attempts run out is `failed`
 * with its last error.
 */
final class NotificationOutbox implements OutcomeNotificationSink
{
    public const MAX_ATTEMPTS = 8;
    public const KIND_REACHED = 'reached';
    public const KIND_CORRECTION = 'correction';
    public const KIND_RETRACTION = 'retraction';
    private const NO_CORRECTION_URL = 'no correction URL is configured for this traffic source; a sent postback cannot be recalled';

    /** @var callable(string): bool */
    private $fetch;

    /**
     * @param (callable(): int)|null $clock
     * @param (callable(string): bool)|null $fetch
     */
    public function __construct(private Connection $conn, private $clock = null, ?callable $fetch = null)
    {
        $this->fetch = $fetch ?? PostbackSender::fetch(...);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /**
     * Queue the `reached` postbacks for a new ledger row, in the caller's
     * transaction. Returns how many rows were queued.
     */
    public function queueReached(
        int $userId,
        int $convId,
        int $clickId,
        string $goalName,
        string $amount,
        string $dedupeKey,
        ?string $transactionId,
        ?int $goalId = null,
    ): int {
        $click = TrafficSourcePixels::clickTokens($this->conn, $userId, $clickId, true);
        if ($click === null || $click['ppc_account_id'] <= 0) {
            return 0;
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT pixel_id, pixel_code FROM 202_ppc_account_pixels WHERE ppc_account_id = ? AND pixel_type_id = 4 ORDER BY pixel_id'
        );
        $this->conn->bind($stmt, 'i', [$click['ppc_account_id']]);
        $pixels = $this->conn->fetchAll($stmt);
        if ($pixels === []) {
            return 0;
        }

        $money = self::money($amount);
        $tokens = [
            'sourceid' => (string) $click['ppc_account_id'],
            'timestamp' => (string) $this->now(),
            'payout' => $money,
            'transactionid' => $transactionId !== null && $transactionId !== '' ? $transactionId : $dedupeKey,
            'p202_goal' => $goalName,
            'p202_goal_id' => $goalId !== null ? (string) $goalId : null,
            'p202_goal_value' => $money,
        ] + $click['tokens'];

        $queued = 0;
        $now = $this->now();
        foreach ($pixels as $pixel) {
            $urls = [];
            foreach (explode(' ', (string) $pixel['pixel_code']) as $url) {
                if (trim($url) !== '') {
                    $urls[] = self::replaceTokens(trim($url), $tokens);
                }
            }
            if ($urls === []) {
                continue;
            }
            $queued += $this->insert($userId, $convId, (int) $pixel['pixel_id'], self::KIND_REACHED, 'pending', implode("\n", $urls), null, $now);
        }

        return $queued;
    }

    /**
     * The ledger row $oldConvId was replaced by $newConvId (a replay or a
     * re-evaluation moved the outcome), or retired with no replacement
     * ($newConvId null). In the caller's transaction.
     */
    public function onReplaced(int $userId, int $oldConvId, ?int $newConvId): void
    {
        $stmt = $this->conn->prepareWrite(
            "SELECT notification_id, pixel_id, status, attempts FROM 202_notification_pending
             WHERE conv_id = ? AND kind = 'reached' FOR UPDATE"
        );
        $this->conn->bind($stmt, 'i', [$oldConvId]);
        $old = $this->conn->fetchAll($stmt);
        if ($old === []) {
            return;
        }
        $now = $this->now();
        $delivered = [];
        foreach ($old as $row) {
            if ((string) $row['status'] === 'pending' && (int) $row['attempts'] === 0) {
                $this->setStatus((int) $row['notification_id'], 'cancelled',
                    $newConvId !== null ? 'the outcome was replaced by conversion ' . $newConvId . ' before this was sent' : 'the outcome was retired before this was sent');
                continue;
            }
            if ((string) $row['status'] !== 'cancelled') {
                $delivered[(int) $row['pixel_id']] = true;
            }
        }
        if ($delivered === []) {
            return;
        }

        if ($newConvId !== null) {
            // The network already heard this outcome: the replacement's own
            // "reached" would count it twice upstream.
            $cancel = $this->conn->prepareWrite(
                "UPDATE 202_notification_pending SET status = 'cancelled', last_error = ?
                 WHERE conv_id = ? AND kind = 'reached' AND status = 'pending' AND attempts = 0"
            );
            $this->conn->bind($cancel, 'si', ['conversion ' . $oldConvId . ' already announced this outcome; a correction is recorded instead', $newConvId]);
            $this->conn->executeUpdate($cancel);
        }
        foreach (array_keys($delivered) as $pixelId) {
            $this->insert(
                $userId,
                $newConvId ?? $oldConvId,
                $pixelId,
                $newConvId !== null ? self::KIND_CORRECTION : self::KIND_RETRACTION,
                'suppressed',
                '',
                self::NO_CORRECTION_URL . ' (announced by conversion ' . $oldConvId . ')',
                $now
            );
        }
    }

    /**
     * Send what is due: pending rows whose next attempt has come, oldest
     * first, at most $limit. Optionally only rows of the given conversions.
     *
     * @param list<int>|null $convIds
     * @return array{sent: int, failed: int, retrying: int}
     */
    public function sendDue(int $limit, ?array $convIds = null): array
    {
        $now = $this->now();
        $sql = "SELECT notification_id, url, attempts FROM 202_notification_pending
                WHERE status = 'pending' AND next_attempt_at <= ?";
        $types = 'i';
        $binds = [$now];
        if ($convIds !== null) {
            if ($convIds === []) {
                return ['sent' => 0, 'failed' => 0, 'retrying' => 0];
            }
            $sql .= ' AND conv_id IN (' . implode(',', array_fill(0, count($convIds), '?')) . ')';
            $types .= str_repeat('i', count($convIds));
            array_push($binds, ...$convIds);
        }
        $sql .= ' ORDER BY notification_id LIMIT ?';
        $types .= 'i';
        $binds[] = max(1, $limit);
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $binds);

        $out = ['sent' => 0, 'failed' => 0, 'retrying' => 0];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $id = (int) $row['notification_id'];
            $attempts = (int) $row['attempts'] + 1;
            // Claim: only the worker that moves the attempt count sends.
            $claim = $this->conn->prepareWrite(
                "UPDATE 202_notification_pending SET attempts = ?, next_attempt_at = ?
                 WHERE notification_id = ? AND status = 'pending' AND attempts = ?"
            );
            $this->conn->bind($claim, 'iiii', [$attempts, $now + self::backoff($attempts), $id, $attempts - 1]);
            if ($this->conn->executeUpdate($claim) !== 1) {
                continue;
            }

            $failedUrl = null;
            foreach (explode("\n", (string) $row['url']) as $url) {
                if ($url !== '' && !($this->fetch)($url)) {
                    $failedUrl = $url;
                    break;
                }
            }
            if ($failedUrl === null) {
                $done = $this->conn->prepareWrite(
                    "UPDATE 202_notification_pending SET status = 'sent', sent_at = ?, last_error = NULL WHERE notification_id = ?"
                );
                $this->conn->bind($done, 'ii', [$now, $id]);
                $this->conn->executeUpdate($done);
                $out['sent']++;
                continue;
            }
            $error = mb_strimwidth('the traffic source did not accept ' . $failedUrl, 0, 255, '…', 'UTF-8');
            $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $mark = $this->conn->prepareWrite('UPDATE 202_notification_pending SET status = ?, last_error = ? WHERE notification_id = ?');
            $this->conn->bind($mark, 'ssi', [$status, $error, $id]);
            $this->conn->executeUpdate($mark);
            $out[$status === 'failed' ? 'failed' : 'retrying']++;
        }

        return $out;
    }

    /** Seconds to wait after attempt $n fails: 1 min doubling, at most 6 h. */
    public static function backoff(int $attempt): int
    {
        return min(21600, 60 * (2 ** max(0, $attempt - 1)));
    }

    /**
     * The URL with its known tokens filled, each value rawurlencoded as
     * replaceTokens() does (with @ kept), and a known token that has no
     * value emptied. Unknown [[tokens]] are left as they are. The rules are
     * TrafficSourcePixels' (the tracker's and gpb's), so a queued postback
     * reads exactly as an immediate one would.
     *
     * @param array<string, scalar|null> $tokens
     */
    public static function replaceTokens(string $url, array $tokens): string
    {
        return TrafficSourcePixels::replaceTokens($url, $tokens, 1);
    }

    /**
     * A ledger amount ("12.50000") as a network reads money: at least two
     * decimals, and no trailing zeros past them ("12.50", "0.12345").
     */
    public static function money(string $amount): string
    {
        if (preg_match('/^(-?\d+)(?:\.(\d*))?$/D', $amount, $m) !== 1) {
            return $amount;
        }
        $fraction = rtrim($m[2] ?? '', '0');

        return $m[1] . '.' . str_pad($fraction, 2, '0');
    }

    /**
     * An outcome written by a replay or re-evaluation whose reaching event
     * had already reached the same goal in a retired outcome (a late event
     * shifted n: $5, $10 and a late $1 become $1, $5, $10). If any of those
     * retired outcomes was announced — sent, or attempted, since a send
     * cannot be taken back — the new outcome's pending `reached` is
     * cancelled and a `correction` recorded as suppressed: the network has
     * heard about this event for this goal once. If none was (they were
     * cancelled unsent), the new one stands and is the announcement.
     * In the caller's transaction. Returns whether it suppressed.
     *
     * @param list<int> $priorConvIds the retired outcomes' ledger rows
     */
    public function onEventMoved(int $userId, int $newConvId, array $priorConvIds): bool
    {
        if ($priorConvIds === []) {
            return false;
        }
        $stmt = $this->conn->prepareWrite(
            "SELECT DISTINCT pixel_id FROM 202_notification_pending
             WHERE kind = 'reached' AND (status IN ('sent', 'failed') OR attempts > 0)
               AND conv_id IN (" . implode(',', array_fill(0, count($priorConvIds), '?')) . ')'
        );
        $this->conn->bind($stmt, str_repeat('i', count($priorConvIds)), array_values($priorConvIds));
        $delivered = array_map(static fn (array $r): int => (int) $r['pixel_id'], $this->conn->fetchAll($stmt));
        if ($delivered === []) {
            return false;
        }
        $cancel = $this->conn->prepareWrite(
            "UPDATE 202_notification_pending SET status = 'cancelled', last_error = ?
             WHERE conv_id = ? AND kind = 'reached' AND status = 'pending' AND attempts = 0"
        );
        $this->conn->bind($cancel, 'si', ['this event already reached the goal in an announced outcome (conversion ' . implode(', ', $priorConvIds) . ')', $newConvId]);
        $this->conn->executeUpdate($cancel);
        $now = $this->now();
        foreach ($delivered as $pixelId) {
            $this->insert($userId, $newConvId, $pixelId, self::KIND_CORRECTION, 'suppressed', '',
                self::NO_CORRECTION_URL . ' (the event was announced by conversion ' . implode(', ', $priorConvIds) . ')', $now);
        }

        return true;
    }

    /**
     * What the outbox holds for a conversion's `reached` announcement:
     * rows in all, rows not cancelled (queued, sent or failed), and rows
     * still waiting to go out.
     *
     * @return array{total: int, live: int, pending: int}
     */
    public function reachedState(int $convId): array
    {
        $stmt = $this->conn->prepareWrite(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status <> 'cancelled'), 0) AS live, COALESCE(SUM(status = 'pending'), 0) AS pending
             FROM 202_notification_pending WHERE conv_id = ? AND kind = 'reached'"
        );
        $this->conn->bind($stmt, 'i', [$convId]);
        $row = $this->conn->fetchOne($stmt) ?? [];

        return ['total' => (int) ($row['total'] ?? 0), 'live' => (int) ($row['live'] ?? 0), 'pending' => (int) ($row['pending'] ?? 0)];
    }

    private function insert(int $userId, int $convId, int $pixelId, string $kind, string $status, string $url, ?string $note, int $now): int
    {
        $stmt = $this->conn->prepareWrite(
            // ON DUPLICATE KEY, not IGNORE: IGNORE would also turn a value
            // the column refuses into a silently truncated row.
            'INSERT INTO 202_notification_pending
                (user_id, conv_id, pixel_id, kind, status, url, attempts, next_attempt_at, last_error, created_at, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE notification_id = notification_id'
        );
        $this->conn->bind($stmt, 'iiisssisi', [$userId, $convId, $pixelId, $kind, $status, $url, $now, $note, $now]);

        return $this->conn->executeUpdate($stmt);
    }

    private function setStatus(int $id, string $status, string $note): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE 202_notification_pending SET status = ?, last_error = ? WHERE notification_id = ?');
        $this->conn->bind($stmt, 'ssi', [$status, mb_strimwidth($note, 0, 255, '…', 'UTF-8'), $id]);
        $this->conn->executeUpdate($stmt);
    }

}
