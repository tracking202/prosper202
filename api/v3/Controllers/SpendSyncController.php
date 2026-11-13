<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\TooManyRequestsException;
use Api\V3\Exception\UpstreamUnavailableException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\PayloadKeys;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\AdPlatformUnavailable;
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function run(int $connectionId, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, [], 'a spend sync run (it takes no body)');
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
        $result = $this->sync->run($connection, self::deadline());
        // A failed run is answered as one (CLAUDE.md #1), as the ad
        // connections answer OpenAI (AdConnectionsController::refusing()):
        // refused here or a refused key is the account's to change (422);
        // OpenAI's limit 429, OpenAI not reached 503, its answer unusable
        // 502. The run is recorded either way (GET /spend-ledger/sync).
        if ($result['error'] !== null) {
            $said = 'Spend sync run for connection ' . $connectionId . ' failed: ' . $result['error'];
            $details = ['upstream' => SpendSync::PLATFORM, 'connection_id' => $connectionId];
            switch ($result['outcome']) {
                case SpendSync::REFUSED:
                    throw new ValidationException('Spend sync refused', ['connection' => $result['error']]);
                case SpendSync::KEY_REFUSED:
                    throw new ValidationException('OpenAI refused connection ' . $connectionId . '\'s Advertiser API key', [
                        'api_key' => 'OpenAI refused the Advertiser API key of connection ' . $connectionId . ' (' . $result['error']
                            . '); replace it (PATCH /ad-connections/' . $connectionId . ' {"api_key": ...}); the hourly run waits until then or until retry_after',
                    ]);
                case SpendSync::RATE_LIMITED:
                    throw new TooManyRequestsException($said . '; the hourly run waits until retry_after', max(1, $result['retry_after'] - time()),
                        ['details' => $details + ['reason' => AdPlatformUnavailable::RATE_LIMITED, 'retry_after' => $result['retry_after']]]);
                case SpendSync::UNAVAILABLE:
                    $wait = $result['wait'] === null || $result['wait'] < 1 ? UpstreamUnavailableException::RETRY_AFTER : min($result['wait'], 3600);

                    throw new UpstreamUnavailableException($said, $wait, $details + ['reason' => AdPlatformUnavailable::UNREACHABLE]);
                default:
                    throw new UpstreamUnavailableException($said, null, $details + ['reason' => AdPlatformUnavailable::UNREADABLE]);
            }
        }
        $run = ['rows' => $result['rows'], 'from' => $result['from'], 'to' => $result['to'], 'complete' => $result['complete'],
            'error' => null, 'note' => $result['note']];

        return ['data' => ['run' => $run] + $this->one($connectionId)];
    }

    /**
     * When a run asked for here stops reading: well inside PHP's time limit,
     * so what it read is recorded and the next run goes on from there.
     */
    private static function deadline(): int
    {
        $limit = (int) ini_get('max_execution_time');

        return time() + ($limit > 0 ? max(5, intdiv($limit * 2, 3)) : 60);
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
