<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Database\Connection;
use RuntimeException;

final class MysqlConversionRepository implements ConversionRepositoryInterface
{
    public function __construct(private Connection $conn)
    {
    }

    public function list(int $userId, array $filters, int $offset, int $limit): array
    {
        $where = ['cl.user_id = ?', 'cl.deleted = 0'];
        $binds = [$userId];
        $types = 'i';

        if (!empty($filters['campaign_id'])) {
            $where[] = 'cl.campaign_id = ?';
            $binds[] = (int) $filters['campaign_id'];
            $types .= 'i';
        }
        if (!empty($filters['time_from'])) {
            $where[] = 'cl.conv_time >= ?';
            $binds[] = (int) $filters['time_from'];
            $types .= 'i';
        }
        if (!empty($filters['time_to'])) {
            $where[] = 'cl.conv_time <= ?';
            $binds[] = (int) $filters['time_to'];
            $types .= 'i';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->conn->prepareRead("SELECT COUNT(*) AS total FROM 202_conversion_logs cl $whereClause");
        $this->conn->bind($countStmt, $types, $binds);
        $total = (int) ($this->conn->fetchOne($countStmt)['total'] ?? 0);

        $sql = "SELECT cl.conv_id, cl.click_id, cl.transaction_id, cl.campaign_id,
                cl.click_payout, cl.user_id, cl.click_time, cl.conv_time, cl.deleted,
                ac.aff_campaign_name
            FROM 202_conversion_logs cl
            LEFT JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id
            $whereClause
            ORDER BY cl.conv_time DESC LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return ['rows' => $this->conn->fetchAll($stmt), 'total' => $total];
    }

    public function findById(int $id, int $userId): ?array
    {
        $sql = "SELECT cl.conv_id, cl.click_id, cl.transaction_id, cl.campaign_id,
                cl.click_payout, cl.user_id, cl.click_time, cl.conv_time, cl.deleted,
                ac.aff_campaign_name
            FROM 202_conversion_logs cl
            LEFT JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id
            WHERE cl.conv_id = ? AND cl.user_id = ? AND cl.deleted = 0 LIMIT 1";

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'ii', [$id, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    public function create(int $userId, array $data): int
    {
        $result = $this->record($userId, $data, function (int $clickId, float $payout) use ($userId): void {
            $this->applyStandardClickUpdate($clickId, $payout, $userId);
        });

        if (!$result['clickFound']) {
            throw new ClickNotFoundException('Click not found or not owned by user');
        }

        return $result['convId'];
    }

    /**
     * Single owner of the transactional conversion write used by every ingestion
     * path (V3 API and the legacy static postback/pixel endpoints).
     *
     * In one transaction it: locks the source click (SELECT ... FOR UPDATE) so
     * concurrent/retried postbacks serialise; de-duplicates on transaction_id
     * (matching the UNIQUE (click_id, transaction_id) key, which ignores
     * `deleted`); inserts the conversion_logs row; and runs an optional
     * caller-supplied click-side update inside the same transaction so the click
     * flag and the audit row commit or roll back together.
     *
     * @param array<string, mixed> $data Requires click_id. Optional:
     *        transaction_id, payout, conv_time, campaign_id, click_time, and the
     *        legacy columns time_difference, ip, pixel_type, user_agent (only
     *        written when present, so the V3 insert keeps its historical shape).
     * @param (callable(int $clickId, float $payout): void)|null $clickSideUpdate
     * @return array{convId: int, duplicate: bool, clickFound: bool}
     */
    public function record(int $userId, array $data, ?callable $clickSideUpdate = null): array
    {
        $clickId = (int) ($data['click_id'] ?? 0);
        if ($clickId <= 0) {
            throw new RuntimeException('click_id is required');
        }

        // Trim centrally so a blank/whitespace-only id is treated as absent
        // (stored NULL, no dedup) across every ingestion path.
        $rawTransactionId = trim((string) ($data['transaction_id'] ?? ''));
        $transactionId = $rawTransactionId !== '' ? $rawTransactionId : null;
        $convTime = (int) ($data['conv_time'] ?? time());
        $payoutOverride = isset($data['payout']) ? (float) $data['payout'] : null;

        return $this->conn->transaction(function () use ($userId, $clickId, $transactionId, $convTime, $payoutOverride, $data, $clickSideUpdate): array {
            // Lock the source click so concurrent/retried postbacks serialise here.
            $clickStmt = $this->conn->prepareWrite(
                'SELECT click_id, aff_campaign_id, click_payout, click_time FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
            );
            $this->conn->bind($clickStmt, 'ii', [$clickId, $userId]);
            $click = $this->conn->fetchOne($clickStmt);

            if ($click === null) {
                return ['convId' => 0, 'duplicate' => false, 'clickFound' => false];
            }

            // Idempotency: a transaction_id already recorded for this click is a
            // replay/retry. The lookup ignores `deleted` so it matches the UNIQUE
            // (click_id, transaction_id) key and never collides on insert.
            if ($transactionId !== null) {
                $dupStmt = $this->conn->prepareWrite(
                    'SELECT conv_id FROM 202_conversion_logs WHERE click_id = ? AND transaction_id = ? LIMIT 1'
                );
                $this->conn->bind($dupStmt, 'is', [$clickId, $transactionId]);
                $dup = $this->conn->fetchOne($dupStmt);
                if ($dup !== null) {
                    return ['convId' => (int) $dup['conv_id'], 'duplicate' => true, 'clickFound' => true];
                }
            }

            $payout = $payoutOverride ?? (float) ($click['click_payout'] ?? 0);
            $campaignId = isset($data['campaign_id']) ? (int) $data['campaign_id'] : (int) $click['aff_campaign_id'];
            $clickTime = isset($data['click_time']) ? (int) $data['click_time'] : (int) ($click['click_time'] ?? 0);

            // Base column set is exactly the historical V3 insert; the NOT-NULL
            // legacy columns are always appended below (with defaults when absent).
            $columns = ['click_id', 'transaction_id', 'campaign_id', 'click_payout', 'user_id', 'click_time', 'conv_time'];
            $types = 'isidiii';
            $values = [$clickId, $transactionId, $campaignId, $payout, $userId, $clickTime, $convTime];

            // These columns are NOT NULL with no DB default. Callers that have the
            // context (the legacy pixel/postback paths) pass them in $data; callers
            // that don't (the V3 API) would otherwise omit them entirely, and the
            // INSERT then fails under STRICT sql_mode with "Field doesn't have a
            // default value" — silently dropping the conversion. Always include them,
            // using the caller's value when supplied and a sensible default otherwise,
            // so every ingestion path writes a valid row. time_difference is the
            // click->conversion gap in seconds.
            $legacyDefaults = [
                'time_difference' => (string) max(0, $convTime - $clickTime),
                'ip' => '',
                'pixel_type' => 0,
                'user_agent' => '',
            ];
            foreach (['time_difference' => 's', 'ip' => 's', 'pixel_type' => 'i', 'user_agent' => 's'] as $col => $type) {
                $value = array_key_exists($col, $data) ? $data[$col] : $legacyDefaults[$col];
                $columns[] = $col;
                $types .= $type;
                $values[] = $type === 'i' ? (int) $value : (string) $value;
            }

            $placeholders = rtrim(str_repeat('?, ', count($values)), ', ');
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_conversion_logs (' . implode(', ', $columns) . ', deleted) VALUES (' . $placeholders . ', 0)'
            );
            $this->conn->bind($stmt, $types, $values);
            $convId = $this->conn->executeInsert($stmt);

            if ($clickSideUpdate !== null) {
                $clickSideUpdate($clickId, $payout);
            }

            return ['convId' => $convId, 'duplicate' => false, 'clickFound' => true];
        });
    }

    private function applyStandardClickUpdate(int $clickId, float $payout, int $userId): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_clicks SET click_lead = 1, click_payout = ? WHERE click_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'dii', [$payout, $clickId, $userId]);
        // executeUpdate() runs the checked execute and closes the statement.
        $this->conn->executeUpdate($stmt);
    }

    public function softDelete(int $id, int $userId): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_conversion_logs SET deleted = 1 WHERE conv_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$id, $userId]);
        $this->conn->execute($stmt);
        $stmt->close();
    }
}
