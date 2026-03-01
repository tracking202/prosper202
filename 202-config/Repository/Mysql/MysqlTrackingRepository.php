<?php

declare(strict_types=1);

namespace Prosper202\Repository\Mysql;

use Prosper202\Database\Connection;
use Prosper202\Repository\TrackingRepositoryInterface;
use RuntimeException;

final class MysqlTrackingRepository implements TrackingRepositoryInterface
{
    private const MAX_VALUE_LENGTH = 350;

    public function __construct(private readonly Connection $conn)
    {
    }

    public function findOrCreateKeyword(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        return $this->findOrCreateSimple('202_keywords', 'keyword_id', 'keyword_name', $name);
    }

    public function findOrCreateC1(string $value): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        return $this->findOrCreateSimple('202_clicks_c1', 'c1_id', 'c1', $value);
    }

    public function findOrCreateC2(string $value): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        return $this->findOrCreateSimple('202_clicks_c2', 'c2_id', 'c2', $value);
    }

    public function findOrCreateC3(string $value): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        return $this->findOrCreateSimple('202_clicks_c3', 'c3_id', 'c3', $value);
    }

    public function findOrCreateC4(string $value): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        return $this->findOrCreateSimple('202_clicks_c4', 'c4_id', 'c4', $value);
    }

    public function findOrCreateVariable(string $value, int $ppcVariableId): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        $stmt = $this->conn->prepareRead(
            'SELECT custom_variable_id FROM 202_custom_variables WHERE ppc_variable_id = ? AND variable = ?'
        );
        $stmt->bind_param('is', $ppcVariableId, $value);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['custom_variable_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_custom_variables SET ppc_variable_id = ?, variable = ?'
        );
        $stmt->bind_param('is', $ppcVariableId, $value);

        return $this->conn->executeInsert($stmt);
    }

    public function findOrCreateVariableSet(string $variables): int
    {
        $stmt = $this->conn->prepareRead(
            'SELECT variable_set_id FROM 202_variable_sets WHERE variables = ?'
        );
        $stmt->bind_param('s', $variables);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['variable_set_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_variable_sets SET variables = ?'
        );
        $stmt->bind_param('s', $variables);

        return $this->conn->executeInsert($stmt);
    }

    public function findOrCreateCustomVar(string $name, string $data): int
    {
        $data = substr($data, 0, self::MAX_VALUE_LENGTH);

        $allowedPattern = '/^[a-zA-Z0-9_]+$/';
        if (!preg_match($allowedPattern, $name)) {
            throw new RuntimeException('Invalid custom variable name: ' . $name);
        }

        $table = '202_tracking_' . $name;
        $idCol = $name . '_id';

        return $this->findOrCreateDynamic($table, $idCol, $name, $data);
    }

    public function findOrCreateUtm(string $value, string $type): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        $allowed = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException('Invalid UTM type: ' . $type);
        }

        $table = '202_' . $type;
        $idCol = $type . '_id';

        return $this->findOrCreateDynamic($table, $idCol, $type, $value);
    }

    private function findOrCreateSimple(string $table, string $idColumn, string $valueColumn, string $value): int
    {
        $stmt = $this->conn->prepareRead(
            "SELECT {$idColumn} FROM {$table} WHERE {$valueColumn} = ?"
        );
        $stmt->bind_param('s', $value);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row[$idColumn];
        }

        $stmt = $this->conn->prepareWrite(
            "INSERT INTO {$table} SET {$valueColumn} = ?"
        );
        $stmt->bind_param('s', $value);

        return $this->conn->executeInsert($stmt);
    }

    private function findOrCreateDynamic(string $table, string $idColumn, string $valueColumn, string $value): int
    {
        $stmt = $this->conn->prepareRead(
            "SELECT {$idColumn} FROM {$table} WHERE {$valueColumn} = ?"
        );
        $stmt->bind_param('s', $value);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row[$idColumn];
        }

        $stmt = $this->conn->prepareWrite(
            "INSERT INTO {$table} SET {$valueColumn} = ?"
        );
        $stmt->bind_param('s', $value);

        return $this->conn->executeInsert($stmt);
    }
}
