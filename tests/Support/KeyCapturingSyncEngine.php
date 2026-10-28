<?php

declare(strict_types=1);

namespace Tests\Support;

use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;

/** A sync engine that records the keys a job runs it with, then fails or succeeds. */
final class KeyCapturingSyncEngine extends SyncEngine
{
    /** @var list<string> the source's and the target's api_key */
    public array $keys = [];

    public function __construct(ServerStateStore $store, private readonly ?\Throwable $failure = null)
    {
        parent::__construct($store);
    }

    public function execute(
        array $sourceProfile,
        array $targetProfile,
        string $entityArg = 'all',
        array $options = [],
        ?callable $eventLogger = null
    ): array {
        $this->keys = [(string) ($sourceProfile['api_key'] ?? ''), (string) ($targetProfile['api_key'] ?? '')];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $counts = ['synced' => 1, 'skipped' => 0, 'failed' => 0, 'pruned' => 0, 'errors' => []];

        return ['entity' => $entityArg, 'results' => ['campaigns' => $counts], 'mappings' => []];
    }
}
