<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\StatementHelpers;

class AttributionController
{
    use StatementHelpers;

    private const array VALID_MODEL_TYPES = ['first_touch', 'last_touch', 'linear', 'time_decay', 'position_based', 'algorithmic'];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
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
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'List query failed');
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
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Query failed');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('Attribution model not found');
        }
        return ['data' => $row];
    }

    /**
     * Validate/encode a weighting_config payload value to a JSON string.
     * json_encode() failures and non-JSON strings must be explicit errors:
     * a silently-emptied config makes the model compute garbage attribution.
     */
    private function normalizeWeightingConfig(mixed $config): string
    {
        if (is_array($config)) {
            $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new ValidationException('weighting_config could not be encoded', ['weighting_config' => json_last_error_msg()]);
            }
            return $json;
        }

        $raw = trim((string)$config);
        if ($raw === '') {
            return '{}';
        }
        json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationException('weighting_config must be valid JSON', ['weighting_config' => json_last_error_msg()]);
        }
        return $raw;
    }

    /**
     * 202_attribution_models has UNIQUE (user_id, model_slug); surface a
     * duplicate as 409 instead of letting the INSERT die as a generic 500.
     */
    private function assertSlugAvailable(string $slug, ?int $excludeId = null): void
    {
        $sql = 'SELECT model_id FROM 202_attribution_models WHERE user_id = ? AND model_slug = ?';
        $types = 'is';
        $binds = [$this->userId, $slug];
        if ($excludeId !== null) {
            $sql .= ' AND model_id != ?';
            $types .= 'i';
            $binds[] = $excludeId;
        }
        $stmt = $this->prepare($sql . ' LIMIT 1');
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Slug lookup failed');
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            throw new ConflictException('A model with this slug already exists', ['model_slug' => $slug]);
        }
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

        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($slug === '') {
            throw new ValidationException('model_name must contain at least one alphanumeric character', ['model_name' => 'Cannot derive a slug']);
        }
        $this->assertSlugAvailable($slug);
        $config = $this->normalizeWeightingConfig($payload['weighting_config'] ?? '{}');
        $isActive = (int)($payload['is_active'] ?? 1);
        $isDefault = (int)($payload['is_default'] ?? 0);
        $now = time();

        $stmt = $this->prepare('INSERT INTO 202_attribution_models (user_id, model_name, model_slug, model_type, weighting_config, is_active, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $this->bind($stmt, 'issssiiii', $this->userId, $name, $slug, $type, $config, $isActive, $isDefault, $now, $now);
        $this->execute($stmt, 'Create failed');
        $id = $stmt->insert_id;
        $stmt->close();

        try {
            return $this->getModel($id);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('attribution model', $e);
        }
    }

    public function updateModel(int $id, array $payload): array
    {
        $this->getModel($id);

        if (array_key_exists('model_type', $payload)) {
            if (!in_array($payload['model_type'], self::VALID_MODEL_TYPES, true)) {
                throw new ValidationException('Invalid model_type', ['model_type' => 'Valid: ' . implode(', ', self::VALID_MODEL_TYPES)]);
            }
        }

        // Create rejects empty names/slugs; update must too, and an emptied
        // slug would additionally break slug-addressed lookups.
        foreach (['model_name', 'model_slug'] as $requiredField) {
            if (array_key_exists($requiredField, $payload) && trim((string)$payload[$requiredField]) === '') {
                throw new ValidationException("$requiredField cannot be empty", [$requiredField => 'Must not be empty']);
            }
        }
        if (array_key_exists('model_slug', $payload)) {
            $this->assertSlugAvailable(trim((string)$payload['model_slug']), $id);
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
            $binds[] = $this->normalizeWeightingConfig($payload['weighting_config']);
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
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Update failed');
        $stmt->close();

        return $this->getModel($id);
    }

    /**
     * Read-only preview of deleteModel() for `?dry_run=1`: the model plus
     * the counts of the snapshot/touchpoint/export rows the cascade removes.
     */
    public function deleteModelPreview(int $id): array
    {
        $model = $this->getModel($id)['data'];

        $snapshots = $this->countRows(
            'SELECT COUNT(*) AS c FROM 202_attribution_snapshots WHERE model_id = ? AND user_id = ?',
            'ii',
            $id,
            $this->userId
        );
        $touchpoints = $this->countRows(
            'SELECT COUNT(*) AS c FROM 202_attribution_touchpoints WHERE snapshot_id IN '
            . '(SELECT snapshot_id FROM 202_attribution_snapshots WHERE model_id = ? AND user_id = ?)',
            'ii',
            $id,
            $this->userId
        );
        $exports = $this->countRows(
            'SELECT COUNT(*) AS c FROM 202_attribution_exports WHERE model_id = ? AND user_id = ?',
            'ii',
            $id,
            $this->userId
        );

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'attribution-models',
            'mode' => 'hard',
            'record' => $model,
            'cascade' => [
                ['resource' => 'attribution-snapshots', 'count' => $snapshots],
                ['resource' => 'attribution-touchpoints', 'count' => $touchpoints],
                ['resource' => 'attribution-exports', 'count' => $exports],
            ],
        ]];
    }

    private function countRows(string $sql, string $types, mixed ...$binds): int
    {
        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Count query failed');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0);
    }

    public function deleteModel(int $id): void
    {
        $this->getModel($id);

        $this->beginTransaction();
        try {
            $stmt = $this->prepare('DELETE FROM 202_attribution_touchpoints WHERE snapshot_id IN (SELECT snapshot_id FROM 202_attribution_snapshots WHERE model_id = ? AND user_id = ?)');
            $this->bind($stmt, 'ii', $id, $this->userId);
            $this->execute($stmt, 'Delete touchpoints failed');
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_attribution_snapshots WHERE model_id = ? AND user_id = ?');
            $this->bind($stmt, 'ii', $id, $this->userId);
            $this->execute($stmt, 'Delete snapshots failed');
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_attribution_exports WHERE model_id = ? AND user_id = ?');
            $this->bind($stmt, 'ii', $id, $this->userId);
            $this->execute($stmt, 'Delete exports failed');
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_attribution_models WHERE model_id = ? AND user_id = ?');
            $this->bind($stmt, 'ii', $id, $this->userId);
            $this->execute($stmt, 'Delete model failed');
            $stmt->close();

            if (!$this->db->commit()) {
                throw new DatabaseException('Transaction commit failed');
            }
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
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Snapshots query failed');
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
        $this->bind($stmt, 'ii', $modelId, $this->userId);
        $this->execute($stmt, 'Exports query failed');
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
        // Validate here, at the entry point: this is the only place the caller
        // can be told their URL is unusable. Storing it unchecked and relying on
        // a guard further down means the rejection happens in a cron nobody is
        // watching -- and it used to happen in the row hydration, taking the
        // whole export queue down with it.
        if ($webhookUrl !== '') {
            try {
                \Prosper202\Validation\OutboundUrlGuard::assertAllowed($webhookUrl, 'webhook_url');
            } catch (\RuntimeException $e) {
                throw new ValidationException($e->getMessage(), ['webhook_url' => $e->getMessage()], $e);
            }
        }
        $now = time();
        // Must be 'pending': the export cron's claimPending() only selects status='pending',
        // and 'queued' is not a valid ExportStatus enum value (would fatal on hydration).
        $status = 'pending';

        $stmt = $this->prepare('INSERT INTO 202_attribution_exports (user_id, model_id, scope_type, scope_id, start_hour, end_hour, requested_format, status, queued_at, created_at, updated_at, webhook_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $this->bind($stmt, 'iisiiissiiis',
            $this->userId, $modelId, $scopeType, $scopeId, $startHour, $endHour, $format, $status, $now, $now, $now, $webhookUrl
        );
        $this->execute($stmt, 'Export schedule failed');
        $exportId = $stmt->insert_id;
        $stmt->close();

        // The export is queued from here; only reading it back can fail.
        try {
            $stmt = $this->prepare('SELECT export_id, user_id, model_id, scope_type, scope_id, start_hour, end_hour, requested_format, status, queued_at, created_at, updated_at, webhook_url FROM 202_attribution_exports WHERE export_id = ?');
            $this->bind($stmt, 'i', $exportId);
            $this->execute($stmt, 'Query failed');
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            throw new WriteCommittedException('attribution export', $e);
        }

        return ['data' => $row];
    }
}
