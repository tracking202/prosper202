<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms\OpenAiAds;

/**
 * One Advertiser API answer, sorted by what the caller can do about it. The
 * kind is decided by the HTTP status, never by words in the body; `error`
 * is for people, with the key taken out.
 */
final class AdvertiserAnswer
{
    /** 2xx with a JSON object. */
    public const OK = 'ok';
    /** 401 or 403: the key was refused (revoked, another account's, or without this permission). */
    public const KEY_REFUSED = 'key_refused';
    /** 404: no such resource, or (for pixels and Conversions API keys) the feature is off for this account. */
    public const NOT_FOUND = 'not_found';
    /** 429, or Prosper202's own hold to OpenAI's per-minute limits. */
    public const RATE_LIMITED = 'rate_limited';
    /** 5xx, no answer, an answer cut off or not JSON, or a request the guard did not send. Try again later. */
    public const UNAVAILABLE = 'unavailable';
    /** Any other 4xx: OpenAI refused the request itself. */
    public const REFUSED = 'refused';

    /** @param array<string, mixed> $json */
    private function __construct(
        public readonly string $kind,
        public readonly int $status,
        public readonly array $json,
        public readonly ?string $error,
    ) {
    }

    /** @param array<string, mixed> $json */
    public static function ok(int $status, array $json): self
    {
        return new self(self::OK, $status, $json, null);
    }

    public static function failed(string $kind, int $status, string $error): self
    {
        return new self($kind, $status, [], $error);
    }

    public function isOk(): bool
    {
        return $this->kind === self::OK;
    }
}
