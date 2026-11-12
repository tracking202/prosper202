<?php

declare(strict_types=1);

namespace Api\V3\Exception;

use Api\V3\HttpException;

/**
 * A platform this server called for the request (an ad platform's API)
 * failed or did not answer: 502 with what it said, which the caller can act
 * on ("try again in a minute") and which carries no secret (the key is taken
 * out where the answer is read). Not this server's failure, so it never
 * names a pending upgrade.
 */
final class UpstreamException extends HttpException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 502, $previous);
    }
}
