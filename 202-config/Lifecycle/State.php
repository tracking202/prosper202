<?php

declare(strict_types=1);

namespace Prosper202\Lifecycle;

/**
 * One state of a record's lifecycle, as a string-backed enum whose cases are
 * the values stored. The enum is the one place that says which moves are
 * legal: next() is the whole transition table, and a writer changes the
 * stored state only through it (Moves::moveTo() for a value held in memory,
 * Moves::sources() for the `WHERE state IN (…)` of an UPDATE).
 *
 * A state that no case names does not exist: read a stored value with
 * tryFrom() and refuse null (CLAUDE.md #4, #11).
 */
interface State extends \BackedEnum
{
    /**
     * The states this one may move to. Empty for a terminal state.
     *
     * @return list<static>
     */
    public function next(): array;

    /**
     * The states a record may be created in. Every other state is reached
     * from one of these by the moves next() lists
     * (EveryLifecycleHoldsItsContractTest).
     *
     * @return list<static>
     */
    public static function initial(): array;
}
