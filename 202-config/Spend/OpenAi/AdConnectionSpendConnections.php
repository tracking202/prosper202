<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

use Prosper202\AdPlatforms\AdConnectionStore;

/**
 * The spend sync's connections read from the ad connections
 * (Prosper202\AdPlatforms\AdConnectionStore): the account's live
 * `openai_ads` connections, the key opened only to run one.
 */
final class AdConnectionSpendConnections implements SpendConnections
{
    public function __construct(private readonly AdConnectionStore $store)
    {
    }

    public function find(int $userId, int $connectionId): ?SpendConnection
    {
        $shown = $this->store->get($userId, $connectionId);
        if ($shown === null || ($shown['platform'] ?? null) !== SpendSync::PLATFORM) {
            return null;
        }
        $calling = $this->store->forCalling($userId, $connectionId);
        if ($calling === null) {
            return null;
        }
        $key = $calling['credentials']['advertiser_api_key'] ?? '';
        if ($key === '') {
            throw new \RuntimeException('the connection holds no Advertiser API key');
        }

        return new SpendConnection(
            $connectionId,
            $userId,
            $calling['account_ref'],
            (string) ($shown['account']['currency'] ?? ''),
            $key
        );
    }

    public function ofAccount(int $userId): array
    {
        $out = [];
        foreach ($this->store->list($userId) as $connection) {
            if (($connection['platform'] ?? null) === SpendSync::PLATFORM) {
                $out[(int) $connection['connection_id']] = (string) ($connection['account']['id'] ?? '');
            }
        }

        return $out;
    }
}
