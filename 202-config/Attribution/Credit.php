<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * One touch's share of one conversion under one model, in exact integer
 * units: credit in 1e-8 (the decimal(9,8) column), revenue in 1e-5 (the
 * decimal(11,5) column, the ledger's own unit).
 */
final class Credit
{
    public function __construct(
        public readonly int $position,
        public readonly int $clickId,
        public readonly int $creditUnits,
        public readonly int $revenueUnits,
    ) {
    }

    /** The credit as the decimal string the column stores. */
    public function creditDecimal(): string
    {
        return intdiv($this->creditUnits, CreditCalculator::CREDIT_SCALE) . '.'
            . str_pad((string) ($this->creditUnits % CreditCalculator::CREDIT_SCALE), 8, '0', STR_PAD_LEFT);
    }
}
