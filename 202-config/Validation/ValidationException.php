<?php

declare(strict_types=1);

namespace Prosper202\Validation;

use Exception;

/**
 * Exception thrown when validation fails.
 */
class ValidationException extends Exception
{
    public function __construct(
        string $message = 'Validation failed',
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }
    
    /**
     * Get all validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Check if a specific field has an error
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }
    
    /**
     * Get error for a specific field
     */
    public function getError(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}