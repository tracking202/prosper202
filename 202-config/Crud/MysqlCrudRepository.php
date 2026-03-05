<?php

declare(strict_types=1);

namespace Prosper202\Crud;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * Configurable MySQL CRUD repository for single-table entities.
 *
 * Usage:
 *   $repo = new MysqlCrudRepository($conn, new TableConfig(
 *       table: '202_aff_networks',
 *       primaryKey: 'aff_network_id',
 *       userIdColumn: 'user_id',
 *       deletedColumn: 'aff_network_deleted',
 *       fields: ['aff_network_name' => 's', 'dni_network_id' => 'i'],
 *       selectColumns: ['aff_network_id', 'user_id', 'aff_network_name', 'dni_network_id'],
 *   ));
 */
final class MysqlCrudRepository implements CrudRepositoryInterface
{
    public function __construct(
        private Connection $conn,
        private TableConfig $config,
    ) {
    }

    public function list(int $userId, int $offset, int $limit): array
    {
        $c = $this->config;
        $delFilter = $c->deletedColumn !== null ? " AND {$c->deletedColumn} = 0" : '';

        $countSql = "SELECT COUNT(*) AS total FROM {$c->table} WHERE {$c->userIdColumn} = ?$delFilter";
        $countStmt = $this->conn->prepareRead($countSql);
        $this->conn->bind($countStmt, 'i', [$userId]);
        $total = (int) ($this->conn->fetchOne($countStmt)['total'] ?? 0);

        $cols = implode(', ', $c->selectColumns);
        $sql = "SELECT $cols FROM {$c->table} WHERE {$c->userIdColumn} = ?$delFilter ORDER BY {$c->primaryKey} DESC LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'iii', [$userId, $limit, $offset]);

        return ['rows' => $this->conn->fetchAll($stmt), 'total' => $total];
    }

    public function findById(int $id, int $userId): ?array
    {
        $c = $this->config;
        $delFilter = $c->deletedColumn !== null ? " AND {$c->deletedColumn} = 0" : '';
        $cols = implode(', ', $c->selectColumns);

        $sql = "SELECT $cols FROM {$c->table} WHERE {$c->primaryKey} = ? AND {$c->userIdColumn} = ?$delFilter LIMIT 1";
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'ii', [$id, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    public function create(int $userId, array $data): int
    {
        $c = $this->config;
        $columns = [$c->userIdColumn];
        $values = [$userId];
        $types = 'i';
        $placeholders = ['?'];

        foreach ($c->fields as $field => $type) {
            if (array_key_exists($field, $data)) {
                $columns[] = $field;
                $values[] = $data[$field];
                $types .= $type;
                $placeholders[] = '?';
            }
        }

        $sql = 'INSERT INTO ' . $c->table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $values);

        return $this->conn->executeInsert($stmt);
    }

    public function update(int $id, int $userId, array $data): void
    {
        $c = $this->config;
        $sets = [];
        $values = [];
        $types = '';

        foreach ($c->fields as $field => $type) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $values[] = $data[$field];
                $types .= $type;
            }
        }

        if (empty($sets)) {
            throw new RuntimeException('No fields to update');
        }

        $values[] = $id;
        $types .= 'i';
        $values[] = $userId;
        $types .= 'i';

        $sql = "UPDATE {$c->table} SET " . implode(', ', $sets) . " WHERE {$c->primaryKey} = ? AND {$c->userIdColumn} = ?";
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $values);
        $this->conn->execute($stmt);
        $stmt->close();
    }

    public function softDelete(int $id, int $userId): void
    {
        $c = $this->config;
        if ($c->deletedColumn === null) {
            $sql = "DELETE FROM {$c->table} WHERE {$c->primaryKey} = ? AND {$c->userIdColumn} = ?";
        } else {
            $sql = "UPDATE {$c->table} SET {$c->deletedColumn} = 1 WHERE {$c->primaryKey} = ? AND {$c->userIdColumn} = ?";
        }

        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, 'ii', [$id, $userId]);
        $this->conn->execute($stmt);
        $stmt->close();
    }
}
