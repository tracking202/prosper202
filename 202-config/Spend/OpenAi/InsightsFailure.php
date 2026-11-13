<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

/**
 * Why a page of insights could not be read; its message names it, its code
 * what the caller can do about it (AdvertiserAnswer's kinds, as
 * AdConnectionsController answers them).
 */
final class InsightsFailure extends \RuntimeException
{
    /** OpenAI's 429, or Prosper202's own hold to its per-minute limits. */
    public const int RATE_LIMITED = 1;
    /** 401 or 403: the Advertiser API key was refused. */
    public const int KEY_REFUSED = 2;
    /** 413: more rows than OpenAI answers in one request. */
    public const int TOO_LARGE = 3;
    /** Not reached: no answer, a 5xx, or a request that was not sent. */
    public const int UNAVAILABLE = 4;
    /** An answer that arrived and cannot be used: not JSON, cut off, not the documented shape. */
    public const int UNREADABLE = 5;
    /** Another 4xx: OpenAI refused the request itself, and sending it again gets the same answer. */
    public const int REQUEST_REFUSED = 6;

    /** @param int|null $retryAfter seconds OpenAI asked to wait (its Retry-After), null when it named none */
    public function __construct(string $message, int $code, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $code);
    }

    public function tooLarge(): bool
    {
        return $this->getCode() === self::TOO_LARGE;
    }

    public function rateLimited(): bool
    {
        return $this->getCode() === self::RATE_LIMITED;
    }

    public function keyRefused(): bool
    {
        return $this->getCode() === self::KEY_REFUSED;
    }

    /** The run's outcome this failure is: rate_limited, key_refused, unavailable, request_refused or unreadable_answer. */
    public function outcome(): string
    {
        return match ($this->getCode()) {
            self::RATE_LIMITED => SpendSync::RATE_LIMITED,
            self::KEY_REFUSED => SpendSync::KEY_REFUSED,
            self::UNAVAILABLE => SpendSync::UNAVAILABLE,
            self::REQUEST_REFUSED => SpendSync::REQUEST_REFUSED,
            default => SpendSync::UNREADABLE,
        };
    }
}
