<?php

declare(strict_types=1);

namespace Api\V3\Exception;

use Api\V3\HttpException;

/**
 * Wraps database-level errors with a generic client message.
 * The real error is available via getPrevious() for logging.
 */
class DatabaseException extends HttpException
{
    public function __construct(string $internalDetail, ?\Throwable $previous = null)
    {
        // Never expose DB details to the client.
        parent::__construct('Internal server error', 500, $previous);
    }
}
