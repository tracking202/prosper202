<?php

declare(strict_types=1);

namespace P202Cli;

class ApiException extends \RuntimeException
{
    public function __construct(string $message, int $code, public array $responseData = [])
    {
        parent::__construct($message, $code);
    }
}
