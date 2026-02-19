<?php

declare(strict_types=1);

namespace Api\V3;

/**
 * Base HTTP exception — carry an HTTP status code and a safe-for-client message.
 *
 * Subclasses exist for common statuses so callers don't need to remember codes
 * and catch blocks can be precise.
 */
class HttpException extends \RuntimeException
{
    public function __construct(string $message, int $httpStatus = 500, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->getCode();
    }
}
