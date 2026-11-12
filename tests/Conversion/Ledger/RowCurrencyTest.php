<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\ExchangeRateCache;
use Prosper202\Conversion\Ledger\LedgerIntegrityException;
use Prosper202\Conversion\Ledger\RevenueCurrencies;
use Prosper202\Conversion\Ledger\RowCurrency;

/**
 * A ledger row's currency as it is read, the arithmetic an amount is
 * converted with, and the sentence a total is shown with when part of it is
 * in another currency. The writer and the readers run against a database in
 * LedgerCurrencyIntegrationTest.
 */
final class RowCurrencyTest extends TestCase
{
    /** @dataProvider stored */
    public function testAStoredValueIsACodeOrNotRecorded(mixed $stored, string $read): void
    {
        self::assertSame($read, RowCurrency::read($stored));
        self::assertSame($read === '' ? null : $read, RowCurrency::forAnswer($stored), 'an answer says null, never a blank code');
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function stored(): iterable
    {
        yield 'a code' => ['USD', 'USD'];
        yield 'lower case, padded' => [' eur ', 'EUR'];
        yield 'none recorded' => ['', ''];
        yield 'null' => [null, ''];
        yield 'two letters' => ['US', ''];
        yield 'four letters' => ['USDT', ''];
        yield 'digits' => ['840', ''];
        yield 'a code with a newline' => ["USD\n", 'USD'];
        yield 'a list' => [['USD'], ''];
        yield 'a number' => [840, ''];
    }

    public function testAnAmountIsConvertedInUnitsAndRoundedHalfAwayFromZero(): void
    {
        self::assertSame(2200000, ExchangeRateCache::convert(2000000, '1.1000000000'));
        self::assertSame(-2200000, ExchangeRateCache::convert(-2000000, '1.1000000000'));
        self::assertSame(1, ExchangeRateCache::convert(1, '0.5000000000'), '0.5 of a unit is one');
        self::assertSame(-1, ExchangeRateCache::convert(-1, '0.5000000000'));
        self::assertSame(0, ExchangeRateCache::convert(0, '158.7651300000'));
        // The widest amount the column holds, at a strong rate, is still exact.
        self::assertSame(15876512999841, ExchangeRateCache::convert(99999999999, '158.7651300000'));
    }

    public function testARefundIsConvertedAtTheRateItsSaleWasRecordedAt(): void
    {
        // 20.00 sent, 22.00 recorded: all of it back is all of it, half is half.
        self::assertSame(2200000, ExchangeRateCache::convertAs(2000000, 2000000, 2200000));
        self::assertSame(1100000, ExchangeRateCache::convertAs(1000000, 2000000, 2200000));
        self::assertSame(1100000, ExchangeRateCache::convertAs(1000000, -2000000, -2200000), 'the signs of the sale do not matter');

        $this->expectException(LedgerIntegrityException::class);
        ExchangeRateCache::convertAs(1000000, 0, 2200000);
    }

    public function testATotalWithOtherCurrenciesInItSaysHowMuchOfEach(): void
    {
        self::assertSame('', RevenueCurrencies::sentence([], 'EUR'), 'nothing to say when every amount is in the account\'s currency');

        $sentence = RevenueCurrencies::sentence([
            ['currency' => 'USD', 'conversions' => 1204, 'amount' => '12300.50000'],
            ['currency' => null, 'conversions' => 1, 'amount' => '45.00000'],
        ], 'EUR');

        self::assertStringContainsString('Not all revenue in this period is in EUR', $sentence);
        self::assertStringContainsString('USD 12,300.50 (1,204 conversions)', $sentence);
        self::assertStringContainsString('45.00 with no currency recorded (1 conversion)', $sentence);
        self::assertStringContainsString('nothing converts them to EUR', $sentence);

        self::assertSame(
            [['currency' => 'USD', 'conversions' => 2, 'amount' => 12.5], ['currency' => null, 'conversions' => 1, 'amount' => 4.0]],
            RevenueCurrencies::forAnswer([
                ['currency' => 'USD', 'conversions' => 2, 'amount' => '12.50000'],
                ['currency' => null, 'conversions' => 1, 'amount' => '4.00000'],
            ])
        );
    }

    /** A role that is not shown campaign figures is not shown them by another currency's name either. */
    public function testTheOtherCurrenciesFiguresAreHiddenWithTheReportsOwn(): void
    {
        $api = (string) file_get_contents(dirname(__DIR__, 3) . '/api/v3/index.php');
        self::assertStringContainsString('$reportFigures = [...\\Api\\V3\\Support\\CampaignFigures::REPORT, ...$otherCurrencies];', $api);
        self::assertStringContainsString('$matchFigures = [...\\Api\\V3\\Support\\CampaignFigures::MATCH_METHODS, ...$otherCurrencies];', $api);
        self::assertContains('stated_amount', \Api\V3\Support\CampaignFigures::RECORD, 'what a postback sent is the conversion\'s money too');

        $masked = \Api\V3\Support\CampaignFigures::mask(
            ['data' => ['total_income' => 40.0, 'currency' => 'EUR'], 'other_currencies' => [['currency' => 'USD', 'conversions' => 3, 'amount' => 37.5]]],
            [...\Api\V3\Support\CampaignFigures::REPORT, ...\Api\V3\Support\CampaignFigures::OTHER_CURRENCIES]
        );

        self::assertSame([['currency' => 'USD', 'conversions' => null, 'amount' => null]], $masked['other_currencies']);
        self::assertNull($masked['data']['total_income']);
        self::assertTrue($masked['masked']);
    }
}
