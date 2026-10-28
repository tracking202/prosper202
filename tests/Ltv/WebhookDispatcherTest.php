<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Ltv\MysqlWebhookRepository;
use Prosper202\Ltv\WebhookDispatcher;

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

    /** @return iterable<string, array{0: string}> */
    public static function refusedUrls(): iterable
    {
        yield 'plain http' => ['http://hooks.example.com/p202'];
        yield 'loopback' => ['https://127.0.0.1/p202'];
        yield 'metadata' => ['https://169.254.169.254/latest'];
        yield 'private' => ['https://10.0.0.8/p202'];
        yield 'port' => ['https://8.8.8.8:8080/p202'];
    }

    /**
     * What the sender's guard says about each refusal is DeliverySenderTest's;
     * here: the dispatcher hands it back as an answer with no status, since
     * no request was made.
     *
     * @dataProvider refusedUrls
     */
    public function testThePostRefusesWhatTheGuardRefuses(string $url): void
    {
        $answer = WebhookDispatcher::post(self::delivery($url));

        self::assertFalse($answer[0]);
        self::assertNull($answer[1]);
        self::assertStringStartsWith('blocked: ', $answer[2]);
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
        $idle = [
            'delivered' => 0, 'failed' => 0, 'held' => 0, 'expired' => 0, 'deferred' => 0, 'taken' => 0,
            'more' => false,
        ];
        self::assertFalse(WebhookDispatcher::didWork($idle));
        self::assertSame('0 delivered, 0 failed or retrying', WebhookDispatcher::summary($idle));

        $busy = [
            'delivered' => 3, 'failed' => 1, 'held' => 4, 'expired' => 1000, 'deferred' => 2, 'taken' => 1,
            'more' => true,
        ];
        self::assertTrue(WebhookDispatcher::didWork($busy));
        self::assertSame(
            '3 delivered, 1 failed or retrying, 4 held for a dead webhook, 1000 expired unsent, 2 left for the next'
            . ' run (their webhook was slow to fail), 1 sent by another run; more due, next run',
            WebhookDispatcher::summary($busy)
        );
        foreach (['failed', 'held', 'expired', 'deferred', 'taken'] as $key) {
            self::assertTrue(WebhookDispatcher::didWork([$key => 1] + $idle), $key);
        }
    }
}
