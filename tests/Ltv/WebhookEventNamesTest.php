<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlWebhookRepository;
use Tests\Support\FakeMysqliConnection;

/**
 * Event-name semantics behind the Landing Page Optimizer bridge (E.1): KNOWN_EVENTS is a
 * documentation listing, validation is well-formedness only, and the '*'
 * wildcard stores the '' subscribe-all value enqueue() already honors.
 */
final class WebhookEventNamesTest extends TestCase
{
    private function repo(FakeMysqliConnection $write, ?FakeMysqliConnection $read = null): MysqlWebhookRepository
    {
        return new MysqlWebhookRepository(new Connection($write, $read ?? new FakeMysqliConnection()));
    }

    public function testKnownEventsListsTheBridgeEvents(): void
    {
        self::assertContains('conversion.recorded', MysqlWebhookRepository::KNOWN_EVENTS);
        self::assertContains('engagement.recorded', MysqlWebhookRepository::KNOWN_EVENTS);
        // The original LTV events stay listed — existing UIs keep rendering.
        self::assertContains('customer.updated', MysqlWebhookRepository::KNOWN_EVENTS);
        self::assertContains('revenue.recorded', MysqlWebhookRepository::KNOWN_EVENTS);
        self::assertContains('subscription.changed', MysqlWebhookRepository::KNOWN_EVENTS);
    }

    public function testCreateWildcardStoresSubscribeAllSentinel(): void
    {
        $write = new FakeMysqliConnection();
        $created = $this->repo($write)->create(7, 'https://8.8.8.8/hooks/p202', ['*']);

        self::assertSame(48, strlen($created['secret']), 'secret is 24 random bytes hex-encoded');
        $inserts = $write->statementsContaining('INSERT INTO 202_ltv_webhooks');
        self::assertCount(1, $inserts);
        self::assertSame('isssii', $inserts[0]->boundTypes);
        self::assertSame('', $inserts[0]->boundValues[3], "['*'] must store '' — the subscribe-all value enqueue() treats as match-everything");
    }

    public function testCreateRejectsWildcardMixedWithNames(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'*' wildcard cannot be combined");
        $this->repo(new FakeMysqliConnection())->create(7, 'https://8.8.8.8/hooks/p202', ['*', 'conversion.recorded']);
    }

    public function testCreateAcceptsWellFormedFutureEventWithoutRepositoryEdits(): void
    {
        $write = new FakeMysqliConnection();
        $this->repo($write)->create(7, 'https://8.8.8.8/hooks/p202', ['lead.scored', 'conversion.recorded']);

        $inserts = $write->statementsContaining('INSERT INTO 202_ltv_webhooks');
        self::assertCount(1, $inserts);
        self::assertSame('lead.scored,conversion.recorded', $inserts[0]->boundValues[3]);
    }

    public function testCreateEmptyListDefaultsToKnownEvents(): void
    {
        $write = new FakeMysqliConnection();
        $this->repo($write)->create(7, 'https://8.8.8.8/hooks/p202', []);

        $inserts = $write->statementsContaining('INSERT INTO 202_ltv_webhooks');
        self::assertSame(implode(',', MysqlWebhookRepository::KNOWN_EVENTS), $inserts[0]->boundValues[3]);
    }

    /**
     * @dataProvider malformedEventNames
     */
    public function testCreateRejectsMalformedEventNames(string $eventName): void
    {
        $write = new FakeMysqliConnection();
        try {
            $this->repo($write)->create(7, 'https://8.8.8.8/hooks/p202', [$eventName]);
            self::fail('Malformed event name "' . $eventName . '" must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('namespaced lowercase slug', $e->getMessage());
            self::assertSame([], $write->statementsContaining('INSERT INTO 202_ltv_webhooks'), 'nothing may be written for a rejected name');
        }
    }

    /**
     * @return list<array{string}>
     */
    public function malformedEventNames(): array
    {
        return [
            ['CONVERSION.RECORDED'],   // uppercase
            ['conversion'],            // no namespace separator
            ['conversion recorded'],   // whitespace
            ['.recorded'],             // empty namespace
            ['conversion.'],           // empty leaf
            ['conv,ersion.recorded'],  // CSV metacharacter
            [''],
        ];
    }

    public function testEnqueueRejectsMalformedNameBeforeAnyQuery(): void
    {
        $read = new FakeMysqliConnection();
        $repo = $this->repo(new FakeMysqliConnection(), $read);

        try {
            $repo->enqueue(7, 'not a slug', ['k' => 'v']);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame([], $read->preparedSql, 'the well-formedness check must short-circuit before DB work');
        }
    }

    public function testEnqueueAcceptsWellFormedUnknownEventAndQueuesForSubscriber(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_ltv_webhooks', [['webhook_id' => 4]]);
        $this->repo($write, $read)->enqueue(7, 'future.event', ['k' => 'v']);

        $inserts = $write->statementsContaining('INSERT INTO 202_ltv_webhook_deliveries');
        self::assertCount(1, $inserts);
        self::assertSame('future.event', $inserts[0]->boundValues[2], 'no membership list may block a well-formed future event');
    }
}
