<?php

declare(strict_types=1);

namespace Tests\Spend\OpenAi;

use PHPUnit\Framework\TestCase;
use Prosper202\Spend\OpenAi\SpendSync;

/** OpenAI's spend doubles, read without losing a row to binary noise or rounding a real seventh decimal. */
final class SpendOfTest extends TestCase
{
    public function testNoiseIsTheAmountItStandsFor(): void
    {
        self::assertSame('12.340000', SpendSync::spendOf(12.340000000000002));
        self::assertSame('0.300000', SpendSync::spendOf(0.1 + 0.2));
        self::assertSame('7.100000', SpendSync::spendOf(7.1));
    }

    public function testARealSeventhDecimalIsPassedOnForTheLedgerToRefuse(): void
    {
        self::assertSame(0.1234567, SpendSync::spendOf(0.1234567));
        self::assertSame(3, SpendSync::spendOf(3));
        self::assertSame('1.5', SpendSync::spendOf('1.5'));
    }
}
