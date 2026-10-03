<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * How a campaign turns a click's ledger rows into the click's value.
 *
 * REPLACE is what every campaign did before the ledger: the latest payable
 * conversion is the click's value. ACCUMULATE sums them, for campaigns that
 * pay for several distinct outcomes on one click.
 */
enum PayoutMode: string
{
    case REPLACE = 'replace';
    case ACCUMULATE = 'accumulate';

    /**
     * Read the stored column. A value that is not one of the two is not
     * guessed into either: the caller gets an exception naming it, so a
     * corrupt campaign row is found rather than silently re-valued.
     */
    public static function fromStored(mixed $value): self
    {
        $mode = is_string($value) ? self::tryFrom($value) : null;
        if ($mode === null) {
            throw new \UnexpectedValueException(
                'payout_mode "' . (is_scalar($value) ? (string) $value : gettype($value))
                . '" is neither replace nor accumulate'
            );
        }

        return $mode;
    }
}
