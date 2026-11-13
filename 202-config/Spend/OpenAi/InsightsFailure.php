<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

/** Why a page of insights could not be read; its message names it. */
final class InsightsFailure extends \RuntimeException
{
    public const int FAILED = 0;
    public const int RATE_LIMITED = 1;
    public const int KEY_REFUSED = 2;
    public const int TOO_LARGE = 3;

    /** @param int|null $retryAfter seconds OpenAI asked to wait (its Retry-After), null when it named none */
    public function __construct(string $message, int $code = self::FAILED, public readonly ?int $retryAfter = null)
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
}
