<?php

declare(strict_types=1);

namespace Tests\Bridge;

use PHPUnit\Framework\TestCase;
use Prosper202\Bridge\EventBridge;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlEngagementRepository;
use Tests\Support\FakeMysqliConnection;

/**
 * The generic bridge emitter (E.2/E.4): versioned envelope shape,
 * fire-and-forget error swallowing, and the fail-closed remote-config gate
 * for optional events.
 */
final class EventBridgeTest extends TestCase
{
    public function testEmitWrapsPayloadInVersionedEnvelope(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_users WHERE', [['install_hash' => 'hash-1234']]);
        $read->whenQueryContainsReturnRows('FROM 202_ltv_webhooks', [['webhook_id' => 5]]);

        EventBridge::emit(new Connection($write, $read), 9, 'conversion.recorded', ['click_id' => 77, 'payout' => 1.5]);

        $inserts = $write->statementsContaining('INSERT INTO 202_ltv_webhook_deliveries');
        self::assertCount(1, $inserts);
        self::assertSame(5, $inserts[0]->boundValues[0], 'queued against the subscribed webhook');
        self::assertSame(9, $inserts[0]->boundValues[1]);
        self::assertSame('conversion.recorded', $inserts[0]->boundValues[2]);

        $body = (string) $inserts[0]->boundValues[3];
        $wire = json_decode($body, true);
        self::assertSame('conversion.recorded', $wire['event'], 'wire body keeps the dispatcher contract {event, occurred_at, data}');
        self::assertIsInt($wire['occurred_at']);

        $envelope = $wire['data'];
        self::assertSame(EventBridge::BRIDGE_VERSION, $envelope['bridge_version']);
        self::assertSame('hash-1234', $envelope['install']['install_hash']);
        self::assertSame(9, $envelope['install']['user_id']);
        self::assertIsString($envelope['install']['p202_version']);
        self::assertSame(['click_id' => 77, 'payout' => 1.5], $envelope['payload']);
        self::assertStringContainsString('"ext":{}', $body, 'ext must encode as a JSON object even when empty');
    }

    public function testEmitCarriesExtBagWhenProvided(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_ltv_webhooks', [['webhook_id' => 5]]);

        EventBridge::emit(new Connection($write, $read), 9, 'conversion.recorded', ['a' => 1], ['trace_id' => 'abc']);

        $inserts = $write->statementsContaining('INSERT INTO 202_ltv_webhook_deliveries');
        $wire = json_decode((string) $inserts[0]->boundValues[3], true);
        self::assertSame(['trace_id' => 'abc'], $wire['data']['ext']);
    }

    public function testEmitIsNoOpWithoutSubscribers(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection(); // subscriber lookup matches no rows

        EventBridge::emit(new Connection($write, $read), 9, 'conversion.recorded', ['a' => 1]);

        self::assertSame([], $write->statementsContaining('INSERT INTO 202_ltv_webhook_deliveries'));
    }

    public function testEmitSwallowsRepositoryFailures(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsExecuteReturns('FROM 202_ltv_webhooks', false); // subscriber lookup fails hard

        EventBridge::emit(new Connection($write, $read), 9, 'conversion.recorded', ['a' => 1]);

        self::assertSame([], $write->statementsContaining('INSERT INTO 202_ltv_webhook_deliveries'), 'no delivery may be queued after a failure');
        $this->addToAssertionCount(1); // reaching here proves the Throwable was swallowed
    }

    public function testEmitSwallowsMalformedEventNames(): void
    {
        $write = new FakeMysqliConnection();

        EventBridge::emit(new Connection($write, new FakeMysqliConnection()), 9, 'NOT A SLUG', ['a' => 1]);

        self::assertSame([], $write->statementsContaining('INSERT INTO 202_ltv_webhook_deliveries'));
        $this->addToAssertionCount(1);
    }

    // --- the remote-config gate for optional events (E.4) ---

    private function gate(?string $storedConfig): bool
    {
        $read = new FakeMysqliConnection();
        if ($storedConfig !== null) {
            $read->whenQueryContainsReturnRows('lpo_bridge_config', [['lpo_bridge_config' => $storedConfig]]);
        }

        return EventBridge::isEventEnabled(new Connection(new FakeMysqliConnection(), $read), 7, 'engagement.recorded');
    }

    public function testGateIsClosedByDefault(): void
    {
        self::assertFalse($this->gate(null), 'unpaired install: no pref row');
        self::assertFalse($this->gate(''), 'paired but no config pulled yet');
        self::assertFalse($this->gate('{"webhook_id":3}'), 'state without an applied config');
    }

    public function testGateFailsClosedOnMalformedConfig(): void
    {
        self::assertFalse($this->gate('{broken'));
        self::assertFalse($this->gate('{"config":{"enabled_events":"*"}}'), 'enabled_events must be a list, not a string');
    }

    public function testGateOpensOnExplicitNameOrWildcard(): void
    {
        self::assertTrue($this->gate('{"webhook_id":3,"config":{"enabled_events":["engagement.recorded"]}}'));
        self::assertTrue($this->gate('{"webhook_id":3,"config":{"enabled_events":["*"]}}'));
        self::assertFalse($this->gate('{"webhook_id":3,"config":{"enabled_events":["conversion.recorded"]}}'), 'a list lacking the event keeps it off');
    }

    public function testGateFailsClosedWhenPrefReadFails(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsExecuteReturns('lpo_bridge_config', false);

        self::assertFalse(EventBridge::isEventEnabled(new Connection(new FakeMysqliConnection(), $read), 7, 'engagement.recorded'));
    }

    public function testRecordEventEmitsOnlyWhenRemoteConfigEnablesIt(): void
    {
        // Gate closed (no bridge config): the engagement write succeeds and
        // nothing is queued.
        $write = new FakeMysqliConnection();
        $repo = new MysqlEngagementRepository(new Connection($write, new FakeMysqliConnection()));
        $repo->recordEvent(7, 501, 'pricing_viewed');
        self::assertCount(1, $write->statementsContaining('INSERT INTO 202_engagement_events'));
        self::assertSame([], $write->statementsContaining('INSERT INTO 202_ltv_webhook_deliveries'));

        // Gate open via remote config: one delivery, with the engagement
        // identifiers in the envelope payload.
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('lpo_bridge_config', [
            ['lpo_bridge_config' => '{"webhook_id":3,"config":{"enabled_events":["engagement.recorded"]}}'],
        ]);
        $read->whenQueryContainsReturnRows('FROM 202_ltv_webhooks', [['webhook_id' => 3]]);
        $repo = new MysqlEngagementRepository(new Connection($write, $read));
        $eventId = $repo->recordEvent(7, 501, 'pricing_viewed', 'api', 88, 1700000000, 12.5);

        $inserts = $write->statementsContaining('INSERT INTO 202_ltv_webhook_deliveries');
        self::assertCount(1, $inserts);
        $wire = json_decode((string) $inserts[0]->boundValues[3], true);
        self::assertSame('engagement.recorded', $wire['event']);
        $payload = $wire['data']['payload'];
        self::assertSame($eventId, $payload['engagement_id'], 'the payload carries the id recordEvent returned');
        self::assertSame(501, $payload['customer_id']);
        self::assertSame('pricing_viewed', $payload['event_name']);
        self::assertSame('api', $payload['source']);
        self::assertSame(88, $payload['click_id']);
        self::assertSame(12.5, $payload['event_value']);
        self::assertSame(1700000000, $payload['occurred_at']);
    }
}
