<?php

declare(strict_types=1);

namespace Api\V3;

abstract class Controller
{
    protected \mysqli $db;
    protected int $userId;

    abstract protected function tableName(): string;
    abstract protected function primaryKey(): string;
    abstract protected function fields(): array;

    protected function selectColumns(): array
    {
        $columns = [$this->primaryKey()];
        foreach ($this->fields() as $col => $def) {
            $columns[] = $col;
        }
        if ($this->userIdColumn()) {
            $columns[] = $this->userIdColumn();
        }
        return array_unique($columns);
    }

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

    public function __construct()
    {
        $this->db = Bootstrap::db();
        $this->userId = Bootstrap::userId();
    }

    public function list(array $params): array
    {
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));
        $fields = $this->fields();
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
            $stmt = $this->db->prepare($countSql);
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
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new \RuntimeException('Not found', 404);
        }

        return ['data' => $row];
    }

    public function create(array $payload): array
    {
        $fields = $this->fields();
        $columns = [];
        $placeholders = [];
        $binds = [];
        $types = '';

        foreach ($fields as $col => $def) {
            if (($def['required'] ?? false) && !isset($payload[$col])) {
                throw new \RuntimeException("Missing required field: $col", 422);
            }
        }

        foreach ($fields as $col => $def) {
            if ($def['readonly'] ?? false) {
                continue;
            }
            if (isset($payload[$col])) {
                $columns[] = $col;
                $placeholders[] = '?';
                $binds[] = $payload[$col];
                $types .= $def['type'];
            }
        }

        if ($this->userIdColumn()) {
            $columns[] = $this->userIdColumn();
            $placeholders[] = '?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        if (empty($columns)) {
            throw new \RuntimeException('No valid fields provided', 422);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->tableName(),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Failed to create record', 500);
        }
        $stmt->bind_param($types, ...$binds);

        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to create record', 500);
        }

        $insertId = $stmt->insert_id;
        $stmt->close();

        return $this->get($insertId);
    }

    public function update(int|string $id, array $payload): array
    {
        $this->get($id);

        $fields = $this->fields();
        $sets = [];
        $binds = [];
        $types = '';

        foreach ($fields as $col => $def) {
            if ($def['readonly'] ?? false) {
                continue;
            }
            if (array_key_exists($col, $payload)) {
                $sets[] = "$col = ?";
                $binds[] = $payload[$col];
                $types .= $def['type'];
            }
        }

        if (empty($sets)) {
            throw new \RuntimeException('No valid fields to update', 422);
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

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$binds);

        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to update record', 500);
        }
        $stmt->close();

        return $this->get($id);
    }

    public function delete(int|string $id): void
    {
        $this->get($id);

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

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $stmt->close();
    }
}
