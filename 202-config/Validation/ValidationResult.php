<?php

declare(strict_types=1);

namespace Prosper202\Validation;

/**
 * Immutable value object representing the result of a validation operation.
 */
final readonly class ValidationResult
{
    public function __construct(
        public bool $isValid,
        public ?string $errorMessage = null,
        public mixed $sanitizedValue = null
    ) {
    }
    
    /**
     * Create a successful validation result
     */
    public static function success(mixed $sanitizedValue = null): self
    {
        return new self(true, null, $sanitizedValue);
    }
    
    /**
     * Create a failed validation result
     */
    public static function failure(string $errorMessage): self
    {
        return new self(false, $errorMessage);
    }
    
    /**
     * Get the error message (throws if valid)
     */
    public function getErrorMessage(): string
    {
        if ($this->isValid) {
            throw new \LogicException('Cannot get error message from valid result');
        }
        
        return $this->errorMessage;
    }
    
    /**
     * Get the sanitized value (throws if invalid)
     */
    public function getSanitizedValue(): mixed
    {
        if (!$this->isValid) {
            throw new \LogicException('Cannot get value from invalid result');
        }
        
        return $this->sanitizedValue;
    }
}