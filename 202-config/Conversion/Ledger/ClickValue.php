<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * What a click's ledger rows add up to.
 */
final readonly class ClickValue
{
    /**
     * @param array<int, array{by: int, reason: SupersededReason}> $derivedSupersessions
     *        Every row the recompute says is superseded for a derived reason,
     *        keyed by conv_id. A row absent here that is stored with a
     *        derived reason is no longer superseded.
     * @param array<int, true> $counted conv_ids that count toward the value
     *        (reversals included when their target counts).
     */
    public function __construct(
        public bool $lead,
        /** The click's value in units; null when the click is not a lead (its stored payout is left alone). */
        public ?int $valueUnits,
        public array $derivedSupersessions,
        public array $counted,
    ) {
    }
}
