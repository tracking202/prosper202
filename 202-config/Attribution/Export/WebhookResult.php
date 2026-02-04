<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Export;

final class WebhookResult
{
    public function __construct(
        public readonly ?int $statusCode,
        public readonly ?string $responseBody,
        public readonly ?string $errorMessage,
    ) {
    }
}
