<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/** One send's answer: delivered, or why not and whether a retry could change it. */
final class SendResult
{
    /**
     * @param array<string, mixed> $summary what was sent, without identifiers' values
     * @param list<string> $messages the platform's validation messages (a test fire)
     */
    public function __construct(
        public readonly bool $delivered,
        public readonly bool $retry,
        public readonly ?int $status,
        public readonly string $reason,
        public readonly array $summary = [],
        public readonly array $messages = [],
    ) {
    }

    /** Whether nothing answered at all (a timeout, no connection): the host is skipped for the rest of the run. */
    public function unanswered(): bool
    {
        return !$this->delivered && $this->retry && $this->status === null;
    }
}
