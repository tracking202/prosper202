<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Destinations\DestinationForm;
use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\Ga4Destination;
use Prosper202\Destinations\GoogleAdsDestination;

/**
 * Setup › Destinations' edit form read into a patch: every setting the form
 * shows, a setting it does not show kept, a blank credential left out, and a
 * row or a list it cannot read refused rather than dropped.
 */
final class DestinationFormTest extends TestCase
{
    private const GA4 = ['destination_id' => 4, 'type' => 'ga4', 'name' => 'Store GA4', 'settings' => [
        'measurement_id' => 'G-ABC123', 'event_name' => 'purchase', 'client_id_source' => 'click', 'user_id_source' => 'none',
        'params' => ['source' => 'utm_source'], 'campaign_ids' => [12], 'send_user_data' => false, 'consent' => [],
    ]];
    private const ADS = ['destination_id' => 5, 'type' => 'google_ads', 'name' => 'Search', 'settings' => [
        'customer_id' => '1234567890', 'conversion_action' => '987654', 'campaign_conversion_actions' => [12 => '555001'],
        'campaign_ids' => [], 'send_user_data' => false, 'consent' => [],
    ]];

    /** @return array<string, mixed> */
    private static function ga4(array $over = []): array
    {
        return $over + [
            'name' => ' Store GA4 ', 'measurement_id' => 'G-NEW4567', 'api_secret' => '', 'event_name' => 'purchase', 'client_id_source' => 'c1', 'user_id_source' => 'customer',
            'param_name' => ['source', 'ad', ''], 'param_field' => ['', 'utm_content', ''], 'all_campaigns' => '1', 'campaign_ids' => ['12'],
            'send_user_data' => '1', 'ad_user_data' => 'granted', 'ad_personalization' => '',
        ];
    }

    public function testAGa4FormBecomesTheWholeSettingsAndNoCredentials(): void
    {
        $patch = DestinationForm::patch(self::ga4(), self::GA4, new Ga4Destination());
        self::assertSame('Store GA4', $patch['name']);
        self::assertSame([
            'measurement_id' => 'G-NEW4567', 'event_name' => 'purchase', 'client_id_source' => 'c1', 'user_id_source' => 'customer',
            'params' => ['ad' => 'utm_content'], 'campaign_ids' => [], 'send_user_data' => true, 'consent' => ['ad_user_data' => 'granted'],
        ], $patch['settings'], 'the row set to Not sent is removed, and Every campaign clears the selection');
        self::assertArrayNotHasKey('credentials', $patch, 'a blank secret is left out, which the page saves as keep');
        self::assertSame(['api_secret' => 'typed-secret-1'], DestinationForm::patch(self::ga4(['api_secret' => ' typed-secret-1 ']), self::GA4, new Ga4Destination())['credentials']);
        self::assertSame([12, 31], DestinationForm::patch(self::ga4(['all_campaigns' => '', 'campaign_ids' => ['12', '31', '12']]), self::GA4, new Ga4Destination())['settings']['campaign_ids']);
    }

    public function testAGoogleAdsFormSetsItsRowsAndOnlyTheCredentialsTyped(): void
    {
        $post = [
            'name' => 'Search', 'customer_id' => '111-222-3333', 'login_customer_id' => '', 'conversion_action' => '987654',
            'cca_campaign' => ['12', '31', ''], 'cca_action' => ['', '555002', ''], 'all_campaigns' => '1',
            'developer_token' => 'devTOKEN_replaced99', 'oauth_client_id' => '', 'oauth_client_secret' => '',
        ];
        $patch = DestinationForm::patch($post, self::ADS, new GoogleAdsDestination());
        self::assertSame([31 => '555002'], $patch['settings']['campaign_conversion_actions'], 'campaign 12\'s cleared action removes it');
        self::assertSame(['111-222-3333', false, []], [$patch['settings']['customer_id'], $patch['settings']['send_user_data'], $patch['settings']['consent']]);
        self::assertSame(['developer_token' => 'devTOKEN_replaced99'], $patch['credentials']);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedForms(): iterable
    {
        yield 'no campaign and not every campaign' => [['all_campaigns' => '', 'campaign_ids' => []], 'settings.campaign_ids'];
        yield 'a campaign id that is not a number' => [['all_campaigns' => '', 'campaign_ids' => ['12abc']], 'settings.campaign_ids'];
        yield 'campaigns that are not a list' => [['all_campaigns' => '', 'campaign_ids' => '12'], 'settings.campaign_ids'];
        yield 'a parameter with a field and no name' => [['param_name' => [''], 'param_field' => ['utm_source']], 'settings.params'];
        yield 'a parameter listed twice' => [['param_name' => ['a', 'a'], 'param_field' => ['c1', 'c2']], 'settings.params'];
        yield 'rows of different lengths' => [['param_name' => ['a'], 'param_field' => ['c1', 'c2']], 'settings.params'];
        yield 'rows that are not lists' => [['param_name' => 'a', 'param_field' => 'c1'], 'settings.params'];
    }

    /**
     * @dataProvider refusedForms
     * @param array<string, mixed> $over
     */
    public function testAFormItCannotReadIsRefusedNamingTheField(array $over, string $field): void
    {
        try {
            DestinationForm::patch(self::ga4($over), self::GA4, new Ga4Destination());
            self::fail('accepted');
        } catch (DestinationInputException $e) {
            self::assertSame($field, $e->field);
        }
    }

    public function testAGoogleAdsRowNeedsACampaignFromTheListOnce(): void
    {
        $base = ['name' => 'Search', 'customer_id' => '1234567890', 'conversion_action' => '987654', 'all_campaigns' => '1'];
        foreach ([[['', ], ['555001']], [['12abc'], ['555001']], [['12', '12'], ['555001', '555002']]] as [$campaigns, $actions]) {
            try {
                DestinationForm::patch($base + ['cca_campaign' => $campaigns, 'cca_action' => $actions], self::ADS, new GoogleAdsDestination());
                self::fail('accepted ' . json_encode($campaigns));
            } catch (DestinationInputException $e) {
                self::assertSame('settings.campaign_conversion_actions', $e->field);
            }
        }
    }
}
