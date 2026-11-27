<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;

/**
 * A staged change's `status`, as stored in its user's ledger file. Written
 * only by ServerStateStore, under the file lock that re-read the record.
 * "Expired" is not a state: it is `staged` past `expires_at_epoch`.
 */
enum StagedChangeStatus: string implements State
{
    use Moves;

    case Staged = 'staged';
    case Applying = 'applying';
    case Applied = 'applied';
    case Discarded = 'discarded';
    /** The apply did not finish, or failed after its write: whether the write landed needs checking. */
    case ApplyInterrupted = 'apply_interrupted';

    public function next(): array
    {
        return match ($this) {
            self::Staged => [self::Applying, self::Discarded],
            // Back to staged only when the dispatch failed with nothing written.
            self::Applying => [self::Staged, self::Applied, self::ApplyInterrupted],
            // A claim closed as stale whose process then returns with its
            // write landed: the one outcome that is known. Never reopened.
            self::ApplyInterrupted => [self::Applied],
            self::Applied, self::Discarded => [],
        };
    }

    public static function initial(): array
    {
        return [self::Staged];
    }

    /** Nothing more is asked of anyone: the record is history, and may be pruned. */
    public function isResolved(): bool
    {
        return match ($this) {
            self::Applied, self::Discarded, self::ApplyInterrupted => true,
            self::Staged, self::Applying => false,
        };
    }

    /**
     * The status a stored change holds, or null when it names none of these:
     * a ledger is read whole, so one unreadable record is nothing that can
     * be acted on rather than a failure of the list (CLAUDE.md #24).
     */
    public static function ofChange(mixed $change): ?self
    {
        $status = is_array($change) ? ($change['status'] ?? null) : null;

        return is_string($status) ? self::tryFrom($status) : null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
