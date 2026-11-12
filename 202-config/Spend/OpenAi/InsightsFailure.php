<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

/** Why a page of insights could not be read; its message names it. */
final class InsightsFailure extends \RuntimeException
{
    public const int FAILED = 0;
    public const int RATE_LIMITED = 1;
    public const int KEY_REFUSED = 2;

    public function rateLimited(): bool
    {
        return $this->getCode() === self::RATE_LIMITED;
    }
}
