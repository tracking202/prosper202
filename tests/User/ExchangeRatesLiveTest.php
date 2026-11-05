<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\ExchangeRates;

/**
 * The hosted rate service converts FROM `currency` INTO `payout_currency`,
 * the order ExchangeRates::convert() sends. Asked the other way round, a
 * pound campaign became fewer dollars than pounds. Calls the live service,
 * so it runs only with P202_LIVE_RATES=1.
 */
final class ExchangeRatesLiveTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('P202_LIVE_RATES') !== '1') {
            self::markTestSkipped('Set P202_LIVE_RATES=1 to ask the hosted exchange-rate service.');
        }
    }

    public function testAStrongerCurrencyBecomesMoreOfAWeakerOne(): void
    {
        $gbp = ExchangeRates::convert('GBP', 'USD', '10');
        self::assertNotNull($gbp);
        self::assertGreaterThan(10.0, (float) $gbp, 'GBP 10 in USD');

        $jpy = ExchangeRates::convert('JPY', 'USD', '1000');
        self::assertNotNull($jpy);
        self::assertLessThan(100.0, (float) $jpy, 'JPY 1000 in USD');

        $back = ExchangeRates::convert('USD', 'GBP', '10');
        self::assertNotNull($back);
        self::assertLessThan(10.0, (float) $back, 'USD 10 in GBP');
    }
}
