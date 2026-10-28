<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A column's type, nullability and DEFAULT as information_schema.COLUMNS
 * answers them, read alike on MariaDB and MySQL 8: MariaDB quotes a string
 * DEFAULT (`'eu'`) where MySQL does not (`eu`), and writes a NULL one as the
 * word NULL. SHOW CREATE TABLE differs further (MySQL 8 writes each text
 * column's COLLATE, MariaDB does not), so a column's shape is asserted from
 * here, never from the DDL text.
 */
final class ColumnShape
{
    /**
     * @return array{type: string, nullable: bool, default: ?string}
     * @throws \RuntimeException when the column cannot be read
     */
    public static function of(\mysqli $db, string $table, string $column): array
    {
        $stmt = $db->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        if ($stmt === false) {
            throw new \RuntimeException('could not prepare the column read: ' . $db->error);
        }
        $stmt->bind_param('ss', $table, $column);
        if (!$stmt->execute()) {
            throw new \RuntimeException("could not read $table.$column: " . $stmt->error);
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new \RuntimeException("could not read $table.$column's result: " . $db->error);
        }
        $row = $result->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            throw new \RuntimeException("$table.$column is not there");
        }
        $default = $row['COLUMN_DEFAULT'];
        // MySQL answers the value, or SQL NULL; MariaDB an expression.
        if (is_string($default) && str_contains($db->server_info, 'MariaDB')) {
            $quoted = strlen($default) >= 2 && $default[0] === "'" && str_ends_with($default, "'");
            if ($default === 'NULL') {
                $default = null;
            } elseif ($quoted) {
                $default = str_replace("''", "'", substr($default, 1, -1));
            }
        }

        return [
            'type' => (string) $row['COLUMN_TYPE'],
            'nullable' => $row['IS_NULLABLE'] === 'YES',
            'default' => $default === null ? null : (string) $default,
        ];
    }
}
