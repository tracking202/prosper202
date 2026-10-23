<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * An adapter's refusal of a request it cannot read, carrying the answer.
 */
final class Refusal extends \RuntimeException
{
    public function __construct(public readonly Outcome $outcome)
    {
        parent::__construct($outcome->message);
    }

    public static function of(OutcomeKind $kind, string $message): self
    {
        return new self(new Outcome($kind, $message));
    }
}
