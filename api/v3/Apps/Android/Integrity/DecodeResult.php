<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

/**
 * What asking Google to decode a token came to:
 *
 *   decoded   Google answered with a verdict (`payload`, its
 *             tokenPayloadExternal); the policy judges it
 *   retry     no verdict, for a reason that may pass: the network, a
 *             timeout, a 5xx, 429 (quota), a credential Google refused or
 *             that lacks access. The worker tries again with backoff, until
 *             its deadline makes the install's state `error`
 *   rejected  Google refused the token itself (400: not a token it can
 *             decode). Retrying cannot change that; the state is `invalid`
 */
final class DecodeResult
{
    public const DECODED = 'decoded';
    public const RETRY = 'retry';
    public const REJECTED = 'rejected';

    /** @param array<mixed>|null $payload */
    private function __construct(
        public readonly string $kind,
        public readonly ?array $payload,
        public readonly string $reason,
        public readonly ?int $httpStatus,
    ) {
    }

    /** @param array<mixed> $payload */
    public static function decoded(array $payload): self
    {
        return new self(self::DECODED, $payload, 'Google decoded the token.', 200);
    }

    public static function retry(string $reason, ?int $httpStatus = null): self
    {
        return new self(self::RETRY, null, $reason, $httpStatus);
    }

    public static function rejected(string $reason, ?int $httpStatus = null): self
    {
        return new self(self::REJECTED, null, $reason, $httpStatus);
    }
}
