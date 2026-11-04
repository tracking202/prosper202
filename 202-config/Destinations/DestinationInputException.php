<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/** A refusal of a destination's settings or credentials, naming the field. */
final class DestinationInputException extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
