<?php
declare(strict_types=1);

namespace Prosper202\Database;

/**
 * Value object representing the result of a database installation operation.
 */
final readonly class InstallResult
{
    /**
     * @param bool $success Whether the installation was successful
     * @param array<string> $createdTables List of tables that were created
     * @param array<string> $errors List of errors encountered
     * @param float $executionTime Time taken to execute in seconds
     */
    public function __construct(
        public bool $success,
        public array $createdTables = [],
        public array $errors = [],
        public float $executionTime = 0.0
    ) {}

    /**
     * Create a successful result.
     *
     * @param array<string> $createdTables
     * @param float $executionTime
     */
    public static function success(array $createdTables = [], float $executionTime = 0.0): self
    {
        return new self(
            success: true,
            createdTables: $createdTables,
            errors: [],
            executionTime: $executionTime
        );
    }

    /**
     * Create a failed result.
     *
     * @param array<string> $errors
     * @param array<string> $createdTables Tables created before failure
     * @param float $executionTime
     */
    public static function failure(array $errors, array $createdTables = [], float $executionTime = 0.0): self
    {
        return new self(
            success: false,
            createdTables: $createdTables,
            errors: $errors,
            executionTime: $executionTime
        );
    }

    /**
     * Check if there were any errors.
     */
    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * Get the count of created tables.
     */
    public function getCreatedTableCount(): int
    {
        return count($this->createdTables);
    }

    /**
     * Get a summary string of the result.
     */
    public function getSummary(): string
    {
        if ($this->success) {
            return sprintf(
                'Installation successful: %d tables created in %.2f seconds',
                $this->getCreatedTableCount(),
                $this->executionTime
            );
        }

        return sprintf(
            'Installation failed: %d errors, %d tables created before failure',
            count($this->errors),
            $this->getCreatedTableCount()
        );
    }
}
