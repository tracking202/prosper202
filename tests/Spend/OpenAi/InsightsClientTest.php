<?php

declare(strict_types=1);

namespace Tests\Spend\OpenAi;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Spend\OpenAi\InsightsClient;
use Prosper202\Spend\OpenAi\InsightsFailure;

/**
 * GET /ad_account/insights as the spend sync asks it and reads the answer,
 * against answers shaped like OpenAI's OpenAPI spec 2.3.0 (InsightsResource;
 * tests/fixtures/openai-ads, not captured from a live account). Nothing
 * leaves the process: the transport is a stand-in.
 */
final class InsightsClientTest extends TestCase
{
    private const KEY = 'sk-adv-test-0123456789';

    /** @var list<array<int, mixed>> */
    private array $sent = [];

    /** @var list<array<string, mixed>> */
    private array $answers = [];

    private function client(): InsightsClient
    {
        $guard = new WebhookGuard(static fn (string $host): array => $host === InsightsClient::HOST ? ['203.0.113.20'] : [], '203.0.113.0/24');
        $transport = function (array $options): array {
            $this->sent[] = $options;

            return (array_shift($this->answers) ?? []) + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => ''];
        };

        return new InsightsClient(new WebhookSender($guard, $transport));
    }

    public static function fixture(string $name, int $hour): string
    {
        $text = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/openai-ads/' . $name);

        // Placeholders are strings so the file is JSON; a number takes the quotes' place.
        $text = (string) preg_replace_callback('/"@H(\d)@"/', static fn (array $m): string => (string) ($hour + 3600 * (int) $m[1]), $text);

        return (string) preg_replace_callback('/@H(\d)@/', static fn (array $m): string => (string) ($hour + 3600 * (int) $m[1]), $text);
    }

    public function testTheRequestIsHourlyPerAdWithItsWindowAndCursor(): void
    {
        $this->answers[] = ['body' => self::fixture('insights-hourly-page-1.json', 1794297600)];
        $page = $this->client()->page(self::KEY, 1794211200, 1794300000, 'start=1:end=2:entity_id=ad_9');

        $url = (string) $this->sent[0][CURLOPT_URL];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://api.ads.openai.com/v1/ad_account/insights?', $url);
        self::assertSame('ad', $query['aggregation_level']);
        self::assertSame('hourly', $query['time_granularity']);
        self::assertSame('2000', $query['limit']);
        self::assertSame(['{"type":"unix_range","start":1794211200,"end":1794300000}'], $query['time_ranges']);
        self::assertSame(InsightsClient::FIELDS, $query['fields']);
        self::assertSame('start=1:end=2:entity_id=ad_9', $query['after']);
        self::assertContains('Authorization: Bearer ' . self::KEY, $this->sent[0][CURLOPT_HTTPHEADER]);
        self::assertTrue($this->sent[0][CURLOPT_HTTPGET] ?? false);

        self::assertCount(2, $page['rows']);
        self::assertSame('ad_501', $page['rows'][0]['ad_id']);
        self::assertSame('start=1794297600:end=1794301200:entity_id=ad_502', $page['next']);
    }

    public function testTheLastPageHasNoCursor(): void
    {
        $this->answers[] = ['body' => self::fixture('insights-hourly-page-2.json', 1794297600)];
        self::assertNull($this->client()->page(self::KEY, 0, 1, null)['next']);
        self::assertStringNotContainsString('after=', (string) $this->sent[0][CURLOPT_URL]);
    }

    /** @return iterable<string, array{array<string, mixed>, string, int}> */
    public static function failures(): iterable
    {
        yield 'rate limited' => [['status' => 429, 'body' => '{}'], 'answered 429', InsightsFailure::RATE_LIMITED];
        yield 'key refused' => [['status' => 401, 'body' => '{}'], 'refused the Advertiser API key', InsightsFailure::KEY_REFUSED];
        yield 'invalid request, in its words' => [['status' => 400, 'body' => (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/openai-ads/error-invalid-request.json')],
            'answered 400: time_ranges must not be in the future', InsightsFailure::FAILED];
        yield 'not a page' => [['body' => '{"object":"list"}'], 'not a page of insights', InsightsFailure::FAILED];
        yield 'not JSON' => [['body' => '<html>'], 'not its JSON', InsightsFailure::FAILED];
        yield 'too many conversion rows' => [['status' => 413, 'body' => '{}'], 'answered 413', InsightsFailure::TOO_LARGE];
        yield 'more without a cursor' => [['body' => '{"object":"list","data":[],"count":0,"first_id":null,"last_id":null,"has_more":true}'], 'gave no cursor', InsightsFailure::FAILED];
        yield 'no answer' => [['status' => 0, 'error' => 'timed out after ' . self::KEY], 'no answer from OpenAI: timed out after [key]', InsightsFailure::FAILED];
        yield 'cut off' => [['body' => '{"object":"list","data":[],"has_more":false}', 'error' => 'the answer was longer than 2097152 bytes'], 'cut off', InsightsFailure::FAILED];
    }

    /**
     * @dataProvider failures
     * @param array<string, mixed> $answer
     */
    public function testAFailureIsNamedAndTheKeyIsNeverInIt(array $answer, string $said, int $code): void
    {
        $this->answers[] = $answer;
        try {
            $this->client()->page(self::KEY, 0, 1, null);
            self::fail('a failed page was answered as a page');
        } catch (InsightsFailure $e) {
            self::assertStringContainsString($said, $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertSame($code, $e->getCode());
        }
    }

    public function testAnAddressOutsideTheCheckIsNeverAsked(): void
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['10.0.0.5'], '');
        $client = new InsightsClient(new WebhookSender($guard, function (array $o): array {
            $this->sent[] = $o;

            return ['status' => 200, 'error' => null, 'primary_ip' => '10.0.0.5', 'location' => null, 'body' => '{}'];
        }));
        try {
            $client->page(self::KEY, 0, 1, null);
            self::fail('a private address was asked');
        } catch (InsightsFailure $e) {
            self::assertStringStartsWith('not sent:', $e->getMessage());
        }
        self::assertSame([], $this->sent);
    }
}
