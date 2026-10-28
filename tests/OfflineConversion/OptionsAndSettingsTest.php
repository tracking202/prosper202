<?php

declare(strict_types=1);

namespace Tests\OfflineConversion;

use PHPUnit\Framework\TestCase;
use Prosper202\OfflineConversion\Consent;
use Prosper202\OfflineConversion\ConversionNames;
use Prosper202\OfflineConversion\ExportOptions;
use Prosper202\OfflineConversion\FeedStore;
use Prosper202\OfflineConversion\Format;

/**
 * What a request, a form and a stored name may say, read strictly: a value
 * that is not one of the listed is refused naming its field, never read as
 * the nearest one (CLAUDE.md #4).
 */
final class OptionsAndSettingsTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testARequestsOptionsAndTheirDefaults(): void
    {
        [$options, $errors] = ExportOptions::fromRequest(['format' => 'microsoft'], self::NOW);
        self::assertSame([], $errors);
        self::assertNotNull($options);
        self::assertSame(Format::MICROSOFT, $options->format);
        self::assertSame(ExportOptions::MAX_DAYS, $options->days);
        self::assertSame(self::NOW - 90 * 86400, $options->from());
        self::assertNull($options->campaignId);
        self::assertNull($options->conversionName);
        self::assertNull($options->adUserData);

        [$options] = ExportOptions::fromRequest([
            'format' => 'google', 'days' => '7', 'campaign_id' => '12', 'conversion_name' => 'Lead, "A"',
            'ad_user_data' => 'granted', 'ad_personalization' => 'denied',
        ], self::NOW, 30);
        self::assertNotNull($options);
        self::assertSame([7, 12, 'Lead, "A"'], [$options->days, $options->campaignId, $options->conversionName]);
        self::assertSame([Consent::GRANTED, Consent::DENIED], [$options->adUserData, $options->adPersonalization]);
        [$options] = ExportOptions::fromRequest(['format' => 'google', 'days' => '', 'ad_user_data' => ''], self::NOW, 30);
        self::assertSame([30, null], [$options?->days, $options?->adUserData], 'empty is absent');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedRequests(): iterable
    {
        yield 'no format' => [[], 'format'];
        yield 'an unknown format' => [['format' => 'facebook'], 'format'];
        yield 'a format in another case' => [['format' => 'Google'], 'format'];
        yield 'a list for a format' => [['format' => ['google']], 'format'];
        yield 'days 0' => [['format' => 'google', 'days' => '0'], 'days'];
        yield 'days 91' => [['format' => 'google', 'days' => '91'], 'days'];
        yield 'days 7.5' => [['format' => 'google', 'days' => '7.5'], 'days'];
        yield 'days 12abc' => [['format' => 'google', 'days' => '12abc'], 'days'];
        yield 'days -1' => [['format' => 'google', 'days' => '-1'], 'days'];
        yield 'campaign 0' => [['format' => 'google', 'campaign_id' => '0'], 'campaign_id'];
        yield 'campaign 1e3' => [['format' => 'google', 'campaign_id' => '1e3'], 'campaign_id'];
        yield 'a formula for a name' => [['format' => 'google', 'conversion_name' => '=1+1'], 'conversion_name'];
        yield 'consent yes' => [['format' => 'google', 'ad_user_data' => 'yes'], 'ad_user_data'];
        yield 'consent in another case' => [['format' => 'google', 'ad_personalization' => 'Granted'], 'ad_personalization'];
        yield 'consent for microsoft' => [['format' => 'microsoft', 'ad_user_data' => 'denied'], 'ad_user_data'];
    }

    /**
     * @dataProvider refusedRequests
     * @param array<string, mixed> $params
     */
    public function testARequestIsRefusedNamingItsField(array $params, string $field): void
    {
        [$options, $errors] = ExportOptions::fromRequest($params, self::NOW);
        self::assertNull($options);
        self::assertArrayHasKey($field, $errors);
    }

    public function testAConversionNameIsStoredAsTypedOrRefused(): void
    {
        foreach (['Lead', 'Prosper202 sale', 'Lead, "A"', 'Vente réalisée', str_repeat('é', 100)] as $name) {
            self::assertNull(ConversionNames::problem($name), $name);
        }
        foreach (['', ' ', ' Lead', 'Lead ', "Lead\tA", "Lead\nA", '=SUM(1)', '+1', '-1', '@x', str_repeat('a', 101), "\xff"] as $name) {
            self::assertNotNull(ConversionNames::problem($name), var_export($name, true));
        }
    }

    public function testAFetchUrlsSettingsAreEveryFieldReadStrictly(): void
    {
        $good = ['format' => 'google', 'days' => '7', 'conversion_name' => '', 'ad_user_data' => 'granted', 'ad_personalization' => ''];
        [$settings, $errors] = FeedStore::settings($good);
        self::assertSame([], $errors);
        self::assertSame(Format::GOOGLE, $settings['format'] ?? null);
        self::assertSame([7, null, Consent::GRANTED, null], [$settings['days'], $settings['conversion_name'], $settings['ad_user_data'], $settings['ad_personalization']]);

        $refused = [
            'format' => ['format' => 'bing'],
            'days' => ['days' => '1'],
            'conversion_name' => ['conversion_name' => '@x'],
            'ad_personalization' => ['ad_personalization' => 'maybe'],
        ];
        foreach ($refused as $field => $bad) {
            [$settings, $errors] = FeedStore::settings($bad + $good);
            self::assertNull($settings, $field);
            self::assertArrayHasKey($field, $errors);
        }
        [$settings, $errors] = FeedStore::settings(['format' => 'microsoft', 'ad_user_data' => 'granted'] + $good);
        self::assertNull($settings);
        self::assertArrayHasKey('ad_user_data', $errors, 'Microsoft\'s file has no consent columns');
        $missing = $good;
        unset($missing['days']);
        [$settings, $errors] = FeedStore::settings($missing);
        self::assertNull($settings);
        self::assertArrayHasKey('days', $errors, 'a field the form always posts is required, never defaulted');
    }

    public function testAFetchUrlEndsInCsvOnTheBaseItIsGiven(): void
    {
        $key = str_repeat('A', 43);
        self::assertTrue(FeedStore::isKey($key));
        self::assertSame('https://track.example.com/api/v3/offline-conversions/' . $key . '?file=conversions.csv', FeedStore::url('https://track.example.com/', $key));
        foreach ([str_repeat('A', 42), str_repeat('A', 44), str_repeat('A', 42) . '=', str_repeat('A', 42) . '/'] as $bad) {
            self::assertFalse(FeedStore::isKey($bad), $bad);
        }
    }
}
