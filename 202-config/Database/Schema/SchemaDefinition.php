<?php
declare(strict_types=1);

namespace Prosper202\Database\Schema;

/**
 * Value object representing a database table schema definition.
 */
final readonly class SchemaDefinition
{
    /**
     * @param string $tableName The name of the table
     * @param string $createStatement The full CREATE TABLE SQL statement
     * @param string $engine The storage engine (default: InnoDB)
     * @param string $charset The character set (default: utf8mb4)
     * @param string $collation The collation (default: utf8mb4_general_ci)
     */
    public function __construct(
        public string $tableName,
        public string $createStatement,
        public string $engine = 'InnoDB',
        public string $charset = 'utf8mb4',
        public string $collation = 'utf8mb4_general_ci'
    ) {}
}
