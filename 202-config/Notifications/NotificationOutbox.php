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
 * (UNIQUE (conv_id, pixel_id, destination, kind)) rather than queuing it
 * twice.
 *
 * What is queued: one `reached` row per destination — per URL — of each
 * server-to-server pixel (type 4) of the click's traffic-source account. A
 * pixel's code may hold several space-separated URLs, and each is tracked on
 * its own row (`destination` is its position in the code), because a retry
 * that restarted a multi-URL row at its first URL would resend the
 * conversion to every endpoint that had already accepted it. The browser
 * pixel types (image, iframe, script, raw) are markup a page renders, so
 * they are not queued (a web intake a browser loads renders them itself,
 * TrafficSourceNotifier; an app install has no page). The URL is resolved
 * at queue time by TrafficSourcePixels (the tracker's rules) with the
 * click's tokens — [[subid]], [[c1]]–[[c4]], [[t202kw]], [[gclid]], the
 * utm_* tokens, [[cpc]], [[referer]], [[timestamp]], [[random]] — and the
 * row's own: [[sourceid]], [[payout]] (this row's amount as a network reads
 * money, `4.00`, not the click's total), [[transactionid]] / [[t202txid]]
 * (the network's id, else the ledger key), [[p202_goal]], [[p202_goal_id]]
 * and [[p202_goal_value]] — so what is sent is what the transaction decided.
 *
 * A traffic source is told about an outcome once (onReplaced()), decided
 * per destination: where the replaced row's `reached` is still pending and
 * unattempted it is cancelled and the replacement's own `reached` goes out
 * instead; where it went out (or may have: an attempt was made, or the row
 * is itself a replacement whose predecessor had gone out, which its
 * `correction` row records) it cannot be recalled, so the replacement's
 * `reached` for that destination — and only that one — is cancelled and a
 * `correction` — or, with no replacement, a `retraction` — is recorded. It
 * is queued like any postback when the pixel has a correction URL for that
 * destination (CorrectionUrls, set on Setup › Traffic Sources: its URLs
 * match the pixel code's by position), and stored `suppressed` with the
 * reason when it has none, which is the default: most networks have no
 * endpoint for one. An event a replay moved to another n is announced once
 * per destination too (onEventMoved()). The full table is in plan §5.10.
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
            foreach (self::destinations((string) $pixel['pixel_code']) as $destination => $url) {
                $queued += $this->insert($userId, $convId, (int) $pixel['pixel_id'], $destination, self::KIND_REACHED, 'pending', self::replaceTokens($url, $tokens), null, $now);
            }
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
        // The replaced row's reached rows and its corrections. A correction
        // on this row means its own reached was cancelled because a
        // predecessor had already announced the outcome at that destination:
        // the destination has heard it although this row's reached never
        // went out.
        $stmt = $this->conn->prepareWrite(
            "SELECT notification_id, pixel_id, destination, kind, status, attempts FROM 202_notification_pending
             WHERE conv_id = ? AND kind IN ('reached', 'correction') ORDER BY notification_id FOR UPDATE"
        );
        $this->conn->bind($stmt, 'i', [$oldConvId]);
        $old = $this->conn->fetchAll($stmt);
        if ($old === []) {
            return;
        }
        $now = $this->now();
        /** @var array<string, array{0: int, 1: int}> $announced (pixel, destination) pairs that heard the outcome */
        $announced = [];
        foreach ($old as $row) {
            $at = [(int) $row['pixel_id'], (int) $row['destination']];
            $status = (string) $row['status'];
            if ((string) $row['kind'] === self::KIND_CORRECTION) {
                $announced[$at[0] . ':' . $at[1]] = $at;
                continue;
            }
            if ($status === 'pending' && (int) $row['attempts'] === 0) {
                $this->setStatus((int) $row['notification_id'], 'cancelled',
                    $newConvId !== null ? 'the outcome was replaced by conversion ' . $newConvId . ' before this was sent' : 'the outcome was retired before this was sent');
                continue;
            }
            // Sent, failed, or pending after an attempt: each may have
            // reached the network. A cancelled reached is announced only if
            // a correction says so (above).
            if ($status !== 'cancelled') {
                $announced[$at[0] . ':' . $at[1]] = $at;
            }
        }

        foreach ($announced as [$pixelId, $destination]) {
            if ($newConvId !== null) {
                // This destination already heard the outcome: the
                // replacement's own "reached" there would count it twice
                // upstream. Only there — a destination the replaced row never
                // reached still gets the replacement's.
                $cancel = $this->conn->prepareWrite(
                    "UPDATE 202_notification_pending SET status = 'cancelled', last_error = ?
                     WHERE conv_id = ? AND pixel_id = ? AND destination = ? AND kind = 'reached' AND status = 'pending' AND attempts = 0"
                );
                $this->conn->bind($cancel, 'siii', [
                    'conversion ' . $oldConvId . ' already announced this outcome here; a correction is recorded instead',
                    $newConvId,
                    $pixelId,
                    $destination,
                ]);
                $this->conn->executeUpdate($cancel);
            }
            $this->recordCorrection(
                $userId,
                $pixelId,
                $destination,
                $newConvId !== null ? self::KIND_CORRECTION : self::KIND_RETRACTION,
                $oldConvId,
                $newConvId,
                '(announced by conversion ' . $oldConvId . ')',
                $now
            );
        }
    }

    /**
     * Record a correction or retraction for one destination of a pixel:
     * queued to the pixel's correction URL for that destination when it has
     * one, `suppressed` with the reason when it has none (or when the URL
     * cannot be filled — the conversion it speaks for could not be read,
     * which is said rather than sent half-filled). A pixel's correction URLs
     * are space-separated like its code and matched to its destinations by
     * position, so the endpoint at the pixel's second URL is corrected at the
     * second correction URL and never at the first one's.
     * In the caller's transaction, beside the ledger rows it describes.
     */
    private function recordCorrection(int $userId, int $pixelId, int $destination, string $kind, int $oldConvId, ?int $newConvId, string $context, int $now): void
    {
        $convId = $newConvId ?? $oldConvId;
        $stored = (new CorrectionUrls($this->conn))->forPixel($userId, $pixelId);
        $template = $stored === null ? null : (self::destinations($stored)[$destination] ?? null);
        if ($template === null) {
            $why = $stored === null ? self::NO_CORRECTION_URL
                : 'the pixel\'s correction URLs name no destination ' . ($destination + 1) . ' (they are matched to the pixel code\'s URLs by position)';
            $this->insert($userId, $convId, $pixelId, $destination, $kind, 'suppressed', '', self::note($why . ' ' . $context), $now);

            return;
        }
        $url = $this->correctionUrl($userId, $template, $kind, $oldConvId, $newConvId);
        if ($url === null) {
            $this->insert($userId, $convId, $pixelId, $destination, $kind, 'suppressed', '',
                self::note('the conversion this ' . $kind . ' is about could not be read, so its correction URL was not filled ' . $context), $now);

            return;
        }
        $this->insert($userId, $convId, $pixelId, $destination, $kind, 'pending', $url, null, $now);
    }

    /** A note as the last_error column holds it (255 characters). */
    private static function note(string $note): string
    {
        return mb_strimwidth($note, 0, 255, '…', 'UTF-8');
    }

    /**
     * The correction URL with its tokens filled: the click's tokens, as a
     * reached postback has them, and the correction's own —
     * [[p202_goal_value]] the new value (0.00 for a retraction),
     * [[p202_previous_value]] what was announced, [[p202_original_conv_id]]
     * the conversion that announced it, [[p202_notification_kind]]
     * correction or retraction — or null when a conversion it needs is gone.
     */
    private function correctionUrl(int $userId, string $template, string $kind, int $oldConvId, ?int $newConvId): ?string
    {
        $old = $this->conversion($userId, $oldConvId);
        $new = $newConvId === null ? null : $this->conversion($userId, $newConvId);
        if ($old === null || ($newConvId !== null && $new === null)) {
            return null;
        }
        $current = $new ?? $old;
        $click = TrafficSourcePixels::clickTokens($this->conn, $userId, (int) $current['click_id'], true);
        if ($click === null) {
            return null;
        }
        $value = $new === null ? '0.00' : self::money((string) $new['click_payout']);
        $goalId = preg_match('/^goal:(\d+):/', (string) ($current['source_ref'] ?? ''), $m) === 1 ? $m[1] : null;
        $tokens = [
            'sourceid' => (string) $click['ppc_account_id'],
            'timestamp' => (string) $this->now(),
            'payout' => $value,
            'transactionid' => ($current['transaction_id'] ?? '') !== '' ? (string) $current['transaction_id'] : (string) $current['dedupe_key'],
            'p202_goal' => $this->goalName($userId, $goalId) ?? (string) ($current['event_name'] ?? ''),
            'p202_goal_id' => $goalId,
            'p202_goal_value' => $value,
            'p202_previous_value' => self::money((string) $old['click_payout']),
            'p202_original_conv_id' => (string) $oldConvId,
            'p202_notification_kind' => $kind,
        ] + $click['tokens'];

        return self::replaceTokens($template, $tokens);
    }

    /**
     * The goal's name, as a reached postback's [[p202_goal]] carries it
     * (the ledger row holds the event name); null for a row that names no
     * goal (the install conversion, whose event name is the install goal's).
     */
    private function goalName(int $userId, ?string $goalId): ?string
    {
        if ($goalId === null) {
            return null;
        }
        $stmt = $this->conn->prepareWrite('SELECT name FROM 202_goals WHERE goal_id = ? AND user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'ii', [(int) $goalId, $userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : (string) $row['name'];
    }

    /** @return array<string, mixed>|null */
    private function conversion(int $userId, int $convId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT conv_id, click_id, click_payout, event_name, source_ref, transaction_id, dedupe_key
             FROM 202_conversion_logs WHERE conv_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$convId, $userId]);

        return $this->conn->fetchOne($stmt);
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

            // One row, one URL (queueReached()): a retry resends only the
            // destination that did not accept it.
            $url = (string) $row['url'];
            if (($this->fetch)($url)) {
                $done = $this->conn->prepareWrite(
                    "UPDATE 202_notification_pending SET status = 'sent', sent_at = ?, last_error = NULL WHERE notification_id = ?"
                );
                $this->conn->bind($done, 'ii', [$now, $id]);
                $this->conn->executeUpdate($done);
                $out['sent']++;
                continue;
            }
            $error = mb_strimwidth('the traffic source did not accept ' . $url, 0, 255, '…', 'UTF-8');
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
     * shifted n: $5, $10 and a late $1 become $1, $5, $10). Decided per
     * destination, as onReplaced() decides a replacement: at every (pixel,
     * destination) where one of those retired outcomes was announced — its
     * `reached` sent, or attempted, since a send cannot be taken back, or a
     * `correction` of it recording that the destination had already heard
     * the event — the new outcome's pending `reached` is cancelled and a
     * `correction` recorded as suppressed: that destination has heard about
     * this event for this goal once. A destination none of them reached (its
     * `reached` cancelled unsent, or never queued) keeps the new outcome's
     * `reached`, which is then the announcement there.
     * In the caller's transaction. Returns whether it suppressed anywhere.
     *
     * @param list<int> $priorConvIds the retired outcomes' ledger rows
     */
    public function onEventMoved(int $userId, int $newConvId, array $priorConvIds): bool
    {
        if ($priorConvIds === []) {
            return false;
        }
        $stmt = $this->conn->prepareWrite(
            "SELECT DISTINCT pixel_id, destination FROM 202_notification_pending
             WHERE ((kind = 'reached' AND (status IN ('sent', 'failed') OR attempts > 0)) OR kind = 'correction')
               AND conv_id IN (" . implode(',', array_fill(0, count($priorConvIds), '?')) . ')
             ORDER BY pixel_id, destination'
        );
        $this->conn->bind($stmt, str_repeat('i', count($priorConvIds)), array_values($priorConvIds));
        $announced = $this->conn->fetchAll($stmt);
        if ($announced === []) {
            return false;
        }
        $now = $this->now();
        $note = 'this event already reached the goal in an announced outcome (conversion ' . implode(', ', $priorConvIds) . ')';
        foreach ($announced as $row) {
            $pixelId = (int) $row['pixel_id'];
            $destination = (int) $row['destination'];
            $cancel = $this->conn->prepareWrite(
                "UPDATE 202_notification_pending SET status = 'cancelled', last_error = ?
                 WHERE conv_id = ? AND pixel_id = ? AND destination = ? AND kind = 'reached' AND status = 'pending' AND attempts = 0"
            );
            $this->conn->bind($cancel, 'siii', [mb_strimwidth($note, 0, 255, '…', 'UTF-8'), $newConvId, $pixelId, $destination]);
            $this->conn->executeUpdate($cancel);
            // The earliest announcing conversion is the one the network
            // heard first: the correction's "original".
            $this->recordCorrection($userId, $pixelId, $destination, self::KIND_CORRECTION, min($priorConvIds), $newConvId,
                '(the event was announced by conversion ' . implode(', ', $priorConvIds) . ')', $now);
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

    /**
     * A pixel's destinations: its code's space-separated URLs, numbered by
     * position. Blank runs between them are not destinations, so a doubled
     * space does not shift the numbers of the URLs after it.
     *
     * @return list<string>
     */
    public static function destinations(string $pixelCode): array
    {
        $urls = [];
        foreach (explode(' ', $pixelCode) as $url) {
            if (trim($url) !== '') {
                $urls[] = trim($url);
            }
        }

        return $urls;
    }

    private function insert(int $userId, int $convId, int $pixelId, int $destination, string $kind, string $status, string $url, ?string $note, int $now): int
    {
        $stmt = $this->conn->prepareWrite(
            // ON DUPLICATE KEY, not IGNORE: IGNORE would also turn a value
            // the column refuses into a silently truncated row.
            'INSERT INTO 202_notification_pending
                (user_id, conv_id, pixel_id, destination, kind, status, url, attempts, next_attempt_at, last_error, created_at, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE notification_id = notification_id'
        );
        $this->conn->bind($stmt, 'iiiisssisi', [$userId, $convId, $pixelId, $destination, $kind, $status, $url, $now, $note, $now]);

        return $this->conn->executeUpdate($stmt);
    }

    private function setStatus(int $id, string $status, string $note): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE 202_notification_pending SET status = ?, last_error = ? WHERE notification_id = ?');
        $this->conn->bind($stmt, 'ssi', [$status, mb_strimwidth($note, 0, 255, '…', 'UTF-8'), $id]);
        $this->conn->executeUpdate($stmt);
    }

}
