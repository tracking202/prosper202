<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

/**
 * One ChatGPT Ads ad account connected to a Prosper202 account, as the spend
 * sync needs it: whose it is, the ad account's id and currency, and its
 * Advertiser API key (unsealed only for the run).
 */
final class SpendConnection
{
    public function __construct(
        public readonly int $connectionId,
        public readonly int $userId,
        public readonly string $accountRef,
        public readonly string $currency,
        #[\SensitiveParameter] public readonly string $apiKey,
    ) {
    }

    public function __debugInfo(): array
    {
        return ['connectionId' => $this->connectionId, 'userId' => $this->userId, 'accountRef' => $this->accountRef,
            'currency' => $this->currency, 'apiKey' => '[sealed]'];
    }
}
