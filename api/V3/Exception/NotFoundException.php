<?php

declare(strict_types=1);

namespace Api\V3\Exception;

use Api\V3\HttpException;

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not found', ?\Throwable $previous = null)
    {
        parent::__construct($message, 404, $previous);
    }
}
