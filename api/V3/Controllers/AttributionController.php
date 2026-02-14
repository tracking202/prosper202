<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Bootstrap;

class AttributionController
{
    private \mysqli $db;
    private int $userId;

    public function __construct()
    {
        $this->db = Bootstrap::db();
        $this->userId = Bootstrap::userId();
    }

    // --- Models ---

    public function listModels(array $params): array
    {
        $where = ['user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        if (!empty($params['type'])) {
            $where[] = 'model_type = ?';
            $binds[] = $params['type'];
            $types .= 's';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT * FROM 202_attribution_models $whereClause ORDER BY model_id DESC");
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return ['data' => $rows];
    }

    public function getModel(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM 202_attribution_models WHERE model_id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $this->userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new \RuntimeException('Attribution model not found', 404);
        }
        return ['data' => $row];
    }

    public function createModel(array $payload): array
    {
        $name = $payload['model_name'] ?? '';
        $type = $payload['model_type'] ?? '';
        if ($name === '' || $type === '') {
            throw new \RuntimeException('model_name and model_type are required', 422);
        }

        $validTypes = ['first_touch', 'last_touch', 'linear', 'time_decay', 'position_based', 'algorithmic'];
        if (!in_array($type, $validTypes, true)) {
            throw new \RuntimeException('Invalid model_type. Valid: ' . implode(', ', $validTypes), 422);
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $config = $payload['weighting_config'] ?? '{}';
        if (is_array($config)) {
            $config = json_encode($config);
        }
        $isActive = (int)($payload['is_active'] ?? 1);
        $isDefault = (int)($payload['is_default'] ?? 0);
        $now = time();

        $stmt = $this->db->prepare('INSERT INTO 202_attribution_models (user_id, model_name, model_slug, model_type, weighting_config, is_active, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssiiis', $this->userId, $name, $slug, $type, $config, $isActive, $isDefault, $now, $now);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Create failed: ' . $stmt->error, 500);
        }
        $id = $stmt->insert_id;
        $stmt->close();

        return $this->getModel($id);
    }

    public function updateModel(int $id, array $payload): array
    {
        $this->getModel($id);

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
            $val = is_array($payload['weighting_config']) ? json_encode($payload['weighting_config']) : $payload['weighting_config'];
            $binds[] = $val;
            $types .= 's';
        }

        if (empty($sets)) {
            throw new \RuntimeException('No fields to update', 422);
        }

        $sets[] = 'updated_at = ?';
        $binds[] = time();
        $types .= 'i';

        $binds[] = $id;
        $types .= 'i';
        $binds[] = $this->userId;
        $types .= 'i';

        $stmt = $this->db->prepare('UPDATE 202_attribution_models SET ' . implode(', ', $sets) . ' WHERE model_id = ? AND user_id = ?');
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $stmt->close();

        return $this->getModel($id);
    }

    public function deleteModel(int $id): void
    {
        $this->getModel($id);

        // Clean up related data
        $stmt = $this->db->prepare('DELETE FROM 202_attribution_touchpoints WHERE snapshot_id IN (SELECT snapshot_id FROM 202_attribution_snapshots WHERE model_id = ? AND user_id = ?)');
        $stmt->bind_param('ii', $id, $this->userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM 202_attribution_snapshots WHERE model_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $this->userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM 202_attribution_exports WHERE model_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $this->userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM 202_attribution_models WHERE model_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $this->userId);
        $stmt->execute();
        $stmt->close();
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
            $binds[] = $params['scope_type'];
            $types .= 's';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT * FROM 202_attribution_snapshots $whereClause ORDER BY date_hour DESC LIMIT ? OFFSET ?");
        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
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

        $stmt = $this->db->prepare('SELECT * FROM 202_attribution_exports WHERE model_id = ? AND user_id = ? ORDER BY export_id DESC');
        $stmt->bind_param('ii', $modelId, $this->userId);
        $stmt->execute();
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

        $scopeType = $payload['scope_type'] ?? 'global';
        $scopeId = (int)($payload['scope_id'] ?? 0);
        $startHour = (int)($payload['start_hour'] ?? 0);
        $endHour = (int)($payload['end_hour'] ?? time());
        $format = $payload['format'] ?? 'csv';
        $webhookUrl = $payload['webhook_url'] ?? '';
        $now = time();

        $stmt = $this->db->prepare('INSERT INTO 202_attribution_exports (user_id, model_id, scope_type, scope_id, start_hour, end_hour, requested_format, status, queued_at, created_at, updated_at, webhook_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $status = 'queued';
        $stmt->bind_param('iisiisssiiss',
            $this->userId, $modelId, $scopeType, $scopeId, $startHour, $endHour, $format, $status, $now, $now, $now, $webhookUrl
        );
        if (!$stmt->execute()) {
            throw new \RuntimeException('Export schedule failed: ' . $stmt->error, 500);
        }
        $exportId = $stmt->insert_id;
        $stmt->close();

        $stmt = $this->db->prepare('SELECT * FROM 202_attribution_exports WHERE export_id = ?');
        $stmt->bind_param('i', $exportId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ['data' => $row];
    }
}
