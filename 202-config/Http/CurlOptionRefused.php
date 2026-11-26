<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * curl answered false to an option (CurlOptions::set()): the request it was
 * for must not be sent, since it would go without that option. The message
 * names the option and curl's reason, never the value, which can hold a key.
 */
final class CurlOptionRefused extends \RuntimeException
{
    public function __construct(public readonly int $option, string $message)
    {
        parent::__construct($message);
    }
}
