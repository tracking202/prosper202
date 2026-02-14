<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Bootstrap;

class ConversionsController
{
    private \mysqli $db;
    private int $userId;

    public function __construct()
    {
        $this->db = Bootstrap::db();
        $this->userId = Bootstrap::userId();
    }

    public function list(array $params): array
    {
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $where = ['cl.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        if (!empty($params['campaign_id'])) {
            $where[] = 'cl.campaign_id = ?';
            $binds[] = (int)$params['campaign_id'];
            $types .= 'i';
        }
        if (!empty($params['time_from'])) {
            $where[] = 'cl.conv_time >= ?';
            $binds[] = (int)$params['time_from'];
            $types .= 'i';
        }
        if (!empty($params['time_to'])) {
            $where[] = 'cl.conv_time <= ?';
            $binds[] = (int)$params['time_to'];
            $types .= 'i';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where) . ' AND cl.deleted = 0';

        $countSql = "SELECT COUNT(*) as total FROM 202_conversion_logs cl $whereClause";
        $stmt = $this->db->prepare($countSql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        $sql = "SELECT cl.*, ac.aff_campaign_name
            FROM 202_conversion_logs cl
            LEFT JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id
            $whereClause
            ORDER BY cl.conv_time DESC LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return [
            'data' => $rows,
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ];
    }

    public function get(int $id): array
    {
        $sql = "SELECT cl.*, ac.aff_campaign_name
            FROM 202_conversion_logs cl
            LEFT JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id
            WHERE cl.conv_id = ? AND cl.user_id = ? AND cl.deleted = 0 LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $id, $this->userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new \RuntimeException('Conversion not found', 404);
        }
        return ['data' => $row];
    }

    public function create(array $payload): array
    {
        $clickId = (int)($payload['click_id'] ?? 0);
        if ($clickId <= 0) {
            throw new \RuntimeException('click_id is required', 422);
        }

        // Verify click belongs to user
        $stmt = $this->db->prepare('SELECT click_id, aff_campaign_id, click_payout FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $clickId, $this->userId);
        $stmt->execute();
        $click = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$click) {
            throw new \RuntimeException('Click not found or not owned by user', 404);
        }

        $payout = (float)($payload['payout'] ?? $click['click_payout'] ?? 0);
        $transactionId = $payload['transaction_id'] ?? '';
        $convTime = (int)($payload['conv_time'] ?? time());
        $campaignId = (int)$click['aff_campaign_id'];
        $clickTime = 0;

        // Get click_time
        $stmt = $this->db->prepare('SELECT click_time FROM 202_clicks WHERE click_id = ? LIMIT 1');
        $stmt->bind_param('i', $clickId);
        $stmt->execute();
        $ct = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $clickTime = (int)($ct['click_time'] ?? 0);

        $sql = "INSERT INTO 202_conversion_logs
            (click_id, transaction_id, campaign_id, click_payout, user_id, click_time, conv_time, deleted)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('isidiii', $clickId, $transactionId, $campaignId, $payout, $this->userId, $clickTime, $convTime);

        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to create conversion: ' . $stmt->error, 500);
        }
        $convId = $stmt->insert_id;
        $stmt->close();

        // Mark click as lead
        $stmt = $this->db->prepare('UPDATE 202_clicks SET click_lead = 1, click_payout = ? WHERE click_id = ? AND user_id = ?');
        $stmt->bind_param('dii', $payout, $clickId, $this->userId);
        $stmt->execute();
        $stmt->close();

        return $this->get($convId);
    }

    public function delete(int $id): void
    {
        $this->get($id); // verify ownership
        $stmt = $this->db->prepare('UPDATE 202_conversion_logs SET deleted = 1 WHERE conv_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $this->userId);
        $stmt->execute();
        $stmt->close();
    }
}
