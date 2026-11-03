<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The postbacks, pixels and payment notifications this install answered
 * (202_inbound_log): written once per request, read by Setup › Inbound Log
 * and GET /conversions/inbound-log, pruned hourly to KEEP_PER_ACCOUNT rows
 * per account.
 *
 * Writing never fails the request it records: one insert, and any error is
 * logged and dropped.
 */
final class InboundLog
{
    public const KEEP_PER_ACCOUNT = 5000;
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 500;
    /** Rows a prune pass deletes per statement, so no delete holds the table long. */
    public const PRUNE_BATCH = 5000;

    /** Outcomes that leave nothing to fix; every other one is listed as refused. */
    public const ACCEPTED_OUTCOMES = ['recorded', 'reversed', 'duplicate', 'test', 'ignored', 'event_recorded'];

    public const ENDPOINTS = ['pb', 'gpb', 'px', 'gpx', 'upx', 'clickbank', 'jvzoo', 'paykickstart'];

    public function __construct(private readonly \mysqli $db)
    {
    }

    public function append(Entry $entry, ?int $now = null): void
    {
        try {
            $conn = new Connection($this->db);
            $stmt = $conn->prepareWrite(
                'INSERT INTO ' . TableRegistry::INBOUND_LOG
                . ' (user_id, received_at, endpoint, method, http_status, outcome, reason, click_id, conversion_id, transaction_id, fields)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $conn->bind($stmt, 'iississiiss', [
                max(0, $entry->userId),
                $now ?? time(),
                substr($entry->endpoint, 0, 24),
                substr($entry->method, 0, 8),
                $entry->httpStatus,
                substr($entry->outcome, 0, 32),
                mb_substr($entry->reason, 0, 500, 'UTF-8'),
                $entry->clickId,
                $entry->conversionId,
                $entry->transactionId === null ? null : mb_substr($entry->transactionId, 0, 255, 'UTF-8'),
                Redactor::encode($entry->fields),
            ]);
            $conn->executeInsert($stmt);
        } catch (\Throwable $e) {
            error_log('Inbound log: the ' . $entry->endpoint . ' request could not be logged: ' . $e->getMessage());
        }
    }

    /**
     * The newest rows first. An admin's list includes the requests no account
     * could be found for (user_id 0), which only an admin sees.
     *
     * @param array{outcome?: string, endpoint?: string, before?: int} $filters
     *        outcome: 'refused' (anything not in ACCEPTED_OUTCOMES), 'accepted', or one outcome name
     * @return list<array<string, mixed>>
     */
    public function list(int $userId, bool $withUnattributed, array $filters = [], int $limit = self::DEFAULT_LIMIT): array
    {
        $where = $withUnattributed ? '(user_id = ? OR user_id = 0)' : 'user_id = ?';
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

        return array_map(self::row(...), $conn->fetchAll($stmt));
    }

    /**
     * Deletes each account's rows beyond its newest KEEP_PER_ACCOUNT, at most
     * $maxBatches statements; answers how many rows went.
     */
    public function prune(int $maxBatches = 40): int
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT user_id FROM ' . TableRegistry::INBOUND_LOG . ' GROUP BY user_id HAVING COUNT(*) > ?'
        );
        $conn->bind($stmt, 'i', [self::KEEP_PER_ACCOUNT]);
        $deleted = 0;
        $batches = 0;
        foreach ($conn->fetchAll($stmt) as $row) {
            $userId = (int) $row['user_id'];
            $cut = $conn->prepareRead(
                'SELECT inbound_id FROM ' . TableRegistry::INBOUND_LOG
                . ' WHERE user_id = ? ORDER BY inbound_id DESC LIMIT 1 OFFSET ?'
            );
            $conn->bind($cut, 'ii', [$userId, self::KEEP_PER_ACCOUNT - 1]);
            $oldestKept = $conn->fetchOne($cut);
            if ($oldestKept === null) {
                continue;
            }
            do {
                if ($batches++ >= $maxBatches) {
                    return $deleted;
                }
                $delete = $conn->prepareWrite(
                    'DELETE FROM ' . TableRegistry::INBOUND_LOG . ' WHERE user_id = ? AND inbound_id < ? LIMIT ' . self::PRUNE_BATCH
                );
                $conn->bind($delete, 'ii', [$userId, (int) $oldestKept['inbound_id']]);
                $gone = $conn->executeUpdate($delete);
                $deleted += $gone;
            } while ($gone === self::PRUNE_BATCH);
        }

        return $deleted;
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
