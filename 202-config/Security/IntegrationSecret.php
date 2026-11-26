<?php

declare(strict_types=1);

namespace Prosper202\Security;

/**
 * An integration secret as opened: the current value and, while its
 * overlap window lasts, the one it replaced. Never served.
 */
final class IntegrationSecret
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $current,
        public readonly int $setAt,
        #[\SensitiveParameter] public readonly ?string $previous,
        public readonly ?int $previousExpiresAt
    ) {
    }

    /** @return list<string> the values a request may be verified with, current first */
    public function accepted(): array
    {
        return $this->previous === null ? [$this->current] : [$this->current, $this->previous];
    }

    public function __debugInfo(): array
    {
        return ['setAt' => $this->setAt, 'previousExpiresAt' => $this->previousExpiresAt];
    }
}
