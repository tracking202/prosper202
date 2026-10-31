<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * One conversion row, as the click-value recompute sees it.
 */
final readonly class LedgerRow
{
    public function __construct(
        public int $convId,
        public int $amountUnits,
        public bool $payable,
        public bool $deleted,
        public ?int $reversesConvId,
        public ConversionSource $source,
        /** The upload batch for a revenue_upload row, null for every other source. */
        public ?int $batchId,
        public ?SupersededReason $supersededReason,
        public ?int $supersededBy,
    ) {
    }

    public function isReversal(): bool
    {
        return $this->reversesConvId !== null;
    }

    /** Superseded for a reason the recompute does not own (see SupersededReason). */
    public function hasFixedSupersession(): bool
    {
        return $this->supersededReason !== null && !$this->supersededReason->isDerived();
    }
}
