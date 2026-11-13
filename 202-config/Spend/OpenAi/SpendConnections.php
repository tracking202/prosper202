<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

/**
 * Where the spend sync finds the ChatGPT Ads connections (the ad
 * connections, 202_ad_connections). Only live connections of the
 * `openai_ads` platform are answered, each only to its own account.
 */
interface SpendConnections
{
    /**
     * The account's connection $connectionId with its key opened, or null
     * when the account has no live ChatGPT Ads connection by that id.
     *
     * @throws \RuntimeException when the stored key cannot be opened
     */
    public function find(int $userId, int $connectionId): ?SpendConnection;

    /**
     * The account's live ChatGPT Ads connections: connection id => ad
     * account id. No key is opened.
     *
     * @return array<int, string>
     */
    public function ofAccount(int $userId): array;

    /**
     * OpenAI refused the connection's key during a run: mark the connection
     * key_refused, as a check of it would (its last_error $why).
     */
    public function keyRefused(int $userId, int $connectionId, string $why, int $now): void;

    /**
     * OpenAI answered a run with the connection's key: a connection marked
     * key_refused is marked connected again, as a check of it would.
     */
    public function keyAccepted(int $userId, int $connectionId, int $now): void;
}
