<?php

declare(strict_types=1);

namespace Api\V3;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ServerStateStore;

/**
 * Base CRUD controller with lifecycle hooks, input validation, and DI.
 *
 * Subclasses declare their schema via tableName(), primaryKey(), fields().
 * Override lifecycle hooks (beforeCreate, afterCreate, etc.) to inject
 * custom behaviour without copy-pasting the entire CRUD method.
 */
abstract class Controller
{
    abstract protected function tableName(): string;
    abstract protected function primaryKey(): string;
    abstract protected function fields(): array;

    /** @var string[]|null  Computed once per instance. */
    private ?array $cachedSelectColumns = null;
    private ?array $cachedFields = null;
    private ?ServerStateStore $stateStore = null;

    public function __construct(protected \mysqli $db, protected int $userId)
    {
    }

    // ─── Schema helpers ──────────────────────────────────────────────

    protected function userIdColumn(): ?string
    {
        return 'user_id';
    }

    protected function deletedColumn(): ?string
    {
        return null;
    }

    protected function listOrderBy(): string
    {
        return $this->primaryKey() . ' DESC';
    }

    protected function maxBulkRows(): int
    {
        $raw = getenv('P202_MAX_BULK_ROWS');
        if (is_string($raw) && trim($raw) !== '') {
            $parsed = (int)$raw;
            if ($parsed > 0) {
                return min(5000, $parsed);
            }
        }
        return 500;
    }

    protected function selectColumns(): array
    {
        if ($this->cachedSelectColumns !== null) {
            return $this->cachedSelectColumns;
        }
        $columns = [$this->primaryKey()];
        foreach ($this->resolveFields() as $col => $def) {
            $columns[] = $col;
        }
        if ($this->userIdColumn()) {
            $columns[] = $this->userIdColumn();
        }
        $this->cachedSelectColumns = array_values(array_unique($columns));
        return $this->cachedSelectColumns;
    }

    protected function resolveFields(): array
    {
        if ($this->cachedFields === null) {
            $this->cachedFields = $this->fields();
        }
        return $this->cachedFields;
    }

    // ─── Input Validation ────────────────────────────────────────────

    /**
     * Validate and coerce payload values against field definitions.
     *
     * @return array  Cleaned payload with only known, writable fields.
     * @throws ValidationException
     */
    protected function validatePayload(array $payload, bool $requireRequired = false): array
    {
        $fields = $this->resolveFields();
        $errors = [];
        $clean = [];

        if ($requireRequired) {
            foreach ($fields as $col => $def) {
                if (($def['required'] ?? false) && !array_key_exists($col, $payload)) {
                    $errors[$col] = "Field '$col' is required";
                }
            }
        }

        foreach ($payload as $col => $value) {
            $def = $fields[$col] ?? null;
            if ($def === null || ($def['readonly'] ?? false)) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            switch ($def['type']) {
                case 'i':
                    if (!is_numeric($value)) {
                        $errors[$col] = "Field '$col' must be an integer";
                    } else {
                        $clean[$col] = (int)$value;
                    }
                    break;
                case 'd':
                    if (!is_numeric($value)) {
                        $errors[$col] = "Field '$col' must be a number";
                    } else {
                        $clean[$col] = (float)$value;
                    }
                    break;
                case 's':
                    $clean[$col] = (string)$value;
                    if (isset($def['max_length']) && mb_strlen($clean[$col]) > $def['max_length']) {
                        $errors[$col] = "Field '$col' exceeds max length of {$def['max_length']}";
                    }
                    break;
                default:
                    $clean[$col] = $value;
            }
        }

        if ($errors) {
            throw new ValidationException('Validation failed', $errors);
        }

        return $clean;
    }

    // ─── Lifecycle Hooks ─────────────────────────────────────────────

    /**
     * Called before INSERT.  Return extra columns to include in the INSERT.
     * @return array<string, array{type: string, value: mixed}>
     */
    protected function beforeCreate(array $payload): array
    {
        return [];
    }

    protected function afterCreate(int $insertId, array $payload): void
    {
    }

    protected function beforeUpdate(int|string $id, array $payload): void
    {
    }

    protected function beforeDelete(int|string $id): void
    {
    }

    // ─── CRUD Operations ─────────────────────────────────────────────

    public function list(array $params): array
    {
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));
        if (!empty($params['cursor'])) {
            $offset = $this->decodeOffsetCursor((string)$params['cursor']);
        }
        $cursorTtl = max(60, min(86400, (int)($params['cursor_ttl'] ?? 3600)));
        $selectExpr = implode(', ', $this->selectColumns());

        $where = [];
        $binds = [];
        $types = '';

        if ($this->userIdColumn()) {
            $where[] = $this->userIdColumn() . ' = ?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        if ($this->deletedColumn()) {
            $where[] = $this->deletedColumn() . ' = 0';
        }

        $fields = $this->resolveFields();
        $filters = $params['filter'] ?? [];
        if (is_array($filters)) {
            foreach ($filters as $field => $value) {
                $fieldDef = $fields[$field] ?? null;
                if ($fieldDef && !($fieldDef['readonly'] ?? false)) {
                    $where[] = "$field = ?";
                    $binds[] = $value;
                    $types .= $fieldDef['type'];
                }
            }
        }

        if (isset($params['updated_since']) && $params['updated_since'] !== '') {
            $updatedColumn = $this->detectTimestampColumn(['updated_at', 'updated_time', 'last_modified', 'modified_at']);
            if ($updatedColumn !== null) {
                $where[] = "$updatedColumn >= ?";
                $binds[] = (int)$params['updated_since'];
                $types .= 'i';
            }
        }

        if (isset($params['deleted_since']) && $params['deleted_since'] !== '' && $this->deletedColumn() !== null) {
            $deletedColumn = $this->detectTimestampColumn(['deleted_at', 'deleted_time', 'removed_at']);
            if ($deletedColumn !== null) {
                $where[] = "$deletedColumn >= ?";
                $binds[] = (int)$params['deleted_since'];
                $types .= 'i';
            }
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $orderBy = $this->listOrderBy();

        $countSql = "SELECT COUNT(*) as total FROM {$this->tableName()} $whereClause";
        $total = 0;
        if ($types) {
            $stmt = $this->prepare($countSql);
            $stmt->bind_param($types, ...$binds);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new DatabaseException('Count query failed');
            }
            $total = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
        } else {
            $result = $this->db->query($countSql);
            if (!$result) {
                throw new DatabaseException('Count query failed');
            }
            $total = (int)$result->fetch_assoc()['total'];
        }

        $sql = "SELECT $selectExpr FROM {$this->tableName()} $whereClause ORDER BY $orderBy LIMIT ? OFFSET ?";
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
            $rows[] = $this->withVersionMetadata($row);
        }
        $stmt->close();

        $nextCursor = null;
        $cursorExpiresAt = null;
        if (($offset + $limit) < $total) {
            $cursorExpiresAt = time() + $cursorTtl;
            $nextCursor = $this->encodeOffsetCursor($offset + $limit, $cursorExpiresAt);
        }

        return [
            'data' => $rows,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'cursor' => $nextCursor,
                'cursor_expires_at' => $cursorExpiresAt,
            ],
        ];
    }

    public function get(int|string $id): array
    {
        $selectExpr = implode(', ', $this->selectColumns());
        $where = [$this->primaryKey() . ' = ?'];
        $binds = [$id];
        $types = is_int($id) ? 'i' : 's';

        if ($this->userIdColumn()) {
            $where[] = $this->userIdColumn() . ' = ?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        if ($this->deletedColumn()) {
            $where[] = $this->deletedColumn() . ' = 0';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT $selectExpr FROM {$this->tableName()} $whereClause LIMIT 1";
        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException();
        }

        $row = $this->withVersionMetadata($row);
        if (!headers_sent()) {
            header('ETag: ' . $row['etag']);
        }

        return ['data' => $row];
    }

    public function create(array $payload): array
    {
        $clean = $this->validatePayload($payload, requireRequired: true);
        $extras = $this->beforeCreate($clean);

        $columns = [];
        $placeholders = [];
        $binds = [];
        $types = '';
        $fields = $this->resolveFields();

        foreach ($fields as $col => $def) {
            if ($def['readonly'] ?? false) {
                continue;
            }
            if (array_key_exists($col, $clean) && !array_key_exists($col, $extras)) {
                $columns[] = $col;
                $placeholders[] = '?';
                $binds[] = $clean[$col];
                $types .= $def['type'];
            }
        }

        foreach ($extras as $col => $info) {
            $columns[] = $col;
            $placeholders[] = '?';
            $binds[] = $info['value'];
            $types .= $info['type'];
        }

        if ($this->userIdColumn()) {
            $columns[] = $this->userIdColumn();
            $placeholders[] = '?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        if (empty($columns)) {
            throw new ValidationException('No valid fields provided');
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->tableName(),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Insert failed');
        }

        $insertId = $stmt->insert_id;
        $stmt->close();

        $this->afterCreate($insertId, $clean);
        $created = $this->get($insertId);
        $this->recordChange('create', (array)$created['data']);

        return $created;
    }

    public function update(int|string $id, array $payload): array
    {
        $current = $this->get($id);
        $currentData = (array)$current['data'];
        $this->assertIfMatchSatisfied($currentData);
        $clean = $this->validatePayload($payload);
        $this->beforeUpdate($id, $clean);

        $sets = [];
        $binds = [];
        $types = '';
        $fields = $this->resolveFields();

        foreach ($fields as $col => $def) {
            if ($def['readonly'] ?? false) {
                continue;
            }
            if (array_key_exists($col, $clean)) {
                $sets[] = "$col = ?";
                $binds[] = $clean[$col];
                $types .= $def['type'];
            }
        }

        if (empty($sets)) {
            throw new ValidationException('No valid fields to update');
        }

        $binds[] = $id;
        $types .= is_int($id) ? 'i' : 's';

        $where = [$this->primaryKey() . ' = ?'];
        if ($this->userIdColumn()) {
            $where[] = $this->userIdColumn() . ' = ?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->tableName(),
            implode(', ', $sets),
            implode(' AND ', $where)
        );

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Update failed');
        }
        $stmt->close();

        $updated = $this->get($id);
        $this->recordChange('update', (array)$updated['data']);
        return $updated;
    }

    public function delete(int|string $id): void
    {
        $existing = $this->get($id);
        $this->beforeDelete($id);

        $binds = [$id];
        $types = is_int($id) ? 'i' : 's';
        $where = [$this->primaryKey() . ' = ?'];

        if ($this->userIdColumn()) {
            $where[] = $this->userIdColumn() . ' = ?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        if ($this->deletedColumn()) {
            $sql = sprintf(
                'UPDATE %s SET %s = 1 WHERE %s',
                $this->tableName(),
                $this->deletedColumn(),
                implode(' AND ', $where)
            );
        } else {
            $sql = sprintf(
                'DELETE FROM %s WHERE %s',
                $this->tableName(),
                implode(' AND ', $where)
            );
        }

        $stmt = $this->prepare($sql);
        $stmt->bind_param($types, ...$binds);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Delete failed');
        }
        $stmt->close();

        $this->recordChange('delete', (array)$existing['data']);
    }

    public function bulkUpsert(array $payload): array
    {
        $idempotencyKey = trim((string)(\Api\V3\RequestContext::header('idempotency-key') ?? ''));
        if ($idempotencyKey === '') {
            throw new ValidationException('Idempotency-Key header is required', ['idempotency_key' => 'Missing Idempotency-Key header']);
        }

        $rows = $payload['rows'] ?? $payload;
        if (!is_array($rows)) {
            throw new ValidationException('rows must be an array', ['rows' => 'Expected array']);
        }
        $maxRows = $this->maxBulkRows();
        if (count($rows) > $maxRows) {
            throw new ValidationException('rows exceeds max size', ['rows' => "Maximum {$maxRows} rows per request"]);
        }

        $requestHash = ServerStateStore::canonicalHash(['rows' => $rows]);
        $scope = 'bulk-upsert:' . $this->tableName() . ':user:' . $this->userId . ':request:' . $requestHash;
        $existing = $this->stateStore()->getIdempotent($scope, $idempotencyKey);
        if (is_array($existing)) {
            $existing['idempotent_replay'] = true;
            return $existing;
        }

        $results = [];
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0];
        $chunkSize = max(1, min(100, $maxRows));
        foreach (array_chunk($rows, $chunkSize, true) as $chunk) {
            $this->transaction(function () use (&$chunk, &$summary, &$results): void {
                foreach ($chunk as $index => $row) {
                    if (!is_array($row)) {
                        $summary['error']++;
                        $results[] = ['index' => $index, 'status' => 'error', 'message' => 'Row must be an object'];
                        continue;
                    }
                    if ($row === []) {
                        $summary['skipped']++;
                        $results[] = ['index' => $index, 'status' => 'skipped', 'message' => 'Row is empty'];
                        continue;
                    }

                    try {
                        $primaryKey = $this->primaryKey();
                        $id = $row[$primaryKey] ?? $row['id'] ?? null;
                        if ($id !== null && $id !== '') {
                            try {
                                $this->get((string)$id);
                                $clean = $this->validatePayload($row);
                                if ($clean === []) {
                                    $summary['skipped']++;
                                    $results[] = ['index' => $index, 'status' => 'skipped', 'message' => 'No mutable fields provided'];
                                    continue;
                                }
                                $updated = $this->update((string)$id, $row);
                                $summary['updated']++;
                                $results[] = ['index' => $index, 'status' => 'updated', 'data' => $updated['data']];
                                continue;
                            } catch (NotFoundException) {
                                // Fall through to create when provided ID does not exist.
                            }
                        }

                        $created = $this->create($row);
                        $summary['created']++;
                        $results[] = ['index' => $index, 'status' => 'created', 'data' => $created['data']];
                    } catch (\Throwable $e) {
                        $summary['error']++;
                        $results[] = ['index' => $index, 'status' => 'error', 'message' => $e->getMessage()];
                    }
                }
            });
        }

        $response = [
            'data' => $results,
            'summary' => $summary,
            'idempotent_replay' => false,
        ];
        $this->stateStore()->incrementMetric('bulk_upsert_created', (int)$summary['created']);
        $this->stateStore()->incrementMetric('bulk_upsert_updated', (int)$summary['updated']);
        $this->stateStore()->incrementMetric('bulk_upsert_skipped', (int)$summary['skipped']);
        $this->stateStore()->incrementMetric('bulk_upsert_errors', (int)$summary['error']);
        $this->stateStore()->putIdempotent($scope, $idempotencyKey, $response);

        return $response;
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    protected function stateStore(): ServerStateStore
    {
        if ($this->stateStore === null) {
            $this->stateStore = new ServerStateStore();
        }
        return $this->stateStore;
    }

    protected function withVersionMetadata(array $row): array
    {
        $version = $this->computeVersionHash($row);
        $row['version'] = $version;
        $row['etag'] = '"' . $version . '"';
        return $row;
    }

    protected function computeVersionHash(array $row): string
    {
        unset($row['version'], $row['etag']);
        ksort($row);
        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return sha1((string)microtime(true));
        }
        return sha1($json);
    }

    protected function assertIfMatchSatisfied(array $currentRow): void
    {
        $ifMatch = trim((string)(\Api\V3\RequestContext::header('if-match') ?? ''));
        if ($ifMatch === '' || $ifMatch === '*') {
            return;
        }

        $expected = trim($ifMatch);
        if (str_starts_with($expected, 'W/')) {
            $expected = substr($expected, 2);
        }
        $expected = trim($expected, '" ');

        $currentVersion = $this->computeVersionHash($currentRow);
        if ($expected !== $currentVersion) {
            $this->stateStore()->incrementMetric('conflicts', 1);
            throw new ConflictException(
                'Version mismatch',
                [
                    'expected_version' => $expected,
                    'current_version' => $currentVersion,
                    'diff_hint' => 'Re-fetch resource and retry update with latest ETag.',
                ]
            );
        }
    }

    protected function encodeOffsetCursor(int $offset, int $expiresAt): string
    {
        $json = json_encode(['offset' => $offset, 'expires_at' => $expiresAt], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new ValidationException('Failed to encode cursor');
        }
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    protected function decodeOffsetCursor(string $cursor): int
    {
        $payload = strtr($cursor, '-_', '+/');
        $padLen = strlen($payload) % 4;
        if ($padLen !== 0) {
            $payload .= str_repeat('=', 4 - $padLen);
        }

        $raw = base64_decode($payload, true);
        if ($raw === false) {
            throw new ValidationException('Invalid cursor', ['cursor' => 'Malformed cursor']);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ValidationException('Invalid cursor', ['cursor' => 'Malformed cursor']);
        }
        if (!empty($decoded['expires_at']) && (int)$decoded['expires_at'] < time()) {
            throw new ValidationException('Cursor expired', ['cursor' => 'Cursor has expired']);
        }

        return max(0, (int)($decoded['offset'] ?? 0));
    }

    protected function detectTimestampColumn(array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if ($this->hasColumn($column)) {
                return $column;
            }
        }
        return null;
    }

    protected function hasColumn(string $column): bool
    {
        $sql = sprintf('SHOW COLUMNS FROM %s LIKE ?', $this->tableName());
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $column);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            return false;
        }
        $row = $result->fetch_assoc();
        $stmt->close();
        return (bool)$row;
    }

    protected function recordChange(string $operation, array $record): void
    {
        $entity = $this->changeEntityName();
        if ($entity === null) {
            return;
        }
        $this->stateStore()->recordChange($entity, $operation, $record, $this->userId);
    }

    protected function changeEntityName(): ?string
    {
        $map = [
            '202_aff_networks' => 'aff-networks',
            '202_ppc_networks' => 'ppc-networks',
            '202_ppc_accounts' => 'ppc-accounts',
            '202_aff_campaigns' => 'campaigns',
            '202_landing_pages' => 'landing-pages',
            '202_text_ads' => 'text-ads',
            '202_trackers' => 'trackers',
        ];
        return $map[$this->tableName()] ?? null;
    }

    protected function transaction(callable $fn): mixed
    {
        $this->db->begin_transaction();
        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    protected function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException("Prepare failed");
        }
        return $stmt;
    }
}
