<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

use Prosper202\Database\Connection;

/**
 * The traffic-source notification outbox (plan §5.2 step 6, §5.5).
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
 * pixel types (image, iframe, script, raw) are markup a page renders; an app
 * install has no page, so they are not queued. The URL is resolved at queue time with the
 * row's own tokens — [[subid]], [[c1]]–[[c4]], [[t202kw]], [[gclid]], the
 * utm_* tokens, [[sourceid]], [[cpc]], [[payout]] (this row's amount, not
 * the click's total), [[transactionid]] / [[t202txid]] (the network's id,
 * else the ledger key), [[p202_goal]], [[p202_goal_value]], [[timestamp]]
 * and [[random]] — so what is sent is what the transaction decided.
 *
 * A traffic source is told about an outcome once (onReplaced()), decided
 * per destination: where the replaced row's `reached` is still pending and
 * unattempted it is cancelled and the replacement's own `reached` goes out
 * instead; where it went out (or may have: an attempt was made, or the row
 * is itself a replacement whose predecessor had gone out, which its
 * `correction` row records) it cannot be recalled, so the replacement's
 * `reached` for that destination — and only that one — is cancelled and a
 * `correction` — or, with no replacement, a `retraction` — is recorded as
 * `suppressed`, because no pixel has a correction URL to carry it yet. The
 * full table is in plan §5.10.
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
    ): int {
        $click = $this->clickTokens($clickId);
        if ($click === null || (int) $click['ppc_account_id'] <= 0) {
            return 0;
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT pixel_id, pixel_code FROM 202_ppc_account_pixels WHERE ppc_account_id = ? AND pixel_type_id = 4 ORDER BY pixel_id'
        );
        $this->conn->bind($stmt, 'i', [(int) $click['ppc_account_id']]);
        $pixels = $this->conn->fetchAll($stmt);
        if ($pixels === []) {
            return 0;
        }

        $tokens = [
            'subid' => (string) $clickId,
            't202kw' => $click['keyword'],
            'c1' => $click['c1'], 'c2' => $click['c2'], 'c3' => $click['c3'], 'c4' => $click['c4'],
            'gclid' => $click['gclid'],
            'utm_source' => $click['utm_source'], 'utm_medium' => $click['utm_medium'], 'utm_campaign' => $click['utm_campaign'],
            'utm_term' => $click['utm_term'], 'utm_content' => $click['utm_content'],
            'sourceid' => (string) $click['ppc_account_id'],
            'cpc' => (string) round((float) $click['click_cpc'], 2),
            'cpc2' => (string) $click['click_cpc'],
            'payout' => $amount,
            'transactionid' => $transactionId ?? $dedupeKey,
            'p202_goal' => $goalName,
            'p202_goal_value' => $amount,
            'timestamp' => (string) $this->now(),
            'random' => (string) random_int(1000000, 9999999),
        ];

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
            $this->insert(
                $userId,
                $newConvId ?? $oldConvId,
                $pixelId,
                $destination,
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
     * value emptied. Unknown [[tokens]] are left as they are.
     *
     * @param array<string, string|null> $tokens
     */
    public static function replaceTokens(string $url, array $tokens): string
    {
        return (string) preg_replace_callback('/\[\[([A-Za-z0-9_]+)\]\]/', static function (array $m) use ($tokens): string {
            $name = strtolower($m[1]);
            if ($name === 't202txid') {
                $name = 'transactionid';
            }
            if (!array_key_exists($name, $tokens)) {
                return $m[0];
            }

            return str_replace('%40', '@', rawurlencode((string) ($tokens[$name] ?? '')));
        }, $url);
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

    /** @return array<string, string|null>|null */
    private function clickTokens(int $clickId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT c.click_id, c.ppc_account_id, c.click_cpc,
                    t1.c1, t2.c2, t3.c3, t4.c4, kw.keyword, g.gclid,
                    us.utm_source, um.utm_medium, uca.utm_campaign, ut.utm_term, uco.utm_content
             FROM 202_clicks c
             LEFT JOIN 202_clicks_advance ca ON ca.click_id = c.click_id
             LEFT JOIN 202_clicks_tracking ct ON ct.click_id = c.click_id
             LEFT JOIN 202_tracking_c1 t1 ON t1.c1_id = ct.c1_id
             LEFT JOIN 202_tracking_c2 t2 ON t2.c2_id = ct.c2_id
             LEFT JOIN 202_tracking_c3 t3 ON t3.c3_id = ct.c3_id
             LEFT JOIN 202_tracking_c4 t4 ON t4.c4_id = ct.c4_id
             LEFT JOIN 202_keywords kw ON kw.keyword_id = ca.keyword_id
             LEFT JOIN 202_google g ON g.click_id = c.click_id
             LEFT JOIN 202_utm_source us ON us.utm_source_id = g.utm_source_id
             LEFT JOIN 202_utm_medium um ON um.utm_medium_id = g.utm_medium_id
             LEFT JOIN 202_utm_campaign uca ON uca.utm_campaign_id = g.utm_campaign_id
             LEFT JOIN 202_utm_term ut ON ut.utm_term_id = g.utm_term_id
             LEFT JOIN 202_utm_content uco ON uco.utm_content_id = g.utm_content_id
             WHERE c.click_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$clickId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $row);
    }
}
