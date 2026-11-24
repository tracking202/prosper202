<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The saved egress proxy row (EgressProxyStore). The credentials are here
 * so EgressProxy can send with them; nothing renders them: a page asks
 * credentialsStored().
 */
final class StoredEgressProxy
{
    public function __construct(
        public readonly string $url,
        #[\SensitiveParameter] private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        public readonly ?int $updatedAt,
    ) {
    }

    public function credentialsStored(): bool
    {
        return $this->username !== '' || $this->password !== '';
    }

    /** @return array{0: string, 1: string} for EgressProxy only */
    public function credentials(): array
    {
        return [$this->username, $this->password];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'credentials_stored' => $this->credentialsStored(), 'updated_at' => $this->updatedAt];
    }
}
