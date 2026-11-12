<?php

declare(strict_types=1);

namespace Tests\AdPlatforms;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserAnswer;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;

/**
 * OpenAI's Advertiser API through the guarded sender, with a stubbed
 * resolver and transport (no network): the request it makes, how each
 * answer is sorted, that the key never comes back in a text, and the hold
 * to OpenAI's per-minute limits.
 */
final class AdvertiserApiTest extends TestCase
{
    private const KEY = 'sk-ads-live-Z9q7Xc2Vb8Nm4Lk1';

    /** @var list<array<int, mixed>> */
    private array $sent = [];
    /** @var list<array<string, mixed>> */
    private array $answers = [];
    private float $clock = 1000.0;

    private function api(): AdvertiserApi
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.20'], '203.0.113.0/24');
        $transport = function (array $options): array {
            $this->sent[] = $options;

            return (array_shift($this->answers) ?? []) + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => '{}'];
        };

        return new AdvertiserApi(new WebhookSender($guard, $transport), fn (): float => $this->clock);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/openai-ads/' . $name);
    }

    /** @return list<string> */
    private function headers(int $i = 0): array
    {
        return array_values((array) $this->sent[$i][CURLOPT_HTTPHEADER]);
    }

    public function testAGetIsSentToTheAdvertiserApiWithTheKeyInTheHeaderOnly(): void
    {
        $this->answers[] = ['body' => self::fixture('ad_account.json')];
        $answer = $this->api()->get(self::KEY, 'ad_account');

        self::assertTrue($answer->isOk());
        self::assertSame('adacct_123', $answer->json['id']);
        self::assertSame('https://api.ads.openai.com/v1/ad_account', $this->sent[0][CURLOPT_URL]);
        self::assertTrue($this->sent[0][CURLOPT_HTTPGET] ?? false);
        self::assertContains('Authorization: Bearer ' . self::KEY, $this->headers());
        self::assertStringNotContainsString(self::KEY, (string) $this->sent[0][CURLOPT_URL]);
        self::assertSame(['api.ads.openai.com:443:203.0.113.20'], $this->sent[0][CURLOPT_RESOLVE], 'pinned to the address the guard checked');
    }

    public function testACreateCarriesItsIdempotencyKeyAndJsonBody(): void
    {
        $this->answers[] = ['body' => self::fixture('pixel_created.json')];
        $answer = $this->api()->post(self::KEY, 'conversions/pixels', ['name' => 'Prosper202', 'client_type' => 'web'], 'p202-abc');

        self::assertSame('clidsrc_123', $answer->json['id']);
        self::assertSame(['name' => 'Prosper202', 'client_type' => 'web'], json_decode((string) $this->sent[0][CURLOPT_POSTFIELDS], true));
        self::assertContains('Idempotency-Key: p202-abc', $this->headers());
        self::assertContains('Content-Type: application/json', $this->headers());
    }

    /** @return iterable<string, array{int, string}> */
    public static function statuses(): iterable
    {
        yield '401' => [401, AdvertiserAnswer::KEY_REFUSED];
        yield '403' => [403, AdvertiserAnswer::KEY_REFUSED];
        yield '404' => [404, AdvertiserAnswer::NOT_FOUND];
        yield '429' => [429, AdvertiserAnswer::RATE_LIMITED];
        yield '400' => [400, AdvertiserAnswer::REFUSED];
        yield '409' => [409, AdvertiserAnswer::REFUSED];
        yield '500' => [500, AdvertiserAnswer::UNAVAILABLE];
        yield '503' => [503, AdvertiserAnswer::UNAVAILABLE];
    }

    /** @dataProvider statuses */
    public function testEachStatusIsSortedByItsCodeAndTheKeyIsTakenOutOfTheText(int $status, string $kind): void
    {
        // An echo of the key in the body must not reach the text shown.
        $this->answers[] = ['status' => $status, 'body' => '{"error":{"message":"Not found for key ' . self::KEY . '"}}'];
        $answer = $this->api()->get(self::KEY, 'conversions/pixels');

        self::assertSame($kind, $answer->kind);
        self::assertSame($status, $answer->status);
        self::assertStringContainsString('OpenAI answered ' . $status, (string) $answer->error);
        self::assertStringNotContainsString(self::KEY, (string) $answer->error);
    }

    public function testNoAnswerAnAnswerCutOffAndOneThatIsNotJsonAreUnavailable(): void
    {
        $this->answers = [
            ['status' => 0, 'error' => 'Connection timed out', 'body' => ''],
            ['body' => '{"id":', 'error' => 'the answer was longer than 2097152 bytes'],
            ['body' => '<html>gateway</html>'],
            ['body' => '[1,2]'],
        ];
        $api = $this->api();
        foreach (['no answer from OpenAI', 'cut off', 'not its JSON', 'not its JSON'] as $said) {
            $answer = $api->get(self::KEY, 'ad_account');
            self::assertSame(AdvertiserAnswer::UNAVAILABLE, $answer->kind, $said);
            self::assertStringContainsString($said, (string) $answer->error);
        }
    }

    public function testAnAddressTheGuardRefusesIsNotSent(): void
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['10.0.0.5']);
        $api = new AdvertiserApi(new WebhookSender($guard, function (array $o): array {
            $this->sent[] = $o;

            return ['status' => 200, 'error' => null, 'primary_ip' => '10.0.0.5', 'location' => null, 'body' => '{}'];
        }));
        $answer = $api->get(self::KEY, 'ad_account');

        self::assertSame(AdvertiserAnswer::UNAVAILABLE, $answer->kind);
        self::assertStringStartsWith('not sent: ', (string) $answer->error);
        self::assertSame([], $this->sent);
    }

    public function testAKeyWithSpacesIsNeverSent(): void
    {
        $answer = $this->api()->get("sk-ads one\n", 'ad_account');

        self::assertSame(AdvertiserAnswer::KEY_REFUSED, $answer->kind);
        self::assertSame([], $this->sent);
    }

    public function testRequestsAreHeldToSixHundredAMinutePerEndpointAndTwelveHundredInAll(): void
    {
        $api = $this->api();
        for ($i = 0; $i < AdvertiserApi::PER_ENDPOINT_PER_MINUTE; $i++) {
            self::assertTrue($api->get(self::KEY, 'campaigns/cmpn_' . ($i % 7))->isOk());
        }
        $held = $api->get(self::KEY, 'campaigns/cmpn_9');
        self::assertSame(AdvertiserAnswer::RATE_LIMITED, $held->kind, 'campaigns/{id} counts as one endpoint');
        self::assertCount(AdvertiserApi::PER_ENDPOINT_PER_MINUTE, $this->sent, 'the held request was not sent');
        self::assertTrue($api->get(self::KEY, 'ad_groups')->isOk(), 'another endpoint still has room');
        self::assertTrue($api->get('another-account-key-1', 'campaigns/cmpn_1')->isOk(), 'another key is another account');

        for ($i = 0; $i < 599; $i++) {
            $api->get(self::KEY, 'ads');
        }
        self::assertSame(AdvertiserAnswer::RATE_LIMITED, $api->get(self::KEY, 'audit_logs')->kind, '1,200 in all');

        $this->clock += 61.0;
        self::assertTrue($api->get(self::KEY, 'campaigns/cmpn_9')->isOk(), 'a minute later there is room again');
    }

    public function testTheEndpointNamesItsIdsAsOne(): void
    {
        self::assertSame('POST /campaigns/{id}/pause', AdvertiserApi::endpoint('POST', 'campaigns/cmpn_101/pause'));
        self::assertSame('GET /conversions/event_settings', AdvertiserApi::endpoint('GET', 'conversions/event_settings'));
        self::assertSame('GET /ad_account', AdvertiserApi::endpoint('GET', 'ad_account'));
    }
}
