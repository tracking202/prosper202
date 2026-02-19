<?php

declare(strict_types=1);

namespace Api\V3\Exception;

use Api\V3\HttpException;

class ValidationException extends HttpException
{
    /**
     * @param array<string, string> $fieldErrors
     */
    public function __construct(string $message = 'Validation failed', private readonly array $fieldErrors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 422, $previous);
    }

    /** @return array<string, string> */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }

    public function toArray(): array
    {
        $out = ['error' => true, 'message' => $this->getMessage(), 'status' => 422];
        if ($this->fieldErrors) {
            $out['field_errors'] = $this->fieldErrors;
        }
        return $out;
    }
}
