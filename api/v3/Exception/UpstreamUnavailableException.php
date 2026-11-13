<?php

declare(strict_types=1);

namespace Api\V3\Exception;

use Api\V3\HttpException;

/**
 * A service this install had to ask (an ad platform's API) could not be
 * asked, or answered something that cannot be used. Nothing the caller sent
 * is wrong, so it is never a 422: 503 with Retry-After when the service was
 * not reached, 502 when its answer was not what its API documents. The
 * message is written for the caller and is served as it is (a 5xx thrown as
 * a plain HttpException is answered "Internal server error").
 */
final class UpstreamUnavailableException extends HttpException
{
    /**
     * @param int|null $retryAfter seconds, for a service that was not reached; null for an answer that cannot be used
     * @param array<string, mixed> $details added to the answer's body
     */
    public function __construct(
        string $message,
        public readonly ?int $retryAfter,
        private readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        if ($retryAfter !== null && $retryAfter < 1) {
            throw new \LogicException('a service to try again says how long to wait');
        }
        parent::__construct($message, $retryAfter === null ? 502 : 503, $previous);
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
