<?php

declare(strict_types=1);

namespace Prosper202\Repository\Mysql;

use Prosper202\Database\Connection;
use Prosper202\Repository\TrackingRepositoryInterface;
use RuntimeException;

final class MysqlTrackingRepository implements TrackingRepositoryInterface
{
    private const MAX_VALUE_LENGTH = 350;
    private const KEYWORD_LENGTH = 150;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * A c1-c4, UTM or variable value as it is stored: cut to 350 bytes, less
     * a character the cut splits. A caller that also sends the value on (an
     * offer URL's token) sends this, so the two agree.
     */
    public static function storedValue(string $value): string
    {
        if (strlen($value) <= self::MAX_VALUE_LENGTH) {
            return $value;
        }

        $cut = preg_replace(
            '/(?:[\xC0-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF7][\x80-\xBF]{0,2})\z/',
            '',
            substr($value, 0, self::MAX_VALUE_LENGTH)
        );
        if ($cut === null) {
            throw new RuntimeException('Cutting a value to its column failed: ' . preg_last_error_msg());
        }

        return $cut;
    }

    /**
     * A keyword as it is stored: its column is a VARCHAR(150), so 150
     * characters, which the server cut on its own (and refused under a strict
     * sql_mode) while the lookup asked for the whole value and found nothing.
     * A byte that starts no whole UTF-8 sequence counts as one and is kept.
     */
    public static function storedKeyword(string $keyword): string
    {
        if (strlen($keyword) <= self::KEYWORD_LENGTH) {
            return $keyword;
        }
        $whole = preg_match(
            '/\A(?:[\xC0-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF7][\x80-\xBF]{3}|[\s\S]){0,'
                . self::KEYWORD_LENGTH . '}/',
            $keyword,
            $cut
        );
        if ($whole !== 1) {
            throw new RuntimeException('Cutting a keyword to its column failed: ' . preg_last_error_msg());
        }

        return $cut[0];
    }

    public function findOrCreateKeyword(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $name = self::storedKeyword($name);

        return $this->findOrCreateSimple('202_keywords', 'keyword_id', 'keyword', $name);
    }

    public function findOrCreateC1(string $value): int
    {
        $value = self::storedValue($value);

        return $this->findOrCreateSimple('202_tracking_c1', 'c1_id', 'c1', $value);
    }

    public function findOrCreateC2(string $value): int
    {
        $value = self::storedValue($value);

        return $this->findOrCreateSimple('202_tracking_c2', 'c2_id', 'c2', $value);
    }

    public function findOrCreateC3(string $value): int
    {
        $value = self::storedValue($value);

        return $this->findOrCreateSimple('202_tracking_c3', 'c3_id', 'c3', $value);
    }

    public function findOrCreateC4(string $value): int
    {
        $value = self::storedValue($value);

        return $this->findOrCreateSimple('202_tracking_c4', 'c4_id', 'c4', $value);
    }

    public function findOrCreateVariable(string $value, int $ppcVariableId): int
    {
        $value = self::storedValue($value);

        $stmt = $this->conn->prepareRead(
            'SELECT custom_variable_id FROM 202_custom_variables WHERE ppc_variable_id = ? AND variable = ?'
        );
        $this->conn->bind($stmt, 'is', [$ppcVariableId, $value]);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['custom_variable_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_custom_variables SET ppc_variable_id = ?, variable = ?'
        );
        $this->conn->bind($stmt, 'is', [$ppcVariableId, $value]);

        return $this->conn->executeInsert($stmt);
    }

    public function findOrCreateVariableSet(string $variables): int
    {
        $stmt = $this->conn->prepareRead(
            'SELECT variable_set_id FROM 202_variable_sets WHERE variables = ?'
        );
        $this->conn->bind($stmt, 's', [$variables]);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['variable_set_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_variable_sets SET variables = ?'
        );
        $this->conn->bind($stmt, 's', [$variables]);
        $variableSetId = $this->conn->executeInsert($stmt);

        // Populate the normalized 202_variable_sets2 mapping (one row per custom
        // variable id in the set) that the custom-variable report joins against.
        // Without this the new set is invisible in reports. Mirrors the legacy
        // INDEXES::get_variable_set_id() behavior that the repository refactor dropped.
        foreach (explode(',', $variables) as $part) {
            $variableId = (int) trim($part);
            if ($variableId <= 0) {
                continue;
            }
            $mapStmt = $this->conn->prepareWrite(
                'INSERT INTO 202_variable_sets2 (variable_set_id, variables) VALUES (?, ?)'
            );
            $this->conn->bind($mapStmt, 'is', [$variableSetId, (string) $variableId]);
            $this->conn->execute($mapStmt);
            $mapStmt->close();
        }

        return $variableSetId;
    }

    public function findOrCreateCustomVar(string $name, string $data): int
    {
        $data = self::storedValue($data);

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
        $value = self::storedValue($value);

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
        $this->conn->bind($stmt, 's', [$value]);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row[$idColumn];
        }

        $stmt = $this->conn->prepareWrite(
            "INSERT INTO {$table} SET {$valueColumn} = ?"
        );
        $this->conn->bind($stmt, 's', [$value]);

        return $this->conn->executeInsert($stmt);
    }

    private function findOrCreateDynamic(string $table, string $idColumn, string $valueColumn, string $value): int
    {
        $stmt = $this->conn->prepareRead(
            "SELECT {$idColumn} FROM {$table} WHERE {$valueColumn} = ?"
        );
        $this->conn->bind($stmt, 's', [$value]);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row[$idColumn];
        }

        $stmt = $this->conn->prepareWrite(
            "INSERT INTO {$table} SET {$valueColumn} = ?"
        );
        $this->conn->bind($stmt, 's', [$value]);

        return $this->conn->executeInsert($stmt);
    }
}
