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
        $stmt = $this->conn->prepareRead($sql);
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
