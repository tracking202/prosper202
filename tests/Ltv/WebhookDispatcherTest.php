<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Ltv\MysqlWebhookRepository;
use Prosper202\Ltv\WebhookDispatcher;
use Prosper202\Validation\OutboundUrlGuard;

/**
 * The dispatcher's parts that need no database: its POST refuses what the
 * guard refuses, before any request, as an answer and not a throw (the run
 * records it on the delivery's row and goes on); the headers a receiver
 * authenticates and de-duplicates by; and the line both cron entry points
 * print. WebhookDispatchIntegrationTest runs the rest on a real server.
 */
final class WebhookDispatcherTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function delivery(string $url): array
    {
        return [
            'delivery_id' => '42',
            'webhook_id' => '7',
            'event_name' => 'revenue.recorded',
            'payload' => '{"event":"revenue.recorded","occurred_at":1,"data":{"amount":49}}',
            'webhook_url' => $url,
            'webhook_secret' => 'the-secret',
        ];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refusedUrls(): iterable
    {
        yield 'plain http' => ['http://hooks.example.com/p202', 'blocked: webhook_url must be a valid https:// URL'];
        $private = 'blocked: webhook_url resolves to a private or reserved address';
        yield 'loopback' => ['https://127.0.0.1/p202', $private];
        yield 'metadata' => ['https://169.254.169.254/latest', $private];
        yield 'private' => ['https://10.0.0.8/p202', $private];
        yield 'port' => ['https://8.8.8.8:8080/p202', 'blocked: webhook_url port must be one of: 443, 8443'];
    }

    /** @dataProvider refusedUrls */
    public function testThePostRefusesWhatTheGuardRefuses(string $url, string $answer): void
    {
        self::assertSame([false, null, $answer], WebhookDispatcher::post(self::delivery($url)));
    }

    /**
     * The request carries the guard's whole set, as the guard gives it
     * (pinned to the approved address, no proxy, no redirects, https, TLS
     * verified, timeouts), beside the POST itself and nothing else.
     */
    public function testTheRequestCarriesTheGuardsWholeSet(): void
    {
        $delivery = self::delivery('https://hooks.example.com/p202');
        $guard = OutboundUrlGuard::curlOptions('https://hooks.example.com/p202', ['93.184.216.34']);

        $options = WebhookDispatcher::requestOptions($delivery, ['93.184.216.34']);

        foreach ($guard as $option => $value) {
            self::assertArrayHasKey($option, $options);
            self::assertSame($value, $options[$option], 'option ' . $option);
        }
        self::assertSame(['hooks.example.com:443:93.184.216.34'], $options[CURLOPT_RESOLVE]);
        self::assertSame([
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $delivery['payload'],
            CURLOPT_HTTPHEADER => WebhookDispatcher::headers($delivery),
            CURLOPT_RETURNTRANSFER => true,
        ], array_diff_key($options, $guard));

        $this->expectException(\Prosper202\Validation\OutboundUrlException::class);
        WebhookDispatcher::requestOptions($delivery, []);
    }

    public function testTheHeadersSignTheExactBody(): void
    {
        $delivery = self::delivery('https://hooks.example.com/p202');

        self::assertSame([
            'Content-Type: application/json',
            'X-P202-Signature: sha256=' . hash_hmac('sha256', (string) $delivery['payload'], 'the-secret'),
            'X-P202-Event: revenue.recorded',
            'X-P202-Delivery: 42',
            'User-Agent: Prosper202-LTV-Webhook/1.0',
        ], WebhookDispatcher::headers($delivery));
        self::assertSame(
            MysqlWebhookRepository::signature((string) $delivery['payload'], 'the-secret'),
            substr(WebhookDispatcher::headers($delivery)[1], strlen('X-P202-Signature: '))
        );
    }

    public function testTheSummarySaysWhatTheRunDid(): void
    {
        $idle = ['delivered' => 0, 'failed' => 0, 'expired' => 0, 'deferred' => 0, 'taken' => 0, 'more' => false];
        self::assertFalse(WebhookDispatcher::didWork($idle));
        self::assertSame('0 delivered, 0 failed or retrying', WebhookDispatcher::summary($idle));

        $busy = ['delivered' => 3, 'failed' => 1, 'expired' => 1000, 'deferred' => 2, 'taken' => 1, 'more' => true];
        self::assertTrue(WebhookDispatcher::didWork($busy));
        self::assertSame(
            '3 delivered, 1 failed or retrying, 1000 expired unsent, 2 left for the next run (their webhook was slow'
            . ' to fail), 1 sent by another run; more due, next run',
            WebhookDispatcher::summary($busy)
        );
        foreach (['failed', 'expired', 'deferred', 'taken'] as $key) {
            self::assertTrue(WebhookDispatcher::didWork([$key => 1] + $idle), $key);
        }
    }
}
