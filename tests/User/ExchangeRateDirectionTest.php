<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\ExchangeRates;

/**
 * Every caller asks the rate service to convert FROM the campaign's
 * currency INTO the account's. The account-currency change asked it the
 * other way round and re-priced campaigns backwards; the request fields and
 * the call sites are held here, the service's own direction by
 * ExchangeRatesLiveTest.
 */
final class ExchangeRateDirectionTest extends TestCase
{
    public function testTheAmountIsSentInItsOwnCurrency(): void
    {
        self::assertSame(
            ['currency' => 'GBP', 'payout_currency' => 'USD', 'payout' => '10.00'],
            ExchangeRates::requestFields('GBP', 'USD', '10.00')
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function callers(): iterable
    {
        yield 'Personal settings' => ['202-account/account.php', 'getForeignPayout($campaignCurrency, $postedCurrency, $payout)'];
        yield 'PUT /users/{id}/preferences' => ['api/v3/Controllers/UsersController.php', '$rate($campaignCurrency, $currency, $payout)'];
        yield 'Setup > Campaigns' => ['tracking202/setup/aff_campaigns.php', 'CampaignCurrency::store($accountCurrency, $campaignCurrency, $aff_campaign_payout,'];
    }

    /** @dataProvider callers */
    public function testEachCallerSendsTheCampaignsCurrencyFirst(string $file, string $call): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        self::assertIsString($source);
        self::assertStringContainsString($call, $source);
    }
}
