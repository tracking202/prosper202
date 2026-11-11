<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

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
                . ' (user_id, received_at, endpoint, method, http_status, outcome, accepted, reason, click_id, conversion_id, transaction_id, fields)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $conn->bind($stmt, 'iissisisiiss', [
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
     * @param array{outcome?: string, endpoint?: string, before?: int} $filters
     *        outcome: 'refused' (anything not in ACCEPTED_OUTCOMES), 'accepted', or one outcome name
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
        if (isset($filters['before'])) {
            $where .= ' AND inbound_id < ?';
            $types .= 'i';
            $values[] = $filters['before'];
        }
        $types .= 'i';
        $values[] = max(1, min(self::MAX_LIMIT, $limit));

        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT inbound_id, user_id, received_at, endpoint, method, http_status, outcome, reason,'
            . ' click_id, conversion_id, transaction_id, fields FROM ' . TableRegistry::INBOUND_LOG
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
        $status = (int) $row['http_status'];
        $userId = (int) $row['user_id'];

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
            'conversion_id' => $row['conversion_id'] === null ? null : (int) $row['conversion_id'],
            'transaction_id' => $row['transaction_id'] === null ? null : (string) $row['transaction_id'],
            'fields' => $fields,
        ];
    }
}
