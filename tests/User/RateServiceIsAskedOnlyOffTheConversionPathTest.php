<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Recording a conversion never asks the exchange-rate service: a postback
 * or pixel converts its amount with the rate kept in 202_exchange_rates, or
 * records it as sent. A request to another host on that path would make
 * every conversion wait on it, and lose the ones it failed.
 *
 * The service is reached through three names: ExchangeRates (the class),
 * getForeignPayout() (the pages' older copy) and ExchangeRateCache's
 * refresh()/refreshQuietly(), which are handed a function that asks it.
 * Every file that uses one is listed here with when it runs, and none is
 * on the click or conversion path. The class that reads the kept rate for a
 * conversion (ExchangeRateCache) names neither, so it has no way to ask.
 *
 * What this does not see: a call built from a string, or a new request to
 * the service that goes through none of these names (OutboundCurlCallsAreListedTest
 * lists every curl_init() in the tree).
 */
final class RateServiceIsAskedOnlyOffTheConversionPathTest extends TestCase
{
    private const NAMES = '/\bExchangeRates\s*::|\bgetForeignPayout\s*\(|\bExchangeRateCache\s*::\s*refresh(?:Quietly)?\s*\(/i';

    /** file => when it asks */
    private const ASKERS = [
        '202-config/User/ExchangeRates.php' => 'the service\'s client, and the cron job\'s refresh of the kept rates',
        '202-config/functions-tracking202.php' => 'defines getForeignPayout(), the pages\' older client',
        '202-cronjobs/index.php' => 'the hourly tier, after the hour is recorded',
        '202-account/account.php' => 'Personal settings, when the owner saves the account\'s currency',
        'tracking202/setup/aff_campaigns.php' => 'Setup > Campaigns, when a campaign is saved',
        'api/v3/Controllers/UsersController.php' => 'PUT /users/{id}/preferences, when it changes the account\'s currency',
    ];

    /** Where a click or a conversion is recorded: nothing here may be an asker. */
    private const CONVERSION_PATH = [
        'tracking202/static/', 'tracking202/redirect/', 'tracking202/Redirect/', '202-config/Conversion/',
        '202-config/PaymentNotification/', '202-config/Goals/', '202-config/Click/', '202-config/Notifications/',
        '202-config/static-endpoint-helpers.php', '202-config/connect2.php', '202-config/functions-shared.php',
        'api/v3/Controllers/ConversionsController.php', 'api/v3/Apps/',
    ];

    public function testEveryFileThatCanAskTheServiceIsListedAndNoneRecordsAConversion(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            $code = '';
            foreach (token_get_all($source) as $token) {
                // Code only: a comment or a string that names the service asks nothing.
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                    $code .= is_array($token) ? $token[1] : $token;
                }
            }
            if (preg_match(self::NAMES, $code) === 1 || preg_match('/\bfunction\s+getForeignPayout\b/i', $code) === 1) {
                $found[] = $file;
            }
        }
        sort($found);
        $listed = array_keys(self::ASKERS);
        sort($listed);

        self::assertSame($listed, $found, 'A file that asks the exchange-rate service is listed with when it does.'
            . ' A conversion is never one of those times: read the kept rate (ExchangeRateCache::rate()) instead.');
        foreach ($found as $file) {
            foreach (self::CONVERSION_PATH as $prefix) {
                self::assertStringStartsNotWith($prefix, $file, "$file records clicks or conversions and may not ask the rate service");
            }
        }
    }

    public function testTheReaderOfTheKeptRateCannotAskTheService(): void
    {
        $files = SourceScan::phpFiles();
        foreach (['202-config/Conversion/Ledger/ExchangeRateCache.php', '202-config/Conversion/MysqlConversionRepository.php',
            '202-config/Conversion/Ledger/MysqlConversionLedger.php', '202-config/Conversion/Ledger/RowCurrency.php'] as $file) {
            self::assertArrayHasKey($file, $files);
            self::assertDoesNotMatchRegularExpression('/\bcurl_|\bfile_get_contents\s*\(\s*[\'"]https?:|\bfsockopen\b|\bstream_socket_client\b|\bgetForeignPayout\b/i', $files[$file], "$file opens no connection");
            self::assertStringNotContainsString('use Prosper202\User\ExchangeRates', $files[$file], "$file does not import the service's client");
        }
    }

    /** @dataProvider plantedAskers */
    public function testAPlantedAskIsSeen(string $code, bool $asks): void
    {
        self::assertSame($asks, preg_match(self::NAMES, $code) === 1, $code);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function plantedAskers(): iterable
    {
        yield 'the client' => ['$rate = ExchangeRates::convert($from, $to, "1");', true];
        yield 'fully qualified' => ['$rate = \Prosper202\User\ExchangeRates::foreignPayout($a, $b, $c);', true];
        yield 'the cron refresh' => ['\Prosper202\User\ExchangeRates :: refreshKept($conn, time());', true];
        yield 'the pages\' copy' => ['$answer = getForeignPayout ($from, $to, $payout);', true];
        yield 'a refresh handed a function' => ['ExchangeRateCache::refreshQuietly($conn, $convert);', true];
        yield 'reading the kept rate' => ['$rate = ExchangeRateCache::rate($conn, $from, $to, time());', false];
        yield 'converting with it' => ['$units = ExchangeRateCache::convert($units, $rate);', false];
    }
}
