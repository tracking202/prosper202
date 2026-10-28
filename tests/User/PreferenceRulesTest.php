<?php

declare(strict_types=1);

namespace Tests\User;

use Api\V3\Controllers\UsersController;
use PHPUnit\Framework\TestCase;
use Prosper202\User\PreferenceRules;

/**
 * PUT /users/{id}/preferences holds each value to the rule of the page that
 * owns it, and refuses what it does not write rather than dropping it.
 */
final class PreferenceRulesTest extends TestCase
{
    /** @return array{0: array<string, int|string>, 1: array<string, string>} */
    private static function validate(array $payload): array
    {
        return PreferenceRules::validate($payload, UsersController::SUPPORTED_CURRENCIES);
    }

    public function testTheChoicesAreThePagesOwn(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui-partials.php';
        require_once dirname(__DIR__, 2) . '/202-config/functions-report-prefs.php';
        self::assertSame(array_keys(p202_report_ranges()), PreferenceRules::REPORT_RANGES);
        self::assertSame(p202_report_pref_fields()['user_pref_limit']['options'], PreferenceRules::REPORT_ROW_LIMITS);
        self::assertSame(p202_report_pref_fields()['user_cpc_or_cpv']['options'], PreferenceRules::CHOICES['user_cpc_or_cpv']);

        // account.php's choice lists, read from the page.
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/202-account/account.php');
        foreach ([
            'keywordChoices' => 'user_keyword_searched_or_bidded',
            'bidChoices' => 'user_pref_dynamic_bid',
            'refererChoices' => 'user_pref_referer_data',
            'privacyChoices' => 'user_pref_privacy',
            'cloakChoices' => 'user_pref_cloak_referer',
            'adChoices' => 'user_pref_ad_settings',
        ] as $variable => $column) {
            self::assertSame(1, preg_match('/\$' . $variable . ' = \[([^\]]*)\];/', $page, $m), "account.php has no \$$variable");
            preg_match_all("/'([^']*)' =>/", $m[1], $keys);
            self::assertSame($keys[1], PreferenceRules::CHOICES[$column], "$column must offer what account.php's \$$variable offers");
        }
    }

    public function testAnUnknownKeyIsRefusedByNameNotDropped(): void
    {
        [$clean, $errors] = self::validate(['user_daily_emial' => '07', 'ipqs_api_key' => 'k']);
        self::assertArrayHasKey('user_daily_emial', $errors);
        self::assertStringContainsString('user_daily_email', $errors['user_daily_emial'], 'the refusal lists what it takes');
        self::assertSame(['ipqs_api_key' => 'k'], $clean);
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function refused(): iterable
    {
        yield 'daily email off' => ['user_daily_email', 'off'];
        yield 'daily email on' => ['user_daily_email', 'on'];
        yield 'daily email 24' => ['user_daily_email', '24'];
        yield 'daily email 7' => ['user_daily_email', '7'];
        yield 'row limit 7' => ['user_pref_limit', 7];
        yield 'row limit 50.0' => ['user_pref_limit', '50.0'];
        yield 'row limit " 50"' => ['user_pref_limit', ' 50'];
        yield 'range last90' => ['user_pref_time_predefined', 'last90'];
        yield 'cost cpm' => ['user_cpc_or_cpv', 'cpm'];
        yield 'bid true' => ['user_pref_dynamic_bid', true];
        yield 'cloak blank' => ['user_pref_cloak_referer', 'blank'];
        yield 'cparam 5' => ['user_ltv_customer_cparam', 5];
        yield 'domain with no host' => ['user_tracking_domain', 'https:///path'];
        yield 'slack over http' => ['user_slack_incoming_webhook', 'http://hooks.slack.com/x'];
        yield 'slack not a url' => ['user_slack_incoming_webhook', 'hooks'];
        yield 'currency XYZ' => ['user_account_currency', 'XYZ'];
        yield 'weights not 100' => ['user_ltv_score_weights', 'volume:50,time:20,scroll:15,video:15,recency:10'];
        yield 'weights missing one' => ['user_ltv_score_weights', 'volume:50,time:20,scroll:15,video:15'];
        yield 'fatigue words' => ['user_ltv_rec_fatigue', 'three'];
        yield 'p13n email' => ['user_ltv_personalization_fields', 'first_name,email'];
        yield 'secret too long' => ['cb_key', str_repeat('k', 251)];
        yield 'secret not a string' => ['ipqs_api_key', 12];
    }

    /** @dataProvider refused */
    public function testAValueThePageWouldNotOfferIsRefused(string $column, mixed $value): void
    {
        [$clean, $errors] = self::validate([$column => $value]);
        self::assertArrayHasKey($column, $errors, "$column = " . var_export($value, true) . ' was accepted');
        self::assertSame([], $clean);
    }

    public function testAcceptedValuesAreNormalizedForTheWrite(): void
    {
        [$clean, $errors] = self::validate([
            'user_daily_email' => '',
            'user_pref_limit' => '100',
            'user_pref_dynamic_bid' => 1,
            'user_account_currency' => ' eur ',
            'user_tracking_domain' => '  track.example.com ',
            'user_ltv_score_weights' => 'recency:10,video:15,scroll:15,time:20,volume:40',
            'user_ltv_personalization_fields' => 'first_name, cf:plan ,first_name',
            'cb_key' => '',
        ]);
        self::assertSame([], $errors);
        self::assertSame([
            'user_daily_email' => '',
            'user_pref_limit' => 100,
            'user_pref_dynamic_bid' => 1,
            'user_account_currency' => 'EUR',
            'user_tracking_domain' => 'track.example.com',
            // The defaults in another order are still the defaults, stored as ''.
            'user_ltv_score_weights' => '',
            'user_ltv_personalization_fields' => 'first_name,cf:plan',
            'cb_key' => '',
        ], $clean);
        self::assertTrue(PreferenceRules::isInteger('user_pref_limit'));
        self::assertFalse(PreferenceRules::isInteger('user_daily_email'));

        [$clean] = self::validate(['user_ltv_score_weights' => 'recency:20,video:15,scroll:15,time:10,volume:40']);
        self::assertSame('volume:40,time:10,scroll:15,video:15,recency:20', $clean['user_ltv_score_weights'], 'stored in canonical order');
    }
}
