<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms;

/** A connection request refused for one field, named, with what would be accepted. */
final class AdConnectionInputException extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
