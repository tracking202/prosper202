<?php

declare(strict_types=1);

namespace Prosper202\Identity;

/**
 * One signal a click carried, in its canonical form (never stored: the graph
 * stores only a keyed hash of it).
 */
final readonly class IdentitySignal
{
    public function __construct(public SignalType $type, public string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('an identity signal needs a value');
        }
    }
}
