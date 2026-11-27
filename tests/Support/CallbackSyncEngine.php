<?php

declare(strict_types=1);

namespace Tests\Support;

use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;

/** A sync engine whose run is a test's closure: what happens to the job while it runs. */
final class CallbackSyncEngine extends SyncEngine
{
    public int $runs = 0;

    /** @param \Closure(): void $during may throw, as a run that fails does */
    public function __construct(ServerStateStore $store, private readonly \Closure $during)
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
        $this->runs++;
        ($this->during)();
        $counts = ['synced' => 1, 'skipped' => 0, 'failed' => 0, 'pruned' => 0, 'errors' => []];

        return ['entity' => $entityArg, 'results' => ['campaigns' => $counts], 'mappings' => []];
    }
}
