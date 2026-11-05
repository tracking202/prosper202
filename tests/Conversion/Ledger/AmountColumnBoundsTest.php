<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\AmountOutOfRange;

/**
 * What a conversion row's decimal(11,5) holds, and the refusal past it
 * (Y5N2): Amount reads 13 whole digits, the column holds six.
 */
final class AmountColumnBoundsTest extends TestCase
{
    public function testTheBoundIsTheColumnsOwn(): void
    {
        self::assertSame(99999999999, Amount::columnMaxUnits());
        self::assertSame('999999.99999', Amount::fromUnits(Amount::columnMaxUnits()));
    }

    public function testAmountsUpToTheLimitPassEitherSign(): void
    {
        foreach (['999999.99999', '-999999.99999', '0', '12.5'] as $amount) {
            $units = Amount::toUnits($amount);
            self::assertSame($units, Amount::checkedForColumn($units, 'payout'), $amount);
        }
    }

    public function testOnePastTheLimitIsRefusedNamingTheField(): void
    {
        foreach (['1000000', '-1000000', '999999.999995', '1234567890123'] as $amount) {
            try {
                Amount::checkedForColumn(Amount::toUnits($amount), 'payout');
                self::fail($amount . ' passed');
            } catch (AmountOutOfRange $e) {
                self::assertSame('payout', $e->field);
                self::assertStringStartsWith('payout ', $e->getMessage());
                self::assertStringEndsWith('is out of range: a conversion holds from -999999.99999 to 999999.99999', $e->getMessage());
            }
        }
    }

    public function testItIsTheExceptionEveryAmountReaderAlreadyRefuses(): void
    {
        self::assertInstanceOf(\InvalidArgumentException::class, new AmountOutOfRange('amount', '1', 1));
    }
}
