<?php

declare(strict_types=1);

namespace Prosper202\Crud;

use RuntimeException;

final class InMemoryCrudRepository implements CrudRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $nextId = 1;

    public function __construct(private TableConfig $config)
    {
    }

    public function list(int $userId, int $offset, int $limit): array
    {
        $c = $this->config;
        $filtered = array_filter($this->rows, function (array $row) use ($userId, $c): bool {
            if (($row[$c->userIdColumn] ?? null) !== $userId) {
                return false;
            }
            if ($c->deletedColumn !== null && !empty($row[$c->deletedColumn])) {
                return false;
            }
            return true;
        });

        // Sort by PK desc
        usort($filtered, fn(array $a, array $b) => ($b[$c->primaryKey] ?? 0) <=> ($a[$c->primaryKey] ?? 0));

        return [
            'rows' => array_slice($filtered, $offset, $limit),
            'total' => count($filtered),
        ];
    }

    public function findById(int $id, int $userId): ?array
    {
        $c = $this->config;
        $row = $this->rows[$id] ?? null;
        if ($row === null) {
            return null;
        }
        if (($row[$c->userIdColumn] ?? null) !== $userId) {
            return null;
        }
        if ($c->deletedColumn !== null && !empty($row[$c->deletedColumn])) {
            return null;
        }

        return $row;
    }

    public function create(int $userId, array $data): int
    {
        $c = $this->config;
        $id = $this->nextId++;
        $row = [$c->primaryKey => $id, $c->userIdColumn => $userId];

        if ($c->deletedColumn !== null) {
            $row[$c->deletedColumn] = 0;
        }

        foreach ($c->fields as $field => $type) {
            if (array_key_exists($field, $data)) {
                $row[$field] = $data[$field];
            }
        }

        $this->rows[$id] = $row;

        return $id;
    }

    public function update(int $id, int $userId, array $data): void
    {
        $c = $this->config;
        if ($this->findById($id, $userId) === null) {
            throw new RuntimeException("Record $id not found");
        }

        $updated = false;
        foreach ($c->fields as $field => $type) {
            if (array_key_exists($field, $data)) {
                $this->rows[$id][$field] = $data[$field];
                $updated = true;
            }
        }

        if (!$updated) {
            throw new RuntimeException('No fields to update');
        }
    }

    public function softDelete(int $id, int $userId): void
    {
        $c = $this->config;
        if (!isset($this->rows[$id]) || ($this->rows[$id][$c->userIdColumn] ?? null) !== $userId) {
            return;
        }

        if ($c->deletedColumn !== null) {
            $this->rows[$id][$c->deletedColumn] = 1;
        } else {
            unset($this->rows[$id]);
        }
    }
}
