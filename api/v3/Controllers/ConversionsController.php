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

        $data = [
            'click_id' => $clickId,
            'transaction_id' => (string)($payload['transaction_id'] ?? ''),
            'conv_time' => (int)($payload['conv_time'] ?? time()),
        ];
        if (array_key_exists('payout', $payload)) {
            $data['payout'] = (float)$payload['payout'];
        }

        // LTV: optional customer identity + product line items. An invalid
        // customer_ref_type or malformed items array is rejected by the
        // repository with an explicit error — never silently dropped.
        if (!empty($payload['customer_id'])) {
            $data['customer_id'] = (int)$payload['customer_id'];
        }
        if (!empty($payload['customer_ref'])) {
            $data['customer_ref'] = (string)$payload['customer_ref'];
            if (!empty($payload['customer_ref_type'])) {
                $data['customer_ref_type'] = (string)$payload['customer_ref_type'];
            }
        }
        if (isset($payload['customer_crm'])) {
            if (!is_array($payload['customer_crm'])) {
                throw new ValidationException('customer_crm must be an object', ['customer_crm' => 'Must be an object of CRM fields']);
            }
            $data['customer_crm'] = $payload['customer_crm'];
        }
        if (isset($payload['items'])) {
            if (!is_array($payload['items'])) {
                throw new ValidationException('items must be an array', ['items' => 'Must be an array of line items']);
            }
            $data['items'] = $payload['items'];
        }

        // Delegate to the single canonical conversion writer so the V3 API and the
        // legacy postback/pixel endpoints share one transactional, idempotent path
        // (locks the click, de-dupes on transaction_id, inserts + flags the click).
        $repo = new \Prosper202\Conversion\MysqlConversionRepository(
            new \Prosper202\Database\Connection($this->db)
        );

        try {
            $convId = $repo->create($this->userId, $data);
        } catch (\Prosper202\Conversion\ClickNotFoundException $e) {
            throw new NotFoundException('Click not found or not owned by user');
        } catch (\Throwable $e) {
            // Preserve the underlying failure for server-side logs via getPrevious().
            throw new DatabaseException('Failed to create conversion: ' . $e->getMessage(), $e);
        }

        return $this->get($convId);
    }

    public function delete(int $id): void
    {
        $this->get($id);

        // Delegate to the canonical repository so the soft-delete also voids
        // the conversion's revenue ledger event (compensating adjustment) and
        // corrects the customer's LTV rollups in the same transaction.
        $repo = new \Prosper202\Conversion\MysqlConversionRepository(
            new \Prosper202\Database\Connection($this->db)
        );
        try {
            $repo->softDelete($id, $this->userId);
        } catch (\Throwable $e) {
            throw new DatabaseException('Delete failed: ' . $e->getMessage(), $e);
        }
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
        // @phpstan-ignore-next-line prosper202.directStmtCall — this IS the centralized ref-safe bind wrapper (no Connection instance; routing through $this->conn would self-recurse)
        if (!$stmt->bind_param($types, ...$values)) {
            $stmt->close();
            throw new DatabaseException('Bind failed');
        }
    }

    private function execute(\mysqli_stmt $stmt, string $message): void
    {
        // @phpstan-ignore-next-line prosper202.directStmtCall — this IS the centralized checked-execute wrapper (no Connection instance; routing through $this->conn would self-recurse)
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException($message);
        }
    }
}
