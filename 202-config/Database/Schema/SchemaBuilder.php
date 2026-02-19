<?php
declare(strict_types=1);

namespace Prosper202\Database\Schema;

/**
 * Factory for creating SchemaDefinition objects from raw SQL.
 */
final class SchemaBuilder
{
    /**
     * Create a SchemaDefinition from a raw SQL CREATE TABLE statement.
     */
    public static function fromRawSql(
        string $tableName,
        string $sql,
        string $engine = 'InnoDB',
        string $charset = 'utf8mb4',
        string $collation = 'utf8mb4_general_ci'
    ): SchemaDefinition {
        return new SchemaDefinition(
            tableName: $tableName,
            createStatement: $sql,
            engine: $engine,
            charset: $charset,
            collation: $collation
        );
    }
}
