<?php

declare(strict_types=1);

namespace Prosper202\Lifecycle;

/** A move the lifecycle's transition table does not hold. */
final class IllegalTransition extends \DomainException
{
    public static function between(State $from, State $to): self
    {
        return new self($from::class . ': ' . $from->value . ' may not become ' . $to->value . '.');
    }
}
