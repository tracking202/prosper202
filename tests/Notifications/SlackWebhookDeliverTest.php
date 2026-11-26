<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Notifications\PostbackResult;
use Prosper202\Notifications\SlackWebhook;

/**
 * YS8E: what the Slack notice queue does with an answer comes from
 * SlackWebhook::deliver(): tried again only where a later try could go
 * differently, and never with the URL's path in the reason.
 */
final class SlackWebhookDeliverTest extends TestCase
{
    private const SECRET = '/services/T0/B0/deliverSecretPath';

    /** @var list<array<int, mixed>> */
    private array $sent = [];

    /** @param array<string, mixed> $answer */
    private function webhook(array $answer, ?callable $resolve = null): SlackWebhook
    {
        $transport = function (array $options) use ($answer): array {
            $this->sent[] = $options;

            return $answer
                + ['status' => 200, 'error' => null, 'primary_ip' => null, 'location' => null, 'body' => 'ok'];
        };
        $resolve ??= static fn (string $host): array => $host === 'hooks.slack.com' ? ['93.184.216.34'] : [];

        return new SlackWebhook(new WebhookSender(new WebhookGuard($resolve, ''), $transport));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: int|null, 3: string}> */
    public static function answers(): array
    {
        return [
            '200' => [['status' => 200], PostbackResult::DELIVERED, 200, ''],
            '204' => [['status' => 204], PostbackResult::DELIVERED, 204, ''],
            'no answer' => [
                ['status' => 0, 'error' => 'Operation timed out'],
                PostbackResult::FAILED,
                null,
                'no answer: Operation timed out',
            ],
            '408' => [['status' => 408], PostbackResult::FAILED, 408, 'the webhook answered 408'],
            '429' => [['status' => 429], PostbackResult::FAILED, 429, 'the webhook answered 429'],
            '500' => [['status' => 500], PostbackResult::FAILED, 500, 'the webhook answered 500'],
            '503' => [['status' => 503], PostbackResult::FAILED, 503, 'the webhook answered 503'],
            'a redirect' => [['status' => 302], PostbackResult::REFUSED, null, 'a redirect, which is not followed'],
            'a removed webhook' => [
                ['status' => 404, 'body' => 'no_service'],
                PostbackResult::REFUSED,
                null,
                'the webhook answered 404: no_service',
            ],
            'a revoked webhook' => [
                ['status' => 403, 'body' => 'invalid_token'],
                PostbackResult::REFUSED,
                null,
                'the webhook answered 403',
            ],
            'a bad request' => [
                ['status' => 400, 'body' => 'bad https://hooks.slack.com' . self::SECRET],
                PostbackResult::REFUSED,
                null,
                'bad https://hooks.slack.com/…',
            ],
            'landed elsewhere' => [['primary_ip' => '10.9.9.9'], PostbackResult::REFUSED, null, 'went to 10.9.9.9'],
        ];
    }

    /**
     * @dataProvider answers
     * @param array<string, mixed> $answer
     */
    public function testEachAnswerIsDeliveredFailedOrRefused(
        array $answer,
        string $outcome,
        ?int $status,
        string $why
    ): void {
        $result = $this->webhook($answer)->deliver('https://hooks.slack.com' . self::SECRET, 'payload={}', 10);

        self::assertSame($outcome, $result->outcome);
        self::assertSame($status, $result->status);
        self::assertStringContainsString($why, $result->reason);
        self::assertStringNotContainsString('deliverSecretPath', $result->reason);
        self::assertSame(10, $this->sent[0][CURLOPT_TIMEOUT]);
        self::assertSame(
            $outcome === PostbackResult::DELIVERED ? null : $result->reason,
            $this->webhook($answer)->post('https://hooks.slack.com' . self::SECRET, 'payload={}'),
            'post() says the same'
        );
    }

    public function testAHostThatDoesNotResolveIsTriedAgainAndAnInternalOneIsNot(): void
    {
        $unresolved = $this->webhook([])->deliver('https://nowhere.example.com' . self::SECRET, 'payload={}');
        self::assertSame(PostbackResult::FAILED, $unresolved->outcome);
        self::assertNull($unresolved->status);
        self::assertStringContainsString('does not resolve', $unresolved->reason);

        $internal = $this->webhook([], static fn (): array => ['10.1.2.3'])
            ->deliver('https://inside.example.com' . self::SECRET, 'payload={}');
        self::assertSame(PostbackResult::REFUSED, $internal->outcome);
        self::assertStringContainsString('private', $internal->reason);
        self::assertSame([], $this->sent, 'neither was connected to');
    }

    public function testAThrowingTransportIsAFailureNotAThrow(): void
    {
        $sender = new WebhookSender(
            new WebhookGuard(static fn (): array => ['93.184.216.34'], ''),
            static function (): array {
                throw new \RuntimeException('boom at https://hooks.slack.com' . self::SECRET);
            }
        );
        $result = (new SlackWebhook($sender))->deliver('https://hooks.slack.com' . self::SECRET, 'payload={}');

        self::assertSame(PostbackResult::FAILED, $result->outcome);
        self::assertStringContainsString('boom at https://hooks.slack.com/…', $result->reason);
    }
}
