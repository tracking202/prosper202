<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/** One send's answer: delivered, or why not and whether a retry could change it. */
final class SendResult
{
    /**
     * @param array<string, mixed> $summary what was sent, without identifiers' values
     * @param list<string> $messages the platform's validation messages (a test fire)
     * @param int|null $waitUntil not a try: the row stays pending, its attempts unchanged, until then
     * @param bool $destinationWaits with $waitUntil: every pending row of the destination waits (it needs the operator)
     * @param bool $withheld nothing was sent and nothing ever will be (nothing the platform could match)
     */
    public function __construct(
        public readonly bool $delivered,
        public readonly bool $retry,
        public readonly ?int $status,
        public readonly string $reason,
        public readonly array $summary = [],
        public readonly array $messages = [],
        public readonly ?int $waitUntil = null,
        public readonly bool $destinationWaits = false,
        public readonly bool $withheld = false,
    ) {
    }

    /** Whether nothing answered at all (a timeout, no connection): the host is skipped for the rest of the run. */
    public function unanswered(): bool
    {
        return !$this->delivered && $this->retry && $this->status === null && $this->waitUntil === null;
    }
}
