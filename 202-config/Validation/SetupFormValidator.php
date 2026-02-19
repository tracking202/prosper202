<?php

declare(strict_types=1);

namespace Prosper202\Validation;

use mysqli;

/**
 * Validation service for setup forms with security-focused input handling.
 */
class SetupFormValidator
{
    public function __construct(
        private readonly mysqli $db
    ) {
    }
    
    /**
     * Validate required field
     */
    public function validateRequired(mixed $value, string $fieldName = 'field'): ValidationResult
    {
        if ($value === null || $value === '') {
            return ValidationResult::failure("$fieldName is required");
        }
        
        $sanitized = is_string($value) ? trim($value) : $value;
        
        if ($sanitized === '') {
            return ValidationResult::failure("$fieldName cannot be empty");
        }
        
        return ValidationResult::success($sanitized);
    }
    
    /**
     * Validate URL format
     */
    public function validateUrl(string $url, string $fieldName = 'URL'): ValidationResult
    {
        $trimmed = trim($url);
        
        if ($trimmed === '') {
            return ValidationResult::failure("$fieldName is required");
        }
        
        if (!str_starts_with($trimmed, 'http://') && !str_starts_with($trimmed, 'https://')) {
            return ValidationResult::failure("$fieldName must start with http:// or https://");
        }
        
        if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return ValidationResult::failure("$fieldName is not a valid URL");
        }
        
        return ValidationResult::success($trimmed);
    }
    
    /**
     * Validate numeric value
     */
    public function validateNumeric(mixed $value, string $fieldName = 'field', ?float $min = null, ?float $max = null): ValidationResult
    {
        if ($value === null || $value === '') {
            return ValidationResult::failure("$fieldName is required");
        }
        
        if (!is_numeric($value)) {
            return ValidationResult::failure("$fieldName must be a number");
        }
        
        $numericValue = (float)$value;
        
        if ($min !== null && $numericValue < $min) {
            return ValidationResult::failure("$fieldName must be at least $min");
        }
        
        if ($max !== null && $numericValue > $max) {
            return ValidationResult::failure("$fieldName must be at most $max");
        }
        
        return ValidationResult::success($numericValue);
    }
    
    /**
     * Validate integer value
     */
    public function validateInteger(mixed $value, string $fieldName = 'field', ?int $min = null, ?int $max = null): ValidationResult
    {
        if ($value === null || $value === '') {
            return ValidationResult::failure("$fieldName is required");
        }
        
        $filtered = filter_var($value, FILTER_VALIDATE_INT);
        
        if ($filtered === false) {
            return ValidationResult::failure("$fieldName must be a valid integer");
        }
        
        if ($min !== null && $filtered < $min) {
            return ValidationResult::failure("$fieldName must be at least $min");
        }
        
        if ($max !== null && $filtered > $max) {
            return ValidationResult::failure("$fieldName must be at most $max");
        }
        
        return ValidationResult::success($filtered);
    }
    
    /**
     * Validate string with length constraints
     */
    public function validateString(mixed $value, string $fieldName = 'field', int $minLength = 1, int $maxLength = 255): ValidationResult
    {
        if ($value === null) {
            return ValidationResult::failure("$fieldName is required");
        }
        
        $stringValue = (string)$value;
        $trimmed = trim($stringValue);
        
        if (strlen($trimmed) < $minLength) {
            return ValidationResult::failure("$fieldName must be at least $minLength characters");
        }
        
        if (strlen($trimmed) > $maxLength) {
            return ValidationResult::failure("$fieldName must be at most $maxLength characters");
        }
        
        // Basic XSS protection
        $sanitized = htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');
        
        return ValidationResult::success($sanitized);
    }
    
    /**
     * Validate email address
     */
    public function validateEmail(string $email, string $fieldName = 'email'): ValidationResult
    {
        $trimmed = trim($email);
        
        if ($trimmed === '') {
            return ValidationResult::failure("$fieldName is required");
        }
        
        $validated = filter_var($trimmed, FILTER_VALIDATE_EMAIL);
        
        if ($validated === false) {
            return ValidationResult::failure("$fieldName is not a valid email address");
        }
        
        return ValidationResult::success($validated);
    }
    
    /**
     * Validate user ownership of a record
     */
    public function validateUserOwnership(int $userId, string $table, string $idColumn, int $recordId, string $recordName = 'record'): ValidationResult
    {
        $tableName = $this->db->real_escape_string($table);
        $columnName = $this->db->real_escape_string($idColumn);
        $userIdEscaped = $this->db->real_escape_string((string)$userId);
        $recordIdEscaped = $this->db->real_escape_string((string)$recordId);
        
        $sql = "SELECT 1 FROM `$tableName` WHERE `user_id` = '$userIdEscaped' AND `$columnName` = '$recordIdEscaped' LIMIT 1";
        $result = $this->db->query($sql);
        
        if (!$result || $result->num_rows === 0) {
            return ValidationResult::failure("You are not authorized to modify this $recordName");
        }
        
        return ValidationResult::success(true);
    }
    
    /**
     * Validate that a record exists
     */
    public function validateRecordExists(string $table, string $idColumn, int $recordId, string $recordName = 'record'): ValidationResult
    {
        $tableName = $this->db->real_escape_string($table);
        $columnName = $this->db->real_escape_string($idColumn);
        $recordIdEscaped = $this->db->real_escape_string((string)$recordId);
        
        $sql = "SELECT 1 FROM `$tableName` WHERE `$columnName` = '$recordIdEscaped' LIMIT 1";
        $result = $this->db->query($sql);
        
        if (!$result || $result->num_rows === 0) {
            return ValidationResult::failure("$recordName not found");
        }
        
        return ValidationResult::success(true);
    }
    
    /**
     * Validate that a slug is unique for the user
     */
    public function validateUniqueSlug(int $userId, string $table, string $slug, ?int $excludeId = null, string $fieldName = 'slug'): ValidationResult
    {
        $tableName = $this->db->real_escape_string($table);
        $userIdEscaped = $this->db->real_escape_string((string)$userId);
        $slugEscaped = $this->db->real_escape_string($slug);
        
        $sql = "SELECT 1 FROM `$tableName` WHERE `user_id` = '$userIdEscaped' AND `model_slug` = '$slugEscaped'";
        
        if ($excludeId !== null) {
            $excludeIdEscaped = $this->db->real_escape_string((string)$excludeId);
            $sql .= " AND `model_id` != '$excludeIdEscaped'";
        }
        
        $sql .= " LIMIT 1";
        
        $result = $this->db->query($sql);
        
        if ($result && $result->num_rows > 0) {
            return ValidationResult::failure("$fieldName must be unique");
        }
        
        return ValidationResult::success($slug);
    }
    
    /**
     * Sanitize a value for database insertion
     */
    public function sanitizeForDatabase(mixed $value): string
    {
        return $this->db->real_escape_string((string)$value);
    }
    
    /**
     * Validate and sanitize an array of values
     */
    public function validateArray(array $data, array $rules): array
    {
        $results = [];
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            $result = $this->validateField($value, $rule);
            
            if (!$result->isValid) {
                $errors[$field] = $result->getErrorMessage();
            } else {
                $results[$field] = $result->getSanitizedValue();
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException('Validation failed', $errors);
        }
        
        return $results;
    }
    
    /**
     * Apply a validation rule to a field
     */
    private function validateField(mixed $value, array $rule): ValidationResult
    {
        $type = $rule['type'] ?? 'string';
        $fieldName = $rule['name'] ?? 'field';
        
        return match ($type) {
            'required' => $this->validateRequired($value, $fieldName),
            'string' => $this->validateString($value, $fieldName, $rule['min'] ?? 1, $rule['max'] ?? 255),
            'integer' => $this->validateInteger($value, $fieldName, $rule['min'] ?? null, $rule['max'] ?? null),
            'numeric' => $this->validateNumeric($value, $fieldName, $rule['min'] ?? null, $rule['max'] ?? null),
            'url' => $this->validateUrl((string)$value, $fieldName),
            'email' => $this->validateEmail((string)$value, $fieldName),
            default => ValidationResult::failure("Unknown validation type: $type")
        };
    }
}