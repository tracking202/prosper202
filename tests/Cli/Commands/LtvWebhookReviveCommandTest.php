<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Commands\BaseCommand;
use P202Cli\Commands\LtvWebhookReviveCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * The PHP CLI's ltv:webhook:revive, held to what the Go CLI's `ltv webhooks
 * revive` sends: PUT /ltv/webhooks/{id} with status active, and requeue
 * true unless --no-requeue.
 */
class LtvWebhookReviveCommandTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $sent = [];

    private function tester(): CommandTester
    {
        $command = new LtvWebhookReviveCommand();
        (new Application('test', '1.0'))->add($command);
        $sent = &$this->sent;
        $client = new class ($sent) extends ApiClient {
            /** @param list<array{0: string, 1: string, 2: array<string, mixed>}> $sent */
            public function __construct(private array &$sent)
            {
                parent::__construct('http://127.0.0.1:9', 'test-key');
            }

            public function put(string $path, array $body = []): array
            {
                $this->sent[] = ['PUT', $path, $body];

                return ['data' => ['webhook_id' => 3, 'status' => 'active', 'revived' => true, 'requeued' => 2]];
            }
        };
        (new \ReflectionProperty(BaseCommand::class, 'client'))->setValue($command, $client);

        return new CommandTester($command);
    }

    public function testItRevivesWithTheMissedDeliveriesUnlessToldNot(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['id' => '3', '--json' => true]));
        self::assertSame(['PUT', 'ltv/webhooks/3', ['status' => 'active', 'requeue' => true]], $this->sent[0]);
        self::assertStringContainsString('"requeued": 2', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['id' => '3', '--no-requeue' => true]));
        self::assertSame(['PUT', 'ltv/webhooks/3', ['status' => 'active', 'requeue' => false]], $this->sent[1]);
    }

    public function testABadIdIsRefusedBeforeAnyRequest(): void
    {
        $tester = $this->tester();
        foreach (['abc', '0', '3x', '-1'] as $bad) {
            self::assertSame(1, $tester->execute(['id' => $bad]), $bad);
            self::assertStringContainsString('webhook id must be a positive whole number', $tester->getDisplay());
        }
        self::assertSame([], $this->sent);
    }
}
