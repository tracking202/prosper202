<?php

declare(strict_types=1);

namespace Api\V3\Support;

/** How one chunk of a bulk-upsert ended (Controller::upsertChunk()). */
enum BulkChunkOutcome: string
{
    /** At least one row written and committed. */
    case Stored = 'stored';
    /** Committed, every row skipped or refused. */
    case NothingStored = 'nothing_stored';
    /** Rolled back by a deadlock on every attempt. */
    case Deadlocked = 'deadlocked';
    /** Rolled back by a lock wait timeout. */
    case LockWait = 'lock_wait';
    /** The rows ran and the commit, or a rollback, was not confirmed. */
    case Unconfirmed = 'unconfirmed';
    /** Could not begin, after an earlier chunk was stored. */
    case Failed = 'failed';

    /** Whether rows of the chunk may be in the database. */
    public function mayHaveWritten(): bool
    {
        return match ($this) {
            self::Stored, self::Unconfirmed => true,
            self::NothingStored, self::Deadlocked, self::LockWait, self::Failed => false,
        };
    }
}
