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
 * `correction` — or, with no replacement, a `retraction` — is recorded
 * (amend(), below). An
 * event a replay moved to another n is announced once per destination too
 * (onAnnouncedBefore(), below). The full table is in plan §5.10.
 *
 * Announced-ness belongs to the outcome, not the row (plan §5.7): a traffic
 * source's knowledge is per (subject, goal, n) and per destination.
 * - A revived row keeps its conv_id, so its `reached` already exists and is
 *   never queued a second time (onRevived()). A retraction of it that is
 *   still pending and unattempted is cancelled, and so is one stored
 *   `suppressed` (neither went out, so the network still holds the revived
 *   value); one that was delivered — sent, failed, or attempted — is
 *   answered by a `correction` with previous value 0. A `reached` the
 *   retirement cancelled unsent, at a destination nothing else announced
 *   the outcome to, is queued again: that network has heard nothing.
 * - A new row for an (subject, goal, n) that some earlier row — retired rows
 *   included, whatever their version — announced at a destination is a
 *   `correction` there, not a fresh `reached` (onAnnouncedBefore()).
 * Every correction and retraction follows one rule (amend()): sent to the
 * destination's correction URL when it has one, stored `suppressed` with the
 * reason otherwise. The correction URLs are the operator's, set per server
 * pixel on Setup › Traffic Sources (CorrectionUrls: one per pixel URL,
 * matched by position, only while the pixel's owner set it); that is the
 * resolver every outbox gets unless a test hands it another, so production
 * can never be built with one that answers "none" for everything. `generation` numbers the
 * corrections and retractions of one conversion at one destination, so a
 * row retired, revived and retired again records each step instead of the
 * second retraction being swallowed by the first one's key.
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

    /** @var callable(int, int): ?string */
    private $correctionUrl;

    /**
     * @param (callable(): int)|null $clock
     * @param (callable(string): bool)|null $fetch
     * @param (callable(int, int): ?string)|null $correctionUrl a destination's
     *        correction URL (pixel id, destination), or null where it has
     *        none; by default the configured ones (CorrectionUrls::resolver())
     */
    public function __construct(private Connection $conn, private $clock = null, ?callable $fetch = null, ?callable $correctionUrl = null)
    {
        $this->fetch = $fetch ?? PostbackSender::fetch(...);
        $this->correctionUrl = $correctionUrl ?? CorrectionUrls::resolver($conn);
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
                $queued += $this->insert($userId, $convId, (int) $pixel['pixel_id'], $destination, self::KIND_REACHED, 0, 'pending', self::replaceTokens($url, $tokens), null, $now);
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
            $this->amend(
                $userId,
                $newConvId ?? $oldConvId,
                $pixelId,
                $destination,
                $newConvId !== null ? self::KIND_CORRECTION : self::KIND_RETRACTION,
                $oldConvId,
                null,
                'announced by conversion ' . $oldConvId,
                $now
            );
        }
    }

    /**
     * Ledger row $convId, retired earlier, counts again (plan §5.7 (1)): the
     * engine revived its outcome and restored the row. It keeps its conv_id,
     * so its `reached` already exists and is never queued a second time.
     * Decided per destination, in the caller's transaction:
     * - its open retraction (the latest, with no correction after it)
     *   pending and unattempted, or `suppressed`: cancelled — it never went
     *   out, so the network still holds the value it was told;
     * - delivered (sent, failed, or pending after an attempt — any of which
     *   may have landed): a retrying one is stopped, since a later success
     *   would zero what the correction restores, and a `correction` with
     *   previous value 0 is recorded under the correction-URL rule;
     * - no open retraction, and its `reached` cancelled unsent with no
     *   correction beside it (the retirement stopped it before it went out,
     *   and no earlier row announced the outcome there): queued again;
     * - otherwise nothing.
     */
    public function onRevived(int $userId, int $convId): void
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT notification_id, pixel_id, destination, kind, status, attempts FROM 202_notification_pending
             WHERE conv_id = ? ORDER BY notification_id FOR UPDATE'
        );
        $this->conn->bind($stmt, 'i', [$convId]);
        /** @var array<string, array{pixel: int, destination: int, reached: array<string, mixed>|null, retraction: array<string, mixed>|null, corrected: bool}> $at */
        $at = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $key = (int) $row['pixel_id'] . ':' . (int) $row['destination'];
            $at[$key] ??= ['pixel' => (int) $row['pixel_id'], 'destination' => (int) $row['destination'], 'reached' => null, 'retraction' => null, 'corrected' => false];
            $kind = (string) $row['kind'];
            if ($kind === self::KIND_REACHED) {
                $at[$key]['reached'] = $row;
            } elseif ($kind === self::KIND_RETRACTION) {
                // In id order: the latest retraction is the open one unless
                // a correction follows it.
                $at[$key]['retraction'] = $row;
            } elseif ($kind === self::KIND_CORRECTION) {
                $at[$key]['corrected'] = true;
                $at[$key]['retraction'] = null;
            }
        }
        $now = $this->now();
        foreach ($at as $d) {
            $retraction = $d['retraction'];
            if ($retraction !== null && (string) $retraction['status'] !== 'cancelled') {
                $status = (string) $retraction['status'];
                $attempts = (int) $retraction['attempts'];
                if ($status === 'suppressed' || ($status === 'pending' && $attempts === 0)) {
                    $this->setStatus((int) $retraction['notification_id'], 'cancelled',
                        'the outcome was revived before this retraction went out; the network still holds its value');
                    continue;
                }
                if ($status === 'pending') {
                    $this->setStatus((int) $retraction['notification_id'], 'cancelled',
                        'the outcome was revived while this retraction was retrying; a correction follows');
                }
                $this->amend($userId, $convId, $d['pixel'], $d['destination'], self::KIND_CORRECTION, $convId, '0',
                    'conversion ' . $convId . ' was revived after its retraction was delivered', $now);
                continue;
            }
            $reached = $d['reached'];
            if ($reached !== null && !$d['corrected'] && $retraction === null
                && (string) $reached['status'] === 'cancelled' && (int) $reached['attempts'] === 0) {
                $restore = $this->conn->prepareWrite(
                    "UPDATE 202_notification_pending SET status = 'pending', next_attempt_at = ?, last_error = NULL
                     WHERE notification_id = ? AND status = 'cancelled' AND attempts = 0"
                );
                $this->conn->bind($restore, 'ii', [$now, (int) $reached['notification_id']]);
                if ($this->conn->executeUpdate($restore) !== 1) {
                    throw new \RuntimeException('notification ' . (int) $reached['notification_id'] . ' of revived conversion ' . $convId . ' could not be queued again');
                }
            }
        }
    }

    /**
     * Ledger row $newConvId is new, and $priorConvIds are the other rows
     * ever written for its (subject, goal, n) — retired ones included,
     * whatever their version (plan §5.7 (2)) — and the retired rows whose
     * reaching event had reached the same goal (a replay that shifts n moves
     * an announced event to a new n: $5, $10 and a late $1 become $1, $5,
     * $10, and the $10 must not be announced twice). At every destination where
     * one of them was announced — its `reached` sent, failed or attempted,
     * or a correction or retraction recorded for it there — this row is a
     * `correction`, not a fresh `reached`: its pending, unattempted
     * `reached` there is cancelled and a correction recorded, whose previous
     * value is what the network last heard there. A destination where this
     * row already carries a correction (onReplaced() recorded it a moment
     * earlier in the same transaction: a new row has no older one) is left
     * as it is. In the caller's transaction; returns whether any destination
     * was withheld.
     *
     * @param list<int> $priorConvIds
     */
    public function onAnnouncedBefore(int $userId, int $newConvId, array $priorConvIds): bool
    {
        $priorConvIds = array_values(array_unique(array_filter($priorConvIds, static fn (int $c): bool => $c !== $newConvId)));
        if ($priorConvIds === []) {
            return false;
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT conv_id, pixel_id, destination, kind, status, attempts FROM 202_notification_pending
             WHERE conv_id IN (' . implode(',', array_fill(0, count($priorConvIds), '?')) . ') ORDER BY notification_id'
        );
        $this->conn->bind($stmt, str_repeat('i', count($priorConvIds)), $priorConvIds);
        /** @var array<string, array{pixel: int, destination: int, last: array{0: int, 1: bool}|null}> $announced last: [conversion, retracted] */
        $announced = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $kind = (string) $row['kind'];
            $status = (string) $row['status'];
            $delivered = $status === 'sent' || $status === 'failed' || (int) $row['attempts'] > 0;
            if ($kind === self::KIND_REACHED && !$delivered) {
                continue;
            }
            $key = (int) $row['pixel_id'] . ':' . (int) $row['destination'];
            $announced[$key] ??= ['pixel' => (int) $row['pixel_id'], 'destination' => (int) $row['destination'], 'last' => null];
            // What the network last heard there: a delivered reached or
            // correction is that row's value, a delivered retraction zero.
            // A suppressed or unsent amendment changed nothing upstream, but
            // a correction marks a row whose predecessor was heard.
            if ($delivered) {
                $announced[$key]['last'] = [(int) $row['conv_id'], $kind === self::KIND_RETRACTION];
            } elseif ($announced[$key]['last'] === null && $kind === self::KIND_CORRECTION) {
                $announced[$key]['last'] = [(int) $row['conv_id'], false];
            }
        }
        if ($announced === []) {
            return false;
        }
        $now = $this->now();
        $note = mb_strimwidth('an earlier row for this goal and n (conversion ' . implode(', ', $priorConvIds) . ') was announced here; a correction is recorded instead', 0, 255, '…', 'UTF-8');
        foreach ($announced as $d) {
            $has = $this->conn->prepareWrite(
                "SELECT COUNT(*) AS n FROM 202_notification_pending WHERE conv_id = ? AND pixel_id = ? AND destination = ? AND kind = 'correction'"
            );
            $this->conn->bind($has, 'iii', [$newConvId, $d['pixel'], $d['destination']]);
            if ((int) ($this->conn->fetchOne($has)['n'] ?? 0) > 0) {
                continue;
            }
            $cancel = $this->conn->prepareWrite(
                "UPDATE 202_notification_pending SET status = 'cancelled', last_error = ?
                 WHERE conv_id = ? AND pixel_id = ? AND destination = ? AND kind = 'reached' AND status = 'pending' AND attempts = 0"
            );
            $this->conn->bind($cancel, 'siii', [$note, $newConvId, $d['pixel'], $d['destination']]);
            $this->conn->executeUpdate($cancel);
            // A retraction-only history (its reached cancelled, a correction
            // suppressed) still names the row the network heard of.
            [$lastConv, $retracted] = $d['last'] ?? [$priorConvIds[0], false];
            $this->amend($userId, $newConvId, $d['pixel'], $d['destination'], self::KIND_CORRECTION, $lastConv,
                $retracted ? '0' : null, 'this goal and n were announced by conversion ' . $lastConv, $now);
        }

        return true;
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

    private function insert(int $userId, int $convId, int $pixelId, int $destination, string $kind, int $generation, string $status, string $url, ?string $note, int $now): int
    {
        $stmt = $this->conn->prepareWrite(
            // ON DUPLICATE KEY, not IGNORE: IGNORE would also turn a value
            // the column refuses into a silently truncated row.
            'INSERT INTO 202_notification_pending
                (user_id, conv_id, pixel_id, destination, kind, generation, status, url, attempts, next_attempt_at, last_error, created_at, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE notification_id = notification_id'
        );
        $this->conn->bind($stmt, 'iiiisissisi', [$userId, $convId, $pixelId, $destination, $kind, $generation, $status, $url, $now, $note, $now]);

        return $this->conn->executeUpdate($stmt);
    }

    /**
     * Record a correction or retraction of $convId at one destination, as
     * its next generation there: queued for the destination's correction URL
     * when it has one — tokens [[subid]], [[p202_goal_value]] and [[payout]]
     * (the value the network should now hold, 0 for a retraction),
     * [[p202_previous_value]], [[p202_conv_id]], [[p202_original_conv_id]],
     * [[p202_notification]] (the kind), [[transactionid]] / [[t202txid]],
     * [[timestamp]], [[random]] — and stored `suppressed`, with why,
     * otherwise. $previousValue null means $originalConvId's value; the
     * ledger is read only for a URL, so a suppressed amendment needs no row.
     */
    private function amend(int $userId, int $convId, int $pixelId, int $destination, string $kind, int $originalConvId, ?string $previousValue, string $why, int $now): void
    {
        $gen = $this->conn->prepareWrite(
            'SELECT COALESCE(MAX(generation) + 1, 0) AS g FROM 202_notification_pending
             WHERE conv_id = ? AND pixel_id = ? AND destination = ? AND kind = ? FOR UPDATE'
        );
        $this->conn->bind($gen, 'iiis', [$convId, $pixelId, $destination, $kind]);
        $generation = (int) ($this->conn->fetchOne($gen)['g'] ?? 0);

        $template = ($this->correctionUrl)($pixelId, $destination);
        if ($template === null || trim($template) === '') {
            $status = 'suppressed';
            $url = '';
            $note = mb_strimwidth(self::NO_CORRECTION_URL . ' (' . $why . ')', 0, 255, '…', 'UTF-8');
        } else {
            $row = $this->conversion($convId);
            $holds = $kind === self::KIND_RETRACTION ? '0.00' : self::money($row['value']);
            $status = 'pending';
            $note = null;
            // The amendment's own tokens exist only on a correction URL, so
            // they are filled here rather than added to the tracker's list,
            // where every pixel would blank them; the rest by its rules.
            $own = [
                'p202_previous_value' => self::money($previousValue ?? $this->conversion($originalConvId)['value']),
                'p202_conv_id' => (string) $convId,
                'p202_original_conv_id' => (string) $originalConvId,
                'p202_notification' => $kind,
            ];
            $url = (string) preg_replace_callback(
                '/\[\[(p202_previous_value|p202_conv_id|p202_original_conv_id|p202_notification)\]\]/i',
                static fn (array $m): string => (string) TrafficSourcePixels::encode($own[strtolower($m[1])]),
                trim($template)
            );
            $url = self::replaceTokens($url, [
                'subid' => (string) $row['click_id'],
                'payout' => $holds,
                'p202_goal_value' => $holds,
                'transactionid' => $row['transaction_id'] ?? $row['dedupe_key'],
                'timestamp' => (string) $now,
                'random' => (string) random_int(1000000, 9999999),
            ]);
        }
        if ($this->insert($userId, $convId, $pixelId, $destination, $kind, $generation, $status, $url, $note, $now) !== 1) {
            // Only a concurrent writer at the same generation lands here, and
            // the engine's subject lock rules that out: refused, not lost.
            throw new \RuntimeException('the ' . $kind . ' of conversion ' . $convId . ' at pixel ' . $pixelId . ' destination ' . $destination
                . ' (generation ' . $generation . ') was not recorded');
        }
    }

    /**
     * What an amendment says about a ledger row: its click, its value, and
     * its transaction id or ledger key.
     *
     * @return array{click_id: int, value: string, transaction_id: string|null, dedupe_key: string}
     */
    private function conversion(int $convId): array
    {
        $stmt = $this->conn->prepareWrite('SELECT click_id, click_payout, transaction_id, dedupe_key FROM 202_conversion_logs WHERE conv_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$convId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            // The user purge deletes a conversion's notifications with it: a
            // notification without its row is a broken link, and an
            // amendment carrying a made-up value would be worse than none.
            throw new \RuntimeException('conversion ' . $convId . ' has notifications but no ledger row');
        }

        return [
            'click_id' => (int) $row['click_id'],
            'value' => (string) $row['click_payout'],
            'transaction_id' => $row['transaction_id'] !== null && $row['transaction_id'] !== '' ? (string) $row['transaction_id'] : null,
            'dedupe_key' => (string) $row['dedupe_key'],
        ];
    }

    private function setStatus(int $id, string $status, string $note): void
    {
        $stmt = $this->conn->prepareWrite('UPDATE 202_notification_pending SET status = ?, last_error = ? WHERE notification_id = ?');
        $this->conn->bind($stmt, 'ssi', [$status, mb_strimwidth($note, 0, 255, '…', 'UTF-8'), $id]);
        $this->conn->executeUpdate($stmt);
    }

}
