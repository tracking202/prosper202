<?php
declare(strict_types=1);

namespace Prosper202\Database\Schema;

/**
 * Value object representing a database table schema definition.
 */
final class SchemaDefinition
{
    /**
     * @param string $tableName The name of the table
     * @param string $createStatement The full CREATE TABLE SQL statement
     * @param string $engine The storage engine (default: InnoDB)
     * @param string $charset The character set (default: utf8mb4)
     * @param string $collation The collation (default: utf8mb4_general_ci)
     */
    public function __construct(
        public readonly string $tableName,
        public readonly string $createStatement,
        public readonly string $engine = 'InnoDB',
        public readonly string $charset = 'utf8mb4',
        public readonly string $collation = 'utf8mb4_general_ci'
    ) {}

    /**
     * Get the table name without the 202_ prefix.
     */
    public function getShortName(): string
    {
        return str_starts_with($this->tableName, '202_')
            ? substr($this->tableName, 4)
            : $this->tableName;
    }

    /**
     * Check if this table uses a specific storage engine.
     */
    public function usesEngine(string $engine): bool
    {
        return strcasecmp($this->engine, $engine) === 0;
    }
}
