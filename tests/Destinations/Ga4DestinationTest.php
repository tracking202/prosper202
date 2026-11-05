<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Destinations\ConversionFacts;
use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\Ga4Destination;

/**
 * The GA4 Measurement Protocol adapter: its settings, the body it builds,
 * and what each answer means, through the guarded sender with a stubbed
 * resolver and transport (no network).
 */
final class Ga4DestinationTest extends TestCase
{
    private const SECRET = 'Sx9-secretAPIvalue';
    private const NOW = 1_780_000_000;

    /** @var list<array<int, mixed>> */
    private array $sent = [];

    /** @param array<string, mixed> $answer */
    private function ga4(array $answer = []): Ga4Destination
    {
        // GA's host resolves to a documentation address the operator allowlist admits.
        $guard = new WebhookGuard(static fn (string $host): array => $host === Ga4Destination::HOST ? ['203.0.113.10'] : [], '203.0.113.0/24');
        $transport = function (array $options) use ($answer): array {
            $this->sent[] = $options;

            return $answer + ['status' => 204, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => ''];
        };

        return new Ga4Destination(new WebhookSender($guard, $transport));
    }

    /** @return array<string, mixed> */
    private function settings(array $over = []): array
    {
        [$settings] = $this->ga4()->validate($over + ['measurement_id' => 'g-abc123', 'params' => ['campaign' => 'utm_campaign']], null);

        return $settings + ['consent' => ['ad_user_data' => 'granted']];
    }

    private static function facts(int $convTime = self::NOW - 60, array $click = []): ConversionFacts
    {
        return new ConversionFacts(
            7,
            4242,
            30,
            $convTime,
            '19.90000',
            'USD',
            'ORD-1001',
            $click + ['c1' => '', 'utm_campaign' => 'spring-sale', 't202kw' => 'trail shoes'],
            88,
            [hash('sha256', 'a@example.com')]
        );
    }

    public function testSettingsAreNormalisedAndBadOnesNamed(): void
    {
        self::assertSame(
            ['measurement_id' => 'G-ABC123', 'event_name' => 'purchase', 'client_id_source' => 'click', 'user_id_source' => 'none', 'params' => []],
            $this->ga4()->validate(['measurement_id' => ' g-abc123 '], null)[0]
        );
        $refused = [
            ['settings.measurement_id', ['measurement_id' => 'UA-1234-1']],
            ['settings.event_name', ['measurement_id' => 'G-ABC123', 'event_name' => 'session_start']],
            ['settings.event_name', ['measurement_id' => 'G-ABC123', 'event_name' => ['purchase']]],
            ['settings.client_id_source', ['measurement_id' => 'G-ABC123', 'client_id_source' => 'cookie']],
            ['settings.params', ['measurement_id' => 'G-ABC123', 'params' => ['email' => 'customer_email']]],
        ];
        foreach ($refused as [$field, $settings]) {
            try {
                $this->ga4()->validate($settings, null);
                self::fail($field . ' was accepted: ' . json_encode($settings));
            } catch (DestinationInputException $e) {
                self::assertSame($field, $e->field);
            }
        }
        $this->expectException(DestinationInputException::class);
        $this->ga4()->validate(['measurement_id' => 'G-ABC123'], ['api_secret' => 'short']);
    }

    public function testTheBodyCarriesTheConversionAndHashesButNoRawData(): void
    {
        $body = Ga4Destination::body($this->settings(['user_id_source' => 'customer']), self::facts());
        self::assertSame('p202.4242', $body['client_id']);
        self::assertSame('p202c.88', $body['user_id'], 'a pseudonymous id, never the customer\'s ref');
        self::assertSame((self::NOW - 60) * 1_000_000, $body['timestamp_micros']);
        self::assertSame(['ad_user_data' => 'GRANTED'], $body['consent']);
        self::assertSame(['sha256_email_address' => [hash('sha256', 'a@example.com')]], $body['user_data']);
        self::assertSame([['name' => 'purchase', 'params' => ['currency' => 'USD', 'value' => 19.9, 'transaction_id' => 'ORD-1001', 'campaign' => 'spring-sale']]], $body['events']);
        self::assertStringNotContainsString('a@example.com', (string) json_encode($body));
        $summary = Ga4Destination::summary($body);
        self::assertSame(['sha256_email_address'], $summary['user_data'], 'the log names which identifiers went');
        self::assertStringNotContainsString(hash('sha256', 'a@example.com'), (string) json_encode($summary), 'and never their values');
        self::assertSame([true, true], [$summary['client_id'], $summary['user_id']], 'nor the client and user ids\' values');
        self::assertStringNotContainsString('p202.4242', (string) json_encode($summary));
    }

    public function testAUserIdIsSentOnlyForACustomerAndOnlyWhenAsked(): void
    {
        self::assertArrayNotHasKey('user_id', Ga4Destination::body($this->settings(), self::facts()), 'user_id_source none');
        $noCustomer = new ConversionFacts(7, 4242, 30, self::NOW - 60, '19.90000', 'USD', 'ORD-1001', ['c1' => ''], null);
        self::assertArrayNotHasKey('user_id', Ga4Destination::body($this->settings(['user_id_source' => 'customer']), $noCustomer));
    }

    public function testAClientIdFromAClickVariable(): void
    {
        $settings = $this->settings(['client_id_source' => 'c1']);
        self::assertSame('123.456', Ga4Destination::body($settings, self::facts(click: ['c1' => '123.456']))['client_id']);
        $this->expectException(DestinationInputException::class);
        Ga4Destination::body($settings, self::facts());
    }

    public function testASendPostsToCollectWithTheSecretInTheQueryOnly(): void
    {
        $ga4 = $this->ga4();
        $result = $ga4->send($this->settings(), ['api_secret' => self::SECRET], self::facts(), false, self::NOW);
        self::assertTrue($result->delivered);
        self::assertCount(1, $this->sent);
        self::assertSame('https://www.google-analytics.com/mp/collect?measurement_id=G-ABC123&api_secret=' . self::SECRET, $this->sent[0][CURLOPT_URL]);
        self::assertStringNotContainsString(self::SECRET, (string) $this->sent[0][CURLOPT_POSTFIELDS]);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($result->summary));
    }

    public function testAnswersAreSentFailedOrRetriedAndNeverQuoteTheSecret(): void
    {
        $cases = [
            'server error retries' => [['status' => 503, 'body' => 'busy'], false, true],
            'rate limit retries' => [['status' => 429], false, true],
            'bad request fails' => [['status' => 400, 'body' => 'api_secret=' . self::SECRET . ' is invalid'], false, false],
            'no answer retries' => [['status' => 0, 'error' => 'Operation timed out for ' . self::SECRET], false, true],
        ];
        foreach ($cases as $name => [$answer, $delivered, $retry]) {
            $result = $this->ga4($answer)->send($this->settings(), ['api_secret' => self::SECRET], self::facts(), false, self::NOW);
            self::assertSame([$delivered, $retry], [$result->delivered, $result->retry], $name);
            self::assertStringNotContainsString(self::SECRET, $result->reason, $name);
        }
        self::assertTrue($this->ga4(['status' => 0, 'error' => 'timeout'])->send($this->settings(), ['api_secret' => self::SECRET], self::facts(), false, self::NOW)->unanswered());
    }

    public function testAnOldConversionAndAPrivateAddressAreFailedUnsent(): void
    {
        $old = $this->ga4()->send($this->settings(), ['api_secret' => self::SECRET], self::facts(self::NOW - 73 * 3600), false, self::NOW);
        self::assertSame([false, false], [$old->delivered, $old->retry]);
        self::assertStringContainsString('72 hours', $old->reason);

        $guard = new WebhookGuard(static fn (): array => ['10.0.0.5'], '');
        $private = (new Ga4Destination(new WebhookSender($guard, function (array $o): array {
            $this->sent[] = $o;

            return ['status' => 204, 'error' => null, 'primary_ip' => null, 'location' => null, 'body' => ''];
        })))->send($this->settings(), ['api_secret' => self::SECRET], self::facts(), false, self::NOW);
        self::assertSame([false, false], [$private->delivered, $private->retry]);
        self::assertSame([], $this->sent, 'nothing is sent to a private address');
    }

    public function testAHostThatDoesNotResolveIsRetriedNotFailed(): void
    {
        $transport = function (array $o): array {
            $this->sent[] = $o;

            return ['status' => 204, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => ''];
        };
        $unresolved = (new Ga4Destination(new WebhookSender(new WebhookGuard(static fn (): array => [], ''), $transport)))
            ->send($this->settings(), ['api_secret' => self::SECRET], self::facts(), false, self::NOW);
        self::assertSame([false, true], [$unresolved->delivered, $unresolved->retry], $unresolved->reason);
        self::assertTrue($unresolved->unanswered(), 'the host is skipped for the rest of the run');
        self::assertStringContainsString('does not resolve', $unresolved->reason);
        self::assertStringNotContainsString(self::SECRET, $unresolved->reason);

        self::assertSame([], $this->sent, 'nothing was sent');
    }

    /**
     * The operator's allowlist is read when the guard is built at send
     * time; a constant, so its own process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAnAllowlistThatDoesNotParseIsRetriedNotFailed(): void
    {
        define('P202_WEBHOOK_ALLOW_NETWORKS', 'not-a-network');
        $transport = function (array $o): array {
            $this->sent[] = $o;

            return ['status' => 204, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => ''];
        };
        $result = (new Ga4Destination(new WebhookSender(null, $transport)))
            ->send($this->settings(), ['api_secret' => self::SECRET], self::facts(), false, self::NOW);
        self::assertSame([false, true], [$result->delivered, $result->retry], $result->reason);
        self::assertStringContainsString('P202_WEBHOOK_ALLOW_NETWORKS', $result->reason);
        self::assertSame([], $this->sent, 'nothing was sent');
    }

    public function testATestFireGoesToTheDebugEndpointAndReportsItsMessages(): void
    {
        $answer = ['status' => 200, 'body' => '{"validationMessages":[{"fieldPath":"events","description":"Event name is reserved.","validationCode":"NAME_RESERVED"}]}'];
        $result = $this->ga4($answer)->send($this->settings(), ['api_secret' => self::SECRET], ConversionFacts::sample(self::NOW, 'EUR'), true, self::NOW);
        self::assertStringStartsWith('https://www.google-analytics.com/debug/mp/collect?', $this->sent[0][CURLOPT_URL]);
        self::assertFalse($result->delivered);
        self::assertSame(['events: Event name is reserved.'], $result->messages);

        $clean = $this->ga4(['status' => 200, 'body' => '{"validationMessages":[]}'])->send($this->settings(), ['api_secret' => self::SECRET], ConversionFacts::sample(self::NOW, 'EUR'), true, self::NOW);
        self::assertTrue($clean->delivered);
        self::assertSame('EUR', $clean->summary['params']['currency']);
    }
}
