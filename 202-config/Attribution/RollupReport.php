<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/** What one rollup pass did, for the worker's output line and the tests. */
final class RollupReport
{
    public int $clicksResolved = 0;
    public int $hoursRebuilt = 0;
    public int $hoursBuilt = 0;
    public int $accountsDeferred = 0;

    public function summary(): string
    {
        return sprintf(
            'rollup: %d hour(s) summed, %d re-summed, %d changed click(s) resolved%s',
            $this->hoursBuilt,
            $this->hoursRebuilt,
            $this->clicksResolved,
            $this->accountsDeferred > 0 ? ', ' . $this->accountsDeferred . ' account(s) deferred to the next run' : ''
        );
    }
}
