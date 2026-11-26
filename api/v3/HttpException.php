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
    private ?string $namedCode = null;

    public function __construct(string $message, int $httpStatus = 500, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->getCode();
    }

    /** Names the answer's ErrorCode, more specific than its status's. */
    public function coded(string $errorCode): static
    {
        if (!ErrorCode::isKnown($errorCode)) {
            throw new \LogicException($errorCode . ' is not in ErrorCode');
        }
        $this->namedCode = $errorCode;

        return $this;
    }

    /** The ErrorCode the answer carries: the one named, else the subclass's, else the status's. */
    public function errorCode(): string
    {
        return $this->namedCode ?? $this->defaultErrorCode();
    }

    protected function defaultErrorCode(): string
    {
        return ErrorCode::forStatus($this->getHttpStatus());
    }
}
