<?php

declare(strict_types=1);

namespace Tests\Spend\OpenAi;

use PHPUnit\Framework\TestCase;
use Prosper202\Spend\OpenAi\SpendSync;

/** OpenAI's spend, at six decimals rounded half up from its decimal text, without losing a row to binary noise. */
final class SpendOfTest extends TestCase
{
    public function testNoiseIsTheAmountItStandsFor(): void
    {
        self::assertSame('12.340000', SpendSync::spendOf(12.340000000000002));
        self::assertSame('0.300000', SpendSync::spendOf(0.1 + 0.2));
        self::assertSame('7.100000', SpendSync::spendOf(7.1));
    }

    public function testMoreThanSixDecimalsAreRoundedHalfUpFromTheText(): void
    {
        self::assertSame('0.123457', SpendSync::spendOf(0.1234567));
        // 5e-7 is 4.9999999999999998e-7 as a double: its shortest text decides, not float arithmetic.
        self::assertSame('0.000001', SpendSync::spendOf(5e-7));
        self::assertSame('0.000000', SpendSync::spendOf(1e-7));
        self::assertSame('1.234568', SpendSync::spendOf(1.23456789));
        self::assertSame('1.000001', SpendSync::spendOf('1.0000005'));
        self::assertSame('100.000000', SpendSync::spendOf(1e2));
    }

    public function testWhatIsNoAmountIsPassedOnForTheLedgerToRefuse(): void
    {
        self::assertSame(3, SpendSync::spendOf(3));
        self::assertSame(-1.5, SpendSync::spendOf(-1.5));
        self::assertSame(1e15, SpendSync::spendOf(1e15));
        self::assertSame('1e2', SpendSync::spendOf('1e2'));
        self::assertNull(SpendSync::spendOf(null));
        self::assertTrue(SpendSync::spendOf(true));
    }
}
