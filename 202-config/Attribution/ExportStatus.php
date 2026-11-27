<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * The status of an export job (202_attribution_exports.status), written only
 * by ExportStore.
 *
 * `pending` (queued_at says from when it may run) → `running` (claimed by one
 * runner, attempts counted) → `completed`, or back to `pending` with a later
 * queued_at (a retryable webhook failure, or a run that died with attempts
 * left), or `failed` with the reason in last_error. A person queues a failed
 * job again. A job that is not running may be deleted from any state.
 */
enum ExportStatus: string implements State
{
    use Moves;

    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Running],
            self::Running => [self::Completed, self::Pending, self::Failed],
            self::Failed => [self::Pending],
            self::Completed => [],
        };
    }

    public static function initial(): array
    {
        return [self::Pending];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
