<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Destinations\ConversionFacts;
use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\GoogleAdsDestination;
use Prosper202\Destinations\SendResult;

/**
 * The Google Ads click-conversion adapter: its settings, the ClickConversion
 * it builds, and what each answer means, through the guarded sender with a
 * stubbed resolver and transport (no network).
 */
final class GoogleAdsDestinationTest extends TestCase
{
    private const NOW = 1_780_000_000;
    private const DEV = 'devTOKEN_abc123XYZ';
    private const CLIENT = '1234-abcd.apps.googleusercontent.com';
    private const SECRET = 'GOCSPX-clientSecret99';
    private const REFRESH = '1//0refreshTokenValue-xyz';
    private const ACCESS = 'ya29.accessTokenValue';

    /** @var list<array<int, mixed>> */
    private array $sent = [];
    /** @var list<array<string, mixed>> answers of the upload endpoint, in order (then 200 {}) */
    private array $uploads = [];
    /** @var list<array<string, mixed>> answers of the token endpoint, in order (then a token) */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->sent = [];
        $this->uploads = [];
        $this->tokens = [];
    }

    private function ads(): GoogleAdsDestination
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.10'], '203.0.113.0/24');
        $transport = function (array $options): array {
            $this->sent[] = $options;
            $token = str_contains((string) $options[CURLOPT_URL], 'oauth2.googleapis.com');
            $answer = ($token ? array_shift($this->tokens) : array_shift($this->uploads))
                ?? ($token ? ['body' => '{"access_token":"' . self::ACCESS . '","expires_in":3599}'] : ['body' => '{"results":[{}]}']);

            return $answer + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => ''];
        };

        return new GoogleAdsDestination(new WebhookSender($guard, $transport));
    }

    /** @return array<string, mixed> */
    private function settings(array $over = []): array
    {
        [$settings] = $this->ads()->validate($over + ['customer_id' => '123-456-7890', 'conversion_action' => '987654'], null);

        return $settings + ['consent' => ['ad_user_data' => 'granted', 'ad_personalization' => 'denied']];
    }

    /** @return array<string, string> */
    private static function credentials(bool $authorised = true): array
    {
        return ['developer_token' => self::DEV, 'oauth_client_id' => self::CLIENT, 'oauth_client_secret' => self::SECRET]
            + ($authorised ? ['refresh_token' => self::REFRESH] : []);
    }

    /**
     * @param array<string, string> $click
     * @param list<string> $emails
     */
    private static function facts(array $click = ['gclid' => 'Cj0KCQ-gclid'], array $emails = [], ?int $clickTime = self::NOW - 7 * 3600, string $zone = 'America/New_York', int $campaign = 30): ConversionFacts
    {
        return new ConversionFacts(
            7,
            4242,
            $campaign,
            self::NOW - 600,
            '19.90000',
            'USD',
            'ORD-1001',
            $click + ['gclid' => '', 'gbraid' => '', 'wbraid' => '', 'c1' => ''],
            88,
            $emails,
            [],
            $zone,
            $clickTime
        );
    }

    /** @return array<string, mixed> the upload body the transport was last handed */
    private function lastUpload(): array
    {
        foreach (array_reverse($this->sent) as $options) {
            if (str_contains((string) $options[CURLOPT_URL], 'googleads.googleapis.com')) {
                return json_decode((string) $options[CURLOPT_POSTFIELDS], true);
            }
        }
        self::fail('nothing was uploaded');
    }

    private static function refused(callable $call, string $field): void
    {
        try {
            $call();
            self::fail('refused: ' . $field);
        } catch (DestinationInputException $e) {
            self::assertSame($field, $e->field);
        }
    }

    public function testSettingsAreNormalisedAndBadOnesNamed(): void
    {
        self::assertSame(
            ['customer_id' => '1234567890', 'login_customer_id' => '1112223333', 'conversion_action' => '987654', 'campaign_conversion_actions' => [31 => 'customers/1112223333/conversionActions/55']],
            $this->ads()->validate(['customer_id' => ' 123-456-7890 ', 'login_customer_id' => '111-222-3333', 'conversion_action' => 987654, 'campaign_conversion_actions' => [31 => 'customers/1112223333/conversionActions/55']], null)[0],
            'the positive control: dashes stripped, an id or a resource name taken'
        );
        self::assertSame(['customer_id' => '1234567890', 'conversion_action' => '5'], $this->ads()->validate(['customer_id' => '1234567890', 'login_customer_id' => '', 'conversion_action' => '5'], null)[0]);
        $refused = [
            ['settings.customer_id', ['customer_id' => '123-456-789', 'conversion_action' => '5']],
            ['settings.customer_id', ['conversion_action' => '5']],
            ['settings.login_customer_id', ['customer_id' => '1234567890', 'login_customer_id' => 'mcc', 'conversion_action' => '5']],
            ['settings.conversion_action', ['customer_id' => '1234567890']],
            ['settings.conversion_action', ['customer_id' => '1234567890', 'conversion_action' => 'Purchase']],
            ['settings.conversion_action', ['customer_id' => '1234567890', 'conversion_action' => '0']],
            ['settings.campaign_conversion_actions', ['customer_id' => '1234567890', 'conversion_action' => '5', 'campaign_conversion_actions' => ['abc' => '6']]],
            ['settings.campaign_conversion_actions', ['customer_id' => '1234567890', 'conversion_action' => '5', 'campaign_conversion_actions' => [31 => 'customers/1/conversionActions/6']]],
        ];
        foreach ($refused as [$field, $settings]) {
            self::refused(fn () => $this->ads()->validate($settings, null), $field);
        }
    }

    public function testCredentialsNeedTheTokenAndTheClientAndNeverTakeATypedRefreshToken(): void
    {
        $settings = ['customer_id' => '1234567890', 'conversion_action' => '5'];
        self::assertSame(self::credentials(false), $this->ads()->validate($settings, self::credentials(false))[1], 'the positive control');
        foreach (['developer_token', 'oauth_client_id', 'oauth_client_secret'] as $missing) {
            $given = self::credentials(false);
            unset($given[$missing]);
            self::refused(fn () => $this->ads()->validate($settings, $given), 'credentials.' . $missing);
        }
        self::refused(fn () => $this->ads()->validate($settings, ['oauth_client_id' => 'not-a-client'] + self::credentials(false)), 'credentials.oauth_client_id');
        self::refused(fn () => $this->ads()->validate($settings, self::credentials(true)), 'credentials.refresh_token');
        self::assertNotContains('refresh_token', $this->ads()->credentialKeys());
    }

    public function testTheGrantIsKeptOnlyForTheSameClient(): void
    {
        $ads = $this->ads();
        self::assertSame(self::REFRESH, $ads->keptOnReplace(self::credentials(), ['developer_token' => 'newDevToken123'] + self::credentials(false))['refresh_token'] ?? null);
        self::assertArrayNotHasKey('refresh_token', $ads->keptOnReplace(self::credentials(), ['oauth_client_id' => 'other.apps.googleusercontent.com'] + self::credentials(false)));
        self::assertTrue($ads->authorised(self::credentials()));
        self::assertFalse($ads->authorised(self::credentials(false)));
    }

    public function testExactlyOneClickIdGoesInGooglesOrder(): void
    {
        $settings = $this->settings();
        $cases = [
            'gclid' => [['gclid' => 'G1', 'wbraid' => 'W1', 'gbraid' => 'B1'], 'G1'],
            'wbraid' => [['wbraid' => 'W1', 'gbraid' => 'B1'], 'W1'],
            'gbraid' => [['gbraid' => 'B1'], 'B1'],
        ];
        foreach ($cases as $type => [$click, $id]) {
            [$conversion, $clickType] = GoogleAdsDestination::conversion($settings, self::facts($click));
            self::assertSame($type, $clickType);
            self::assertSame($id, $conversion[$type]);
            self::assertSame([], array_intersect_key($conversion, array_diff_key(['gclid' => 1, 'wbraid' => 1, 'gbraid' => 1], [$type => 1])), $type . ' alone');
        }
    }

    public function testTheBodyCarriesTheConversionAndConsent(): void
    {
        [$conversion] = GoogleAdsDestination::conversion($this->settings(), self::facts());
        self::assertSame([
            'gclid' => 'Cj0KCQ-gclid',
            'conversionAction' => 'customers/1234567890/conversionActions/987654',
            'conversionDateTime' => '2026-05-28 16:16:40-04:00',
            'conversionValue' => 19.9,
            'currencyCode' => 'USD',
            'orderId' => 'ORD-1001',
            'consent' => ['adUserData' => 'GRANTED', 'adPersonalization' => 'DENIED'],
        ], $conversion);
        [$unstated] = GoogleAdsDestination::conversion(['consent' => []] + $this->settings(), self::facts());
        self::assertArrayNotHasKey('consent', $unstated, 'no consent stated, none sent');
        // A campaign with its own conversion action.
        [$mapped] = GoogleAdsDestination::conversion($this->settings(['campaign_conversion_actions' => [30 => '111']]), self::facts());
        self::assertSame('customers/1234567890/conversionActions/111', $mapped['conversionAction']);
    }

    public function testTheTimeIsTheAccountsZoneWithTheOffsetOfThatDay(): void
    {
        self::assertSame('2026-05-28 16:16:40-04:00', GoogleAdsDestination::dateTime(self::NOW - 600, 'America/New_York'));
        self::assertSame('2026-01-15 07:00:00-05:00', GoogleAdsDestination::dateTime(1_768_478_400, 'America/New_York'), 'winter: standard time');
        self::assertSame('2026-01-15 12:00:00+00:00', GoogleAdsDestination::dateTime(1_768_478_400, 'UTC'));
        self::assertSame('2026-01-15 17:30:00+05:30', GoogleAdsDestination::dateTime(1_768_478_400, 'Asia/Kolkata'));
        self::assertSame(1, preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d[+-]\d\d:\d\d\z/', GoogleAdsDestination::dateTime(self::NOW, 'Europe/London')));
    }

    public function testUserIdentifiersGoOnlyWhenHashedDataIsThereAndNotBesideABraid(): void
    {
        $hash = hash('sha256', 'jane@example.com');
        [$off] = GoogleAdsDestination::conversion($this->settings(), self::facts());
        self::assertArrayNotHasKey('userIdentifiers', $off, 'no hashes in the facts (send_user_data off, or consent denied), none sent');
        [$on] = GoogleAdsDestination::conversion($this->settings(), self::facts(emails: [$hash]));
        self::assertSame([['hashedEmail' => $hash]], $on['userIdentifiers']);
        [$braid] = GoogleAdsDestination::conversion($this->settings(), self::facts(['wbraid' => 'W1'], [$hash]));
        self::assertArrayNotHasKey('userIdentifiers', $braid, 'Google ignores them beside a wbraid alone');
        [$leadOnly, $type] = GoogleAdsDestination::conversion($this->settings(), self::facts([], [$hash]));
        self::assertSame(['', [['hashedEmail' => $hash]]], [$type, $leadOnly['userIdentifiers']], 'enhanced conversions for leads: identifiers without a click id');
    }

    public function testAConversionGoogleCannotMatchIsWithheldAndNothingIsSent(): void
    {
        self::assertSame([null, ''], GoogleAdsDestination::conversion($this->settings(), self::facts([])));
        $result = $this->ads()->send($this->settings(), self::credentials(), self::facts([]), false, self::NOW);
        self::assertTrue($result->withheld);
        self::assertFalse($result->retry);
        self::assertStringContainsString('nothing Google can match', $result->reason);
        self::assertSame([], $this->sent);
        // Positive control: the same conversion with a gclid is uploaded.
        self::assertTrue($this->ads()->send($this->settings(), self::credentials(), self::facts(), false, self::NOW)->delivered);
    }

    public function testASendUploadsWithTheTokenItMintsOncePerRun(): void
    {
        $ads = $this->ads();
        $result = $ads->send($this->settings(['login_customer_id' => '1112223333']), self::credentials(), self::facts(), false, self::NOW);
        self::assertTrue($result->delivered);
        self::assertCount(2, $this->sent, 'a token, then the upload');
        self::assertSame('https://oauth2.googleapis.com/token', $this->sent[0][CURLOPT_URL]);
        parse_str((string) $this->sent[0][CURLOPT_POSTFIELDS], $form);
        self::assertSame(['grant_type' => 'refresh_token', 'client_id' => self::CLIENT, 'client_secret' => self::SECRET, 'refresh_token' => self::REFRESH], $form);
        self::assertSame('https://googleads.googleapis.com/v26/customers/1234567890:uploadClickConversions', $this->sent[1][CURLOPT_URL]);
        $headers = $this->sent[1][CURLOPT_HTTPHEADER];
        self::assertContains('Authorization: Bearer ' . self::ACCESS, $headers);
        self::assertContains('developer-token: ' . self::DEV, $headers);
        self::assertContains('login-customer-id: 1112223333', $headers);
        $body = $this->lastUpload();
        self::assertTrue($body['partialFailure']);
        self::assertArrayNotHasKey('validateOnly', $body, 'a real upload is not validate-only');
        self::assertCount(1, $body['conversions']);
        self::assertSame(['conversion_action', 'click_id', 'conversion_date_time', 'value', 'currency', 'order_id', 'user_identifiers', 'consent', 'validate_only'], array_keys($result->summary));
        self::assertSame('gclid', $result->summary['click_id']);
        self::assertStringNotContainsString('Cj0KCQ', (string) json_encode($result->summary), 'the summary names the click id type, not its value');

        $ads->send($this->settings(), self::credentials(), self::facts(), false, self::NOW + 60);
        self::assertCount(3, $this->sent, 'the second send reuses the access token');
    }

    public function testAClickTooRecentWaitsUncountedAndAnOldOneFails(): void
    {
        $recent = $this->ads()->send($this->settings(), self::credentials(), self::facts(clickTime: self::NOW - 3600), false, self::NOW);
        self::assertSame([false, true, self::NOW - 3600 + 6 * 3600], [$recent->delivered, $recent->retry, $recent->waitUntil]);
        self::assertFalse($recent->destinationWaits);
        self::assertSame([], $this->sent, 'nothing is sent before Google takes it');

        $old = $this->ads()->send($this->settings(), self::credentials(), self::facts(clickTime: self::NOW - 91 * 86400), false, self::NOW);
        self::assertSame([false, false, null], [$old->delivered, $old->retry, $old->waitUntil]);
        self::assertStringContainsString('90 days', $old->reason);
        self::assertSame([], $this->sent);
    }

    public function testADestinationNotYetConnectedWaitsWithoutARequest(): void
    {
        $result = $this->ads()->send($this->settings(), self::credentials(false), self::facts(), false, self::NOW);
        self::assertSame([false, true, true, self::NOW + 3600], [$result->delivered, $result->retry, $result->destinationWaits, $result->waitUntil]);
        self::assertStringContainsString('not connected', $result->reason);
        self::assertSame([], $this->sent);
    }

    public function testARevokedGrantMakesTheDestinationWait(): void
    {
        $this->tokens = [['status' => 400, 'body' => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}']];
        $result = $this->ads()->send($this->settings(), self::credentials(), self::facts(), false, self::NOW);
        self::assertTrue($result->destinationWaits);
        self::assertSame(self::NOW + 3600, $result->waitUntil);
        self::assertStringContainsString('invalid_grant', $result->reason);
        self::assertCount(1, $this->sent, 'no upload without a token');

        $this->tokens = [['status' => 503, 'body' => 'busy']];
        $busy = $this->ads()->send($this->settings(), self::credentials(), self::facts(), false, self::NOW);
        self::assertSame([true, null, false], [$busy->retry, $busy->waitUntil, $busy->destinationWaits], 'a busy token endpoint is a plain retry');
    }

    /** @return array<string, mixed> */
    private static function partialFailure(string $code, string $message = 'm'): array
    {
        return ['status' => 200, 'body' => json_encode(['partialFailureError' => ['code' => 3, 'message' => $message, 'details' => [[
            '@type' => 'type.googleapis.com/google.ads.googleads.v26.errors.GoogleAdsFailure',
            'errors' => [['errorCode' => ['conversionUploadError' => $code], 'message' => $message]],
        ]]], 'results' => [new \stdClass()]])];
    }

    public function testPartialFailuresAndHttpErrorsMapToFailedOrPending(): void
    {
        $cases = [
            // [answer, delivered, retry, waits]
            'EXPIRED_EVENT' => [self::partialFailure('EXPIRED_EVENT'), false, false, false],
            'NO_CONVERSION_ACTION_FOUND' => [self::partialFailure('NO_CONVERSION_ACTION_FOUND'), false, false, false],
            'TOO_RECENT_EVENT' => [self::partialFailure('TOO_RECENT_EVENT'), false, true, true],
            'TOO_RECENT_CONVERSION_ACTION' => [self::partialFailure('TOO_RECENT_CONVERSION_ACTION'), false, true, true],
            'ORDER_ID_ALREADY_IN_USE' => [self::partialFailure('ORDER_ID_ALREADY_IN_USE'), true, false, false],
            'HTTP 503' => [['status' => 503, 'body' => '{"error":{"code":503,"message":"unavailable"}}'], false, true, false],
            '429 quota' => [['status' => 429, 'body' => '{"error":{"code":429,"details":[{"errors":[{"errorCode":{"quotaError":"RESOURCE_EXHAUSTED"},"message":"q"}]}]}}'], false, true, false],
            'HTTP 400' => [['status' => 400, 'body' => '{"error":{"code":400,"details":[{"errors":[{"errorCode":{"requestError":"INVALID_CUSTOMER_ID"},"message":"bad"}]}]}}'], false, false, false],
            'HTTP 403' => [['status' => 403, 'body' => '{"error":{"code":403,"details":[{"errors":[{"errorCode":{"authorizationError":"DEVELOPER_TOKEN_NOT_APPROVED"},"message":"no"}]}]}}'], false, false, false],
            'no answer' => [['status' => 0, 'error' => 'Operation timed out'], false, true, false],
        ];
        foreach ($cases as $name => [$answer, $delivered, $retry, $waits]) {
            $this->uploads = [$answer];
            $result = $this->ads()->send($this->settings(), self::credentials(), self::facts(), false, self::NOW);
            self::assertSame([$delivered, $retry, $waits], [$result->delivered, $result->retry, $result->waitUntil !== null], $name);
            self::assertFalse($result->destinationWaits, $name);
        }
        // "Too recent" a day after the click is a counted retry, not another wait.
        $this->uploads = [self::partialFailure('TOO_RECENT_EVENT')];
        $late = $this->ads()->send($this->settings(), self::credentials(), self::facts(clickTime: self::NOW - 30 * 3600), false, self::NOW);
        self::assertSame([true, null], [$late->retry, $late->waitUntil]);
        $this->uploads = [self::partialFailure('EXPIRED_EVENT', 'The click is too old')];
        self::assertSame('Google Ads refused it: EXPIRED_EVENT: The click is too old', $this->ads()->send($this->settings(), self::credentials(), self::facts(), false, self::NOW)->reason);
        $this->uploads = [['status' => 0, 'error' => 'timed out']];
        self::assertTrue($this->ads()->send($this->settings(), self::credentials(), self::facts(), false, self::NOW)->unanswered(), 'a host that gave no answer is skipped for the run');
        $this->uploads = [['status' => 403, 'body' => '{"error":{"code":403,"details":[{"errors":[{"errorCode":{"authorizationError":"CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE"},"message":"no"}]}]}}']];
        self::assertStringContainsString('17 December 2025', $this->ads()->send($this->settings(), self::credentials(), self::facts(), false, self::NOW)->reason);
    }

    public function testNoSecretIsInAnyReason(): void
    {
        $echo = self::DEV . ' ' . self::SECRET . ' ' . self::REFRESH . ' ' . self::ACCESS . ' ' . rawurlencode(self::REFRESH);
        $results = [];
        $this->uploads = [['status' => 400, 'body' => '{"error":{"code":400,"message":"' . $echo . '"}}']];
        $results[] = $this->ads()->send($this->settings(), self::credentials(), self::facts(), false, self::NOW);
        $this->uploads = [self::partialFailure('INVALID_USER_IDENTIFIER', $echo)];
        $results[] = $this->ads()->send($this->settings(), self::credentials(), self::facts(), true, self::NOW);
        // The token endpoint cannot know an access token it has not minted.
        $this->tokens = [['status' => 401, 'body' => '{"error":"invalid_client","error_description":"' . str_replace(self::ACCESS, '', $echo) . '"}']];
        $results[] = $this->ads()->send($this->settings(), self::credentials(), self::facts(), false, self::NOW);
        $this->uploads = [['status' => 0, 'error' => 'failed for ' . $echo]];
        $results[] = $this->ads()->send($this->settings(), self::credentials(), self::facts(), false, self::NOW);
        foreach ($results as $i => $result) {
            $text = $result->reason . ' ' . implode(' ', $result->messages) . ' ' . json_encode($result->summary);
            self::assertStringContainsString('…', $text, 'case ' . $i . ' quoted the answer (the positive control)');
            foreach ([self::DEV, self::SECRET, self::REFRESH, self::ACCESS, rawurlencode(self::REFRESH)] as $secret) {
                self::assertStringNotContainsString($secret, $text, 'case ' . $i);
            }
        }
    }

    public function testATestFireIsValidateOnlyAndPassesWhenOnlyItsMadeUpClickIsRefused(): void
    {
        $sample = ConversionFacts::sample(self::NOW, 'USD', 'UTC');
        $this->uploads = [self::partialFailure('UNPARSEABLE_GCLID')];
        $result = $this->ads()->send($this->settings(), self::credentials(), $sample, true, self::NOW);
        self::assertTrue($result->delivered, 'only the made-up click was refused');
        $body = $this->lastUpload();
        self::assertTrue($body['validateOnly']);
        self::assertSame(GoogleAdsDestination::TEST_GCLID, $body['conversions'][0]['gclid']);
        self::assertSame('2026-05-28 20:26:40+00:00', $body['conversions'][0]['conversionDateTime']);

        $this->uploads = [self::partialFailure('NO_CONVERSION_ACTION_FOUND', 'The conversion action cannot be found.')];
        $bad = $this->ads()->send($this->settings(), self::credentials(), $sample, true, self::NOW);
        self::assertFalse($bad->delivered);
        self::assertSame(['NO_CONVERSION_ACTION_FOUND: The conversion action cannot be found.'], $bad->messages);

        $this->uploads = [['status' => 403, 'body' => '{"error":{"code":403,"details":[{"errors":[{"errorCode":{"authorizationError":"USER_PERMISSION_DENIED"},"message":"denied"}]}]}}']];
        $denied = $this->ads()->send($this->settings(), self::credentials(), $sample, true, self::NOW);
        self::assertSame([false, false], [$denied->delivered, $denied->retry]);
        self::assertSame(['USER_PERMISSION_DENIED: denied'], $denied->messages);

        $unconnected = $this->ads()->send($this->settings(), self::credentials(false), $sample, true, self::NOW);
        self::assertFalse($unconnected->delivered);
        self::assertStringContainsString('not connected', $unconnected->reason);
    }

    public function testAnswerIsReadWithoutTheNetwork(): void
    {
        $facts = self::facts();
        self::assertTrue(GoogleAdsDestination::answer(200, '{"results":[{"gclid":"x"}]}', false, $facts, self::NOW)->delivered);
        $notJson = GoogleAdsDestination::answer(200, '<html>', false, $facts, self::NOW);
        self::assertSame([false, true], [$notJson->delivered, $notJson->retry], 'an answer that is not JSON is retried; orderId makes that safe');
        $generic = GoogleAdsDestination::answer(200, '{"partialFailureError":{"code":3,"message":"something"}}', false, $facts, self::NOW);
        self::assertSame([false, false, 'Google Ads refused it: something'], [$generic->delivered, $generic->retry, $generic->reason]);
        self::assertInstanceOf(SendResult::class, $generic);
    }
}
