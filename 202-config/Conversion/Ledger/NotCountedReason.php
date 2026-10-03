<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * Why a ledger row is not part of its click's value, as the breakdown says
 * it. One reason per row, in the order LedgerExplainer checks them.
 *
 * There is no "duplicate": a conversion that repeats a row's dedupe key is
 * answered as a duplicate and never stored, so no stored row can be one.
 */
enum NotCountedReason: string
{
    case DELETED = 'deleted';
    case UNPAID = 'unpaid';
    case SUPERSEDED = 'superseded';
    case NOT_NETTED = 'not_netted';

    /** The sentence the breakdown shows for a row with this reason (SUPERSEDED defers to its SupersededReason). */
    public function explanation(): string
    {
        return match ($this) {
            self::DELETED => 'Deleted, so it no longer counts.',
            self::UNPAID => 'Tracked, not paid: an outcome the campaign does not pay for, or an event recorded for visibility.',
            self::SUPERSEDED => 'Superseded by another conversion.',
            self::NOT_NETTED => 'Reverses a conversion that does not count toward the click, so there is nothing for it to net against.',
        };
    }
}
