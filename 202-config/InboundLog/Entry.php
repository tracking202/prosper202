<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

/**
 * One inbound request as the log keeps it: where it came in, what it was
 * answered and why. $fields is already redacted (Redactor).
 */
final class Entry
{
    /**
     * @param int $userId the account it was for; 0 when none could be found
     * @param array<string, mixed> $fields the request's fields, redacted
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $method,
        public readonly int $httpStatus,
        public readonly string $outcome,
        public readonly string $reason,
        public readonly int $userId = 0,
        public readonly ?int $clickId = null,
        public readonly ?int $conversionId = null,
        public readonly ?string $transactionId = null,
        public readonly array $fields = [],
    ) {
    }
}
