<?php

declare(strict_types=1);

namespace P202Cli;

class ApiException extends \RuntimeException
{
    public array $responseData;

    public function __construct(string $message, int $code, array $data = [])
    {
        parent::__construct($message, $code);
        $this->responseData = $data;
    }
}
