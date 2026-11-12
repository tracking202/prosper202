<?php

declare(strict_types=1);

namespace Api\V3\Exception;

use Api\V3\HttpException;

/**
 * A request refused by a limit: answered 429 with Retry-After, and the same
 * wait in the body (retry_after_seconds), beside what the limit is.
 */
final class TooManyRequestsException extends HttpException
{
    /**
     * @param array<string, mixed> $details added to the answer's body
     */
    public function __construct(
        string $message,
        public readonly int $retryAfter,
        private readonly array $details = [],
    ) {
        if ($retryAfter < 1) {
            throw new \LogicException('a refusal by a limit must say how long to wait');
        }
        parent::__construct($message, 429);
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
