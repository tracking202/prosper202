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
        $clickId = (int) $data['click_id'];
        if ($clickId <= 0) {
            throw new RuntimeException('click_id is required');
        }

        $transactionId = (string) ($data['transaction_id'] ?? '');
        $convTime = (int) ($data['conv_time'] ?? time());
        $payoutOverride = isset($data['payout']) ? (float) $data['payout'] : null;

        return $this->conn->transaction(function () use ($clickId, $transactionId, $payoutOverride, $userId, $convTime): int {
            // Look up source click inside transaction with FOR UPDATE to prevent TOCTOU race
            $clickStmt = $this->conn->prepareWrite(
                'SELECT click_id, aff_campaign_id, click_payout, click_time FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
            );
            $this->conn->bind($clickStmt, 'ii', [$clickId, $userId]);
            $click = $this->conn->fetchOne($clickStmt);

            if ($click === null) {
                throw new RuntimeException('Click not found or not owned by user');
            }

            $payout = $payoutOverride ?? (float) ($click['click_payout'] ?? 0);
            $campaignId = (int) $click['aff_campaign_id'];
            $clickTime = (int) ($click['click_time'] ?? 0);
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_conversion_logs (click_id, transaction_id, campaign_id, click_payout, user_id, click_time, conv_time, deleted) VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $this->conn->bind($stmt, 'isidiii', [$clickId, $transactionId, $campaignId, $payout, $userId, $clickTime, $convTime]);
            $convId = $this->conn->executeInsert($stmt);

            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_clicks SET click_lead = 1, click_payout = ? WHERE click_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'dii', [$payout, $clickId, $userId]);
            $this->conn->execute($stmt);
            $stmt->close();

            return $convId;
        });
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
