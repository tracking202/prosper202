<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/** What one webhook delivery did. */
final class WebhookResult
{
    private function __construct(
        public readonly bool $delivered,
        public readonly ?int $status,
        public readonly ?string $error,
        public readonly bool $retryable,
    ) {
    }

    public static function delivered(int $status): self
    {
        return new self(true, $status, null, false);
    }

    public static function failed(?int $status, string $error, bool $retryable): self
    {
        return new self(false, $status, $error, $retryable);
    }
}
