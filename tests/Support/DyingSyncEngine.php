<?php

declare(strict_types=1);

namespace Tests\Support;

use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;

/**
 * A sync engine whose process ends mid-run, after logging one write: killed
 * (no PHP code runs after it), out of time, out of memory, or exit().
 */
final class DyingSyncEngine extends SyncEngine
{
    public function __construct(ServerStateStore $store, private readonly string $how)
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
        if ($eventLogger !== null) {
            $eventLogger('info', 'Created target record', ['entity' => 'campaigns', 'key' => 'A']);
            $eventLogger('info', 'Updated target record', ['entity' => 'campaigns', 'key' => 'B']);
            $eventLogger('info', 'Created target record', ['entity' => 'campaigns', 'key' => 'C']);
        }
        switch ($this->how) {
            case 'kill':
                posix_kill(getmypid(), SIGKILL);
                sleep(10);
                break;
            case 'time':
                set_time_limit(1);
                while (true) {
                    $spin = 1;
                }
                // Not reached: the time limit ends the process.
            case 'memory':
                $hold = [];
                while (true) {
                    $hold[] = str_repeat('x', 1024 * 1024);
                }
                // Not reached: the memory limit ends the process.
            case 'exit':
                exit(3);
        }

        throw new \LogicException('unknown way to die: ' . $this->how);
    }
}
