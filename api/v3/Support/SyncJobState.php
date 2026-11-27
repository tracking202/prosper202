<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * Where a server-side sync job stands: the `status` of its record in the
 * state store. ServerStateStore::changeJob() is the one writer of a move.
 */
enum SyncJobState: string implements State
{
    use Moves;

    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function next(): array
    {
        return match ($this) {
            // Failed without running: a run that cannot start for a reason
            // the next try meets too (a key that cannot be sealed).
            self::Queued => [self::Running, self::Cancelled, self::Failed],
            // Queued again: a try that failed on something the next may not.
            self::Running => [self::Succeeded, self::Partial, self::Failed, self::Cancelled, self::Queued],
            self::Succeeded, self::Partial, self::Failed, self::Cancelled => [],
        };
    }

    public static function initial(): array
    {
        return [self::Queued];
    }

    /**
     * The states a job ends in: it runs no more, keeps no key, and has one
     * audit record.
     *
     * @return list<self>
     */
    public static function finished(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $state): bool => $state->isTerminal()));
    }

    /**
     * The state of a job record, or null when its status is one no case
     * names. For reads over many jobs, where one unreadable record is left
     * alone and the rest are answered; a write asks ofStored() and refuses.
     *
     * @param array<string, mixed> $job
     */
    public static function ofRecord(array $job): ?self
    {
        $status = $job['status'] ?? null;

        return is_string($status) ? self::tryFrom($status) : null;
    }
}
