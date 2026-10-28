<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * Why a ledger row does not count toward its click's value.
 *
 * Two kinds, and the difference matters to the recompute:
 *
 * - DERIVED reasons (REPLACE, BATCH) are a function of the click's rows and
 *   its payout mode. The recompute owns them: it sets them, and it clears
 *   them when the row that superseded this one is deleted.
 * - FIXED reasons are decisions made once, by something other than the
 *   recompute, and it never touches them: PRE_LEDGER (the row predates the
 *   ledger and the click's value was carried over as a legacy_baseline row),
 *   REPLAY and REEVALUATION (the goal engine replaced this outcome).
 */
enum SupersededReason: string
{
    case REPLACE = 'replace';
    case BATCH = 'batch';
    case PRE_LEDGER = 'pre_ledger';
    case REPLAY = 'replay';
    case REEVALUATION = 'reevaluation';

    public function isDerived(): bool
    {
        return $this === self::REPLACE || $this === self::BATCH;
    }

    /** The sentence the breakdown shows for a row superseded this way. */
    public function explanation(): string
    {
        return match ($this) {
            self::REPLACE => 'A later conversion replaced this value (the campaign pays the latest conversion).',
            self::BATCH => 'A newer revenue upload replaced this value.',
            self::PRE_LEDGER => 'Recorded before the conversion ledger; the click value from then is kept as its own row.',
            self::REPLAY => 'A goal outcome was re-decided when an earlier event arrived late.',
            self::REEVALUATION => 'A goal was re-evaluated under a newer version.',
        };
    }
}
