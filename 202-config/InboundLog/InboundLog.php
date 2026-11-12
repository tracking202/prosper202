<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

use Prosper202\Conversion\PostbackAuth\PostbackAuthGate;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The postbacks, pixels and payment notifications this install answered
 * (202_inbound_log): written once per request, read by Setup › Inbound Log
 * and GET /conversions/inbound-log, pruned hourly to each account's newest
 * KEEP_PER_ACCOUNT requests that wrote something (BACKED_OUTCOMES) and its
 * newest KEEP_REFUSED others, and KEEP_UNATTRIBUTED for requests no account
 * was found for. A flood of refusals or of replays (duplicate, already_lead)
 * never evicts a request that wrote a conversion or event.
 *
 * Writing never fails the request it records: one insert, and any error is
 * logged and dropped. A request that wrote nothing (no account, refused, or
 * a replay) is written at most UNBACKED_PER_MINUTE times a minute per
 * account, so a flood cannot outgrow the prune. Only a request that wrote a
 * row, which a sender cannot repeat at will, goes uncapped.
 */
final class InboundLog
{
    public const KEEP_PER_ACCOUNT = 5000;
    /** An account's refused requests kept, apart from its accepted ones. */
    public const KEEP_REFUSED = 5000;
    /** Rows kept for requests no account could be found for (user_id 0). */
    public const KEEP_UNATTRIBUTED = 1000;
    /** Unattributed or refused requests logged per account a minute; the rest are not logged. */
    public const UNBACKED_PER_MINUTE = 60;
    /** How long one prune may run before it leaves the rest to the next hour. */
    public const PRUNE_SECONDS = 30.0;
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 500;
    /** Rows a prune pass deletes per statement, so no delete holds the table long. */
    public const PRUNE_BATCH = 5000;

    /** Outcomes that leave nothing to fix; every other one is listed as refused. */
    public const ACCEPTED_OUTCOMES = ['recorded', 'reversed', 'duplicate', 'test', 'ignored', 'event_recorded'];

    /**
     * Accepted outcomes that wrote a conversion or event (the accepted column
     * is 1). The others, a replayed transaction id included, wrote nothing and
     * can be sent at any rate, so they are capped and pruned with the refusals.
     */
    public const BACKED_OUTCOMES = ['recorded', 'reversed', 'event_recorded'];

    /** The classic postbacks and pixels, which the account's postback authentication decides. */
    public const POSTBACK_ENDPOINTS = ['pb', 'gpb', 'px', 'gpx', 'upx'];

    /** The request fields a search reads, beside the row's own click, conversion and transaction id. */
    public const SEARCHED_FIELDS = [
        'subid', 'sid', 'txid', 'transaction_id', 'transactionid', 'order_id', 'orderid', 'oid', 't202txid',
        'ctransreceipt', 'custom_subid', 'invoice_id',
    ];
    public const MAX_SEARCH = 255;

    /** How long a "record it now" in progress keeps another from starting on the same row. */
    public const REPLAY_CLAIM_SECONDS = 120;

    private const COLUMNS = 'inbound_id, user_id, received_at, endpoint, method, http_status, outcome, reason,'
        . ' click_id, conversion_id, transaction_id, fields, auth, replayed_at, replay_done, match_method';

    public const ENDPOINTS = ['pb', 'gpb', 'px', 'gpx', 'upx', 'clickbank', 'jvzoo', 'paykickstart', 'shopify', 'zaxaa'];

    public function __construct(private readonly \mysqli $db)
    {
    }

    /** Answers whether the row was written. */
    public function append(Entry $entry, ?int $now = null): bool
    {
        try {
            $now ??= time();
            $userId = max(0, $entry->userId);
            $conn = new Connection($this->db);
            $accepted = $userId > 0 && in_array($entry->outcome, self::BACKED_OUTCOMES, true);
            if (!$accepted && $this->unbackedCapReached($conn, $userId, $now)) {
                return false;
            }
            $stmt = $conn->prepareWrite(
                'INSERT INTO ' . TableRegistry::INBOUND_LOG
                . ' (user_id, received_at, endpoint, method, http_status, outcome, accepted, reason, click_id, conversion_id, transaction_id, fields, auth, match_method)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $conn->bind($stmt, 'iissisisiissss', [
                $userId,
                $now,
                substr($entry->endpoint, 0, 24),
                substr($entry->method, 0, 8),
                $entry->httpStatus,
                substr($entry->outcome, 0, 32),
                $accepted ? 1 : 0,
                mb_substr($entry->reason, 0, 500, 'UTF-8'),
                $entry->clickId,
                $entry->conversionId,
                $entry->transactionId === null ? null : mb_substr(Redactor::scalar($entry->transactionId), 0, 255, 'UTF-8'),
                Redactor::encode($entry->fields),
                in_array($entry->auth, PostbackAuthGate::PRESENTED, true) ? $entry->auth : '',
                $entry->matchMethod === null ? null : substr($entry->matchMethod, 0, 16),
            ]);
            $conn->executeInsert($stmt);

            return true;
        } catch (\Throwable $e) {
            error_log('Inbound log: the ' . $entry->endpoint . ' request could not be logged: ' . $e->getMessage());

            return false;
        }
    }

    /** UNBACKED_PER_MINUTE of the account's unbacked rows (accepted = 0) arrived in the last minute. */
    private function unbackedCapReached(Connection $conn, int $userId, int $now): bool
    {
        $stmt = $conn->prepareWrite(
            'SELECT received_at FROM ' . TableRegistry::INBOUND_LOG
            . ' WHERE user_id = ? AND accepted = 0 ORDER BY inbound_id DESC LIMIT 1 OFFSET ' . (self::UNBACKED_PER_MINUTE - 1)
        );
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);

        return $row !== null && (int) $row['received_at'] > $now - 60;
    }

    /**
     * The newest rows first. An admin's list includes the requests no account
     * could be found for (user_id 0), which only an admin sees. With
     * $withUnverifiedShopify the list also has the Shopify webhooks no stored
     * secret verified, for an account that stores one: nothing proves whose
     * they are, so they come without their fields.
     *
     * @param array{outcome?: string, endpoint?: string, before?: int, search?: string} $filters
     *        outcome: 'refused' (anything not in ACCEPTED_OUTCOMES), 'accepted', or one outcome name;
     *        search: a subid (click id), conversion id or transaction id, matched whole
     * @return list<array<string, mixed>>
     */
    public function list(
        int $userId,
        bool $withUnattributed,
        array $filters = [],
        int $limit = self::DEFAULT_LIMIT,
        bool $withUnverifiedShopify = false
    ): array {
        $where = $withUnattributed ? '(user_id = ? OR user_id = 0)' : 'user_id = ?';
        if (!$withUnattributed && $withUnverifiedShopify) {
            $where = "(user_id = ? OR (user_id = 0 AND endpoint = 'shopify' AND outcome = 'unverified'))";
        }
        $types = 'i';
        $values = [$userId];
        $outcome = $filters['outcome'] ?? null;
        $accepted = "'" . implode("', '", self::ACCEPTED_OUTCOMES) . "'";
        if ($outcome === 'refused') {
            $where .= ' AND outcome NOT IN (' . $accepted . ')';
        } elseif ($outcome === 'accepted') {
            $where .= ' AND outcome IN (' . $accepted . ')';
        } elseif ($outcome !== null) {
            $where .= ' AND outcome = ?';
            $types .= 's';
            $values[] = $outcome;
        }
        if (isset($filters['endpoint'])) {
            $where .= ' AND endpoint = ?';
            $types .= 's';
            $values[] = $filters['endpoint'];
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            [$clause, $searchTypes, $searchValues] = self::searchClause($filters['search']);
            $where .= ' AND ' . $clause;
            $types .= $searchTypes;
            array_push($values, ...$searchValues);
        }
        if (isset($filters['before'])) {
            $where .= ' AND inbound_id < ?';
            $types .= 'i';
            $values[] = $filters['before'];
        }
        $types .= 'i';
        $values[] = max(1, min(self::MAX_LIMIT, $limit));

        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT ' . self::COLUMNS . ' FROM ' . TableRegistry::INBOUND_LOG
            . ' WHERE ' . $where . ' ORDER BY inbound_id DESC LIMIT ?'
        );
        $conn->bind($stmt, $types, $values);

        $rows = array_map(self::row(...), $conn->fetchAll($stmt));
        if (!$withUnattributed) {
            foreach ($rows as $i => $row) {
                if ($row['user_id'] === null) {
                    $rows[$i]['fields'] = null;
                    $rows[$i]['transaction_id'] = null;
                }
            }
        }

        return $rows;
    }

    /**
     * The rows a search finds: the value is the row's click, conversion or
     * transaction id, or the subid or order number the request carried. A
     * request refused before its click was read keeps the subid only in its
     * fields, so those are read too. The log is bounded per account, so the
     * LIKEs read a bounded range.
     *
     * @return array{0: string, 1: string, 2: list<int|string>}
     */
    private static function searchClause(string $search): array
    {
        $search = mb_substr(trim($search), 0, self::MAX_SEARCH, 'UTF-8');
        $clauses = ['transaction_id = ?'];
        $types = 's';
        $values = [$search];
        $json = json_encode($search, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($json)) {
            // LIKE's wildcards, after JSON's escaping; | escapes, since a backslash is JSON's own.
            $like = str_replace(['|', '%', '_'], ['||', '|%', '|_'], $json);
            foreach (self::SEARCHED_FIELDS as $name) {
                $clauses[] = "fields LIKE ? ESCAPE '|'";
                $types .= 's';
                $values[] = '%"' . $name . '":' . $like . '%';
            }
        }
        if (preg_match('/\A[1-9][0-9]{0,17}\z/D', $search) === 1) {
            $clauses[] = 'click_id = ?';
            $clauses[] = 'conversion_id = ?';
            $types .= 'ii';
            $values[] = (int) $search;
            $values[] = (int) $search;
        }

        return ['(' . implode(' OR ', $clauses) . ')', $types, $values];
    }

    /**
     * One of the account's own rows, or null. A request no account was found
     * for is nobody's to act on, so user 0's rows are never answered.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $inboundId): ?array
    {
        if ($userId <= 0 || $inboundId <= 0) {
            return null;
        }
        $conn = new Connection($this->db);
        $stmt = $conn->prepareWrite(
            'SELECT ' . self::COLUMNS . ' FROM ' . TableRegistry::INBOUND_LOG . ' WHERE inbound_id = ? AND user_id = ?'
        );
        $conn->bind($stmt, 'ii', [$inboundId, $userId]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? null : self::row($row);
    }

    /**
     * Take the row for one "record it now" (InboundReplay): true for the one
     * caller that may write. A row already recorded this way, or that another
     * caller took less than REPLAY_CLAIM_SECONDS ago, is not taken.
     */
    public function claimReplay(int $userId, int $inboundId, int $now): bool
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareWrite(
            'UPDATE ' . TableRegistry::INBOUND_LOG . ' SET replayed_at = ?'
            . ' WHERE inbound_id = ? AND user_id = ? AND replay_done = 0 AND (replayed_at IS NULL OR replayed_at < ?)'
        );
        $conn->bind($stmt, 'iiii', [$now, $inboundId, $userId, $now - self::REPLAY_CLAIM_SECONDS]);

        return $conn->executeUpdate($stmt) === 1;
    }

    /**
     * The row is recorded: with the conversion a "record it now" recorded or
     * found on file, or none (an event that reached no goal, a payment a
     * recorder of its own settled).
     */
    public function finishReplay(int $userId, int $inboundId, ?int $conversionId, int $now): void
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareWrite(
            'UPDATE ' . TableRegistry::INBOUND_LOG . ' SET conversion_id = COALESCE(?, conversion_id), replayed_at = ?, replay_done = 1'
            . ' WHERE inbound_id = ? AND user_id = ?'
        );
        $conn->bind($stmt, 'iiii', [$conversionId, $now, $inboundId, $userId]);
        $conn->executeUpdate($stmt);
    }

    /** Give the row back after a "record it now" that recorded nothing, so it can be asked again. */
    public function releaseReplay(int $userId, int $inboundId, int $claimedAt): void
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareWrite(
            'UPDATE ' . TableRegistry::INBOUND_LOG . ' SET replayed_at = NULL'
            . ' WHERE inbound_id = ? AND user_id = ? AND replay_done = 0 AND replayed_at = ?'
        );
        $conn->bind($stmt, 'iii', [$inboundId, $userId, $claimedAt]);
        $conn->executeUpdate($stmt);
    }

    /**
     * Deletes each account's backed rows beyond its newest KEEP_PER_ACCOUNT,
     * its other ones beyond KEEP_REFUSED and the unattributed ones beyond
     * KEEP_UNATTRIBUTED, until caught up or $seconds have passed; answers how
     * many rows went. Each round takes one batch from every bucket over its
     * cap, the unattributed rows last, so no account waits on another's backlog.
     */
    public function prune(float $seconds = self::PRUNE_SECONDS): int
    {
        $conn = new Connection($this->db);
        $deadline = microtime(true) + $seconds;
        $deleted = 0;
        do {
            $behind = false;
            $over = $conn->prepareWrite(
                'SELECT user_id, accepted FROM ' . TableRegistry::INBOUND_LOG . ' GROUP BY user_id, accepted'
                . ' HAVING COUNT(*) > IF(user_id = 0, ?, IF(accepted = 1, ?, ?)) ORDER BY user_id = 0, user_id, accepted DESC'
            );
            $conn->bind($over, 'iii', [self::KEEP_UNATTRIBUTED, self::KEEP_PER_ACCOUNT, self::KEEP_REFUSED]);
            foreach ($conn->fetchAll($over) as $row) {
                $userId = (int) $row['user_id'];
                $accepted = (int) $row['accepted'];
                $keep = $userId === 0 ? self::KEEP_UNATTRIBUTED : ($accepted === 1 ? self::KEEP_PER_ACCOUNT : self::KEEP_REFUSED);
                $gone = $this->pruneBatch($conn, $userId, $accepted, $keep);
                $deleted += $gone;
                $behind = $behind || $gone === self::PRUNE_BATCH;
                if (microtime(true) >= $deadline) {
                    return $deleted;
                }
            }
        } while ($behind);

        return $deleted;
    }

    /** One delete of the bucket's rows older than its newest $keep. */
    private function pruneBatch(Connection $conn, int $userId, int $accepted, int $keep): int
    {
        $cut = $conn->prepareWrite(
            'SELECT inbound_id FROM ' . TableRegistry::INBOUND_LOG
            . ' WHERE user_id = ? AND accepted = ? ORDER BY inbound_id DESC LIMIT 1 OFFSET ?'
        );
        $conn->bind($cut, 'iii', [$userId, $accepted, $keep - 1]);
        $oldestKept = $conn->fetchOne($cut);
        if ($oldestKept === null) {
            return 0;
        }
        $delete = $conn->prepareWrite(
            'DELETE FROM ' . TableRegistry::INBOUND_LOG
            . ' WHERE user_id = ? AND accepted = ? AND inbound_id < ? LIMIT ' . self::PRUNE_BATCH
        );
        $conn->bind($delete, 'iii', [$userId, $accepted, (int) $oldestKept['inbound_id']]);

        return $conn->executeUpdate($delete);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $fields = null;
        if (is_string($row['fields'] ?? null) && $row['fields'] !== '') {
            $decoded = json_decode($row['fields'], true);
            $fields = is_array($decoded) ? $decoded : null;
        }
        $userId = (int) $row['user_id'];
        $conversionId = $row['conversion_id'] === null ? null : (int) $row['conversion_id'];
        $replayedAt = ($row['replayed_at'] ?? null) === null ? null : (int) $row['replayed_at'];
        $auth = (string) ($row['auth'] ?? '');
        $done = (int) ($row['replay_done'] ?? 0) === 1;
        $shaped = self::shape($row, $fields, $userId, $conversionId, $replayedAt, $auth, $done);

        return $shaped + [
            'fix' => InboundFix::of($shaped),
            'can_record' => InboundReplay::refusalOf($shaped) === null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<array-key, mixed>|null $fields
     * @return array<string, mixed>
     */
    private static function shape(
        array $row,
        ?array $fields,
        int $userId,
        ?int $conversionId,
        ?int $replayedAt,
        string $auth,
        bool $done,
    ): array {
        $status = (int) $row['http_status'];

        return [
            'inbound_id' => (int) $row['inbound_id'],
            'received_at' => (int) $row['received_at'],
            'endpoint' => (string) $row['endpoint'],
            'method' => (string) $row['method'],
            'http_status' => $status,
            'accepted' => in_array((string) $row['outcome'], self::ACCEPTED_OUTCOMES, true),
            'outcome' => (string) $row['outcome'],
            'reason' => (string) $row['reason'],
            'user_id' => $userId === 0 ? null : $userId,
            'click_id' => $row['click_id'] === null ? null : (int) $row['click_id'],
            'conversion_id' => $conversionId,
            'transaction_id' => $row['transaction_id'] === null ? null : (string) $row['transaction_id'],
            'fields' => $fields,
            // What a postback or pixel presented; null where that was not read.
            'auth' => $auth === '' ? null : $auth,
            // When "record it now" recorded the row's conversion; null for a row never recorded that way.
            'recorded_later_at' => $done ? $replayedAt : null,
            'record_started_at' => $done ? null : $replayedAt,
            // How the endpoint found the click (a MatchMethod value), where it said before answering.
            'match_method' => ($row['match_method'] ?? null) === null ? null : (string) $row['match_method'],
        ];
    }
}
