<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Destinations\ConversionFacts;
use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\MinorUnits;
use Prosper202\Destinations\OpenAiAdsDestination;
use Prosper202\Destinations\PiiHash;

/**
 * The ChatGPT Ads (OpenAI Conversions API) adapter: its settings, the body it
 * builds, which conversions it holds back, and what each answer means,
 * through the guarded sender with a stubbed resolver and transport (no
 * network, no OpenAI account).
 */
final class OpenAiAdsDestinationTest extends TestCase
{
    private const KEY = 'oai-capi-Key_0123456789abcdef';
    private const NOW = 1_780_000_000;
    private const OPPREF = 'opp_7Hq2-Xk9_aZ';

    /** @var list<array<int, mixed>> */
    private array $sent = [];

    /** @param array<string, mixed> $answer */
    private function oai(array $answer = [], string $resolvesTo = '203.0.113.10'): OpenAiAdsDestination
    {
        $guard = new WebhookGuard(static fn (string $host): array => $host === OpenAiAdsDestination::HOST ? [$resolvesTo] : [], '203.0.113.0/24');
        $transport = function (array $options) use ($answer): array {
            $this->sent[] = $options;

            return $answer + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => '{}'];
        };

        return new OpenAiAdsDestination(new WebhookSender($guard, $transport));
    }

    /** @return array<string, mixed> */
    private function settings(array $over = []): array
    {
        [$settings] = $this->oai()->validate($over + ['pixel_id' => 'px_4821'], null);

        return $settings;
    }

    /** @param array<string, string> $click */
    private static function facts(
        array $click = ['oppref' => self::OPPREF],
        int $convTime = self::NOW - 600,
        string $value = '89.00000',
        string $currency = 'USD',
        ?string $page = 'https://shop.example.com/checkout/done',
        array $emails = [],
        ?string $ip = null,
        ?int $customer = null,
        int $campaign = 30,
        int $source = 70,
        bool $restates = false,
    ): ConversionFacts {
        return new ConversionFacts(
            7, 4242, $campaign, $convTime, $value, $currency, 'ORD-1001',
            $click + ['c1' => '', 'gclid' => ''], $customer, $emails, [], 'UTC', self::NOW - 3600,
            $ip, null, $page, $source, $restates
        );
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

    /** @return array<string, mixed> the last body the transport was handed */
    private function lastBody(): array
    {
        self::assertNotSame([], $this->sent, 'something was sent');

        return json_decode((string) end($this->sent)[CURLOPT_POSTFIELDS], true, 16, JSON_THROW_ON_ERROR);
    }

    public function testSettingsAreNormalisedWithOpprefTheDefaultScope(): void
    {
        self::assertSame(
            [['pixel_id' => 'px_4821', 'event_type' => 'order_created', 'scope' => 'oppref', 'event_types' => []], null],
            $this->oai()->validate(['pixel_id' => ' px_4821 '], null)
        );
        self::assertSame(
            ['pixel_id' => 'px_4821', 'event_type' => 'custom', 'scope' => 'source', 'event_types' => [31 => 'lead_created'], 'custom_event_name' => 'demo_booked', 'ppc_account_id' => 70],
            $this->settings(['event_type' => 'custom', 'custom_event_name' => 'demo_booked', 'scope' => 'source', 'ppc_account_id' => 70, 'event_types' => ['31' => 'lead_created']])
        );
        self::assertSame(['conversions_api_key' => self::KEY], $this->oai()->validate(['pixel_id' => 'px_4821'], ['conversions_api_key' => ' ' . self::KEY . ' '])[1]);
        self::assertFalse($this->oai()->takesConsent(), 'the Google consent pair does not apply');
        self::assertSame(7, $this->oai()->backfillDays());
    }

    public function testEveryBadSettingIsRefusedNamingItsField(): void
    {
        $refused = [
            ['settings.pixel_id', ['pixel_id' => '']],
            ['settings.pixel_id', ['pixel_id' => 'has space']],
            ['settings.pixel_id', ['pixel_id' => ['px']]],
            ['settings.event_type', ['pixel_id' => 'p', 'event_type' => 'purchase']],
            ['settings.custom_event_name', ['pixel_id' => 'p', 'event_type' => 'custom']],
            ['settings.custom_event_name', ['pixel_id' => 'p', 'event_type' => 'custom', 'custom_event_name' => 'Demo Booked']],
            ['settings.custom_event_name', ['pixel_id' => 'p', 'event_type' => 'custom', 'custom_event_name' => 'order_created']],
            ['settings.custom_event_name', ['pixel_id' => 'p', 'event_type' => 'custom', 'custom_event_name' => str_repeat('a', 65)]],
            ['settings.custom_event_name', ['pixel_id' => 'p', 'custom_event_name' => 'demo_booked']],
            ['settings.scope', ['pixel_id' => 'p', 'scope' => 'everything']],
            ['settings.ppc_account_id', ['pixel_id' => 'p', 'scope' => 'source']],
            ['settings.ppc_account_id', ['pixel_id' => 'p', 'scope' => 'source', 'ppc_account_id' => '70']],
            ['settings.ppc_account_id', ['pixel_id' => 'p', 'scope' => 'all', 'ppc_account_id' => 70]],
            ['settings.event_types', ['pixel_id' => 'p', 'event_types' => 'lead_created']],
            ['settings.event_types', ['pixel_id' => 'p', 'event_types' => ['31' => 'custom']]],
            ['settings.event_types', ['pixel_id' => 'p', 'event_types' => ['31' => 'purchase']]],
            ['settings.event_types', ['pixel_id' => 'p', 'event_types' => ['abc' => 'lead_created']]],
        ];
        foreach ($refused as [$field, $settings]) {
            self::refused(fn () => $this->oai()->validate($settings, null), $field);
        }
        self::refused(fn () => $this->oai()->validate(['pixel_id' => 'p'], ['conversions_api_key' => 'short']), 'credentials.conversions_api_key');
        self::refused(fn () => $this->oai()->validate(['pixel_id' => 'p'], ['conversions_api_key' => 'has a space in it 0123456789']), 'credentials.conversions_api_key');
        self::refused(fn () => $this->oai()->validate(['pixel_id' => 'p'], []), 'credentials.conversions_api_key');
    }

    public function testTheBodyIsOneWebEventInMinorUnitsUnderTheSharedId(): void
    {
        $result = $this->oai()->send($this->settings(), ['conversions_api_key' => self::KEY], self::facts(), false, self::NOW);
        self::assertTrue($result->delivered);
        self::assertSame('https://bzr.openai.com/v1/events?pid=px_4821', $this->sent[0][CURLOPT_URL]);
        self::assertContains('Authorization: Bearer ' . self::KEY, $this->sent[0][CURLOPT_HTTPHEADER]);
        self::assertSame([
            'integration_source' => 'prosper202',
            'events' => [[
                'id' => 'ORD-1001',
                'type' => 'order_created',
                'timestamp_ms' => (self::NOW - 600) * 1000,
                'action_source' => 'web',
                'source_url' => 'https://shop.example.com/checkout/done',
                'oppref' => self::OPPREF,
                'data' => ['type' => 'contents', 'amount' => 8900, 'currency' => 'USD'],
            ]],
        ], $this->lastBody());
        self::assertStringNotContainsString(self::KEY, (string) $this->sent[0][CURLOPT_POSTFIELDS], 'the key is in the header only');
        self::assertSame(['event' => 'order_created', 'id' => 'ORD-1001', 'amount' => 8900, 'currency' => 'USD', 'oppref' => true, 'user' => []], $result->summary);
        self::assertStringNotContainsString(self::OPPREF, (string) json_encode($result->summary), 'the log keeps no click id\'s value');
    }

    public function testTheDataTypeFollowsTheEventTypeAndACampaignCanHaveItsOwn(): void
    {
        $settings = $this->settings(['event_type' => 'trial_started', 'event_types' => ['31' => 'lead_created']]);
        $event = OpenAiAdsDestination::body($settings, self::facts())['events'][0];
        self::assertSame(['trial_started', 'plan_enrollment'], [$event['type'], $event['data']['type']]);
        $event = OpenAiAdsDestination::body($settings, self::facts(campaign: 31))['events'][0];
        self::assertSame(['lead_created', 'customer_action'], [$event['type'], $event['data']['type']]);
        $custom = OpenAiAdsDestination::body($this->settings(['event_type' => 'custom', 'custom_event_name' => 'demo_booked']), self::facts())['events'][0];
        self::assertSame(['custom', 'demo_booked', 'custom'], [$custom['type'], $custom['custom_event_name'], $custom['data']['type']]);
        foreach (OpenAiAdsDestination::EVENT_TYPES as $type => $dataType) {
            self::assertContains($dataType, ['contents', 'customer_action', 'plan_enrollment', 'custom'], $type);
        }
    }

    public function testAmountsAreInTheCurrencysMinorUnitAndAnUnknownCurrencyIsRefused(): void
    {
        self::assertSame([8900, 8900, 1250, 29, 1, 0], [
            MinorUnits::of('89.00', 'USD'), MinorUnits::of('8900', 'JPY'), MinorUnits::of('1.25', 'KWD'),
            MinorUnits::of('0.29', 'EUR'), MinorUnits::of('0.005', 'USD'), MinorUnits::of('0.004', 'USD'),
        ]);
        self::assertSame(8901, MinorUnits::of('8900.5', 'JPY'), 'half up at the exponent');
        self::refused(static fn () => MinorUnits::of('1.00', 'XYZ'), 'currency');
        self::refused(static fn () => MinorUnits::of('-1.00', 'USD'), 'amount');
        self::refused(static fn () => MinorUnits::of('1e3', 'USD'), 'amount');

        $zero = OpenAiAdsDestination::body($this->settings(), self::facts(value: '0.00000'))['events'][0]['data'];
        self::assertSame(['type' => 'contents'], $zero, 'a zero amount leaves out amount and currency');

        $result = $this->oai()->send($this->settings(), ['conversions_api_key' => self::KEY], self::facts(currency: 'XYZ'), false, self::NOW);
        self::assertSame([false, false], [$result->delivered, $result->retry]);
        self::assertStringContainsString('"XYZ"', $result->reason);
        self::assertSame([], $this->sent, 'nothing guessed, nothing sent');
    }

    public function testUserDataIsHashedByOpenAisRulesAndTheAddressGoesRaw(): void
    {
        $email = PiiHash::emailPlain(' Jane.Doe+promo@Gmail.com ');
        self::assertSame(hash('sha256', 'jane.doe+promo@gmail.com'), $email, 'trimmed and lowercased only');
        self::assertNotSame(PiiHash::email('jane.doe+promo@gmail.com'), $email, 'not Google\'s Gmail folding');
        self::assertSame(hash('sha256', '15551234567'), PiiHash::phoneDigits('+1 (555) 123-4567'), 'E.164 digits without the +');
        self::assertNull(PiiHash::phoneDigits('555 123 4567'), 'no country code is not guessed');
        self::assertNull(PiiHash::emailPlain('not-an-email'));
        self::assertSame($email, $this->oai()->hashEmail(' Jane.Doe+promo@Gmail.com '));

        $user = OpenAiAdsDestination::body($this->settings(), self::facts(emails: [(string) $email], ip: '203.0.113.7', customer: 88))['events'][0]['user'];
        self::assertSame([
            'emails_sha256' => [$email],
            'external_ids_sha256' => [hash('sha256', 'p202c.88')],
            'ip_address' => '203.0.113.7',
        ], $user);
        self::assertArrayNotHasKey('user', OpenAiAdsDestination::body($this->settings(), self::facts())['events'][0], 'no user data, no user object');
    }

    public function testScopeHoldsBackWhatTheDestinationDoesNotSend(): void
    {
        $send = fn (array $settings, ConversionFacts $facts) => $this->oai()->send($this->settings($settings), ['conversions_api_key' => self::KEY], $facts, false, self::NOW);

        $none = $send([], self::facts(click: []));
        self::assertSame([false, true], [$none->delivered, $none->withheld]);
        self::assertStringContainsString('oppref', $none->reason);
        self::assertSame([], $this->sent, 'scope oppref sends nothing for a click without one');

        self::assertTrue($send(['scope' => 'all'], self::facts(click: []))->delivered, 'scope all sends it');
        self::assertArrayNotHasKey('oppref', $this->lastBody()['events'][0]);

        self::assertTrue($send(['scope' => 'source', 'ppc_account_id' => 70], self::facts(click: [], source: 70))->delivered);
        $other = $send(['scope' => 'source', 'ppc_account_id' => 70], self::facts(source: 71));
        self::assertTrue($other->withheld, 'another traffic source is held back even with an oppref');
        self::assertCount(2, $this->sent);
    }

    public function testTooOldTooFarAheadAndNoPageAreFailedUnsentAndNeverRetried(): void
    {
        $send = fn (ConversionFacts $facts) => $this->oai()->send($this->settings(), ['conversions_api_key' => self::KEY], $facts, false, self::NOW);
        $old = $send(self::facts(convTime: self::NOW - 7 * 86400 - 1));
        self::assertSame([false, false, false], [$old->delivered, $old->retry, $old->withheld]);
        self::assertStringContainsString('older than OpenAI accepts', $old->reason);
        self::assertTrue($send(self::facts(convTime: self::NOW - 7 * 86400))->delivered, 'the positive control: exactly 7 days is taken');

        $ahead = $send(self::facts(convTime: self::NOW + 601));
        self::assertSame([false, false], [$ahead->delivered, $ahead->retry]);
        self::assertStringContainsString('10 minutes ahead', $ahead->reason);

        $noPage = $send(self::facts(page: null));
        self::assertSame([false, false], [$noPage->delivered, $noPage->retry]);
        self::assertStringContainsString('page', $noPage->reason);
        self::assertCount(1, $this->sent, 'only the positive control went');
    }

    public function testEachAnswerIsSentFailedOrRetriedAndNeverQuotesTheKey(): void
    {
        $cases = [
            [['status' => 200, 'body' => '{}'], true, false, null],
            [['status' => 202, 'body' => ''], true, false, null],
            [['status' => 400, 'body' => '{"error":{"message":"events[0].source_url is required for key ' . self::KEY . '"}}'], false, false, 'OpenAI refused the event: events[0].source_url is required'],
            [['status' => 401, 'body' => '{"error":{"message":"invalid key"}}'], false, false, 'the Conversions API key was refused (401'],
            [['status' => 403, 'body' => ''], false, false, 'the Conversions API key was refused (403)'],
            [['status' => 404, 'body' => 'not found'], false, false, 'OpenAI answered 404'],
            [['status' => 408, 'body' => ''], false, true, 'OpenAI answered 408'],
            [['status' => 429, 'body' => 'slow down'], false, true, 'OpenAI answered 429'],
            [['status' => 503, 'body' => 'busy'], false, true, 'OpenAI answered 503'],
            [['status' => 0, 'error' => 'Operation timed out', 'primary_ip' => ''], false, true, 'no answer: Operation timed out'],
        ];
        foreach ($cases as [$answer, $delivered, $retry, $reason]) {
            $result = $this->oai($answer)->send($this->settings(), ['conversions_api_key' => self::KEY], self::facts(), false, self::NOW);
            $why = (string) $answer['status'];
            self::assertSame([$delivered, $retry], [$result->delivered, $result->retry], $why);
            if ($reason !== null) {
                self::assertStringContainsString($reason, $result->reason, $why);
            }
            self::assertStringNotContainsString(self::KEY, $result->reason . json_encode($result->summary) . implode(' ', $result->messages), $why);
        }
        self::assertTrue($this->oai(['status' => 0, 'error' => 'timed out', 'primary_ip' => ''])->send($this->settings(), ['conversions_api_key' => self::KEY], self::facts(), false, self::NOW)->unanswered());
    }

    public function testAnAddressTheGuardRefusesIsNotSent(): void
    {
        $result = $this->oai([], '10.0.0.5')->send($this->settings(), ['conversions_api_key' => self::KEY], self::facts(), false, self::NOW);
        self::assertFalse($result->delivered);
        self::assertSame([], $this->sent);
    }

    public function testATestFireValidatesOnlyAndIgnoresScope(): void
    {
        $sample = ConversionFacts::sample(self::NOW, 'USD');
        $result = $this->oai()->send($this->settings(), ['conversions_api_key' => self::KEY], $sample, true, self::NOW);
        self::assertTrue($result->delivered, 'the sample click has no oppref, and a test fire is still sent');
        $body = $this->lastBody();
        self::assertTrue($body['validate_only']);
        self::assertSame(self::NOW * 1000, $body['events'][0]['timestamp_ms']);
        self::assertSame('https://shop.example.com/checkout/done', $body['events'][0]['source_url']);
        self::assertArrayNotHasKey('validate_only', OpenAiAdsDestination::body($this->settings(), self::facts()), 'a real send is saved');

        $refused = $this->oai(['status' => 400, 'body' => '{"error":{"message":"pixel not found"}}'])->send($this->settings(), ['conversions_api_key' => self::KEY], $sample, true, self::NOW);
        self::assertSame([false, ['pixel not found']], [$refused->delivered, $refused->messages]);
    }

    public function testARestatementSaysOpenAiKeepsTheFirstEvent(): void
    {
        $result = $this->oai()->send($this->settings(), ['conversions_api_key' => self::KEY], self::facts(restates: true), false, self::NOW);
        self::assertTrue($result->delivered);
        self::assertStringContainsString('keeps it', (string) ($result->summary['note'] ?? ''));
    }

    public function testTheClickIdIsReadThroughOneAccessorUnmodified(): void
    {
        self::assertSame(self::OPPREF, OpenAiAdsDestination::oppref(self::facts()));
        self::assertSame('', OpenAiAdsDestination::oppref(self::facts(click: [])));
        self::assertSame('', OpenAiAdsDestination::oppref(self::facts(click: ['oppref' => '   '])));
    }
}
