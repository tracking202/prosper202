<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Prosper202\Goals\InvalidGoalDefinition;
use Prosper202\Goals\TrafficSourceNotifier;
use Prosper202\Goals\WebEvents;

/**
 * Reading an event from a pixel or postback's query (plan §2.2): strict,
 * by parameter name, and with an id that makes a retry a duplicate.
 */
final class WebEventsTest extends TestCase
{
    public function testAnEventIsRequestedOnlyByANonEmptyEventParameter(): void
    {
        self::assertFalse(WebEvents::requested([]));
        self::assertFalse(WebEvents::requested(['event' => '']), 'an unfilled [[event]] template value is absent');
        self::assertFalse(WebEvents::requested(['event' => '  ']));
        self::assertTrue(WebEvents::requested(['event' => 'sale']));
        self::assertTrue(WebEvents::requested(['event' => ['a']]), 'present and malformed is requested, and then refused');
    }

    public function testAValidEventFromAQuery(): void
    {
        $e = WebEvents::fromQuery(
            ['event' => 'purchase', 'event_props' => '{"plan":"pro","seats":3}', 'amount' => '12.5'],
            'ORD-1',
            1_700_000_000,
            true
        );
        self::assertSame('@tx:ORD-1', $e->eventId);
        self::assertSame('purchase', $e->name);
        self::assertSame(['plan' => 'pro', 'seats' => 3], $e->properties);
        self::assertSame(12.5, $e->revenue);
        self::assertTrue($e->revenueTrusted);
        self::assertSame('ORD-1', $e->transactionId);
        self::assertSame(1_700_000_000, $e->occurredAt);
        self::assertTrue($e->clockedByServer, 'a pixel gives no time of its own');
        self::assertSame(5, WebEvents::fromQuery(['event' => 'x', 'amount' => '5'], '', 1, false)->revenue, 'an integer amount stays an integer');
    }

    public function testEventIdsAreTheSendersOrDerivedWithoutCollision(): void
    {
        self::assertSame('e-1', WebEvents::fromQuery(['event' => 'x', 'event_id' => 'e-1'], 'T', 1, true)->eventId);
        self::assertSame('@once:x', WebEvents::fromQuery(['event' => 'x'], '', 1, true)->eventId);
        self::assertSame('@tx:T', WebEvents::fromQuery(['event' => 'x'], 'T', 1, true)->eventId);

        // Too long, or not printable ASCII: hashed under its own prefix.
        $long = str_repeat('a', WebEvents::MAX_TX_IN_ID + 1);
        self::assertSame('@txh:' . hash('sha256', $long), WebEvents::derivedId($long, 'x'));
        self::assertSame('@txh:' . hash('sha256', 'a b'), WebEvents::derivedId('a b', 'x'));
        self::assertSame('@tx:' . str_repeat('a', WebEvents::MAX_TX_IN_ID), WebEvents::derivedId(str_repeat('a', WebEvents::MAX_TX_IN_ID), 'x'));

        // Injective across the three forms (CLAUDE.md #17): no transaction id
        // can make the id of another, nor of an id-less event.
        $ids = [];
        foreach (['', 'h:abc', 'x', 'once:x', ':x', $long, 'a b'] as $tx) {
            $ids[WebEvents::derivedId($tx, 'x')] = $tx;
        }
        self::assertCount(7, $ids);
        foreach (array_keys($ids) as $id) {
            self::assertLessThanOrEqual(128, strlen($id), 'every derived id fits the event id column');
            self::assertStringStartsWith('@', $id, 'and sits in the namespace no sender may use');
        }
    }

    /**
     * @dataProvider malformed
     * @param array<string, mixed> $get
     * @param list<string> $fields
     */
    public function testMalformedParametersAreRefusedByName(array $get, array $fields): void
    {
        try {
            WebEvents::fromQuery($get, '', 1, true);
            self::fail('refused');
        } catch (InvalidGoalDefinition $e) {
            self::assertSame($fields, array_keys($e->errors()));
        }
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function malformed(): iterable
    {
        yield 'a name with a space' => [['event' => 'Sale Complete'], ['event']];
        yield 'a name that is a list' => [['event' => ['sale']], ['event']];
        yield 'a reserved event id' => [['event' => 'x', 'event_id' => '@install'], ['event_id']];
        yield 'an event id with a space' => [['event' => 'x', 'event_id' => 'a b'], ['event_id']];
        yield 'props that are not JSON' => [['event' => 'x', 'event_props' => '{bad'], ['event_props']];
        yield 'props that are a list' => [['event' => 'x', 'event_props' => '[1,2]'], ['event_props']];
        yield 'props with a bad name' => [['event' => 'x', 'event_props' => '{"1a":1}'], ['event_props.1a']];
        yield 'props nested' => [['event' => 'x', 'event_props' => '{"a":{"b":1}}'], ['event_props.a']];
        yield 'an exponent amount' => [['event' => 'x', 'amount' => '1e3'], ['amount']];
        yield 'a padded amount' => [['event' => 'x', 'amount' => ' 5'], ['amount']];
        yield 'six decimal places' => [['event' => 'x', 'amount' => '0.123456'], ['amount']];
        yield 'an amount that is a list' => [['event' => 'x', 'amount' => ['5']], ['amount']];
        yield 'everything at once' => [['event' => 'a b', 'amount' => 'x', 'event_props' => '1'], ['amount', 'event', 'event_props']];
    }

    public function testTheNameOfANoGoalsHitIsKeptOnlyWhenItIsOne(): void
    {
        self::assertSame('sale', WebEvents::nameFrom(['event' => 'sale']));
        self::assertNull(WebEvents::nameFrom(['event' => 'Sale Complete']));
        self::assertNull(WebEvents::nameFrom(['event' => ['sale']]));
        self::assertNull(WebEvents::nameFrom([]));
    }

    public function testMoneyIsWrittenAsANetworkReadsIt(): void
    {
        self::assertSame('1.00', TrafficSourceNotifier::money('1.00000'));
        self::assertSame('12.50', TrafficSourceNotifier::money('12.50000'));
        self::assertSame('0.12345', TrafficSourceNotifier::money('0.12345'));
        self::assertSame('-3.10', TrafficSourceNotifier::money('-3.10000'));
        self::assertSame('7.00', TrafficSourceNotifier::money('7'));
    }
}
