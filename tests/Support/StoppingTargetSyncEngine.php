<?php

declare(strict_types=1);

namespace Tests\Support;

use Api\V3\Exception\SyncException;
use Api\V3\Support\RemoteApiClient;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;

/**
 * The real sync engine over two campaigns only in the source, with a target
 * that creates the first and meets $onSecondPost at the second; or with a
 * read that meets $onRead.
 */
final class StoppingTargetSyncEngine extends SyncEngine
{
    public int $posts = 0;

    public function __construct(
        ServerStateStore $store,
        private readonly ?SyncException $onSecondPost,
        private readonly ?SyncException $onRead = null
    ) {
        parent::__construct($store);
    }

    protected function buildClients(array $sourceProfile, array $targetProfile): array
    {
        $target = new class ('https://stage.example.com', 'k', $this) extends RemoteApiClient {
            public function __construct(string $url, string $key, private readonly StoppingTargetSyncEngine $engine)
            {
                parent::__construct($url, $key);
            }

            protected function send(string $method, string $url, array $headers, ?string $body): array
            {
                if ($method !== 'POST') {
                    return [200, '{"data":{}}'];
                }
                $this->engine->posts++;
                if ($this->engine->posts === 2) {
                    throw $this->engine->stop();
                }

                return [201, '{"data":{"aff_campaign_id":' . (100 + $this->engine->posts) . '}}'];
            }
        };

        return [new RemoteApiClient('https://prod.example.com', 'k'), $target];
    }

    public function stop(): SyncException
    {
        return $this->onSecondPost ?? SyncException::remoteFailed('unexpected');
    }

    protected function fetchPortableData(RemoteApiClient $client, array $query = []): array
    {
        if ($this->onRead !== null) {
            throw $this->onRead;
        }
        $empty = array_fill_keys(SyncEngine::supportedEntities(), []);
        if (!str_contains($client->baseUrl(), 'prod.')) {
            return $empty;
        }

        return ['campaigns' => [
            ['aff_campaign_id' => 1, 'aff_campaign_name' => 'A'],
            ['aff_campaign_id' => 2, 'aff_campaign_name' => 'B'],
        ]] + $empty;
    }
}
