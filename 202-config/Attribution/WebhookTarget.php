<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * A webhook destination that passed WebhookGuard: the parts of its URL, and
 * the addresses its host resolved to at the moment of the check, every one
 * of them allowed. The sender connects to `pinned()` and nothing else, so
 * the name cannot be re-resolved to somewhere else between the check and
 * the connection.
 */
final class WebhookTarget
{
    /** @param non-empty-list<string> $addresses canonical text form (inet_ntop) */
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly bool $ipLiteral,
        public readonly array $addresses,
    ) {
    }

    /** The one address the connection is made to. */
    public function pinned(): string
    {
        return $this->addresses[0];
    }

    /**
     * The CURLOPT_RESOLVE entry that pins the host to the checked address,
     * or null for an IP-literal URL, which curl connects to as written.
     */
    public function resolveEntry(): ?string
    {
        if ($this->ipLiteral) {
            return null;
        }
        $address = $this->pinned();

        return $this->host . ':' . $this->port . ':' . (str_contains($address, ':') ? '[' . $address . ']' : $address);
    }
}
