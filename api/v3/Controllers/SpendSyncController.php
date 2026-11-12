<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\NotFoundException;
use Api\V3\HttpException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\PayloadKeys;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\Database\Connection;
use Prosper202\Spend\OpenAi\AdConnectionSpendConnections;
use Prosper202\Spend\OpenAi\SpendSync;

/**
 * /spend-ledger/sync: whether each ChatGPT Ads connection's spend is
 * pulled into the spend ledger (off until turned on), its last run, and a
 * run now.
 */
final class SpendSyncController
{
    private readonly SpendSync $sync;
    private readonly AdConnectionSpendConnections $connections;

    public function __construct(\mysqli $db, private readonly int $userId)
    {
        $conn = new Connection($db);
        $this->connections = new AdConnectionSpendConnections(new AdConnectionStore($conn));
        $this->sync = new SpendSync($conn, $this->connections);
    }

    /** @return array<string, mixed> */
    public function list(): array
    {
        return ['data' => $this->sync->status($this->userId)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $connectionId, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['enabled'], 'a spend sync');
        $enabled = $payload['enabled'] ?? null;
        if (!is_bool($enabled)) {
            throw new ValidationException('Invalid enabled', ['enabled' => 'is required: true to pull this connection\'s spend every hour, false to stop']);
        }
        if (!$this->sync->setEnabled($this->userId, $connectionId, $enabled)) {
            throw new NotFoundException('No ChatGPT Ads connection ' . $connectionId . ' (GET /ad-connections lists them)');
        }

        return ['data' => $this->one($connectionId)];
    }

    /** @return array<string, mixed> */
    public function run(int $connectionId): array
    {
        $state = $this->one($connectionId);
        if ($state['enabled'] !== true) {
            throw new ValidationException('Spend sync is off', [
                'enabled' => 'is false for connection ' . $connectionId . ': turn it on first (PUT /spend-ledger/sync/' . $connectionId . ' {"enabled": true})',
            ]);
        }
        $connection = $this->connections->find($this->userId, $connectionId);
        if ($connection === null) {
            throw new NotFoundException('No ChatGPT Ads connection ' . $connectionId . ' (GET /ad-connections lists them)');
        }
        $result = $this->sync->run($connection);
        // A failed run is answered as one (CLAUDE.md #1): refused here (the
        // currency) is a 422, the account's to change; OpenAI's refusal, its
        // 429 or its silence is a 424 (failed dependency) with OpenAI's words,
        // which a 5xx would hide. The run is recorded either way, and GET
        // /spend-ledger/sync shows it (retry_after after a 429).
        if ($result['error'] !== null) {
            if ($result['refused']) {
                throw new ValidationException('Spend sync refused', ['connection' => $result['error']]);
            }
            throw new HttpException('Spend sync run failed: ' . $result['error']
                . ($result['rate_limited'] ? ' (the next run waits; GET /spend-ledger/sync says until when)' : ''), 424);
        }
        unset($result['refused'], $result['rate_limited']);

        return ['data' => ['run' => $result] + $this->one($connectionId)];
    }

    /** @return array<string, mixed> */
    private function one(int $connectionId): array
    {
        foreach ($this->sync->status($this->userId) as $state) {
            if ($state['connection_id'] === $connectionId) {
                return $state;
            }
        }
        throw new NotFoundException('No ChatGPT Ads connection ' . $connectionId . ' (GET /ad-connections lists them)');
    }
}
