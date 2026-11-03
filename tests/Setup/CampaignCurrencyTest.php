<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\CampaignCurrency;

/**
 * A campaign's payout in its own currency is kept beside the converted one,
 * and the converted one is what a conversion at the campaign payout records.
 */
final class CampaignCurrencyTest extends TestCase
{
    public function testAPayoutInTheAccountCurrencyIsStoredAsTyped(): void
    {
        $never = static function (): ?string {
            self::fail('no rate is asked for');
        };
        self::assertSame(
            ['currency' => 'USD', 'payout' => '12.50', 'foreign_payout' => '0'],
            CampaignCurrency::store('USD', 'USD', '12.50', $never)
        );
        self::assertSame(
            ['currency' => 'USD', 'payout' => '0', 'foreign_payout' => '0'],
            CampaignCurrency::store('USD', 'EUR', '0', $never),
            '0 is 0 in any currency'
        );
    }

    /** The rate is asked FROM the campaign's currency INTO the account's. */
    public function testAForeignPayoutIsConvertedIntoTheAccountCurrency(): void
    {
        $asked = [];
        $convert = static function (string $from, string $to, string $amount) use (&$asked): ?string {
            $asked[] = [$from, $to, $amount];
            return '13.4123';
        };
        self::assertSame(
            ['currency' => 'GBP', 'payout' => '13.41', 'foreign_payout' => '10.00'],
            CampaignCurrency::store('USD', 'GBP', '10', $convert)
        );
        self::assertSame([['GBP', 'USD', '10.00']], $asked);
    }

    /** @return iterable<string, array{?string}> */
    public static function unusableRates(): iterable
    {
        yield 'no answer' => [null];
        yield 'zero' => ['0'];
        yield 'not a number' => ['n/a'];
        yield 'too large for the column' => ['1000000'];
    }

    /** @dataProvider unusableRates */
    public function testNoUsableRateStoresNothing(?string $answer): void
    {
        $this->expectException(\RuntimeException::class);
        CampaignCurrency::store('USD', 'EUR', '10', static fn (): ?string => $answer);
    }

    public function testANegativeForeignPayoutIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        CampaignCurrency::store('USD', 'EUR', '-10', static fn (): ?string => '11.00');
    }

    public function testTheFormShowsAForeignCampaignInItsOwnCurrency(): void
    {
        $row = ['aff_campaign_currency' => 'EUR', 'aff_campaign_payout' => '11.18', 'aff_campaign_foreign_payout' => '10.00'];
        self::assertSame(['currency' => 'EUR', 'payout' => '10.00'], CampaignCurrency::shown($row, 'USD'));
    }

    /**
     * A campaign 1.9.80 saved without the field carries the column default
     * (USD) and no foreign payout: its payout is in the account currency.
     */
    public function testACampaignWithNoForeignPayoutIsInTheAccountCurrency(): void
    {
        $row = ['aff_campaign_currency' => 'USD', 'aff_campaign_payout' => '9.00', 'aff_campaign_foreign_payout' => '0.00'];
        self::assertSame(['currency' => 'EUR', 'payout' => '9.00'], CampaignCurrency::shown($row, 'EUR'));
        self::assertSame(['currency' => 'EUR', 'payout' => ''], CampaignCurrency::shown([], 'EUR'), 'a new campaign');
    }

    /** Renaming a campaign does not re-price it: the stored conversion is kept. */
    public function testASaveThatKeepsTheForeignAmountKeepsTheConversion(): void
    {
        $row = ['aff_campaign_currency' => 'GBP', 'aff_campaign_payout' => '13.41', 'aff_campaign_foreign_payout' => '10.00'];
        $never = static function (): ?string {
            self::fail('no rate is asked for');
        };
        self::assertSame(
            ['currency' => 'GBP', 'payout' => '13.41', 'foreign_payout' => '10.00'],
            CampaignCurrency::store('USD', 'GBP', '10', $never, $row)
        );
        self::assertSame(
            ['currency' => 'GBP', 'payout' => '16.00', 'foreign_payout' => '12.00'],
            CampaignCurrency::store('USD', 'GBP', '12', static fn (): ?string => '16.00', $row),
            'a new amount is converted'
        );
        self::assertSame(
            ['currency' => 'EUR', 'payout' => '11.18', 'foreign_payout' => '10.00'],
            CampaignCurrency::store('USD', 'EUR', '10', static fn (): ?string => '11.18', $row),
            'so is the same amount in another currency'
        );
    }
}
