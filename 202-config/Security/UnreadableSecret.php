<?php

declare(strict_types=1);

namespace Prosper202\Security;

/** A stored integration secret that is there and cannot be opened: never read as none (CLAUDE.md #11). */
final class UnreadableSecret extends \RuntimeException
{
    public function __construct(public readonly string $secretName, public readonly int $userId, string $why, ?\Throwable $previous = null)
    {
        parent::__construct('The stored ' . $secretName . ' of account ' . $userId . ' cannot be opened: ' . $why
            . '. Configure the key it was sealed with, or save the secret again.', 0, $previous);
    }
}
