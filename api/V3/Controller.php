<?php

declare(strict_types=1);

namespace Api\V3;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;

/**
 * Base CRUD controller with lifecycle hooks, input validation, and DI.
 *
 * Subclasses declare their schema via tableName(), primaryKey(), fields().
 * Override lifecycle hooks (beforeCreate, afterCreate, etc.) to inject
 * custom behaviour without copy-pasting the entire CRUD method.
 */
abstract class Controller
{
    protected \mysqli $db;
    protected int $userId;

    abstract protected function tableName(): string;
    abstract protected function primaryKey(): string;
    abstract protected function fields(): array;

    /** @var string[]|null  Computed once per instance. */
    private ?array $cachedSelectColumns = null;
    private ?array $cachedFields = null;

    public function __construct(\mysqli $db, int $userId)
    {
        $this->db = $db;
        $this->userId = $userId;
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

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $orderBy = $this->listOrderBy();

        $countSql = "SELECT COUNT(*) as total FROM {$this->tableName()} $whereClause";
        $total = 0;
        if ($types) {
            $stmt = $this->prepare($countSql);
            $stmt->bind_param($types, ...$binds);
            $stmt->execute();
            $total = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
        } else {
            $result = $this->db->query($countSql);
            $total = (int)$result->fetch_assoc()['total'];
        }

        $sql = "SELECT $selectExpr FROM {$this->tableName()} $whereClause ORDER BY $orderBy LIMIT ? OFFSET ?";
        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->prepare($sql);
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
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException();
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
            if (array_key_exists($col, $clean)) {
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

        return $this->get($insertId);
    }

    public function update(int|string $id, array $payload): array
    {
        $this->get($id);
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

        return $this->get($id);
    }

    public function delete(int|string $id): void
    {
        $this->get($id);
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
        $stmt->execute();
        $stmt->close();
    }

    // ─── Helpers ─────────────────────────────────────────────────────

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
