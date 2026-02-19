<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Export;

final readonly class WebhookResult
{
    public function __construct(
        public ?int $statusCode,
        public ?string $responseBody,
        public ?string $errorMessage,
    ) {
    }
}
