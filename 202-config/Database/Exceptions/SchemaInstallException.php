<?php
declare(strict_types=1);

namespace Prosper202\Database\Exceptions;

use RuntimeException;

/**
 * Exception thrown when schema installation fails.
 */
class SchemaInstallException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?string $tableName = null,
        private readonly ?string $sql = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for a table creation failure.
     */
    public static function tableCreationFailed(string $tableName, string $error, ?string $sql = null): self
    {
        return new self(
            "Failed to create table '{$tableName}': {$error}",
            $tableName,
            $sql
        );
    }

    /**
     * Create exception for a connection failure.
     */
    public static function connectionFailed(string $message): self
    {
        return new self("Database connection failed: {$message}");
    }

    /**
     * Get the table name that failed (if applicable).
     */
    public function getTableName(): ?string
    {
        return $this->tableName;
    }

    /**
     * Get the SQL statement that failed (if applicable).
     */
    public function getSql(): ?string
    {
        return $this->sql;
    }
}
