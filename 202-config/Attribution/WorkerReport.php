<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/** What one worker run did, for the cron's output line and the tests. */
final class WorkerReport
{
    public int $mergesRequeued = 0;
    public int $modelsFannedOut = 0;
    public int $remaining = 0;
    public ?RollupReport $rollup = null;
    /** @var array<string, int> outcome => count */
    public array $outcomes = [];

    public function count(string $outcome): void
    {
        $this->outcomes[$outcome] = ($this->outcomes[$outcome] ?? 0) + 1;
    }

    public function processed(): int
    {
        return array_sum($this->outcomes);
    }

    public function summary(): string
    {
        $parts = [];
        ksort($this->outcomes);
        foreach ($this->outcomes as $outcome => $n) {
            $parts[] = $outcome . '=' . $n;
        }

        return sprintf(
            'processed %d (%s); merges re-queued %d; model changes fanned out %d; still due %d%s',
            $this->processed(),
            $parts === [] ? 'none' : implode(', ', $parts),
            $this->mergesRequeued,
            $this->modelsFannedOut,
            $this->remaining,
            $this->rollup !== null ? '; ' . $this->rollup->summary() : ''
        );
    }
}
