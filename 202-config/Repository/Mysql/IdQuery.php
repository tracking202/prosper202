<?php

declare(strict_types=1);

namespace Prosper202\Repository\Mysql;

use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Repository\PositiveId;

/**
 * The statements a lookup table is read and written with. The SQL stays in
 * the repositories, where the source scans of table writes read it.
 */
final readonly class IdQuery
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * The id in $column of the first row $sql finds, or null for no row.
     *
     * @param list<int|string> $values
     */
    public function first(
        string $sql,
        string $types,
        array $values,
        string $column
    ): ?int {
        return $this->idOf($this->conn->prepareRead($sql), $sql, $types, $values, $column);
    }

    /**
     * The id of the row $select finds, or of the row $insert makes from the
     * same values. An insert refused as a duplicate means a unique key holds
     * a row the select did not see (another request inserted it first, or a
     * replica had not caught up): it is read again from the primary. Still
     * not found (a key on part of the value), the refusal stands.
     *
     * @param list<int|string> $values
     */
    public function firstOrInsert(
        string $select,
        string $insert,
        string $types,
        array $values,
        string $column
    ): int {
        $found = $this->first($select, $types, $values, $column);
        if ($found !== null) {
            return $found;
        }
        try {
            return $this->insert($insert, $types, $values);
        } catch (\RuntimeException $refused) {
            if (!Connection::isMysqlError($refused, 1062, 'Duplicate entry')) {
                throw $refused;
            }

            return $this->idOf($this->conn->prepareWrite($select), $select, $types, $values, $column)
                ?? throw $refused;
        }
    }

    /**
     * @param \mysqli_stmt $stmt
     * @param list<int|string> $values
     */
    private function idOf(object $stmt, string $sql, string $types, array $values, string $column): ?int
    {
        $this->conn->bind($stmt, $types, $values);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        $id = PositiveId::of($row[$column] ?? null);
        if ($id === null) {
            throw new QueryException(sprintf(
                'A lookup found a row whose %s is not a row id (%s): %s',
                $column,
                get_debug_type($row[$column] ?? null),
                $sql
            ));
        }

        return $id;
    }

    /**
     * The id an INSERT into a table with an AUTO_INCREMENT key made.
     *
     * @param list<int|string> $values
     */
    public function insert(string $sql, string $types, array $values): int
    {
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $values);
        $id = $this->conn->executeInsert($stmt);
        if ($id < 1) {
            throw new QueryException('An insert reported no row id: ' . $sql);
        }

        return $id;
    }

    /**
     * An INSERT of one row whose key the caller supplies.
     *
     * @param list<int|string> $values
     */
    public function insertRow(string $sql, string $types, array $values): void
    {
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $values);
        $inserted = $this->conn->executeUpdate($stmt);
        if ($inserted !== 1) {
            throw new QueryException(sprintf(
                'An insert of one row reported %d: %s',
                $inserted,
                $sql
            ));
        }
    }
}
