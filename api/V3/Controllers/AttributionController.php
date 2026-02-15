<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;

class AttributionController
{
    private \mysqli $db;
    private int $userId;

    private const VALID_MODEL_TYPES = ['first_touch', 'last_touch', 'linear', 'time_decay', 'position_based', 'algorithmic'];

    public function __construct(\mysqli $db, int $userId)
    {
        $this->db = $db;
        $this->userId = $userId;
    }

    // --- Models ---

    public function listModels(array $params): array
    {
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $where = ['user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        if (!empty($params['type'])) {
            $where[] = 'model_type = ?';
            $binds[] = (string)$params['type'];
            $types .= 's';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT model_id, user_id, model_name, model_slug, model_type, weighting_config, is_active, is_default, created_at, updated_at
            FROM 202_attribution_models $whereClause ORDER BY model_id DESC LIMIT ? OFFSET ?";
        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('List query failed');
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return ['data' => $rows, 'pagination' => ['limit' => $limit, 'offset' => $offset]];
    }

    public function getModel(int $id): array
    {
        $stmt = $this->prepare('SELECT model_id, user_id, model_name, model_slug, model_type, weighting_config, is_active, is_default, created_at, updated_at FROM 202_attribution_models WHERE model_id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('Attribution model not found');
        }
        return ['data' => $row];
    }

    public function createModel(array $payload): array
    {
        $name = trim((string)($payload['model_name'] ?? ''));
        $type = trim((string)($payload['model_type'] ?? ''));
        if ($name === '' || $type === '') {
            throw new ValidationException('model_name and model_type are required');
        }

        if (!in_array($type, self::VALID_MODEL_TYPES, true)) {
            throw new ValidationException('Invalid model_type', ['model_type' => 'Valid: ' . implode(', ', self::VALID_MODEL_TYPES)]);
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $config = $payload['weighting_config'] ?? '{}';
        if (is_array($config)) {
            $config = json_encode($config);
        }
        $isActive = (int)($payload['is_active'] ?? 1);
        $isDefault = (int)($payload['is_default'] ?? 0);
        $now = time();

        $stmt = $this->prepare('INSERT INTO 202_attribution_models (user_id, model_name, model_slug, model_type, weighting_config, is_active, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssiiii', $this->userId, $name, $slug, $type, $config, $isActive, $isDefault, $now, $now);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Create failed');
        }
        $id = $stmt->insert_id;
        $stmt->close();

        return $this->getModel($id);
    }

    public function updateModel(int $id, array $payload): array
    {
        $this->getModel($id);

        if (array_key_exists('model_type', $payload)) {
            if (!in_array($payload['model_type'], self::VALID_MODEL_TYPES, true)) {
                throw new ValidationException('Invalid model_type', ['model_type' => 'Valid: ' . implode(', ', self::VALID_MODEL_TYPES)]);
            }
        }

        $sets = [];
        $binds = [];
        $types = '';

        foreach (['model_name' => 's', 'model_slug' => 's', 'model_type' => 's', 'is_active' => 'i', 'is_default' => 'i'] as $f => $t) {
            if (array_key_exists($f, $payload)) {
                $sets[] = "$f = ?";
                $binds[] = $payload[$f];
                $types .= $t;
            }
        }
        if (array_key_exists('weighting_config', $payload)) {
            $sets[] = 'weighting_config = ?';
            $val = is_array($payload['weighting_config']) ? json_encode($payload['weighting_config']) : (string)$payload['weighting_config'];
            $binds[] = $val;
            $types .= 's';
        }

        if (empty($sets)) {
            throw new ValidationException('No fields to update');
        }

        $sets[] = 'updated_at = ?';
        $binds[] = time();
        $types .= 'i';

        $binds[] = $id;
        $types .= 'i';
        $binds[] = $this->userId;
        $types .= 'i';

        $stmt = $this->prepare('UPDATE 202_attribution_models SET ' . implode(', ', $sets) . ' WHERE model_id = ? AND user_id = ?');
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Update failed');
        }
        $stmt->close();

        return $this->getModel($id);
    }

    public function deleteModel(int $id): void
    {
        $this->getModel($id);

        $this->db->begin_transaction();
        try {
            $stmt = $this->prepare('DELETE FROM 202_attribution_touchpoints WHERE snapshot_id IN (SELECT snapshot_id FROM 202_attribution_snapshots WHERE model_id = ? AND user_id = ?)');
            $stmt->bind_param('ii', $id, $this->userId);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete touchpoints failed'); }
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_attribution_snapshots WHERE model_id = ? AND user_id = ?');
            $stmt->bind_param('ii', $id, $this->userId);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete snapshots failed'); }
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_attribution_exports WHERE model_id = ? AND user_id = ?');
            $stmt->bind_param('ii', $id, $this->userId);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete exports failed'); }
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_attribution_models WHERE model_id = ? AND user_id = ?');
            $stmt->bind_param('ii', $id, $this->userId);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete model failed'); }
            $stmt->close();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // --- Snapshots ---

    public function listSnapshots(int $modelId, array $params): array
    {
        $this->getModel($modelId);

        $limit = max(1, min(1000, (int)($params['limit'] ?? 500)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $where = ['model_id = ?', 'user_id = ?'];
        $binds = [$modelId, $this->userId];
        $types = 'ii';

        if (!empty($params['scope_type'])) {
            $where[] = 'scope_type = ?';
            $binds[] = (string)$params['scope_type'];
            $types .= 's';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT snapshot_id, model_id, user_id, scope_type, scope_id, date_hour, attributed_revenue, attributed_cost
            FROM 202_attribution_snapshots $whereClause ORDER BY date_hour DESC LIMIT ? OFFSET ?";
        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';
        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Snapshots query failed');
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return ['data' => $rows, 'pagination' => ['limit' => $limit, 'offset' => $offset]];
    }

    // --- Exports ---

    public function listExports(int $modelId): array
    {
        $this->getModel($modelId);

        $stmt = $this->prepare('SELECT export_id, user_id, model_id, scope_type, scope_id, start_hour, end_hour, requested_format, status, queued_at, started_at, completed_at, file_path, webhook_url, created_at, updated_at FROM 202_attribution_exports WHERE model_id = ? AND user_id = ? ORDER BY export_id DESC');
        $stmt->bind_param('ii', $modelId, $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Exports query failed');
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return ['data' => $rows];
    }

    public function scheduleExport(int $modelId, array $payload): array
    {
        $this->getModel($modelId);

        $scopeType = (string)($payload['scope_type'] ?? 'global');
        $scopeId = (int)($payload['scope_id'] ?? 0);
        $startHour = (int)($payload['start_hour'] ?? 0);
        $endHour = (int)($payload['end_hour'] ?? time());
        $format = (string)($payload['format'] ?? 'csv');
        $webhookUrl = (string)($payload['webhook_url'] ?? '');
        $now = time();
        $status = 'queued';

        $stmt = $this->prepare('INSERT INTO 202_attribution_exports (user_id, model_id, scope_type, scope_id, start_hour, end_hour, requested_format, status, queued_at, created_at, updated_at, webhook_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iisiiissiiis',
            $this->userId, $modelId, $scopeType, $scopeId, $startHour, $endHour, $format, $status, $now, $now, $now, $webhookUrl
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Export schedule failed');
        }
        $exportId = $stmt->insert_id;
        $stmt->close();

        $stmt = $this->prepare('SELECT export_id, user_id, model_id, scope_type, scope_id, start_hour, end_hour, requested_format, status, queued_at, created_at, updated_at, webhook_url FROM 202_attribution_exports WHERE export_id = ?');
        $stmt->bind_param('i', $exportId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ['data' => $row];
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }
}
