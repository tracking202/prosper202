<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * An amount a conversion row cannot hold (Amount::checkedForColumn()), named
 * by the field it came in as. A decimal(11,5) column holds -999999.99999 to
 * 999999.99999: a larger value failed the INSERT under strict sql_mode (a
 * 500 naming nothing) and, under an empty sql_mode, was stored clamped to
 * the column's limit and answered as recorded (Y5N2). Every conversion
 * writer refuses it instead, saying which field and what range.
 */
final class AmountOutOfRange extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $value, int $maxUnits)
    {
        parent::__construct(sprintf(
            '%s %s is out of range: a conversion holds from -%s to %s',
            $field,
            $value,
            Amount::fromUnits($maxUnits),
            Amount::fromUnits($maxUnits)
        ));
    }
}
