<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;

class ConversionsController
{
    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
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
        $stmt = $this->prepare($countSql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Count query failed');
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

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

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'List query failed');
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
        $sql = "SELECT cl.conv_id, cl.click_id, cl.transaction_id, cl.campaign_id,
                cl.click_payout, cl.user_id, cl.click_time, cl.conv_time, cl.deleted,
                ac.aff_campaign_name
            FROM 202_conversion_logs cl
            LEFT JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id
            WHERE cl.conv_id = ? AND cl.user_id = ? AND cl.deleted = 0 LIMIT 1";

        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Query failed');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('Conversion not found');
        }
        return ['data' => $row];
    }

    public function create(array $payload): array
    {
        $clickId = (int)($payload['click_id'] ?? 0);
        if ($clickId <= 0) {
            throw new ValidationException('click_id is required', ['click_id' => 'Must be a positive integer']);
        }

        $stmt = $this->prepare('SELECT click_id, aff_campaign_id, click_payout, click_time FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $clickId, $this->userId);
        $this->execute($stmt, 'Query failed');
        $click = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$click) {
            throw new NotFoundException('Click not found or not owned by user');
        }

        $payout = (float)($payload['payout'] ?? $click['click_payout'] ?? 0);
        $transactionId = (string)($payload['transaction_id'] ?? '');
        $convTime = (int)($payload['conv_time'] ?? time());
        $campaignId = (int)$click['aff_campaign_id'];
        $clickTime = (int)($click['click_time'] ?? 0);

        $this->db->begin_transaction();
        try {
            $sql = "INSERT INTO 202_conversion_logs
                (click_id, transaction_id, campaign_id, click_payout, user_id, click_time, conv_time, deleted)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)";
            $stmt = $this->prepare($sql);
            $this->bind($stmt, 'isidiii', $clickId, $transactionId, $campaignId, $payout, $this->userId, $clickTime, $convTime);

            $this->execute($stmt, 'Failed to create conversion');
            $convId = $stmt->insert_id;
            $stmt->close();

            $stmt = $this->prepare('UPDATE 202_clicks SET click_lead = 1, click_payout = ? WHERE click_id = ? AND user_id = ?');
            $this->bind($stmt, 'dii', $payout, $clickId, $this->userId);
            $this->execute($stmt, 'Failed to update click');
            $stmt->close();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $this->get($convId);
    }

    public function delete(int $id): void
    {
        $this->get($id);
        $stmt = $this->prepare('UPDATE 202_conversion_logs SET deleted = 1 WHERE conv_id = ? AND user_id = ?');
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Delete failed');
        $stmt->close();
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }

    private function bind(\mysqli_stmt $stmt, string $types, mixed ...$values): void
    {
        $values = array_values($values);
        $refs = [$stmt, $types];
        foreach ($values as $index => $value) {
            $refs[] = &$values[$index];
        }
        if (!call_user_func_array('mysqli_stmt_bind_param', $refs)) {
            $stmt->close();
            throw new DatabaseException('Bind failed');
        }
    }

    private function execute(\mysqli_stmt $stmt, string $message): void
    {
        if (!mysqli_stmt_execute($stmt)) {
            $stmt->close();
            throw new DatabaseException($message);
        }
    }
}
