<?php

declare(strict_types=1);

namespace Api\V3\Exception;

use Api\V3\HttpException;

class ConflictException extends HttpException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'Conflict', private readonly array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 409, $previous);
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
