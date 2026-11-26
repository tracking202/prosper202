<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Commands\BaseCommand;
use P202Cli\Commands\LtvWebhookRotateSecretCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * The PHP CLI's ltv:webhook:rotate-secret, held to what the Go CLI's `ltv
 * webhooks rotate-secret` sends: POST /ltv/webhooks/{id}/rotate-secret, with
 * overlap_hours only when given, as a number.
 */
class LtvWebhookRotateSecretCommandTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $sent = [];

    private function tester(): CommandTester
    {
        $command = new LtvWebhookRotateSecretCommand();
        (new Application('test', '1.0'))->add($command);
        $sent = &$this->sent;
        $client = new class ($sent) extends ApiClient {
            /** @param list<array{0: string, 1: string, 2: array<string, mixed>}> $sent */
            public function __construct(private array &$sent)
            {
                parent::__construct('http://127.0.0.1:9', 'test-key');
            }

            public function post(string $path, array $body = []): array
            {
                $this->sent[] = ['POST', $path, $body];

                return ['data' => ['webhook_id' => 3, 'secret' => 'whsec_new', 'overlap_hours' => 24]];
            }
        };
        (new \ReflectionProperty(BaseCommand::class, 'client'))->setValue($command, $client);

        return new CommandTester($command);
    }

    public function testItRotatesWithTheOverlapItIsGiven(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['id' => '3', '--json' => true]));
        self::assertSame(['POST', 'ltv/webhooks/3/rotate-secret', []], $this->sent[0]);
        self::assertStringContainsString('"secret": "whsec_new"', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['id' => '3', '--overlap-hours' => '0']));
        self::assertSame(['POST', 'ltv/webhooks/3/rotate-secret', ['overlap_hours' => 0]], $this->sent[1]);
    }

    public function testABadIdOrOverlapIsRefusedBeforeAnyRequest(): void
    {
        $tester = $this->tester();
        foreach (['abc', '0', '3x', '-1'] as $bad) {
            self::assertSame(1, $tester->execute(['id' => $bad]), $bad);
            self::assertStringContainsString('webhook id must be a positive whole number', $tester->getDisplay());
        }
        foreach (['169', '-1', '1.5', '24h', '', '007'] as $bad) {
            self::assertSame(1, $tester->execute(['id' => '3', '--overlap-hours' => $bad]), $bad);
            self::assertStringContainsString('--overlap-hours must be a whole number from 0 to 168', $tester->getDisplay());
        }
        self::assertSame([], $this->sent);
    }
}
