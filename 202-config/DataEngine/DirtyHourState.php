<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;
use Prosper202\Lifecycle\UnknownState;

/**
 * Where a dirty hour (a 202_dirty_hours row) is. The table has no state
 * column: the state is its two flags, `processed` and `deleted`, which are
 * written together and spelled here (DirtyHours' upsert writes SET_QUEUED
 * out; QueueStatesHaveOneWriterTest holds it to this spelling).
 *
 * A writer that changed clicks queues their range; the cron job's pass
 * (DirtyHours::pass()) takes each, rolls it up and deletes it. Taken is what
 * lets a change that lands during the roll-up survive it: the writer's queue
 * puts the row back to Queued, and the pass's delete, which asks for Taken,
 * finds nothing to delete.
 *
 * No state is terminal: a range rolled up is a row deleted.
 */
enum DirtyHourState: string implements State
{
    use Moves;

    case Queued = 'queued';
    case Taken = 'taken';

    /** Each state as a WHERE condition and as a SET list, for DirtyHours' statements. */
    public const IS_QUEUED = 'processed = 0';
    public const IS_TAKEN = 'processed <> 0';
    public const SET_QUEUED = 'processed = 0, deleted = 0';
    public const SET_TAKEN = 'processed = 1, deleted = 1';

    public function next(): array
    {
        return match ($this) {
            self::Queued => [self::Taken],
            // When a writer queues the range again, or its pass ended without finishing it.
            self::Taken => [self::Queued],
        };
    }

    public static function initial(): array
    {
        return [self::Queued];
    }

    /**
     * A row's flags as its state: `processed` decides, as it has for every
     * pass, and `deleted` only ever accompanied it.
     *
     * @throws UnknownState for a flag that is not a whole number
     */
    public static function ofRow(mixed $processed, string $where = '202_dirty_hours'): self
    {
        if (is_int($processed) || (is_string($processed) && preg_match('/\A\d+\z/', $processed) === 1)) {
            return (int) $processed === 0 ? self::Queued : self::Taken;
        }
        throw UnknownState::of(self::class, $processed, $where . '.processed');
    }

    /** ofRow() as a WHERE condition (DirtyHoursQueueIntegrationTest holds the two together). */
    public function condition(): string
    {
        return match ($this) {
            self::Queued => self::IS_QUEUED,
            self::Taken => self::IS_TAKEN,
        };
    }

    /** The flags a row in this state is written with, as a SET list. */
    public function assignment(): string
    {
        return match ($this) {
            self::Queued => self::SET_QUEUED,
            self::Taken => self::SET_TAKEN,
        };
    }
}
