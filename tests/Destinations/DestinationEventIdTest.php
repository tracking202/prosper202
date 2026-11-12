<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DestinationEventId;
use Tests\Support\FakeMysqliConnection;

/**
 * One event id per conversion on every channel: the destinations' server
 * events and the traffic source's browser pixels take it from one function.
 */
final class DestinationEventIdTest extends TestCase
{
    public function testTheTransactionIdElseTheConversionsOwnName(): void
    {
        self::assertSame('ORD-1001', DestinationEventId::from('ORD-1001', 7));
        self::assertSame('ORD-1001', DestinationEventId::from('  ORD-1001 ', 7));
        self::assertSame('P202-7', DestinationEventId::from('', 7));
        self::assertSame('P202-7', DestinationEventId::from('   ', 7));
        self::assertSame('P202-7', DestinationEventId::from(null, 7));
    }

    public function testAConversionThatReplacedOthersGoesByTheFirstOnesName(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('superseded_by = ?', [['conv_id' => 3, 'transaction_id' => 'ORD-FIRST']]);
        self::assertSame('ORD-FIRST', DestinationEventId::of(new Connection($db), 1, 4242, 7, 'ORD-SECOND'));
        $read = $db->statementsContaining('superseded_by = ?');
        self::assertCount(1, $read);

        $blank = new FakeMysqliConnection();
        $blank->whenQueryContainsReturnRows('superseded_by = ?', [['conv_id' => 3, 'transaction_id' => '']]);
        self::assertSame('P202-3', DestinationEventId::of(new Connection($blank), 1, 4242, 7, 'ORD-SECOND'));

        $none = new FakeMysqliConnection();
        $none->whenQueryContainsReturnRows('superseded_by = ?', []);
        self::assertSame('ORD-SECOND', DestinationEventId::of(new Connection($none), 1, 4242, 7, 'ORD-SECOND'));
        self::assertSame('P202-7', DestinationEventId::of(new Connection($none), 1, 4242, 7, null));
    }

    public function testTheMetaPurchaseCarriesTheEventIdNotTheTransactionToken(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('superseded_by = ?', []);
        $tokens = TrafficSourcePixels::withConversionEventId(new Connection($db), ['transactionid' => 'k-dedupe'], 1, 4242, 7, '');
        self::assertSame('P202-7', $tokens[TrafficSourcePixels::EVENT_ID], 'the name the destinations send the conversion under');
        self::assertSame('k-dedupe', $tokens['transactionid'], 'the [[transactionid]] token networks receive is unchanged');

        $markup = (string) TrafficSourcePixels::facebookPurchase('123456789012345', $tokens, 'USD');
        self::assertStringContainsString('eid=P202-7', $markup);
        self::assertStringNotContainsString('k-dedupe', $markup);
    }

    public function testALedgerThatCannotBeReadSendsThePixelWithoutAnEventId(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsResultFails('superseded_by = ?');
        $log = ini_set('error_log', '/dev/null');
        try {
            $tokens = TrafficSourcePixels::withConversionEventId(new Connection($db), ['transactionid' => 'ORD-1'], 1, 4242, 7, 'ORD-1');
        } finally {
            ini_set('error_log', (string) $log);
        }
        self::assertArrayNotHasKey(TrafficSourcePixels::EVENT_ID, $tokens);
        self::assertStringNotContainsString('eid=', (string) TrafficSourcePixels::facebookPurchase('123456789012345', $tokens, 'USD'));
    }
}
