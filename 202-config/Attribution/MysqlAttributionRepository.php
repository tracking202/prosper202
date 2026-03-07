<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;
use RuntimeException;

final class MysqlAttributionRepository implements AttributionRepositoryInterface
{
    public function __construct(private Connection $conn)
    {
    }

    public function listModels(int $userId, array $filters, int $offset, int $limit): array
    {
        $where = ['user_id = ?'];
        $binds = [$userId];
        $types = 'i';

        if (!empty($filters['type'])) {
            $where[] = 'model_type = ?';
            $binds[] = (string) $filters['type'];
            $types .= 's';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT model_id, user_id, model_name, model_slug, model_type, weighting_config,
                is_active, is_default, created_at, updated_at
            FROM 202_attribution_models $whereClause ORDER BY model_id DESC LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return $this->conn->fetchAll($stmt);
    }

    public function findModel(int $id, int $userId): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT model_id, user_id, model_name, model_slug, model_type, weighting_config,
                    is_active, is_default, created_at, updated_at
             FROM 202_attribution_models WHERE model_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$id, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    public function createModel(int $userId, array $data): int
    {
        $name = (string) ($data['model_name'] ?? '');
        $type = (string) ($data['model_type'] ?? '');
        $slug = (string) ($data['model_slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));
        $config = $data['weighting_config'] ?? '{}';
        if (is_array($config)) {
            $config = json_encode($config);
        }
        $isActive = (int) ($data['is_active'] ?? 1);
        $isDefault = (int) ($data['is_default'] ?? 0);
        $now = time();

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_attribution_models
             (user_id, model_name, model_slug, model_type, weighting_config, is_active, is_default, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'issssiiii', [$userId, $name, $slug, $type, $config, $isActive, $isDefault, $now, $now]);

        return $this->conn->executeInsert($stmt);
    }

    public function updateModel(int $id, int $userId, array $data): void
    {
        $sets = [];
        $values = [];
        $types = '';

        foreach (['model_name' => 's', 'model_slug' => 's', 'model_type' => 's', 'is_active' => 'i', 'is_default' => 'i'] as $f => $t) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = ?";
                $values[] = $data[$f];
                $types .= $t;
            }
        }
        if (array_key_exists('weighting_config', $data)) {
            $sets[] = 'weighting_config = ?';
            $val = is_array($data['weighting_config']) ? json_encode($data['weighting_config']) : (string) $data['weighting_config'];
            $values[] = $val;
            $types .= 's';
        }

        if (empty($sets)) {
            throw new RuntimeException('No fields to update');
        }

        $sets[] = 'updated_at = ?';
        $values[] = time();
        $types .= 'i';

        $values[] = $id;
        $types .= 'i';
        $values[] = $userId;
        $types .= 'i';

        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_attribution_models SET ' . implode(', ', $sets) . ' WHERE model_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, $types, $values);
        $this->conn->execute($stmt);
        $stmt->close();
    }

    public function deleteModel(int $id, int $userId): void
    {
        $this->conn->transaction(function () use ($id, $userId): void {
            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_attribution_touchpoints WHERE snapshot_id IN (SELECT snapshot_id FROM 202_attribution_snapshots WHERE model_id = ? AND user_id = ?)'
            );
            $this->conn->bind($stmt, 'ii', [$id, $userId]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_attribution_snapshots WHERE model_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$id, $userId]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_attribution_exports WHERE model_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$id, $userId]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_attribution_models WHERE model_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$id, $userId]);
            $this->conn->execute($stmt);
            $stmt->close();
        });
    }

    public function listSnapshots(int $modelId, int $userId, array $filters, int $offset, int $limit): array
    {
        $where = ['model_id = ?', 'user_id = ?'];
        $binds = [$modelId, $userId];
        $types = 'ii';

        if (!empty($filters['scope_type'])) {
            $where[] = 'scope_type = ?';
            $binds[] = (string) $filters['scope_type'];
            $types .= 's';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT snapshot_id, model_id, user_id, scope_type, scope_id, date_hour,
                attributed_revenue, attributed_cost
            FROM 202_attribution_snapshots $whereClause ORDER BY date_hour DESC LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return $this->conn->fetchAll($stmt);
    }

    public function listExports(int $modelId, int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT export_id, user_id, model_id, scope_type, scope_id, start_hour, end_hour,
                    requested_format, status, queued_at, started_at, completed_at,
                    file_path, webhook_url, created_at, updated_at
             FROM 202_attribution_exports WHERE model_id = ? AND user_id = ? ORDER BY export_id DESC'
        );
        $this->conn->bind($stmt, 'ii', [$modelId, $userId]);

        return $this->conn->fetchAll($stmt);
    }

    public function scheduleExport(int $modelId, int $userId, array $data): int
    {
        $now = time();

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_attribution_exports
             (user_id, model_id, scope_type, scope_id, start_hour, end_hour, requested_format, status, queued_at, created_at, updated_at, webhook_url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'iisiiissiiis', [
            $userId, $modelId,
            (string) ($data['scope_type'] ?? 'global'),
            (int) ($data['scope_id'] ?? 0),
            (int) ($data['start_hour'] ?? 0),
            (int) ($data['end_hour'] ?? time()),
            (string) ($data['format'] ?? 'csv'),
            'queued',
            $now, $now, $now,
            (string) ($data['webhook_url'] ?? ''),
        ]);
        return $this->conn->executeInsert($stmt);
    }
}
