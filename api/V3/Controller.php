<?php

declare(strict_types=1);

namespace Api\V3;

abstract class Controller
{
    protected \mysqli $db;
    protected int $userId;

    abstract protected function tableName(): string;
    abstract protected function primaryKey(): string;
    abstract protected function fields(): array; // ['column' => ['type' => 's|i|d', 'required' => bool, 'readonly' => bool]]

    protected function userIdColumn(): ?string
    {
        return 'user_id';
    }

    protected function deletedColumn(): ?string
    {
        return null; // Override to enable soft delete, e.g. 'aff_campaign_deleted'
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

        // Filter support: ?filter[field]=value
        $filters = $params['filter'] ?? [];
        if (is_array($filters)) {
            foreach ($filters as $field => $value) {
                $fieldDef = $this->fields()[$field] ?? null;
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

        $sql = "SELECT * FROM {$this->tableName()} $whereClause ORDER BY $orderBy LIMIT ? OFFSET ?";
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
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    public function get(int|string $id): array
    {
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
        $sql = "SELECT * FROM {$this->tableName()} $whereClause LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
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

        // Validate required fields
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

        // Add user_id
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
            throw new \RuntimeException('Database error: ' . $this->db->error, 500);
        }
        $stmt->bind_param($types, ...$binds);

        if (!$stmt->execute()) {
            throw new \RuntimeException('Insert failed: ' . $stmt->error, 500);
        }

        $insertId = $stmt->insert_id;
        $stmt->close();

        return $this->get($insertId);
    }

    public function update(int|string $id, array $payload): array
    {
        // Verify ownership
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
            throw new \RuntimeException('Update failed: ' . $stmt->error, 500);
        }
        $stmt->close();

        return $this->get($id);
    }

    public function delete(int|string $id): void
    {
        // Verify ownership
        $this->get($id);

        if ($this->deletedColumn()) {
            // Soft delete
            $binds = [$id];
            $types = is_int($id) ? 'i' : 's';
            $where = [$this->primaryKey() . ' = ?'];

            if ($this->userIdColumn()) {
                $where[] = $this->userIdColumn() . ' = ?';
                $binds[] = $this->userId;
                $types .= 'i';
            }

            $sql = sprintf(
                'UPDATE %s SET %s = 1 WHERE %s',
                $this->tableName(),
                $this->deletedColumn(),
                implode(' AND ', $where)
            );
        } else {
            $binds = [$id];
            $types = is_int($id) ? 'i' : 's';
            $where = [$this->primaryKey() . ' = ?'];

            if ($this->userIdColumn()) {
                $where[] = $this->userIdColumn() . ' = ?';
                $binds[] = $this->userId;
                $types .= 'i';
            }

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
