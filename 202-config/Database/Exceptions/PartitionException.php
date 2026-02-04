<?php
declare(strict_types=1);

namespace Prosper202\Database\Exceptions;

use RuntimeException;

/**
 * Exception thrown when partition operations fail.
 */
class PartitionException extends RuntimeException
{
    private ?string $tableName;

    public function __construct(
        string $message,
        ?string $tableName = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->tableName = $tableName;
    }

    /**
     * Create exception when partitioning is not supported.
     */
    public static function notSupported(): self
    {
        return new self('MySQL partitioning is not supported or not enabled on this server');
    }

    /**
     * Create exception for a partition creation failure.
     */
    public static function creationFailed(string $tableName, string $error): self
    {
        return new self(
            "Failed to create partitions for table '{$tableName}': {$error}",
            $tableName
        );
    }

    /**
     * Get the table name that failed (if applicable).
     */
    public function getTableName(): ?string
    {
        return $this->tableName;
    }
}
