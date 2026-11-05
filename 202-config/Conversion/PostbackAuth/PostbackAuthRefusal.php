<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

/**
 * Why PostbackAuthGate refused a request: a fixed word for logs and the
 * inbound log (`reason`), and a sentence for the sender, which never
 * repeats a token.
 */
final class PostbackAuthRefusal
{
    public const STATUS = 403;

    public function __construct(
        public readonly string $reason,
        public readonly string $message,
    ) {
    }
}
