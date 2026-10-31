<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;

// dollar_format() lives in functions-tracking202.php, which loads without
// the bootstrap (HtmlReportFormatterTest loads it the same way).
require_once dirname(__DIR__, 2) . '/202-config/functions-tracking202.php';

/**
 * How the report pages write an amount. A negative one is in the
 * accounting form, its parentheses the only sign: it kept its own as well,
 * and a refund read "($-5.00)".
 */
final class DollarFormatTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?string, bool, string}> */
    public static function amounts(): iterable
    {
        yield 'a positive amount' => [5, 'USD', false, '$5.00'];
        yield 'zero' => [0, 'USD', false, '$0.00'];
        yield 'a negative amount' => [-5, 'USD', false, '($5.00)'];
        yield 'a negative amount under a dollar' => [-0.4, 'USD', false, '($0.40)'];
        yield 'a negative string, as mysqli answers' => ['-1234.5', 'USD', false, '($1,234.50)'];
        yield 'a negative cost per view' => [-0.00125, 'USD', true, '($0.00125)'];
    }

    /** @dataProvider amounts */
    public function testAnAmountIsWrittenWithOneSign(mixed $amount, ?string $currency, bool $cpv, string $expected): void
    {
        self::assertSame($expected, dollar_format($amount, $currency, $cpv));
    }
}
